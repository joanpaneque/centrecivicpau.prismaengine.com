<?php

namespace App\Services\Assistant;

use App\Models\Carta;
use App\Models\SetMenu;
use App\Services\Catalog\CartaDocument;
use App\Services\Catalog\MenuDocument;
use Illuminate\Support\Facades\Log;

/**
 * Translates a set menu or a carta (name, subtitle, sections, dishes and descriptions)
 * into the printing languages in one call. Results are stored on the model together with
 * a hash of the Catalan/Spanish texts, so a later change invalidates them.
 */
class MenuTranslator
{
    private const NAMES = [
        'fr' => 'francés', 'en' => 'inglés', 'de' => 'alemán', 'ru' => 'ruso (alfabeto cirílico)', 'it' => 'italiano', 'nl' => 'neerlandés',
    ];

    public function __construct(
        private readonly AssistantService $assistant,
        private readonly MenuDocument $document,
        private readonly CartaDocument $cartas,
    ) {}

    /**
     * Make sure the menu or carta has up-to-date translations for these languages. Returns
     * false when some could not be produced (the page then falls back to Spanish).
     *
     * @param  list<string>  $locales
     */
    public function ensure(SetMenu|Carta $menu, array $locales): bool
    {
        $document = $menu instanceof Carta ? $this->cartas : $this->document;
        $missing = $document->missing($menu, $locales);

        if ($missing === []) {
            return true;
        }

        if (! $this->assistant->configured()) {
            return false;
        }

        $source = $document->source($menu);
        $hash = $document->hash($menu);
        $languages = implode(', ', array_map(fn (string $l) => "{$l} = ".self::NAMES[$l], $missing));
        $shape = $this->shape($source);
        $kind = $menu instanceof Carta ? 'una carta' : 'un menú';

        $system = <<<PROMPT
        Eres traductor de cartas de restaurante. Te paso {$kind} en catalán (ca) y castellano (es): cada texto viene como {"ca": …, "es": …}.
        Tradúcelo a: {$languages}.
        - Traducción natural de carta de restaurante, con mayúscula inicial. Platos típicos catalanes o españoles muy conocidos (paella, crema catalana, escalivada, pa amb tomàquet, tortilla…) se dejan en su nombre original o con una aclaración breve si hace falta.
        - No traduzcas nombres propios ni marcas. No añadas precios.
        - Mantén EXACTAMENTE la estructura: mismo número de secciones y de platos, en el mismo orden; cada texto pasa a ser una sola cadena y los null siguen siendo null.
        Responde SOLO con un objeto JSON cuyas claves sean los códigos de idioma y cada valor tenga esta forma:
        PROMPT;

        try {
            $reply = $this->assistant->complete([
                ['role' => 'system', 'content' => $system."\n".json_encode($shape, JSON_UNESCAPED_UNICODE)],
                ['role' => 'user', 'content' => (string) json_encode($source, JSON_UNESCAPED_UNICODE)],
            ], []);
        } catch (AssistantException $e) {
            Log::warning('Menu translation failed', [$menu instanceof Carta ? 'carta' : 'menu' => $menu->id, 'error' => $e->getMessage()]);

            return false;
        }

        $translated = $this->parse((string) ($reply['message']['content'] ?? ''), $source, $missing);
        $stored = $menu->translations ?? [];
        $locales = ($stored['hash'] ?? null) === $hash ? ($stored['locales'] ?? []) : [];

        $menu->forceFill(['translations' => ['hash' => $hash, 'locales' => $translated + $locales]])->saveQuietly();

        return count(array_intersect_key($translated, array_flip($missing))) === count($missing);
    }

    /**
     * Keep only well-formed languages whose structure matches the source: same keys, lists
     * of the same length, and a string (or null) in place of every {ca, es} text.
     *
     * @param  array<string, mixed>  $source
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

            if (! is_array($value) || ! is_string($value['name'] ?? null)) {
                continue;
            }

            $matched = $this->match($source, $value);

            if (is_array($matched)) {
                $result[$locale] = $matched;
            }
        }

        return $result;
    }

    /**
     * The source with every text replaced by "…": what the answer has to look like.
     */
    private function shape(mixed $source): mixed
    {
        if ($this->isText($source)) {
            return '…';
        }

        return is_array($source) ? array_map(fn ($node) => $this->shape($node), $source) : null;
    }

    /**
     * @return mixed the translated node, or false when its structure does not match
     */
    private function match(mixed $source, mixed $value): mixed
    {
        if ($source === null) {
            return null;
        }

        if ($this->isText($source)) {
            return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 1000) : null;
        }

        if (! is_array($source) || ! is_array($value)) {
            return false;
        }

        if (array_is_list($source)) {
            if (! array_is_list($value) || count($value) !== count($source)) {
                return false;
            }
        }

        $result = [];

        foreach ($source as $key => $node) {
            $matched = $this->match($node, $value[$key] ?? null);

            if ($matched === false) {
                return false;
            }

            $result[$key] = $matched;
        }

        return $result;
    }

    private function isText(mixed $node): bool
    {
        return is_array($node) && array_keys($node) === ['ca', 'es'];
    }
}
