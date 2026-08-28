<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Complete = 'complete';
    case Cancelled = 'cancelled';
    case Other = 'other';
}
