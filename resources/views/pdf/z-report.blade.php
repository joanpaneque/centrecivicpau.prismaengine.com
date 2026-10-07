@extends('pdf.layout')

@php
    $money = fn (int $cents) => number_format($cents / 100, 2, ',', '.').' €';
    $summary = $session->summary ?? [];
    $methods = ['cash' => 'Efectiu / Efectivo', 'card' => 'Targeta / Tarjeta'];
@endphp

@section('title', 'Informe Z')

@section('content')
    <h1>Informe Z {{ $session->z_number ? '#'.$session->z_number : '' }}</h1>
    <div>{{ $issuer['business_name'] ?? '' }} · {{ $issuer['name'] ?? '' }} · NIF {{ $issuer['tax_id'] ?? '' }}</div>
    <div class="muted">Caixa / Caja: {{ $session->device->name ?? '-' }}</div>

    <table style="margin-top: 10px">
        <tr><td>Obertura / Apertura</td><td class="right">{{ $session->opened_at->format('d/m/Y H:i') }} ({{ $session->opener->name ?? '-' }})</td></tr>
        <tr><td>Tancament / Cierre</td><td class="right">{{ $session->closed_at?->format('d/m/Y H:i') ?? '-' }} ({{ $session->closer->name ?? '-' }})</td></tr>
        <tr><td>Tiquets</td><td class="right">{{ $summary['tickets'] ?? 0 }} @if (! empty($summary['first_ticket'])) ({{ $summary['first_ticket'] }} – {{ $summary['last_ticket'] }}) @endif</td></tr>
        <tr class="total"><td>TOTAL</td><td class="right">{{ $money((int) ($summary['total'] ?? 0)) }}</td></tr>
    </table>

    <h2>Per forma de pagament / Por forma de pago</h2>
    <table>
        @foreach (($summary['by_method'] ?? []) as $method => $amount)
            <tr><td>{{ $methods[$method] ?? $method }}</td><td class="right">{{ $money((int) $amount) }}</td></tr>
        @endforeach
    </table>

    <h2>IVA</h2>
    <table>
        <tr><th>Tipus</th><th class="right">Base</th><th class="right">Quota</th><th class="right">Total</th></tr>
        @foreach (($summary['vat_breakdown'] ?? []) as $row)
            <tr>
                <td>{{ rtrim(rtrim(number_format((float) $row['rate'], 2, ',', ''), '0'), ',') }}%</td>
                <td class="right">{{ $money((int) $row['base']) }}</td>
                <td class="right">{{ $money((int) $row['vat']) }}</td>
                <td class="right">{{ $money((int) $row['total']) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Altres / Otros</h2>
    <table>
        <tr><td>Descomptes / Descuentos</td><td class="right">{{ $money((int) ($summary['discounts'] ?? 0)) }}</td></tr>
        <tr><td>Suplement terrassa / Suplemento terraza</td><td class="right">{{ $money((int) ($summary['surcharge'] ?? 0)) }}</td></tr>
        <tr><td>Anul·lacions / Anulaciones ({{ $summary['voids']['count'] ?? 0 }})</td><td class="right">{{ $money((int) ($summary['voids']['amount'] ?? 0)) }}</td></tr>
    </table>

    <h2>Efectiu / Efectivo</h2>
    <table>
        <tr><td>Fons inicial / Fondo inicial</td><td class="right">{{ $money($session->opening_float) }}</td></tr>
        <tr><td>Esperat / Esperado</td><td class="right">{{ $money((int) $session->expected_cash) }}</td></tr>
        <tr><td>Comptat / Contado</td><td class="right">{{ $money((int) $session->counted_cash) }}</td></tr>
        <tr class="total"><td>Diferència / Diferencia</td><td class="right {{ ($session->difference ?? 0) !== 0 ? 'warn' : '' }}">{{ $money((int) $session->difference) }}</td></tr>
    </table>

    @if (! empty($session->cash_count))
        <h2>Recompte / Recuento</h2>
        <table>
            @foreach ($session->cash_count as $denomination => $qty)
                @if ($qty > 0)
                    <tr><td>{{ $money((int) $denomination) }} × {{ $qty }}</td><td class="right">{{ $money((int) $denomination * $qty) }}</td></tr>
                @endif
            @endforeach
        </table>
    @endif

    @if ($session->notes)
        <p class="muted">{{ $session->notes }}</p>
    @endif
@endsection
