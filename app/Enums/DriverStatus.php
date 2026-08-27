<?php

namespace App\Enums;

enum DriverStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Dormant = 'dormant';
}
