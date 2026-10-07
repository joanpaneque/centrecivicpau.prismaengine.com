<?php

namespace App\Support;

class Translation
{
    public const LOCALES = ['ca', 'es'];

    /**
     * Pick the text for a locale, falling back to the other language when missing.
     *
     * @param  array<string, string|null>|null  $value
     */
    public static function pick(?array $value, ?string $locale = null): string
    {
        if ($value === null) {
            return '';
        }

        $locale ??= app()->getLocale();
        $preferred = $value[$locale] ?? null;

        if (is_string($preferred) && trim($preferred) !== '') {
            return $preferred;
        }

        foreach (self::LOCALES as $fallback) {
            $text = $value[$fallback] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * Normalize user input into a {ca, es} array.
     *
     * @param  array<string, mixed>|string|null  $value
     * @return array{ca: string, es: string}
     */
    public static function normalize(array|string|null $value): array
    {
        if (is_string($value)) {
            return ['ca' => $value, 'es' => $value];
        }

        return [
            'ca' => trim((string) ($value['ca'] ?? '')),
            'es' => trim((string) ($value['es'] ?? '')),
        ];
    }
}
