<?php

namespace App\Enum;

/**
 * Canal d'envoi d'un code OTP (colonne `one_time_codes.channel`).
 */
enum OtpChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
