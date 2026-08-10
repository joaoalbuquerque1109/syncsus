<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantDatabaseEvent extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<TenantDatabase, $this> */
    public function tenantDatabase(): BelongsTo
    {
        return $this->belongsTo(TenantDatabase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
