<?php

declare(strict_types=1);

namespace App\Modules\Reception\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\Room;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Medical\Infrastructure\Eloquent\EncounterDestination;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Encounter extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    public function resolvePatient(): ?Patient
    {
        return $this->resolveCoreReference(Patient::class, 'patient_public_id', 'patient_id');
    }

    public function getPatientAttribute(): ?Patient
    {
        return $this->relationLoaded('patient') ? $this->getRelation('patient') : $this->resolvePatient();
    }

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
    }

    public function getHealthUnitAttribute(): ?HealthUnit
    {
        return $this->relationLoaded('healthUnit') ? $this->getRelation('healthUnit') : $this->resolveHealthUnit();
    }

    /** @return BelongsTo<EntryType, $this> */
    public function entryType(): BelongsTo
    {
        return $this->belongsTo(EntryType::class);
    }

    /** @return BelongsTo<ArrivalMethod, $this> */
    public function arrivalMethod(): BelongsTo
    {
        return $this->belongsTo(ArrivalMethod::class);
    }

    public function resolveAssignedSpecialty(): ?Specialty
    {
        return $this->resolveCoreReference(
            Specialty::class,
            'assigned_specialty_public_id',
            'assigned_specialty_id',
        );
    }

    public function getAssignedSpecialtyAttribute(): ?Specialty
    {
        return $this->relationLoaded('assignedSpecialty')
            ? $this->getRelation('assignedSpecialty')
            : $this->resolveAssignedSpecialty();
    }

    /** @return BelongsTo<Department, $this> */
    public function currentDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'current_department_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function currentRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'current_room_id');
    }

    public function resolveRiskLevel(): ?RiskLevel
    {
        return $this->risk_level_id === null ? null : RiskLevel::query()->find($this->risk_level_id);
    }

    public function getRiskLevelAttribute(): ?RiskLevel
    {
        return $this->relationLoaded('riskLevel') ? $this->getRelation('riskLevel') : $this->resolveRiskLevel();
    }

    /** @return HasOne<ReceptionRecord, $this> */
    public function receptionRecord(): HasOne
    {
        return $this->hasOne(ReceptionRecord::class);
    }

    /** @return HasMany<EncounterCompanion, $this> */
    public function companions(): HasMany
    {
        return $this->hasMany(EncounterCompanion::class);
    }

    /** @return HasMany<EncounterStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(EncounterStatusHistory::class);
    }

    /** @return HasMany<QueueEntry, $this> */
    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    /** @return HasOne<TriageAssessment, $this> */
    public function triageAssessment(): HasOne
    {
        return $this->hasOne(TriageAssessment::class);
    }

    /** @return HasOne<MedicalConsultation, $this> */
    public function medicalConsultation(): HasOne
    {
        return $this->hasOne(MedicalConsultation::class);
    }

    /** @return HasOne<EncounterDestination, $this> */
    public function destination(): HasOne
    {
        return $this->hasOne(EncounterDestination::class);
    }

    /** @return HasMany<ExamOrder, $this> */
    public function examOrders(): HasMany
    {
        return $this->hasMany(ExamOrder::class);
    }

    public function administrativePriorityEnum(): AdministrativePriority
    {
        $priority = $this->getAttribute('administrative_priority');

        return $priority instanceof AdministrativePriority
            ? $priority
            : AdministrativePriority::from((string) $priority);
    }

    public function currentStatusEnum(): EncounterStatus
    {
        $status = $this->getAttribute('current_status');

        return $status instanceof EncounterStatus ? $status : EncounterStatus::from((string) $status);
    }

    public function arrivalAt(): CarbonImmutable
    {
        $value = $this->getAttribute('arrival_at');

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }

    public function triageStartedAt(): ?CarbonImmutable
    {
        return $this->optionalTimestamp('triage_started_at');
    }

    public function triageFinishedAt(): ?CarbonImmutable
    {
        return $this->optionalTimestamp('triage_finished_at');
    }

    public function medicalStartedAt(): ?CarbonImmutable
    {
        return $this->optionalTimestamp('medical_started_at');
    }

    public function medicalFinishedAt(): ?CarbonImmutable
    {
        return $this->optionalTimestamp('medical_finished_at');
    }

    public function closedAt(): ?CarbonImmutable
    {
        return $this->optionalTimestamp('closed_at');
    }

    private function optionalTimestamp(string $attribute): ?CarbonImmutable
    {
        $value = $this->getAttribute($attribute);
        if ($value === null) {
            return null;
        }
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }

    protected function casts(): array
    {
        return [
            'current_status' => EncounterStatus::class,
            'administrative_priority' => AdministrativePriority::class,
            'arrival_at' => 'immutable_datetime',
            'registration_at' => 'immutable_datetime',
            'triage_started_at' => 'immutable_datetime',
            'triage_finished_at' => 'immutable_datetime',
            'medical_started_at' => 'immutable_datetime',
            'medical_finished_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
