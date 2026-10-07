<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;

/**
 * Jobs stay pending until the cashier TPV on the PC with the Windows drivers prints them.
 */
class SystemPrinterDriver implements PrinterDriver
{
    public function send(PrintJob $job, ?Printer $printer): string
    {
        return 'pending';
    }
}
