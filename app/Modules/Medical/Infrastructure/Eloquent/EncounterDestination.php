<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\DestinationType;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EncounterDestination extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function typeEnum(): DestinationType
    {
        $type = $this->getAttribute('destination_type');

        return $type instanceof DestinationType ? $type : DestinationType::from((string) $type);
    }

    protected function casts(): array
    {
        return [
            'destination_type' => DestinationType::class,
            'details' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
