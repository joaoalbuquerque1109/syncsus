<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\ClinicalNote;
use App\Modules\Medical\Infrastructure\Eloquent\Diagnosis;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class VoidClinicalRecordAction
{
    public function __construct(private RecordAuditEventAction $audit) {}

    public function diagnosis(MedicalConsultation $consultation, Diagnosis $record, string $reason, User $user, HealthUnit $unit, Request $request): void
    {
        $this->execute($consultation, $record, $reason, $user, $unit, $request, 'diagnosis.voided');
    }

    public function prescription(MedicalConsultation $consultation, Prescription $record, string $reason, User $user, HealthUnit $unit, Request $request): void
    {
        $this->execute($consultation, $record, $reason, $user, $unit, $request, 'prescription.cancelled');
    }

    public function note(MedicalConsultation $consultation, ClinicalNote $record, string $reason, User $user, HealthUnit $unit, Request $request): void
    {
        $this->execute($consultation, $record, $reason, $user, $unit, $request, 'clinical_note.voided');
    }

    private function execute(
        MedicalConsultation $consultation,
        Model $record,
        string $reason,
        User $user,
        HealthUnit $unit,
        Request $request,
        string $event,
    ): void {
        DB::transaction(function () use ($consultation, $record, $reason, $user, $unit, $request, $event): void {
            $lockedConsultation = MedicalConsultation::query()
                ->with('encounter')
                ->whereKey($consultation->getKey())
                ->whereHas('encounter', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
                ->lockForUpdate()
                ->firstOrFail();
            $locked = $record::query()
                ->whereKey($record->getKey())
                ->where('medical_consultation_id', $lockedConsultation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $ownerId = match (true) {
                $locked instanceof Diagnosis => (int) $locked->diagnosed_by,
                $locked instanceof Prescription => (int) $locked->professional_id,
                $locked instanceof ClinicalNote => (int) $locked->author_id,
                default => 0,
            };
            abort_unless($ownerId === (int) $user->getKey() || $user->isPlatformAdministrator(), 403);

            if (($locked instanceof Diagnosis && $locked->status === 'voided')
                || ($locked instanceof Prescription && $locked->status !== 'finalized')
                || ($locked instanceof ClinicalNote && $locked->status === 'voided')) {
                throw ValidationException::withMessages(['reason' => 'Este registro ja foi anulado.']);
            }

            $now = now();
            $attributes = match (true) {
                $locked instanceof Diagnosis => [
                    'status' => 'voided', 'voided_at' => $now,
                    'void_reason' => $reason, 'voided_by' => $user->getKey(),
                ],
                $locked instanceof Prescription => [
                    'status' => 'cancelled', 'cancelled_at' => $now,
                    'cancellation_reason' => $reason, 'cancelled_by' => $user->getKey(),
                ],
                $locked instanceof ClinicalNote => [
                    'status' => 'voided', 'voided_at' => $now,
                    'void_reason' => $reason, 'voided_by' => $user->getKey(),
                ],
                default => throw new LogicException('Tipo clinico nao suportado.'),
            };
            $locked->update($attributes);
            if ($locked instanceof Prescription && filled($locked->document_id)) {
                ClinicalDocument::query()
                    ->whereKey($locked->document_id)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'voided',
                        'voided_at' => $now,
                        'voided_by' => $user->getKey(),
                        'void_reason' => $reason,
                    ]);
            }
            $this->audit->execute(
                $event,
                $request,
                $user,
                ['record_id' => $locked->getKey(), 'reason' => $reason],
                (int) $unit->getKey(),
                (int) $lockedConsultation->encounter->patient_id,
                (int) $lockedConsultation->encounter_id,
            );
        });
    }
}
