<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;

/**
 * Sends ESC/POS bytes to a network thermal printer (usually TCP 9100).
 * The server must be able to reach the printer IP; a cloud host cannot print to a venue LAN
 * (for that, the printer is assigned to the Windows print program of the bar).
 */
class EscPosPrinterDriver implements PrinterDriver
{
    public function __construct(private readonly PrintDocumentEncoder $encoder) {}

    public function send(PrintJob $job, ?Printer $printer): string
    {
        if ($printer === null || $printer->ip === null || $printer->ip === '') {
            return 'failed';
        }

        $bytes = $this->encode($job->document, $printer);
        $port = $printer->port ?? 9100;
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($printer->ip, $port, $errno, $errstr, 3);

        if ($socket === false) {
            return 'failed';
        }

        stream_set_timeout($socket, 3);
        $written = fwrite($socket, $bytes);
        fclose($socket);

        return $written === false || $written < 1 ? 'failed' : 'printed';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function encode(array $document, ?Printer $printer = null): string
    {
        return $this->encoder->escpos($document, $printer?->paper_width, $printer->codepage ?? 'cp858');
    }
}
