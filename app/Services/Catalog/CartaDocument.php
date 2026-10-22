<?php

namespace App\Services\Catalog;

use App\Models\Carta;
use App\Models\CartaItem;
use App\Models\CartaSection;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Printable carta, with the same designs and languages as the set menus. Each language
 * starts on a new A4 sheet; a short carta fits on one, a long one flows onto as few sheets
 * as possible with the same type size on all of them.
 */
class CartaDocument
{
    /** Below this the type gets too small: better another sheet. */
    private const MIN_DENSITY = 0.3;

    /** Usable height of a sheet (mm); the festive designs have drawings along the bottom. */
    private const PAGE_HEIGHT = ['classic' => 240, 'kids' => 240];

    private const DECORATED_PAGE_HEIGHT = 220;

    public function __construct(private readonly MenuDocument $menus) {}

    /**
     * Texts to translate, in both source languages, with the structure of the carta.
     *
     * @return array{name: array{ca: string, es: string}, subtitle: array{ca: string, es: string}|null, sections: list<array{name: array{ca: string, es: string}, items: list<array{name: array{ca: string, es: string}, description: array{ca: string, es: string}|null}>}>, notes: array{ca: string, es: string}|null}
     */
    public function source(Carta $carta): array
    {
        $carta->loadMissing('sections.items.product');

        return [
            'name' => $this->menus->pair($carta->name),
            'subtitle' => $carta->subtitle ? $this->menus->pair($carta->subtitle) : null,
            'sections' => array_values($carta->sections->map(fn (CartaSection $s) => [
                'name' => $this->menus->pair($s->name),
                'items' => array_values($s->items->map(fn (CartaItem $i) => [
                    'name' => $this->menus->pair($i->displayName()),
                    'description' => $i->description ? $this->menus->pair($i->description) : null,
                ])->all()),
            ])->all()),
            'notes' => $carta->notes ? $this->menus->pair($carta->notes) : null,
        ];
    }

    public function hash(Carta $carta): string
    {
        return md5((string) json_encode($this->source($carta), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Languages (other than ca/es) still missing or out of date for the current texts.
     *
     * @param  list<string>  $locales
     * @return list<string>
     */
    public function missing(Carta $carta, array $locales): array
    {
        $stored = $carta->translations ?? [];
        $fresh = ($stored['hash'] ?? null) === $this->hash($carta);

        return array_values(array_filter(
            array_intersect(MenuDocument::LOCALES, $locales),
            fn (string $locale) => ! in_array($locale, ['ca', 'es'], true) && (! $fresh || ! isset($stored['locales'][$locale])),
        ));
    }

    /**
     * The carta in one language, ready for the template.
     *
     * @return array{kind: string, locale: string, name: string, subtitle: string|null, notes: string|null, vat: string, sections: list<array{name: string, items: list<array{name: string, description: string|null, price: string|null}>}>}
     */
    public function localized(Carta $carta, string $locale): array
    {
        $source = $this->source($carta);
        $stored = $carta->translations ?? [];
        $fresh = ($stored['hash'] ?? null) === $this->hash($carta);
        $translated = $fresh ? ($stored['locales'][$locale] ?? null) : null;
        $pick = fn (array $pair, mixed $other) => in_array($locale, ['ca', 'es'], true)
            ? ($pair[$locale] ?: ($pair['ca'] ?: $pair['es']))
            : (is_string($other) && trim($other) !== '' ? trim($other) : ($pair['es'] ?: $pair['ca']));

        $sections = [];

        foreach ($source['sections'] as $s => $section) {
            $items = [];

            foreach ($section['items'] as $i => $item) {
                $dish = $carta->sections[$s]->items[$i];
                $price = $dish->effectivePrice();
                $items[] = [
                    'name' => $pick($item['name'], $translated['sections'][$s]['items'][$i]['name'] ?? null),
                    'description' => $item['description'] ? $pick($item['description'], $translated['sections'][$s]['items'][$i]['description'] ?? null) : null,
                    'price' => $dish->price_text ?? ($price === null ? null : $this->menus->money($price, $locale)),
                ];
            }

            $sections[] = ['name' => $pick($section['name'], $translated['sections'][$s]['name'] ?? null), 'items' => $items];
        }

        return [
            'kind' => 'carta',
            'locale' => $locale,
            'name' => $pick($source['name'], $translated['name'] ?? null),
            'subtitle' => $source['subtitle'] ? $pick($source['subtitle'], $translated['subtitle'] ?? null) : null,
            'notes' => $source['notes'] ? $pick($source['notes'], $translated['notes'] ?? null) : null,
            'vat' => (MenuDocument::LABELS[$locale] ?? MenuDocument::LABELS['es'])['vat'],
            'sections' => $sections,
        ];
    }

    /**
     * @param  list<string>  $locales
     */
    public function pdf(Carta $carta, string $template, array $locales): string
    {
        return Pdf::loadHTML($this->html($carta, $template, $locales))->setPaper('a4')->output();
    }

    /**
     * The same pages as HTML (the print dialog previews it in an iframe).
     *
     * @param  list<string>  $locales
     */
    public function html(Carta $carta, string $template, array $locales): string
    {
        $template = in_array($template, MenuDocument::TEMPLATES, true) ? $template : 'classic';
        $locales = array_values(array_intersect(MenuDocument::LOCALES, $locales)) ?: ['ca'];
        $logo = $this->menus->logo();
        $pages = [];

        foreach ($locales as $locale) {
            array_push($pages, ...$this->fitted($this->localized($carta, $locale), $template, $logo, $carta->maxPages(), $carta->css()));
        }

        return $this->menus->view($template, $pages, $logo, $carta->css());
    }

    /**
     * How the carta really prints, language by language: sheets DomPDF needs, type size used
     * and whether it is at the smallest readable size. The assistant uses it to check its
     * layout (fit in one sheet, its CSS…) and fix it before showing it.
     *
     * @param  list<string>  $locales
     * @return list<array{locale: string, sheets: int, planned_sheets: int, max_pages: int|null, type_size: float, at_minimum: bool, fits: bool}>
     */
    public function report(Carta $carta, string $template, array $locales): array
    {
        $template = in_array($template, MenuDocument::TEMPLATES, true) ? $template : 'classic';
        $logo = $this->menus->logo();
        $report = [];

        foreach (array_values(array_intersect(MenuDocument::LOCALES, $locales)) ?: ['ca'] as $locale) {
            $pages = $this->fitted($this->localized($carta, $locale), $template, $logo, $carta->maxPages(), $carta->css());
            $density = (float) ($pages[0]['sizes']['density'] ?? 1);
            $sheets = $this->sheets($this->menus->view($template, $pages, $logo, $carta->css()));
            $report[] = [
                'locale' => $locale,
                'sheets' => $sheets,
                'planned_sheets' => count($pages),
                'max_pages' => $carta->maxPages(),
                'type_size' => round($density, 2),
                'at_minimum' => $density <= ($carta->maxPages() !== null ? 0.0 : self::MIN_DENSITY) + 0.001,
                'fits' => $carta->maxPages() === null || $sheets <= $carta->maxPages(),
            ];
        }

        return $report;
    }

    private function sheets(string $html): int
    {
        $pdf = Pdf::loadHTML($html)->setPaper('a4');
        $pdf->render();

        return (int) $pdf->getDomPDF()->getCanvas()->get_page_count();
    }

    /**
     * Sheets for one language: the largest type that keeps the fewest sheets, checked with
     * DomPDF and shrunk if a sheet still overflows.
     *
     * @param  array{kind: string, locale: string, name: string, subtitle: string|null, notes?: string|null, vat: string, sections: list<array{name: string, items: list<array{name: string, description: string|null, price: string|null}>}>}  $carta
     * @return list<array<string, mixed>>
     */
    private function fitted(array $carta, string $template, ?string $logo, ?int $maxPages = null, ?string $css = null): array
    {
        $density = $this->density($carta, $template, $logo !== null, $maxPages);

        do {
            $pages = $this->paginate($carta, $this->menus->sizes($density), $template, $logo !== null);
            $sheets = $this->sheets($this->menus->view($template, $pages, $logo, $css));
            $density = round($density - 0.05, 2);
        } while ($sheets > count($pages) && $density >= 0);

        return $pages;
    }

    /**
     * Largest density that needs no more sheets than the smallest readable type would. With
     * a maximum of sheets, the type may go smaller to keep within it, or stay bigger when it
     * allows more sheets than the fewest.
     *
     * @param  array{name: string, subtitle: string|null, notes?: string|null, sections: list<array{name: string, items: list<array{name: string, description: string|null, price: string|null}>}>}  $carta
     */
    public function density(array $carta, string $template = 'classic', bool $logo = true, ?int $maxPages = null): float
    {
        $minimum = $maxPages !== null ? 0.0 : self::MIN_DENSITY;
        $fewest = count($this->paginate($carta, $this->menus->sizes($minimum), $template, $logo));
        $target = $maxPages !== null ? max($fewest, $maxPages) : $fewest;

        for ($step = 0; 1 - $step * 0.02 > $minimum; $step++) {
            $d = round(1 - $step * 0.02, 2);

            if (count($this->paginate($carta, $this->menus->sizes($d), $template, $logo)) <= $target) {
                return $d;
            }
        }

        return $minimum;
    }

    /**
     * Split the carta into sheets by estimated height. The title goes on the first sheet,
     * the VAT note on the last; a section cut in two repeats its heading.
     *
     * @param  array{locale?: string, vat?: string, name: string, subtitle: string|null, notes?: string|null, sections: list<array{name: string, items: list<array{name: string, description: string|null, price: string|null}>}>}  $carta
     * @param  array<string, float>  $z
     * @return list<array<string, mixed>>
     */
    public function paginate(array $carta, array $z, string $template, bool $logo): array
    {
        $pt = 0.3528;
        $width = $template === 'kids' ? 92 : 118;
        $line = fn (float $size, string $text) => ceil(max(1, mb_strlen($text)) / max(1, $width / ($size * $pt * 0.55))) * $size * $pt * 1.3;
        $heading = $z['sectionGap'] + $z['section'] * $pt * 1.35 + $z['headingGap'] + ($template === 'kids' ? 2 * $z['boxPadding'] + 2 : 0);

        $header = ($logo ? $z['logo'] + 2 : 0) + $z['titleGap'] + $z['title'] * $pt * 1.25
            + ($carta['subtitle'] ? $z['includesGap'] + $line($z['includes'], $carta['subtitle']) : 0)
            + $z['dividerTop'] + 4.6 + $z['dividerBottom'];

        $capacity = self::PAGE_HEIGHT[$template] ?? self::DECORATED_PAGE_HEIGHT;
        $pages = [];
        $current = [];
        $used = $header;

        foreach ($carta['sections'] as $section) {
            $part = ['name' => $section['name'], 'items' => []];

            foreach ($section['items'] as $item) {
                $height = $line($z['item'], $item['name']) + 2 * $z['itemGap'] + ($item['description'] ? $line($z['includes'], $item['description']) : 0);
                $needed = $height + ($part['items'] === [] ? $heading : 0);

                if (($used + $needed + 8) * 1.06 > $capacity && ($current !== [] || $part['items'] !== [])) {
                    if ($part['items'] !== []) {
                        $current[] = $part;
                    }

                    $pages[] = $current;
                    $current = [];
                    $part = ['name' => $section['name'], 'items' => []];
                    $used = 6;
                    $needed = $height + $heading;
                }

                $part['items'][] = $item;
                $used += $needed;
            }

            if ($part['items'] !== []) {
                $current[] = $part;
            } elseif ($section['items'] === []) {
                $current[] = $part;
                $used += $heading;
            }
        }

        // The notes go at the end of the last sheet; if they do not fit, on a sheet of their own.
        $notes = $carta['notes'] ?? null;

        if ($notes) {
            $notesHeight = 4 + array_sum(array_map(fn (string $text) => $line($this->notesSize($z), $text), explode("\n", $notes)));

            if (($used + $notesHeight + 8) * 1.06 > $capacity && $current !== []) {
                $pages[] = $current;
                $current = [];
            }
        }

        $pages[] = $current;
        $last = count($pages) - 1;

        return array_map(fn (array $sections, int $i) => [
            'kind' => 'carta',
            'locale' => $carta['locale'] ?? 'ca',
            'name' => $carta['name'],
            'subtitle' => $carta['subtitle'],
            'vat' => $carta['vat'] ?? '',
            'notes' => $i === $last ? ($carta['notes'] ?? null) : null,
            'first' => $i === 0,
            'last' => $i === $last,
            'sections' => $sections,
            'sizes' => $z,
        ], $pages, array_keys($pages));
    }

    /**
     * Type size (pt) of the notes: small, a little under the dish descriptions.
     *
     * @param  array<string, float>  $z
     */
    public function notesSize(array $z): float
    {
        return round(max(6.5, $z['includes'] * 0.85), 1);
    }
}
