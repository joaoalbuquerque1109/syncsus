<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Infrastructure\Eloquent;

use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProfessionalRegistration extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<HealthProfessional, $this> */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(HealthProfessional::class, 'health_professional_id');
    }

    public function label(): string
    {
        return mb_strtoupper((string) $this->council_type)
            .'/'.mb_strtoupper((string) $this->state)
            .' '.(string) $this->registration_number;
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_date',
            'expires_at' => 'immutable_date',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
