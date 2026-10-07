<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;
use RuntimeException;

/**
 * Placeholder for network ESC/POS printers (IP + port 9100). It already converts the
 * document into ESC/POS bytes; only the socket transport remains to be enabled.
 */
class EscPosPrinterDriver implements PrinterDriver
{
    public function send(PrintJob $job, ?Printer $printer): string
    {
        if ($printer === null || $printer->ip === null) {
            return 'failed';
        }

        $bytes = $this->encode($job->document);

        throw new RuntimeException(sprintf('ESC/POS transport not enabled (%d bytes for %s:%d).', strlen($bytes), $printer->ip, $printer->port ?? 9100));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function encode(array $document): string
    {
        $esc = "\x1B";
        $gs = "\x1D";
        $out = $esc.'@';
        $width = (int) ($document['width'] ?? 48);

        /** @var list<array<string, mixed>> $lines */
        $lines = $document['lines'] ?? [];

        foreach ($lines as $line) {
            $type = $line['type'] ?? 'text';
            $bold = ! empty($line['bold']);
            $out .= $esc.'E'.($bold ? "\x01" : "\x00");
            $out .= $gs.'!'.(($line['size'] ?? 1) > 1 ? "\x11" : "\x00");

            $out .= match ($type) {
                'divider' => str_repeat('-', $width)."\n",
                'row' => $this->row((string) ($line['left'] ?? ''), (string) ($line['right'] ?? ''), $width)."\n",
                'feed' => "\n",
                'cut' => $gs.'V'."\x41\x03",
                'qr' => (string) ($line['data'] ?? '')."\n",
                default => $this->align((string) ($line['text'] ?? ''), (string) ($line['align'] ?? 'left'), $width)."\n",
            };
        }

        return $out;
    }

    private function row(string $left, string $right, int $width): string
    {
        $space = max(1, $width - mb_strlen($left) - mb_strlen($right));

        return $left.str_repeat(' ', $space).$right;
    }

    private function align(string $text, string $align, int $width): string
    {
        $pad = max(0, $width - mb_strlen($text));

        return match ($align) {
            'center' => str_repeat(' ', intdiv($pad, 2)).$text,
            'right' => str_repeat(' ', $pad).$text,
            default => $text,
        };
    }
}
