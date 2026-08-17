<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SecurityAuditLog extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
    }

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
