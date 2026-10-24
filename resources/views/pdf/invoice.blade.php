@extends('pdf.layout')

@php
    $money = fn (int $cents) => number_format($cents / 100, 2, ',', '.').' €';
    $name = fn ($value) => \App\Support\Translation::pick(is_array($value) ? $value : null);
    // One ticket, or every ticket of a customer summary ($statement).
    $many = $tickets->count() > 1;
    $paid = [];

    foreach ($tickets as $t) {
        foreach ($t->payments as $payment) {
            $paid[$payment->method] = ($paid[$payment->method] ?? 0) + $payment->amount;
        }
    }
@endphp

@section('title', 'Factura '.$invoice->full_number)

@section('content')
    <table class="header">
        <tr>
            <td style="width: 55%">
                @if ($logo)
                    <img src="{{ $logo }}" style="max-height: 70px; max-width: 220px; margin-bottom: 6px">
                @endif
                <div><strong>{{ $issuer['business_name'] ?? '' }}</strong></div>
                <div>{{ $issuer['name'] ?? '' }} · NIF {{ $issuer['tax_id'] ?? '' }}</div>
                <div>{{ $issuer['address'] ?? '' }}</div>
                <div>{{ $issuer['postal_code'] ?? '' }} {{ $issuer['city'] ?? '' }} ({{ $issuer['province'] ?? '' }})</div>
            </td>
            <td style="width: 45%" class="right">
                <h1>FACTURA</h1>
                <div><strong>{{ $invoice->full_number }}</strong></div>
                <div>Data / Fecha: {{ $invoice->issued_at->format('d/m/Y') }}</div>
                @if ($statement)
                    <div class="muted">Resum / Resumen {{ $statement->label() }} · {{ $tickets->count() }} tiquets</div>
                @else
                    <div class="muted">Tiquet / Ticket: {{ $tickets->first()->full_number }} ({{ $tickets->first()->issued_at->format('d/m/Y H:i') }})</div>
                @endif
            </td>
        </tr>
    </table>

    <h2>Client / Cliente</h2>
    <div class="box">
        <div><strong>{{ $invoice->customer_name }}</strong></div>
        <div>NIF: {{ $invoice->customer_tax_id }}</div>
        <div>{{ $invoice->customer_address }}</div>
    </div>

    <h2>Detall / Detalle</h2>
    <table>
        <thead>
            <tr>
                <th>Concepte / Concepto</th>
                <th class="right">Quant.</th>
                <th class="right">Preu / Precio</th>
                <th class="right">IVA</th>
                <th class="right">Import / Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tickets as $ticket)
            @if ($many)
                <tr><td colspan="5"><strong>Tiquet {{ $ticket->full_number }}</strong> <span class="muted">{{ $ticket->issued_at->format('d/m/Y H:i') }}@if ($ticket->table_label) · {{ $ticket->table_label }}@endif</span></td></tr>
            @endif
            @foreach ($ticket->lines as $line)
                <tr>
                    <td>
                        {{ $name($line->name) }}
                        @foreach ($line->modifiers ?? [] as $modifier)
                            <div class="muted small">{{ is_array($modifier) ? $name($modifier['name'] ?? $modifier) : $modifier }}</div>
                        @endforeach
                        @if ($line->discount_amount > 0)
                            <div class="muted small">Descompte / Descuento: -{{ $money($line->discount_amount) }}</div>
                        @endif
                    </td>
                    <td class="right">{{ rtrim(rtrim(number_format($line->quantity, 3, ',', ''), '0'), ',') }}</td>
                    <td class="right">{{ $money($line->unit_price) }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format($line->vat_rate, 2, ',', ''), '0'), ',') }}%</td>
                    <td class="right">{{ $money($line->total) }}</td>
                </tr>
            @endforeach
            @if ($ticket->surcharge_amount > 0)
                <tr>
                    <td colspan="4">Suplement terrassa / Suplemento terraza ({{ rtrim(rtrim(number_format($ticket->surcharge_rate, 2, ',', ''), '0'), ',') }}%)</td>
                    <td class="right">{{ $money($ticket->surcharge_amount) }}</td>
                </tr>
            @endif
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 12px; width: 60%; margin-left: 40%">
        <thead>
            <tr>
                <th>IVA</th>
                <th class="right">Base</th>
                <th class="right">Quota / Cuota</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($vat as $row)
                <tr>
                    <td>{{ rtrim(rtrim(number_format((float) $row['rate'], 2, ',', ''), '0'), ',') }}%</td>
                    <td class="right">{{ $money((int) $row['base']) }}</td>
                    <td class="right">{{ $money((int) $row['vat']) }}</td>
                    <td class="right">{{ $money((int) $row['total']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">TOTAL</td>
                <td class="right">{{ $money($total) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="muted" style="margin-top: 14px">
        Forma de pagament / pago:
        @foreach ($paid as $method => $amount)
            {{ $method === 'card' ? 'Targeta / Tarjeta' : 'Efectiu / Efectivo' }} {{ $money($amount) }}@if (! $loop->last), @endif
        @endforeach
    </p>

    <table class="header" style="margin-top: 18px">
        <tr>
            <td style="width: 90px"><img src="{{ \App\Support\Qr::dataUri($qr, 90) }}" style="width: 80px; height: 80px"></td>
            <td class="small muted">
                VERI*FACTU (simulat / simulado). Factura verificable a la seu electrònica de l'AEAT.<br>
                @if ($many)
                    Aquesta factura substitueix els tiquets simplificats / Esta factura sustituye a los tickets simplificados {{ $tickets->pluck('full_number')->implode(', ') }}.
                @else
                    Aquesta factura substitueix el tiquet simplificat {{ $tickets->first()->full_number }}.
                @endif
            </td>
        </tr>
    </table>
@endsection
