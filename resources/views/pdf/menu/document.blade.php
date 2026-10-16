@php
    /*
     * Printed set menu: one A4 page per language. $template picks the design; each page
     * brings its own sizes (smaller type for long menus) so it fits on one sheet. Frame and
     * drawings are position: fixed, which DomPDF repeats on every sheet.
     */
    $svg = fn (string $body, string $box = '0 0 100 100') => 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="'.$box.'">'.$body.'</svg>');

    $art = [
        'flourish' => fn ($c) => $svg('<line x1="0" y1="10" x2="120" y2="10" stroke="'.$c.'" stroke-width="1.4"/><line x1="180" y1="10" x2="300" y2="10" stroke="'.$c.'" stroke-width="1.4"/><polygon points="150,1 159,10 150,19 141,10" fill="'.$c.'"/><circle cx="130" cy="10" r="3" fill="'.$c.'"/><circle cx="170" cy="10" r="3" fill="'.$c.'"/>', '0 0 300 20'),
        'corner' => function ($c, bool $flipX = false, bool $flipY = false) use ($svg) {
            $x = fn ($v) => $flipX ? 100 - $v : $v;
            $y = fn ($v) => $flipY ? 100 - $v : $v;
            $p = fn (array $points) => implode(' ', array_map(fn ($pt) => $x($pt[0]).','.$y($pt[1]), $points));

            return $svg('<polyline points="'.$p([[4, 96], [4, 4], [96, 4]]).'" fill="none" stroke="'.$c.'" stroke-width="3"/><polyline points="'.$p([[14, 96], [14, 14], [96, 14]]).'" fill="none" stroke="'.$c.'" stroke-width="1.2"/><polygon points="'.$p([[4, 4], [16, 4], [4, 16]]).'" fill="'.$c.'"/><circle cx="'.$x(26).'" cy="'.$y(26).'" r="4" fill="'.$c.'"/>');
        },
        'pumpkin' => fn () => $svg('<rect x="46" y="8" width="8" height="18" rx="3" fill="#3f6212"/><ellipse cx="30" cy="58" rx="24" ry="32" fill="#ea580c"/><ellipse cx="70" cy="58" rx="24" ry="32" fill="#ea580c"/><ellipse cx="50" cy="58" rx="24" ry="34" fill="#f97316"/><polygon points="34,48 42,40 46,52" fill="#1c1917"/><polygon points="66,48 58,40 54,52" fill="#1c1917"/><polygon points="32,66 68,66 62,78 56,72 50,80 44,72 38,78" fill="#1c1917"/>'),
        'bat' => fn () => $svg('<path d="M50 40 C 42 30, 30 28, 4 34 C 14 40, 16 48, 14 56 C 22 50, 30 52, 34 58 C 38 50, 44 48, 50 52 C 56 48, 62 50, 66 58 C 70 52, 78 50, 86 56 C 84 48, 86 40, 96 34 C 70 28, 58 30, 50 40 Z" fill="#2e1065"/><circle cx="46" cy="36" r="3" fill="#2e1065"/><circle cx="54" cy="36" r="3" fill="#2e1065"/>'),
        'ghost' => fn () => $svg('<path d="M20 90 L20 45 C 20 20, 80 20, 80 45 L80 90 L70 80 L60 90 L50 80 L40 90 L30 80 Z" fill="#ffffff" stroke="#6b21a8" stroke-width="3"/><circle cx="40" cy="48" r="5" fill="#2e1065"/><circle cx="60" cy="48" r="5" fill="#2e1065"/>'),
        'holly' => fn () => $svg('<path d="M50 50 C 40 30, 20 30, 6 38 C 14 44, 12 52, 18 56 C 26 52, 32 58, 50 50 Z" fill="#166534"/><path d="M50 50 C 60 30, 80 30, 94 38 C 86 44, 88 52, 82 56 C 74 52, 68 58, 50 50 Z" fill="#15803d"/><circle cx="44" cy="56" r="7" fill="#dc2626"/><circle cx="56" cy="56" r="7" fill="#b91c1c"/><circle cx="50" cy="66" r="7" fill="#dc2626"/>'),
        'snow' => fn ($c) => $svg('<g stroke="'.$c.'" stroke-width="5" stroke-linecap="round"><line x1="50" y1="8" x2="50" y2="92"/><line x1="14" y1="29" x2="86" y2="71"/><line x1="14" y1="71" x2="86" y2="29"/><line x1="50" y1="22" x2="40" y2="12"/><line x1="50" y1="22" x2="60" y2="12"/><line x1="50" y1="78" x2="40" y2="88"/><line x1="50" y1="78" x2="60" y2="88"/></g>'),
        'tree' => fn () => $svg('<polygon points="50,6 80,46 66,46 88,74 12,74 34,46 20,46" fill="#166534"/><rect x="44" y="74" width="12" height="16" fill="#78350f"/><polygon points="50,0 54,8 62,8 56,13 58,21 50,16 42,21 44,13 38,8 46,8" fill="#eab308"/><circle cx="40" cy="58" r="4" fill="#dc2626"/><circle cx="62" cy="40" r="4" fill="#dc2626"/><circle cx="58" cy="64" r="4" fill="#eab308"/>'),
        'egg' => fn ($a, $b) => $svg('<ellipse cx="50" cy="54" rx="32" ry="42" fill="'.$a.'"/><polyline points="20,50 30,40 40,50 50,40 60,50 70,40 80,50" fill="none" stroke="#ffffff" stroke-width="5"/><circle cx="36" cy="70" r="5" fill="'.$b.'"/><circle cx="52" cy="76" r="5" fill="'.$b.'"/><circle cx="66" cy="68" r="5" fill="'.$b.'"/>'),
        'flower' => fn ($c) => $svg('<circle cx="50" cy="26" r="17" fill="'.$c.'"/><circle cx="74" cy="44" r="17" fill="'.$c.'"/><circle cx="65" cy="72" r="17" fill="'.$c.'"/><circle cx="35" cy="72" r="17" fill="'.$c.'"/><circle cx="26" cy="44" r="17" fill="'.$c.'"/><circle cx="50" cy="52" r="14" fill="#facc15"/>'),
        'bunny' => fn () => $svg('<ellipse cx="38" cy="26" rx="8" ry="22" fill="#f5f5f4" stroke="#a8a29e" stroke-width="2"/><ellipse cx="62" cy="26" rx="8" ry="22" fill="#f5f5f4" stroke="#a8a29e" stroke-width="2"/><ellipse cx="38" cy="28" rx="3" ry="14" fill="#f9a8d4"/><ellipse cx="62" cy="28" rx="3" ry="14" fill="#f9a8d4"/><circle cx="50" cy="66" r="28" fill="#f5f5f4" stroke="#a8a29e" stroke-width="2"/><circle cx="40" cy="60" r="4" fill="#44403c"/><circle cx="60" cy="60" r="4" fill="#44403c"/><ellipse cx="50" cy="72" rx="5" ry="4" fill="#f472b6"/>'),
        'star' => fn ($c) => $svg('<polygon points="50,4 62,38 98,38 69,59 80,94 50,73 20,94 31,59 2,38 38,38" fill="'.$c.'"/>'),
        'balloon' => fn ($c) => $svg('<ellipse cx="50" cy="38" rx="26" ry="32" fill="'.$c.'"/><polygon points="50,70 45,77 55,77" fill="'.$c.'"/><path d="M50 77 C 40 86, 60 90, 50 99" fill="none" stroke="#475569" stroke-width="2"/><ellipse cx="40" cy="26" rx="6" ry="10" fill="#ffffff" opacity="0.5"/>'),
    ];

    $themes = [
        'classic' => [
            'bg' => '#fffdf8', 'ink' => '#1e293b', 'title' => '#00056a', 'accent' => '#b08d57', 'soft' => '#64748b',
            'font' => 'DejaVu Serif, Georgia, serif',
            'divider' => $art['flourish']('#b08d57'),
            'decor' => [
                ['src' => $art['corner']('#b08d57'), 'style' => 'top:9mm;left:9mm;width:24mm'],
                ['src' => $art['corner']('#b08d57', true), 'style' => 'top:9mm;right:9mm;width:24mm'],
                ['src' => $art['corner']('#b08d57', false, true), 'style' => 'bottom:9mm;left:9mm;width:24mm'],
                ['src' => $art['corner']('#b08d57', true, true), 'style' => 'bottom:9mm;right:9mm;width:24mm'],
            ],
            'frame' => 'border: 0.6mm solid #b08d57; outline: none;',
        ],
        'halloween' => [
            'bg' => '#fff4e6', 'ink' => '#2e1065', 'title' => '#c2410c', 'accent' => '#6b21a8', 'soft' => '#7c2d12',
            'font' => 'DejaVu Serif, Georgia, serif',
            'divider' => $art['flourish']('#ea580c'),
            'decor' => [
                ['src' => $art['bat'](), 'style' => 'top:12mm;left:14mm;width:30mm'],
                ['src' => $art['bat'](), 'style' => 'top:22mm;left:44mm;width:16mm'],
                ['src' => $art['bat'](), 'style' => 'top:14mm;right:16mm;width:24mm'],
                ['src' => $art['ghost'](), 'style' => 'bottom:14mm;left:14mm;width:26mm'],
                ['src' => $art['pumpkin'](), 'style' => 'bottom:12mm;right:12mm;width:38mm'],
                ['src' => $art['pumpkin'](), 'style' => 'bottom:14mm;right:50mm;width:22mm'],
            ],
            'frame' => 'border: 1.2mm dashed #ea580c;',
        ],
        'christmas' => [
            'bg' => '#fffaf5', 'ink' => '#1f2937', 'title' => '#b91c1c', 'accent' => '#166534', 'soft' => '#4b5563',
            'font' => 'DejaVu Serif, Georgia, serif',
            'divider' => $art['flourish']('#b91c1c'),
            'decor' => [
                ['src' => $art['holly'](), 'style' => 'top:10mm;left:10mm;width:34mm'],
                ['src' => $art['holly'](), 'style' => 'top:10mm;right:10mm;width:34mm'],
                ['src' => $art['snow']('#93c5fd'), 'style' => 'top:40mm;left:18mm;width:10mm'],
                ['src' => $art['snow']('#93c5fd'), 'style' => 'top:60mm;right:20mm;width:12mm'],
                ['src' => $art['snow']('#bfdbfe'), 'style' => 'top:150mm;left:12mm;width:8mm'],
                ['src' => $art['snow']('#bfdbfe'), 'style' => 'top:180mm;right:14mm;width:9mm'],
                ['src' => $art['tree'](), 'style' => 'bottom:12mm;left:12mm;width:30mm'],
                ['src' => $art['tree'](), 'style' => 'bottom:12mm;right:12mm;width:30mm'],
            ],
            'frame' => 'border: 1.2mm double #166534;',
        ],
        'easter' => [
            'bg' => '#fdfbff', 'ink' => '#3b0764', 'title' => '#7c3aed', 'accent' => '#10b981', 'soft' => '#6b7280',
            'font' => 'DejaVu Serif, Georgia, serif',
            'divider' => $art['flourish']('#a78bfa'),
            'decor' => [
                ['src' => $art['flower']('#f9a8d4'), 'style' => 'top:12mm;left:12mm;width:22mm'],
                ['src' => $art['flower']('#c4b5fd'), 'style' => 'top:26mm;left:30mm;width:14mm'],
                ['src' => $art['flower']('#a7f3d0'), 'style' => 'top:12mm;right:12mm;width:22mm'],
                ['src' => $art['egg']('#c4b5fd', '#f472b6'), 'style' => 'bottom:14mm;left:14mm;width:20mm'],
                ['src' => $art['egg']('#6ee7b7', '#7c3aed'), 'style' => 'bottom:14mm;left:34mm;width:16mm'],
                ['src' => $art['egg']('#fcd34d', '#10b981'), 'style' => 'bottom:14mm;right:44mm;width:16mm'],
                ['src' => $art['bunny'](), 'style' => 'bottom:12mm;right:12mm;width:30mm'],
            ],
            'frame' => 'border: 1mm solid #c4b5fd;',
        ],
        'kids' => [
            'bg' => '#f0f9ff', 'ink' => '#0f172a', 'title' => '#2563eb', 'accent' => '#f59e0b', 'soft' => '#475569',
            'font' => 'DejaVu Sans, Arial, sans-serif',
            'divider' => null,
            'decor' => [
                ['src' => $art['balloon']('#f87171'), 'style' => 'top:10mm;left:10mm;width:20mm'],
                ['src' => $art['balloon']('#60a5fa'), 'style' => 'top:16mm;left:26mm;width:16mm'],
                ['src' => $art['balloon']('#4ade80'), 'style' => 'top:10mm;right:12mm;width:20mm'],
                ['src' => $art['star']('#facc15'), 'style' => 'top:30mm;right:34mm;width:10mm'],
                ['src' => $art['star']('#f472b6'), 'style' => 'bottom:20mm;left:16mm;width:14mm'],
                ['src' => $art['star']('#facc15'), 'style' => 'bottom:14mm;right:16mm;width:18mm'],
                ['src' => $art['star']('#60a5fa'), 'style' => 'top:40mm;left:16mm;width:9mm'],
            ],
            'frame' => 'border: 1.4mm solid #93c5fd; border-radius: 8mm;',
        ],
    ];

    $t = $themes[$template] ?? $themes['classic'];
    $kidColors = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#ec4899'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 0; size: A4 portrait; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: {{ $t['bg'] }}; }
        body { font-family: {!! $t['font'] !!}; color: {{ $t['ink'] }}; }
        .frame { position: fixed; top: 7mm; left: 7mm; width: 196mm; height: 283mm; {!! $t['frame'] !!} }
        .decor { position: fixed; }
        .sheet { padding: 20mm 30mm 0; text-align: center; page-break-after: always; }
        .sheet.last { page-break-after: auto; }
        h1 { color: {{ $t['title'] }}; margin: 0; line-height: 1.15; font-weight: bold; }
        .price { display: inline-block; padding: 1.2mm 7mm; border-radius: 10mm; background: {{ $t['title'] }}; color: #ffffff; font-weight: bold; font-family: DejaVu Sans, Arial, sans-serif; }
        .includes { color: {{ $t['soft'] }}; font-style: italic; }
        .divider { width: 70mm; height: 4.6mm; }
        h2 { color: {{ $t['accent'] }}; text-transform: uppercase; letter-spacing: 1.2pt; margin: 0; }
        .choose { color: {{ $t['soft'] }}; }
        .item { line-height: 1.25; }
        .supplement { font-family: DejaVu Sans, Arial, sans-serif; color: {{ $t['accent'] }}; font-weight: bold; }
        .vat { margin-top: 6mm; font-size: 8pt; color: {{ $t['soft'] }}; font-family: DejaVu Sans, Arial, sans-serif; }
        .kids-box { margin-left: auto; margin-right: auto; border-radius: 6mm; width: 120mm; background: #ffffff; border: 0.8mm solid #bae6fd; }
        .kids-box h2 { color: #ffffff; display: inline-block; padding: 0.8mm 5mm; border-radius: 5mm; letter-spacing: 0.6pt; }
        @media screen {
            html, body { width: 210mm; height: 297mm; overflow: hidden; }
        }
    </style>
</head>
<body>
    <div class="frame"></div>
    @foreach ($t['decor'] as $decor)
        <img class="decor" src="{{ $decor['src'] }}" style="{{ $decor['style'] }}" alt="">
    @endforeach

@foreach ($pages as $page)
    @php $z = $page['sizes']; @endphp
    <div class="sheet {{ $loop->last ? 'last' : '' }}">
        @if ($logo)<img src="{{ $logo }}" alt="" style="height: {{ $z['logo'] }}mm; max-width: 60mm"><br>@endif

        <h1 style="font-size: {{ $z['title'] }}pt; margin-top: {{ $z['titleGap'] }}mm">
            @if ($template === 'kids')
                @foreach (mb_str_split($page['name']) as $i => $char)<span style="color: {{ $kidColors[$i % count($kidColors)] }}">{{ $char }}</span>@endforeach
            @else
                {{ $page['name'] }}
            @endif
        </h1>

        <div class="price" style="font-size: {{ $z['price'] }}pt; margin-top: {{ $z['priceGap'] }}mm">{{ $page['price'] }}</div>
        @if ($page['includes'])<div class="includes" style="font-size: {{ $z['includes'] }}pt; margin-top: {{ $z['includesGap'] }}mm">{{ $page['includes'] }}</div>@endif

        <div style="margin: {{ $z['dividerTop'] }}mm 0 {{ $z['dividerBottom'] }}mm">
            @if ($t['divider'])<img class="divider" src="{{ $t['divider'] }}" alt="">@else<div style="height: 4.6mm"></div>@endif
        </div>

        @foreach ($page['sections'] as $s => $section)
            @if ($template === 'kids')
                <div class="kids-box" style="margin-top: {{ $z['sectionGap'] }}mm; padding: {{ $z['boxPadding'] }}mm 6mm">
                    <h2 style="font-size: {{ $z['section'] }}pt; background: {{ $kidColors[$s % count($kidColors)] }}; margin-bottom: {{ $z['headingGap'] }}mm">{{ $section['name'] }}</h2>
            @else
                <div style="margin-top: {{ $z['sectionGap'] }}mm">
                    <h2 style="font-size: {{ $z['section'] }}pt; margin-bottom: {{ $z['headingGap'] }}mm">{{ $section['name'] }}</h2>
            @endif
                    @if ($section['choose'])<div class="choose" style="font-size: {{ $z['includes'] - 1 }}pt">{{ $section['choose'] }}</div>@endif
                    @foreach ($section['items'] as $item)
                        <div class="item" style="font-size: {{ $z['item'] }}pt; margin: {{ $z['itemGap'] }}mm 0">{{ $item['name'] }} @if ($item['supplement'])<span class="supplement" style="font-size: {{ round($z['item'] * 0.72, 1) }}pt">{{ $item['supplement'] }}</span>@endif</div>
                    @endforeach
                </div>
        @endforeach

        <div class="vat">{{ $page['vat'] }}@if ($business) · {{ $business }}@endif</div>
    </div>
@endforeach
</body>
</html>
