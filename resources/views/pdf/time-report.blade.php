@extends('pdf.layout')

@php
    $hours = fn (int $minutes) => sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    $types = ['clock_in' => 'Entrada', 'clock_out' => 'Sortida / Salida', 'break_start' => 'Inici pausa', 'break_end' => 'Fi pausa'];
    $actions = ['modify' => 'Modificació', 'add' => 'Afegit', 'annul' => 'Anul·lació'];
@endphp

@section('title', 'Registre de jornada')

@section('content')
    <h1>Registre de jornada / Registro de jornada</h1>
    <div>{{ $issuer['business_name'] ?? '' }} · {{ $issuer['name'] ?? '' }} · NIF {{ $issuer['tax_id'] ?? '' }}</div>
    <div class="muted">Període / Periodo: {{ $from->format('d/m/Y') }} – {{ $to->format('d/m/Y') }} · Generat: {{ $generatedAt->format('d/m/Y H:i') }}</div>
    <p class="small muted">Art. 34.9 de l'Estatut dels Treballadors. Els registres originals són inalterables; les correccions es mostren a part amb motiu i autor.</p>

    @foreach ($data as $row)
        <h2>{{ $row['user']->name }} @if ($row['user']->tax_id) <span class="muted">({{ $row['user']->tax_id }})</span> @endif</h2>
        <div class="small">
            Integritat: @if ($row['chain']['valid']) <span class="badge">correcta ({{ $row['chain']['checked'] }})</span> @else <span class="warn">TRENCADA al registre #{{ $row['chain']['broken_at'] }}</span> @endif
        </div>

        <table style="margin-top: 4px">
            <thead>
                <tr><th>Dia</th><th>Sessions</th><th class="right">Pauses</th><th class="right">Treballat</th><th class="right">Planificat</th><th class="right">Extra</th></tr>
            </thead>
            <tbody>
                @foreach ($row['report']['days'] as $day)
                    <tr>
                        <td>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('D d/m') }}</td>
                        <td>
                            @foreach ($day['sessions'] as $session)
                                {{ \Carbon\CarbonImmutable::parse($session['start'])->format('H:i') }}–{{ $session['end'] ? \Carbon\CarbonImmutable::parse($session['end'])->format('H:i') : '??' }}@if (! $loop->last), @endif
                            @endforeach
                            @if ($day['shifts']) <span class="muted small">(torn {{ implode(', ', $day['shifts']) }})</span> @endif
                        </td>
                        <td class="right">{{ $hours($day['break_minutes']) }}</td>
                        <td class="right">{{ $hours($day['worked_minutes']) }}</td>
                        <td class="right">{{ $hours($day['planned_minutes']) }}</td>
                        <td class="right">{{ $hours($day['overtime_minutes']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="2">Total</td>
                    <td class="right">{{ $hours($row['report']['totals']['breaks']) }}</td>
                    <td class="right">{{ $hours($row['report']['totals']['worked']) }}</td>
                    <td class="right">{{ $hours($row['report']['totals']['planned']) }}</td>
                    <td class="right">{{ $hours($row['report']['totals']['overtime']) }}</td>
                </tr>
            </tbody>
        </table>

        <table style="margin-top: 6px" class="small">
            <thead><tr><th>Registre original</th><th>Hora</th><th>Origen</th><th>Rebut</th><th>Empremta</th></tr></thead>
            <tbody>
                @foreach ($row['entries'] as $entry)
                    <tr>
                        <td>{{ $types[$entry->type] ?? $entry->type }}</td>
                        <td>{{ $entry->occurred_at->format('d/m H:i:s') }}</td>
                        <td>{{ $entry->source }} {{ $entry->device?->name }}</td>
                        <td>{{ $entry->received_at->format('d/m H:i') }} @if ($entry->synced_late) <span class="warn">(retard)</span> @endif</td>
                        <td class="muted">{{ substr($entry->hash, 0, 16) }}…</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($row['corrections']->isNotEmpty())
            <table style="margin-top: 6px" class="small">
                <thead><tr><th>Correcció</th><th>Original</th><th>Nou valor</th><th>Motiu</th><th>Per</th><th>Quan</th></tr></thead>
                <tbody>
                    @foreach ($row['corrections'] as $correction)
                        <tr>
                            <td>{{ $actions[$correction->action] ?? $correction->action }}</td>
                            <td>{{ $correction->original_type ? ($types[$correction->original_type] ?? '').' '.$correction->original_occurred_at?->format('d/m H:i') : '-' }}</td>
                            <td>{{ $correction->new_type ? ($types[$correction->new_type] ?? '').' '.$correction->new_occurred_at?->format('d/m H:i') : '-' }}</td>
                            <td>{{ $correction->reason }}</td>
                            <td>{{ $correction->corrector?->name }}</td>
                            <td>{{ $correction->created_at->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
@endsection
