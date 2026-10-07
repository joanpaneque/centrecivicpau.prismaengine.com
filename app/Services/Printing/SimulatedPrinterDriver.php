<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;

class SimulatedPrinterDriver implements PrinterDriver
{
    public function send(PrintJob $job, ?Printer $printer): string
    {
        return 'simulated';
    }
}
