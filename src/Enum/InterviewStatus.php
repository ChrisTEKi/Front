<?php

namespace App\Enum;

enum InterviewStatus: string
{
    case Scheduled = 'scheduled';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
