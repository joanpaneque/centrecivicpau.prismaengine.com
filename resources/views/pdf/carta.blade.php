<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 40px 48px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; }
        .head { text-align: center; margin-bottom: 18px; }
        img.logo { max-height: 64px; max-width: 220px; }
        h1 { font-family: DejaVu Serif, serif; font-size: 26px; margin: 6px 0 0; color: #00056a; }
        .family { margin-bottom: 14px; page-break-inside: avoid; }
        h2 { font-size: 14px; color: #fff; padding: 5px 10px; margin: 0 0 4px; border-radius: 6px; }
        h3 { font-size: 12px; margin: 8px 0 2px; color: #334155; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 4px; border-bottom: 1px dotted #cbd5e1; vertical-align: top; }
        td.price { text-align: right; white-space: nowrap; font-weight: bold; width: 70px; }
        .es { color: #64748b; font-size: 9px; }
        .soldout { color: #b91c1c; font-size: 9px; }
        .foot { margin-top: 18px; text-align: center; color: #94a3b8; font-size: 9px; }
    </style>
</head>
<body>
    <div class="head">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt=""><br>@endif
        <h1>Carta · {{ $business }}</h1>
    </div>

    @foreach ($families as $family)
        <div class="family">
            <h2 style="background: {{ $family['color'] }}">{{ $family['name']['ca'] ?? '' }} <span style="font-weight: normal; opacity: .85">· {{ $family['name']['es'] ?? '' }}</span></h2>
            @foreach ($family['groups'] as $group)
                @if ($group['title'])<h3>{{ $group['title'] }}</h3>@endif
                <table>
                    @foreach ($group['products'] as $product)
                        <tr>
                            <td>
                                {{ $product->name['ca'] ?? '' }}
                                @if ($product->sold_out)<span class="soldout">· esgotat</span>@endif
                                @if (($product->name['es'] ?? '') !== ($product->name['ca'] ?? ''))<br><span class="es">{{ $product->name['es'] ?? '' }}</span>@endif
                            </td>
                            <td class="price">{{ $product->price === null ? '—' : number_format($product->price / 100, 2, ',', '.').' €' }}</td>
                        </tr>
                    @endforeach
                </table>
            @endforeach
        </div>
    @endforeach

    <div class="foot">IVA inclòs / IVA incluido · {{ now()->format('d/m/Y') }}</div>
</body>
</html>
