<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Domain\Enums;

enum LaboratoryResultIngestionStatus: string
{
    case Received = 'received';
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
    case ManualReview = 'manual_review';
}
