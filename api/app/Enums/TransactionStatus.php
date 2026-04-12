<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case REVERSED = 'reversed';
    case FAILED = 'failed';
}
