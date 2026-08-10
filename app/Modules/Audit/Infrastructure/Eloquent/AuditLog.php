<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuditLog extends Model
{
    use HasUlids;

    protected $guarded = [];

    public function getConnectionName()
    {
        $configured = parent::getConnectionName();
        if ($configured !== null) {
            return $configured;
        }
        $context = app(TenantContext::class);

        return $context->isResolved() ? $context->connectionName() : config('database.default');
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

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

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
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
