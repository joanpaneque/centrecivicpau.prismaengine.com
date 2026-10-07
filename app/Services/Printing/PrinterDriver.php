<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;

/**
 * Sends a print document (resources/js/pos/printing/document.ts format) to a printer.
 * Swap the binding in AppServiceProvider to use real hardware.
 */
interface PrinterDriver
{
    /**
     * @return string Final job status (simulated, sent, failed...)
     */
    public function send(PrintJob $job, ?Printer $printer): string;
}
