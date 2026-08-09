<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Domain\Enums;

enum ExamMappingMatchType: string
{
    case Exact = 'exact';
    case Probable = 'probable';
    case Manual = 'manual';
    case Unmatched = 'unmatched';
}
