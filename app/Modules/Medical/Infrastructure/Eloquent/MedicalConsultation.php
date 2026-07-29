<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Room;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class MedicalConsultation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<QueueEntry, $this> */
    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** @return BelongsTo<Specialty, $this> */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return HasOne<PhysicalExam, $this> */
    public function physicalExam(): HasOne
    {
        return $this->hasOne(PhysicalExam::class);
    }

    /** @return HasMany<Diagnosis, $this> */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    /** @return HasMany<Prescription, $this> */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /** @return HasMany<ExamOrder, $this> */
    public function examOrders(): HasMany
    {
        return $this->hasMany(ExamOrder::class);
    }

    /** @return HasMany<ClinicalNote, $this> */
    public function clinicalNotes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class);
    }

    /** @return HasMany<Referral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    /** @return HasOne<EncounterDestination, $this> */
    public function destination(): HasOne
    {
        return $this->hasOne(EncounterDestination::class);
    }

    /** @return HasMany<MedicalAddendum, $this> */
    public function addenda(): HasMany
    {
        return $this->hasMany(MedicalAddendum::class);
    }

    /** @return HasMany<ClinicalDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ClinicalDocument::class, 'medical_consultation_id');
    }

    public function statusEnum(): MedicalConsultationStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof MedicalConsultationStatus ? $status : MedicalConsultationStatus::from((string) $status);
    }

    public function version(): int
    {
        return (int) $this->getAttribute('lock_version');
    }

    protected function casts(): array
    {
        return [
            'status' => MedicalConsultationStatus::class,
            'requires_reassessment' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }
}
