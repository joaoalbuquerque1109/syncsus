<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent;

use App\Support\Models\CoreModel;

final class PatientNumberSequence extends CoreModel
{
    protected $table = 'core_number_sequences';

    protected $guarded = [];
}
