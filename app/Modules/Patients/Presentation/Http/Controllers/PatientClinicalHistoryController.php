<?php

declare(strict_types=1);

namespace App\Modules\Patients\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PatientClinicalHistoryController extends Controller
{
    public function storeAllergy(
        Request $request,
        Patient $patient,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'substance' => ['required', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe', 'unknown'])],
            'status' => ['required', Rule::in(['active', 'inactive', 'resolved'])],
        ]);
        [$user, $unit] = $this->context($request);
        $this->ensurePatientOrganization($patient, $unit);

        $allergy = $patient->allergies()->create([
            ...$data,
            'source' => 'patient_record',
            'recorded_by' => $user->getKey(),
            'recorded_at' => now(),
        ]);
        $audit->execute('patient.allergy_recorded', $request, $user, [
            'allergy_id' => $allergy->getKey(),
        ], (int) $unit->getKey(), (int) $patient->getKey());

        return back()->with('success', 'Alergia registrada no histórico clínico.');
    }

    public function storeCondition(
        Request $request,
        Patient $patient,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'description' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'resolved'])],
            'onset_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        [$user, $unit] = $this->context($request);
        $this->ensurePatientOrganization($patient, $unit);

        $condition = $patient->conditions()->create([
            ...$data,
            'recorded_by' => $user->getKey(),
        ]);
        $audit->execute('patient.condition_recorded', $request, $user, [
            'condition_id' => $condition->getKey(),
        ], (int) $unit->getKey(), (int) $patient->getKey());

        return back()->with('success', 'Condição de saúde registrada.');
    }

    public function storeMedication(
        Request $request,
        Patient $patient,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'medication_name' => ['required', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:64'],
            'status' => ['required', Rule::in(['active', 'suspended', 'completed'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        [$user, $unit] = $this->context($request);
        $this->ensurePatientOrganization($patient, $unit);

        $medication = $patient->medications()->create([
            ...$data,
            'source' => 'patient_record',
            'recorded_by' => $user->getKey(),
            'recorded_at' => now(),
        ]);
        $audit->execute('patient.medication_recorded', $request, $user, [
            'medication' => $medication->public_id,
        ], (int) $unit->getKey(), (int) $patient->getKey());

        return back()->with('success', 'Medicamento de uso contínuo registrado.');
    }

    public function saveSocialHistory(
        Request $request,
        Patient $patient,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'smoking_status' => ['nullable', Rule::in(['never', 'former', 'current', 'unknown'])],
            'alcohol_use' => ['nullable', Rule::in(['none', 'occasional', 'frequent', 'unknown'])],
            'other_substance_use' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        [$user, $unit] = $this->context($request);
        $this->ensurePatientOrganization($patient, $unit);

        $patient->socialHistory()->updateOrCreate(
            ['patient_id' => $patient->getKey()],
            [...$data, 'recorded_by' => $user->getKey(), 'recorded_at' => now()],
        );
        $audit->execute(
            'patient.social_history_updated',
            $request,
            $user,
            [],
            (int) $unit->getKey(),
            (int) $patient->getKey(),
        );

        return back()->with('success', 'Histórico social atualizado.');
    }

    /** @return array{User, HealthUnit} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);

        return [$user, $unit];
    }

    private function ensurePatientOrganization(Patient $patient, HealthUnit $unit): void
    {
        abort_unless((int) $patient->organization_id === (int) $unit->organization_id, 404);
    }
}
