<?php

namespace App\Services\Tickets;

/**
 * Prices are VAT-inclusive (hospitality). The breakdown groups by rate and extracts the
 * base; the terrace surcharge is spread across rates proportionally. The same algorithm
 * lives in resources/js/pos/money.ts so the cashier can compute it offline.
 */
class TaxCalculator
{
    /**
     * @param  list<array{total: int, vat_rate: float}>  $lines
     * @return array{subtotal: int, surcharge_amount: int, total: int, vat_breakdown: list<array{rate: float, base: int, vat: int, total: int}>}
     */
    public function compute(array $lines, float $surchargeRate): array
    {
        /** @var array<string, int> $byRate */
        $byRate = [];

        foreach ($lines as $line) {
            $key = number_format($line['vat_rate'], 2, '.', '');
            $byRate[$key] = ($byRate[$key] ?? 0) + $line['total'];
        }

        ksort($byRate);
        $subtotal = array_sum($byRate);
        $surcharge = (int) round($subtotal * $surchargeRate / 100);

        $allocated = 0;
        $keys = array_keys($byRate);
        $breakdown = [];

        foreach ($keys as $index => $key) {
            $gross = $byRate[$key];
            $share = $index === count($keys) - 1
                ? $surcharge - $allocated
                : ($subtotal > 0 ? (int) round($surcharge * $gross / $subtotal) : 0);
            $allocated += $share;

            $total = $gross + $share;
            $rate = (float) $key;
            $base = (int) round($total / (1 + $rate / 100));

            $breakdown[] = ['rate' => $rate, 'base' => $base, 'vat' => $total - $base, 'total' => $total];
        }

        return [
            'subtotal' => $subtotal,
            'surcharge_amount' => $surcharge,
            'total' => $subtotal + $surcharge,
            'vat_breakdown' => $breakdown,
        ];
    }
}
