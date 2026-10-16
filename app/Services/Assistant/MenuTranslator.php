<?php

namespace App\Services\Assistant;

use App\Models\SetMenu;
use App\Services\Catalog\MenuDocument;
use Illuminate\Support\Facades\Log;

/**
 * Translates a set menu (name, what it includes, sections and dishes) into the printing
 * languages in one call. Results are stored on the menu together with a hash of the
 * Catalan/Spanish texts, so a later change to the menu invalidates them.
 */
class MenuTranslator
{
    private const NAMES = [
        'fr' => 'francés', 'en' => 'inglés', 'de' => 'alemán', 'ru' => 'ruso (alfabeto cirílico)', 'it' => 'italiano', 'nl' => 'neerlandés',
    ];

    public function __construct(
        private readonly AssistantService $assistant,
        private readonly MenuDocument $document,
    ) {}

    /**
     * Make sure the menu has up-to-date translations for these languages. Returns false
     * when some could not be produced (the page then falls back to Spanish).
     *
     * @param  list<string>  $locales
     */
    public function ensure(SetMenu $menu, array $locales): bool
    {
        $missing = $this->document->missing($menu, $locales);

        if ($missing === []) {
            return true;
        }

        if (! $this->assistant->configured()) {
            return false;
        }

        $source = $this->document->source($menu);
        $hash = $this->document->hash($menu);
        $languages = implode(', ', array_map(fn (string $l) => "{$l} = ".self::NAMES[$l], $missing));
        $shape = [
            'name' => '…',
            'includes' => $source['includes'] ? '…' : null,
            'sections' => array_map(fn (array $s) => ['name' => '…', 'items' => array_fill(0, count($s['items']), '…')], $source['sections']),
        ];

        $system = <<<PROMPT
        Eres traductor de cartas de restaurante. Te paso un menú en catalán (ca) y castellano (es).
        Tradúcelo a: {$languages}.
        - Traducción natural de carta de restaurante, con mayúscula inicial. Platos típicos catalanes o españoles muy conocidos (paella, crema catalana, escalivada, pa amb tomàquet, tortilla…) se dejan en su nombre original o con una aclaración breve si hace falta.
        - No traduzcas nombres propios ni marcas. No añadas precios.
        - Mantén EXACTAMENTE la estructura: mismo número de secciones y de platos, en el mismo orden.
        Responde SOLO con un objeto JSON cuyas claves sean los códigos de idioma y cada valor tenga esta forma:
        PROMPT;

        try {
            $reply = $this->assistant->complete([
                ['role' => 'system', 'content' => $system."\n".json_encode($shape, JSON_UNESCAPED_UNICODE)],
                ['role' => 'user', 'content' => (string) json_encode($source, JSON_UNESCAPED_UNICODE)],
            ], []);
        } catch (AssistantException $e) {
            Log::warning('Menu translation failed', ['menu' => $menu->id, 'error' => $e->getMessage()]);

            return false;
        }

        $translated = $this->parse((string) ($reply['message']['content'] ?? ''), $source, $missing);
        $stored = $menu->translations ?? [];
        $locales = ($stored['hash'] ?? null) === $hash ? ($stored['locales'] ?? []) : [];

        $menu->forceFill(['translations' => ['hash' => $hash, 'locales' => $translated + $locales]])->saveQuietly();

        return count(array_intersect_key($translated, array_flip($missing))) === count($missing);
    }

    /**
     * Keep only well-formed languages whose structure matches the menu.
     *
     * @param  array{name: array<string, string>, includes: array<string, string>|null, sections: list<array{name: array<string, string>, items: list<array<string, string>>}>}  $source
     * @param  list<string>  $locales
     * @return array<string, array<string, mixed>>
     */
    public function parse(string $content, array $source, array $locales): array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($content, $start, $end - $start + 1), true) : null;

        if (! is_array($data)) {
            return [];
        }

        $result = [];

        foreach ($locales as $locale) {
            $value = $data[$locale] ?? null;

            if (! is_array($value) || ! is_string($value['name'] ?? null) || count((array) ($value['sections'] ?? [])) !== count($source['sections'])) {
                continue;
            }

            $sections = [];

            foreach ($source['sections'] as $s => $section) {
                $row = $value['sections'][$s] ?? null;

                if (! is_array($row) || count((array) ($row['items'] ?? [])) !== count($section['items'])) {
                    continue 2;
                }

                $sections[] = [
                    'name' => mb_substr(trim((string) ($row['name'] ?? '')), 0, 120),
                    'items' => array_map(fn ($item) => mb_substr(trim((string) $item), 0, 160), array_values((array) $row['items'])),
                ];
            }

            $result[$locale] = [
                'name' => mb_substr(trim($value['name']), 0, 120),
                'includes' => is_string($value['includes'] ?? null) ? mb_substr(trim($value['includes']), 0, 250) : null,
                'sections' => $sections,
            ];
        }

        return $result;
    }
}
