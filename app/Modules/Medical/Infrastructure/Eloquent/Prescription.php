<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Prescription extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** @return HasMany<PrescriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('display_order');
    }

    protected function casts(): array
    {
        return [
            'finalized_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
