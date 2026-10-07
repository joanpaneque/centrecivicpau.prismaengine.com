@extends('pdf.layout')

@php
    $money = fn (int $cents) => number_format($cents / 100, 2, ',', '.');
    $name = fn ($value) => \App\Support\Translation::pick(is_array($value) ? $value : null);
@endphp

@section('title', 'Tiquet '.$ticket->full_number)

@section('content')
    <style>
        @page { margin: 10px; }
        body { font-size: 8.5px; }
        td, th { border: none; padding: 1px 0; }
    </style>
    <div class="center">
        @if ($logo)
            <img src="{{ $logo }}" style="max-width: 150px; max-height: 60px"><br>
        @endif
        <strong style="font-size: 11px">{{ $issuer['business_name'] ?? '' }}</strong><br>
        {{ $issuer['name'] ?? '' }} - NIF {{ $issuer['tax_id'] ?? '' }}<br>
        {{ $issuer['address'] ?? '' }}<br>
        {{ $issuer['postal_code'] ?? '' }} {{ $issuer['city'] ?? '' }}
    </div>
    <hr>
    <div class="center"><strong>FACTURA SIMPLIFICADA</strong></div>
    <table>
        <tr><td><strong>{{ $ticket->full_number }}</strong></td><td class="right">{{ $ticket->issued_at->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Taula: {{ $ticket->table_label ?? '-' }}</td><td class="right"></td></tr>
    </table>
    <hr>
    <table>
        @foreach ($ticket->lines as $line)
            <tr>
                <td>{{ rtrim(rtrim(number_format($line->quantity, 3, ',', ''), '0'), ',') }} {{ $name($line->name) }}</td>
                <td class="right">{{ $money($line->total) }}</td>
            </tr>
        @endforeach
        @if ($ticket->surcharge_amount > 0)
            <tr><td>Suplement terrassa ({{ rtrim(rtrim(number_format($ticket->surcharge_rate, 2, ',', ''), '0'), ',') }}%)</td><td class="right">{{ $money($ticket->surcharge_amount) }}</td></tr>
        @endif
        <tr><td><strong style="font-size: 11px">TOTAL</strong></td><td class="right"><strong style="font-size: 11px">{{ $money($ticket->total) }} €</strong></td></tr>
    </table>
    <hr>
    <table>
        <tr><th>IVA</th><th class="right">Base</th><th class="right">Quota</th></tr>
        @foreach ($ticket->vat_breakdown as $row)
            <tr>
                <td>{{ rtrim(rtrim(number_format((float) $row['rate'], 2, ',', ''), '0'), ',') }}%</td>
                <td class="right">{{ $money((int) $row['base']) }}</td>
                <td class="right">{{ $money((int) $row['vat']) }}</td>
            </tr>
        @endforeach
    </table>
    @if (! empty($ticket->verifactu['qr_url']))
        <div class="center" style="margin-top: 6px">
            <img src="{{ \App\Support\Qr::dataUri($ticket->verifactu['qr_url'], 80) }}" style="width: 70px; height: 70px"><br>
            <span class="small">VERI*FACTU (simulat)</span>
        </div>
    @endif
@endsection
