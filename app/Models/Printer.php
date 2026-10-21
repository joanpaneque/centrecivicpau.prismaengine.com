<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $type simulated|escpos_network|system
 * @property int|null $print_agent_id the Windows program that prints it (null = the server or the cashier browser)
 * @property string|null $agent_key its id in that program, which is where it is set up
 * @property string $output escpos (raw bytes, thermal printers)|driver (through the Windows driver)
 * @property string $codepage cp858|cp1252|ascii (accents on ESC/POS printers)
 * @property string|null $ip
 * @property int|null $port
 * @property string|null $model
 * @property string|null $system_name Windows printer name shown in the OS dialog
 * @property int $paper_width characters per line
 * @property int $margin_top blank lines before every document
 * @property int $margin_bottom blank lines after every document (before the cut)
 * @property int|null $margin_bottom_under only add the bottom margin to documents shorter than this many lines (null = always)
 * @property bool $is_ticket_printer
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PrintAgent|null $agent
 */
class Printer extends Model
{
    public const TYPES = ['system', 'escpos_network', 'simulated'];

    public const OUTPUTS = ['escpos', 'driver'];

    public const CODEPAGES = ['cp858', 'cp1252', 'ascii'];

    public const MAX_MARGIN = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_ticket_printer' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PrintAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'print_agent_id');
    }

    /**
     * Prints without any dialog: through the Windows program, or straight to a network
     * printer the server can reach.
     */
    public function printsDirectly(): bool
    {
        return $this->print_agent_id !== null || $this->type === 'escpos_network';
    }

    /**
     * The document as this printer prints it: with its blank margin on top, and at the
     * bottom before the paper cut (or at the end if there is no cut).
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function withMargins(array $document): array
    {
        $lines = array_values(array_filter((array) ($document['lines'] ?? []), 'is_array'));
        $top = min(self::MAX_MARGIN, max(0, (int) $this->margin_top));
        $bottom = min(self::MAX_MARGIN, max(0, (int) $this->margin_bottom));

        if ($bottom > 0 && $this->margin_bottom_under !== null && $this->documentHeight($lines) >= $this->margin_bottom_under) {
            $bottom = 0;
        }

        if ($top === 0 && $bottom === 0) {
            return $document;
        }

        if ($bottom > 0) {
            $cut = null;

            foreach ($lines as $index => $line) {
                if (($line['type'] ?? null) === 'cut') {
                    $cut = $index;
                }
            }

            array_splice($lines, $cut ?? count($lines), 0, [['type' => 'feed', 'lines' => $bottom]]);
        }

        if ($top > 0) {
            array_unshift($lines, ['type' => 'feed', 'lines' => $top]);
        }

        return ['lines' => $lines] + $document;
    }

    /**
     * Rough length of a document in normal printed lines: long text wraps at the paper
     * width and big text takes two lines.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function documentHeight(array $lines): int
    {
        $width = max(16, (int) ($this->paper_width ?: 48));
        $height = 0;

        foreach ($lines as $line) {
            $big = (int) ($line['size'] ?? 1) > 1;

            $height += match ($line['type'] ?? 'text') {
                'feed' => max(1, (int) ($line['lines'] ?? 1)),
                'cut' => 0,
                'qr' => 10,
                'image' => 6,
                'row', 'divider' => $big ? 2 : 1,
                default => (int) max(1, ceil(mb_strlen((string) ($line['text'] ?? '')) / ($big ? intdiv($width, 2) : $width))) * ($big ? 2 : 1),
            };
        }

        return $height;
    }

    /**
     * Set up in the Windows print program (name, where and how it prints); the web only
     * chooses what it prints.
     */
    public function managedByAgent(): bool
    {
        return $this->print_agent_id !== null && $this->agent_key !== null;
    }

    /**
     * @return BelongsToMany<ProductionDestination, $this>
     */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(ProductionDestination::class);
    }
}
