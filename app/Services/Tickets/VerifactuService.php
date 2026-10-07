<?php

namespace App\Services\Tickets;

use App\Models\Ticket;

/**
 * Simulated VERI*FACTU layer: computes the record fingerprint (SHA-256 over the chained
 * fields of the AEAT spec) and the validation QR URL. Nothing is sent to the AEAT; a future
 * submitter only has to read tickets.verifactu and post the XML records.
 */
class VerifactuService
{
    public const QR_BASE_URL = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';

    public function chain(Ticket $ticket): void
    {
        $previous = Ticket::query()
            ->where('ticket_series_id', $ticket->ticket_series_id)
            ->where('number', '<', $ticket->number)
            ->whereNotNull('hash')
            ->orderByDesc('number')
            ->value('hash');

        $issuerTaxId = (string) ($ticket->issuer['tax_id'] ?? '');
        $vatTotal = array_sum(array_column($ticket->vat_breakdown, 'vat'));
        $generatedAt = now()->toIso8601String();

        $fields = [
            'IDEmisorFactura='.$issuerTaxId,
            'NumSerieFactura='.$ticket->full_number,
            'FechaExpedicionFactura='.$ticket->issued_at->format('d-m-Y'),
            'TipoFactura=F2',
            'CuotaTotal='.$this->amount($vatTotal),
            'ImporteTotal='.$this->amount($ticket->total),
            'Huella='.($previous ?? ''),
            'FechaHoraHusoGenRegistro='.$generatedAt,
        ];

        $hash = strtoupper(hash('sha256', implode('&', $fields)));

        $ticket->forceFill([
            'previous_hash' => $previous,
            'hash' => $hash,
            'verifactu' => [
                'simulated' => true,
                'status' => 'not_sent',
                'record_type' => 'alta',
                'invoice_type' => 'F2',
                'generated_at' => $generatedAt,
                'qr_url' => $this->qrUrl($issuerTaxId, $ticket->full_number, $ticket->issued_at->format('d-m-Y'), $ticket->total),
            ],
        ])->save();
    }

    public function qrUrl(string $taxId, string $number, string $date, int $total): string
    {
        return self::QR_BASE_URL.'?'.http_build_query([
            'nif' => $taxId,
            'numserie' => $number,
            'fecha' => $date,
            'importe' => $this->amount($total),
        ]);
    }

    private function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
