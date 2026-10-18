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
