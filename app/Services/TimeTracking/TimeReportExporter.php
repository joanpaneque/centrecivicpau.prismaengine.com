<?php

namespace App\Services\TimeTracking;

use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\User;
use App\Support\AppSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Exports of the working-time record. Every export carries both the original entries and
 * the corrections, so the evidence required by art. 34.9 ET is never lost.
 */
class TimeReportExporter
{
    public function __construct(
        private readonly WorkedTime $worked,
        private readonly TimeEntryRecorder $recorder,
    ) {}

    /**
     * @param  Collection<int, User>  $users
     * @return list<array{user: User, report: array<string, mixed>, entries: Collection<int, TimeEntry>, corrections: Collection<int, TimeEntryCorrection>, chain: array{valid: bool, checked: int, broken_at: int|null}}>
     */
    public function data(Collection $users, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = [];

        foreach ($users as $user) {
            $result[] = [
                'user' => $user,
                'report' => $this->worked->report($user, $from, $to),
                'entries' => TimeEntry::query()->with('device:id,name')->where('user_id', $user->id)->whereBetween('occurred_at', [$from->startOfDay(), $to->endOfDay()])->orderBy('occurred_at')->get(),
                'corrections' => TimeEntryCorrection::query()->with('corrector:id,name')->where('user_id', $user->id)
                    ->where(fn ($q) => $q->whereBetween('new_occurred_at', [$from->startOfDay(), $to->endOfDay()])->orWhereBetween('original_occurred_at', [$from->startOfDay(), $to->endOfDay()]))
                    ->orderBy('created_at')->get(),
                'chain' => $this->recorder->verifyChain($user),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    public function pdf(Collection $users, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return Pdf::loadView('pdf.time-report', [
            'data' => $this->data($users, $from, $to),
            'from' => $from,
            'to' => $to,
            'issuer' => AppSettings::issuer(),
            'generatedAt' => CarbonImmutable::now(),
        ])->setPaper('a4')->output();
    }

    /**
     * @param  Collection<int, User>  $users
     */
    public function spreadsheet(Collection $users, CarbonImmutable $from, CarbonImmutable $to, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'time').'.'.$format;
        $writer = $format === 'csv' ? new CsvWriter(new CsvOptions(FIELD_DELIMITER: ';')) : new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([
            'Treballador / Trabajador', 'DNI/NIE', 'Data / Fecha', 'Tipus / Tipo', 'Hora', 'Origen', 'Dispositiu / Dispositivo',
            'Rebut / Recibido', 'Retard sync', 'Correcció / Corrección', 'Hora corregida', 'Motiu / Motivo', 'Corregit per / Corregido por', 'Empremta / Huella',
        ]));

        $data = $this->data($users, $from, $to);

        foreach ($data as $row) {
            $user = $row['user'];
            $byEntry = $row['corrections']->groupBy('time_entry_id');

            foreach ($row['entries'] as $entry) {
                $correction = $byEntry->get($entry->id)?->last();
                $writer->addRow(Row::fromValues([
                    $user->name, (string) $user->tax_id, $entry->occurred_at->format('Y-m-d'), $entry->type, $entry->occurred_at->format('H:i:s'),
                    $entry->source, (string) $entry->device?->name, $entry->received_at->format('Y-m-d H:i:s'), $entry->synced_late ? 'sí' : 'no',
                    (string) $correction?->action, (string) $correction?->new_occurred_at?->format('Y-m-d H:i:s'), (string) $correction?->reason,
                    (string) $correction?->corrector?->name, $entry->hash,
                ]));
            }

            foreach ($row['corrections']->where('action', 'add') as $added) {
                $writer->addRow(Row::fromValues([
                    $user->name, (string) $user->tax_id, (string) $added->new_occurred_at?->format('Y-m-d'), (string) $added->new_type, (string) $added->new_occurred_at?->format('H:i:s'),
                    'admin', '', $added->created_at->format('Y-m-d H:i:s'), 'no', 'add', (string) $added->new_occurred_at?->format('Y-m-d H:i:s'), $added->reason,
                    $added->corrector->name, $added->hash,
                ]));
            }
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Resum / Resumen', '', 'Treballat (h)', 'Planificat (h)', 'Extra (h)', 'Pauses (h)']));

        foreach ($data as $row) {
            $totals = $row['report']['totals'];
            $writer->addRow(Row::fromValues([
                $row['user']->name, '',
                round($totals['worked'] / 60, 2), round($totals['planned'] / 60, 2), round($totals['overtime'] / 60, 2), round($totals['breaks'] / 60, 2),
            ]));
        }

        $writer->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }
}
