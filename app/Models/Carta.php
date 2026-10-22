<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A printable carta (drinks, tapas, desserts…), separate from the set menus.
 *
 * @property int $id
 * @property array<string, string> $name
 * @property array<string, string>|null $subtitle
 * @property array<string, string>|null $notes small text at the bottom of the last sheet (a legend, allergens…)
 * @property string $template classic|halloween|christmas|easter|kids (printed design)
 * @property array{max_pages?: int|null, css?: string|null}|null $layout fit in at most N sheets per language; extra CSS for the printed page
 * @property array{hash?: string, locales?: array<string, array<string, mixed>>}|null $translations texts in the printing languages other than ca/es
 * @property bool $active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Carta extends Model
{
    use HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'subtitle' => 'array',
            'notes' => 'array',
            'layout' => 'array',
            'translations' => 'array',
            'active' => 'boolean',
        ];
    }

    public function maxPages(): ?int
    {
        $pages = $this->layout['max_pages'] ?? null;

        return is_numeric($pages) && (int) $pages > 0 ? (int) $pages : null;
    }

    public function css(): ?string
    {
        $css = $this->layout['css'] ?? null;

        return is_string($css) && trim($css) !== '' ? $css : null;
    }

    /**
     * Extra CSS as it can go inside the page's <style>: no way out of the tag, no imports
     * or external files (the PDF is made on the server).
     */
    public static function cleanCss(mixed $css): ?string
    {
        if (! is_string($css)) {
            return null;
        }

        $css = str_replace('<', '', $css);
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/url\s*\([^)]*\)/i', 'none', $css);
        $css = str_ireplace(['expression(', 'javascript:', 'behavior:'], '', $css);
        $css = trim(mb_substr($css, 0, 8000));

        return $css === '' ? null : $css;
    }

    /**
     * @return HasMany<CartaSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(CartaSection::class)->orderBy('sort');
    }
}
