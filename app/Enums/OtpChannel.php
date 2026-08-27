<?php

namespace App\Enums;

enum OtpChannel: string
{
    case Whatsapp = 'whatsapp';
    case Sms = 'sms';
}
