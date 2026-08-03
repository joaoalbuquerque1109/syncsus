<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Domain\Enums;

enum LaboratoryTransmissionStatus: string
{
    case AwaitingContract = 'awaiting_contract';
    case Pending = 'pending';
    case Sending = 'sending';
    case Retrying = 'retrying';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case ManualReview = 'manual_review';
}
