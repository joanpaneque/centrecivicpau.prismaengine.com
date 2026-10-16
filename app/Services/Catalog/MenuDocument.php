<?php

namespace App\Services\Catalog;

use App\Models\SetMenu;
use App\Models\SetMenuSection;
use App\Models\SetMenuSectionItem;
use App\Services\Printing\DocumentPdfRenderer;
use App\Support\AppSettings;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Printable set menu: one A4 page per language with the chosen design. Catalan and
 * Spanish come from the menu itself; the other languages from its stored translations.
 */
class MenuDocument
{
    /** Printing languages, in the order they are offered. */
    public const LOCALES = ['es', 'ca', 'fr', 'en', 'de', 'ru', 'it', 'nl'];

    public const TEMPLATES = ['classic', 'halloween', 'christmas', 'easter', 'kids'];

    private const LABELS = [
        'es' => ['vat' => 'IVA incluido', 'choose' => 'Elige :n'],
        'ca' => ['vat' => 'IVA inclòs', 'choose' => 'Tria\'n :n'],
        'fr' => ['vat' => 'TVA incluse', 'choose' => 'Au choix : :n'],
        'en' => ['vat' => 'VAT included', 'choose' => 'Choose :n'],
        'de' => ['vat' => 'inkl. MwSt.', 'choose' => ':n zur Auswahl'],
        'ru' => ['vat' => 'НДС включён', 'choose' => 'Выберите :n'],
        'it' => ['vat' => 'IVA inclusa', 'choose' => 'Scegli :n'],
        'nl' => ['vat' => 'Inclusief btw', 'choose' => 'Kies :n'],
    ];

    public function __construct(private readonly DocumentPdfRenderer $renderer) {}

    /**
     * Texts to translate, in both source languages, with the structure of the menu.
     *
     * @return array{name: array<string, string>, includes: array<string, string>|null, sections: list<array{name: array<string, string>, items: list<array<string, string>>}>}
     */
    public function source(SetMenu $menu): array
    {
        $menu->loadMissing('sections.items.product');

        return [
            'name' => $this->pair($menu->name),
            'includes' => $menu->includes ? $this->pair($menu->includes) : null,
            'sections' => array_values($menu->sections->map(fn (SetMenuSection $s) => [
                'name' => $this->pair($s->name),
                'items' => array_values($s->items->map(fn (SetMenuSectionItem $i) => $this->pair($i->name ?? $i->product->name ?? []))->all()),
            ])->all()),
        ];
    }

    public function hash(SetMenu $menu): string
    {
        return md5((string) json_encode($this->source($menu), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Languages (other than ca/es) still missing or out of date for the current texts.
     *
     * @param  list<string>  $locales
     * @return list<string>
     */
    public function missing(SetMenu $menu, array $locales): array
    {
        $stored = $menu->translations ?? [];
        $fresh = ($stored['hash'] ?? null) === $this->hash($menu);

        return array_values(array_filter(
            array_intersect(self::LOCALES, $locales),
            fn (string $locale) => ! in_array($locale, ['ca', 'es'], true) && (! $fresh || ! isset($stored['locales'][$locale])),
        ));
    }

    /**
     * The menu in one language, ready for the template.
     *
     * @return array{locale: string, name: string, includes: string|null, price: string, vat: string, sections: list<array{name: string, choose: string|null, items: list<array{name: string, supplement: string|null}>}>}
     */
    public function localized(SetMenu $menu, string $locale): array
    {
        $source = $this->source($menu);
        $stored = $menu->translations ?? [];
        $fresh = ($stored['hash'] ?? null) === $this->hash($menu);
        $translated = $fresh ? ($stored['locales'][$locale] ?? null) : null;
        $pick = fn (array $pair, mixed $other) => in_array($locale, ['ca', 'es'], true)
            ? ($pair[$locale] ?: ($pair['ca'] ?: $pair['es']))
            : (is_string($other) && trim($other) !== '' ? trim($other) : ($pair['es'] ?: $pair['ca']));
        $labels = self::LABELS[$locale] ?? self::LABELS['es'];
        $menu->loadMissing('sections.items');

        $sections = [];

        foreach ($source['sections'] as $s => $section) {
            $items = [];

            foreach ($section['items'] as $i => $item) {
                $supplement = $menu->sections[$s]->items[$i]->supplement ?? 0;
                $items[] = [
                    'name' => $pick($item, $translated['sections'][$s]['items'][$i] ?? null),
                    'supplement' => $supplement > 0 ? '+'.$this->money($supplement, $locale) : null,
                ];
            }

            $choices = $menu->sections[$s]->choices ?? 1;
            $sections[] = [
                'name' => $pick($section['name'], $translated['sections'][$s]['name'] ?? null),
                'choose' => $choices > 1 ? str_replace(':n', (string) $choices, $labels['choose']) : null,
                'items' => $items,
            ];
        }

        return [
            'locale' => $locale,
            'name' => $pick($source['name'], $translated['name'] ?? null),
            'includes' => $source['includes'] ? $pick($source['includes'], $translated['includes'] ?? null) : null,
            'price' => $this->money($menu->price, $locale),
            'vat' => $labels['vat'],
            'sections' => $sections,
        ];
    }

    /**
     * @param  list<string>  $locales
     */
    public function pdf(SetMenu $menu, string $template, array $locales): string
    {
        return Pdf::loadHTML($this->html($menu, $template, $locales))->setPaper('a4')->output();
    }

    /**
     * The same pages as HTML (the print dialog previews it in an iframe).
     *
     * @param  list<string>  $locales
     */
    public function html(SetMenu $menu, string $template, array $locales): string
    {
        $template = in_array($template, self::TEMPLATES, true) ? $template : 'classic';
        $locales = array_values(array_intersect(self::LOCALES, $locales)) ?: ['ca'];
        $logo = $this->logo();
        $pages = array_map(fn (string $locale) => $this->fitted($this->localized($menu, $locale), $template, $logo), $locales);

        return $this->view($template, $pages, $logo);
    }

    /**
     * Sizes for one language: start from the estimate and, if DomPDF still needs a second
     * sheet, shrink the type until it fits on one.
     *
     * @param  array{locale: string, name: string, includes: string|null, price: string, vat: string, sections: list<array{name: string, choose: string|null, items: list<array{name: string, supplement: string|null}>}>}  $page
     * @return array<string, mixed>
     */
    private function fitted(array $page, string $template, ?string $logo): array
    {
        $density = $this->density($page, $template, $logo !== null);

        do {
            $candidate = $page + ['sizes' => $this->sizes($density)];
            $pdf = Pdf::loadHTML($this->view($template, [$candidate], $logo))->setPaper('a4');
            $pdf->render();
            $sheets = $pdf->getDomPDF()->getCanvas()->get_page_count();
            $density = round($density - 0.05, 2);
        } while ($sheets > 1 && $density >= 0);

        return $candidate;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     */
    private function view(string $template, array $pages, ?string $logo): string
    {
        return view('pdf.menu.document', [
            'title' => $pages[0]['name'] ?? '',
            'template' => $template,
            'pages' => $pages,
            'logo' => $logo,
            'business' => (string) AppSettings::get('business_name', ''),
        ])->render();
    }

    /**
     * Type sizes (pt) and spacings (mm) for a density between 0 (very long menu) and 1.
     *
     * @return array<string, float>
     */
    public function sizes(float $d): array
    {
        return [
            'density' => $d,
            'logo' => round(12 + 14 * $d, 1),
            'title' => round(18 + 16 * $d, 1),
            'titleGap' => round(1 + 3.5 * $d, 1),
            'price' => round(11 + 9 * $d, 1),
            'priceGap' => round(1 + 3.5 * $d, 1),
            'includes' => round(8 + 4 * $d, 1),
            'includesGap' => round(0.8 + 2.2 * $d, 1),
            'dividerTop' => round(1 + 5 * $d, 1),
            'dividerBottom' => round(0.5 + 2.5 * $d, 1),
            'section' => round(9 + 8 * $d, 1),
            'sectionGap' => round(2 + 8 * $d, 1),
            'headingGap' => round(0.5 + 1.8 * $d, 1),
            'item' => round(7.5 + 7.5 * $d, 1),
            'itemGap' => round(0.3 + 2.3 * $d, 1),
            'boxPadding' => round(1 + 3 * $d, 1),
        ];
    }

    /**
     * Largest density whose estimated height fits the printable area of one A4 page, so
     * every language (German and Russian run longer) stays on a single sheet.
     *
     * @param  array{includes: string|null, sections: list<array{name: string, choose: string|null, items: list<array{name: string, supplement: string|null}>}>}  $page
     */
    public function density(array $page, string $template = 'classic', bool $logo = true): float
    {
        for ($d = 1.0; $d > 0; $d -= 0.02) {
            if ($this->height($page, $this->sizes($d), $template, $logo) <= 250) {
                return round($d, 2);
            }
        }

        return 0.0;
    }

    /**
     * @param  array{includes: string|null, sections: list<array{name: string, choose: string|null, items: list<array{name: string, supplement: string|null}>}>}  $page
     * @param  array<string, float>  $z
     */
    private function height(array $page, array $z, string $template, bool $logo): float
    {
        $pt = 0.3528;
        $line = fn (float $size, string $text, float $width) => ceil(max(1, mb_strlen($text)) / max(1, $width / ($size * $pt * 0.55))) * $size * $pt * 1.3;
        $width = $template === 'kids' ? 108 : 146;

        $h = ($logo ? $z['logo'] + 2 : 0) + $z['titleGap'] + $z['title'] * $pt * 1.25 + $z['priceGap'] + $z['price'] * $pt * 1.3 + 3;
        $h += $page['includes'] ? $z['includesGap'] + $line($z['includes'], $page['includes'], 146) : 0;
        $h += $z['dividerTop'] + 4.6 + $z['dividerBottom'];

        foreach ($page['sections'] as $section) {
            $h += $z['sectionGap'] + $z['section'] * $pt * 1.35 + $z['headingGap'] + ($section['choose'] ? $z['includes'] * $pt * 1.4 : 0);
            $h += $template === 'kids' ? 2 * $z['boxPadding'] + 2 : 0;

            foreach ($section['items'] as $item) {
                $h += $line($z['item'], $item['name'].($item['supplement'] ? ' +0,00 €' : ''), $width) + 2 * $z['itemGap'];
            }
        }

        // DomPDF lays text out a little taller than this estimate.
        return ($h + 8) * 1.06;
    }

    private function money(int $cents, string $locale): string
    {
        return $locale === 'en'
            ? '€'.number_format($cents / 100, 2, '.', ',')
            : number_format($cents / 100, 2, ',', '.').' €';
    }

    /**
     * @param  array<string, string>|mixed  $value
     * @return array{ca: string, es: string}
     */
    private function pair(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return ['ca' => trim((string) ($value['ca'] ?? '')), 'es' => trim((string) ($value['es'] ?? ''))];
    }

    /**
     * Inline so the same markup works in the PDF and in the browser preview.
     */
    private function logo(): ?string
    {
        $path = $this->renderer->logoPath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : (str_ends_with(strtolower($path), '.webp') ? 'image/webp' : 'image/jpeg');

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
