<?php

namespace App\Enums;

enum YangoOrderStatus: string
{
    case Complete = 'complete';
    case Cancelled = 'cancelled';
    case Other = 'other';
}
