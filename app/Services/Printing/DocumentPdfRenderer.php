<?php

namespace App\Services\Printing;

use App\Support\AppSettings;
use App\Support\Qr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class DocumentPdfRenderer
{
    /**
     * @param  array{width?: int, lines?: list<array<string, mixed>>}  $document
     */
    public function render(array $document, string $title): string
    {
        $width = (int) ($document['width'] ?? 48);
        $paperWidth = $width <= 32 ? 164.41 : 226.77;

        return Pdf::loadView('pdf.print-document', [
            'title' => $title,
            'logo' => $this->logoPath(),
            'lines' => is_array($document['lines'] ?? null) ? $document['lines'] : [],
        ])->setPaper([0, 0, $paperWidth, 2000])->output();
    }

    public static function qrDataUri(string $data): string
    {
        return Qr::dataUri($data, 120);
    }

    private function logoPath(): ?string
    {
        $path = AppSettings::get('logo_path');

        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        $default = public_path('images/logo-centre-civic.png');

        return is_file($default) ? $default : null;
    }
}
