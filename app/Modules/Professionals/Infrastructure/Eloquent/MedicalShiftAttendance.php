<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MedicalShiftAttendance extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'duty_date' => 'immutable_date',
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
        ];
    }
}
