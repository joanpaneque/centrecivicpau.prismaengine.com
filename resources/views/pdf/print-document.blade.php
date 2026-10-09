<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 8px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .big { font-size: 11px; }
        .muted { color: #555; }
        .divider { border-bottom: 1px dashed #333; height: 6px; }
        img.qr { width: 70px; height: 70px; }
        img.logo { max-width: 140px; max-height: 50px; }
    </style>
</head>
<body>
    <table>
        @foreach ($lines as $line)
            @php $type = $line['type'] ?? ''; @endphp
            @if ($type === 'text')
                <tr>
                    <td colspan="2" class="{{ ($line['align'] ?? 'left') === 'center' ? 'center' : (($line['align'] ?? '') === 'right' ? 'right' : '') }} {{ ! empty($line['bold']) ? 'bold' : '' }} {{ ($line['size'] ?? 1) == 2 ? 'big' : '' }}">
                        {{ $line['text'] ?? '' }}
                    </td>
                </tr>
            @elseif ($type === 'row')
                <tr>
                    <td class="{{ ! empty($line['bold']) ? 'bold' : '' }} {{ ($line['size'] ?? 1) == 2 ? 'big' : '' }}">{{ $line['left'] ?? '' }}</td>
                    <td class="right {{ ! empty($line['bold']) ? 'bold' : '' }} {{ ($line['size'] ?? 1) == 2 ? 'big' : '' }}">{{ $line['right'] ?? '' }}</td>
                </tr>
            @elseif ($type === 'divider')
                <tr><td colspan="2" class="divider"></td></tr>
            @elseif ($type === 'qr' && ! empty($line['data']))
                <tr>
                    <td colspan="2" class="center">
                        <img class="qr" src="{{ \App\Services\Printing\DocumentPdfRenderer::qrDataUri((string) $line['data']) }}"><br>
                        @if (! empty($line['caption']))
                            <span class="muted">{{ $line['caption'] }}</span>
                        @endif
                    </td>
                </tr>
            @elseif ($type === 'image')
                @if (! empty($logo))
                    <tr><td colspan="2" class="center"><img class="logo" src="{{ $logo }}"></td></tr>
                @elseif (is_string($line['url'] ?? null) && str_starts_with($line['url'], 'data:image/'))
                    <tr><td colspan="2" class="center"><img class="logo" src="{{ $line['url'] }}"></td></tr>
                @endif
            @elseif ($type === 'feed')
                <tr><td colspan="2" style="height: {{ max(1, (int) ($line['lines'] ?? 1)) * 8 }}px"></td></tr>
            @endif
        @endforeach
    </table>
</body>
</html>
