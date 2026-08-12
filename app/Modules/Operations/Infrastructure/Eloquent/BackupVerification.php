<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

final class BackupVerification extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function finishedAt(): ?CarbonImmutable
    {
        $finishedAt = $this->getAttribute('finished_at');
        if ($finishedAt === null) {
            return null;
        }

        return $finishedAt instanceof CarbonImmutable
            ? $finishedAt
            : Carbon::parse((string) $finishedAt)->toImmutable();
    }

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'core_reference_at' => 'immutable_datetime',
            'restore_point_at' => 'immutable_datetime',
            'restore_compatible' => 'boolean',
        ];
    }
}
