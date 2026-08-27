<?php

namespace App\Enums;

enum DriverPhotoStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
