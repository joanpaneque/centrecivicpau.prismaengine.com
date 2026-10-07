<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;

/**
 * Sends a print document to a printer and returns the resulting job status.
 */
interface PrinterDriver
{
    /**
     * @return string Final job status (pending, printed, simulated, failed)
     */
    public function send(PrintJob $job, ?Printer $printer): string;
}
