<?php

namespace App\Services\Printing;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The built Windows print program, published by the deploy into
 * storage/app/private/print-agent (the .exe and a version.txt).
 */
class PrintAgentRelease
{
    public const FILE = 'CentreCivicImpressio.exe';

    public function folder(): string
    {
        return (string) (config('services.print_agent.release_path') ?: storage_path('app/private/print-agent'));
    }

    public function path(): string
    {
        return $this->folder().'/'.self::FILE;
    }

    public function available(): bool
    {
        return is_file($this->path());
    }

    public function version(): ?string
    {
        $file = $this->folder().'/version.txt';
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : null;
    }

    public function size(): ?int
    {
        return $this->available() ? (int) filesize($this->path()) : null;
    }

    public function response(): BinaryFileResponse
    {
        abort_unless($this->available(), 404, 'El programa encara no s\'ha publicat en aquest servidor.');

        return response()->download($this->path(), self::FILE, ['Content-Type' => 'application/vnd.microsoft.portable-executable']);
    }
}
