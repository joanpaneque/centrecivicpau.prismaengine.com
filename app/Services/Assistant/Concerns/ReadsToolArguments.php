<?php

namespace App\Services\Assistant\Concerns;

use App\Services\Assistant\MenuToolError;
use App\Support\Translation;

/**
 * Reading what the model passes to a tool: bilingual texts, prices in euros and ids.
 * Anything invalid becomes a MenuToolError, which goes back to the model as text.
 */
trait ReadsToolArguments
{
    /**
     * A Catalan/Spanish pair from {$field}_ca and {$field}_es (one fills the other).
     *
     * @param  array<string, mixed>  $args
     * @return array{ca: string, es: string}|null
     */
    private function names(array $args, bool $required, string $field = 'name', int $max = 80): ?array
    {
        $ca = is_string($args[$field.'_ca'] ?? null) ? mb_substr(trim($args[$field.'_ca']), 0, $max) : '';
        $es = is_string($args[$field.'_es'] ?? null) ? mb_substr(trim($args[$field.'_es']), 0, $max) : '';

        if ($ca === '' && $es === '') {
            if ($required) {
                throw new MenuToolError("Falta el texto ({$field}_ca y {$field}_es).");
            }

            return null;
        }

        return Translation::normalize(['ca' => $ca !== '' ? $ca : $es, 'es' => $es !== '' ? $es : $ca]);
    }

    private function cents(mixed $euros): ?int
    {
        if ($euros === null || $euros === '') {
            return null;
        }

        if (is_string($euros)) {
            $euros = str_replace(',', '.', $euros);
        }

        if (! is_numeric($euros) || (float) $euros < 0 || (float) $euros > 10000) {
            throw new MenuToolError('Precio no válido.');
        }

        return (int) round((float) $euros * 100);
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function optionalInt(array $args, string $key): ?int
    {
        return is_numeric($args[$key] ?? null) ? (int) $args[$key] : null;
    }
}
