<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Domain\Enums;

enum ExamCatalogMatchStatus: string
{
    case Exact = 'exact';
    case Probable = 'probable';
    case Unmatched = 'unmatched';
    case Conflict = 'conflict';
}
