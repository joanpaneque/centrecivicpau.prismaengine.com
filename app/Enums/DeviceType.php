<?php

namespace App\Enums;

enum DeviceType: string
{
    case Tablet = 'tablet';
    case Cashier = 'cashier';
    case Kds = 'kds';
    case Clock = 'clock';
}
