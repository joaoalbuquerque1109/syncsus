<?php

declare(strict_types=1);

namespace App\Modules\Patients\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Application\Actions\LogPatientAccessAction;
use App\Modules\Patients\Application\Actions\SavePatientAction;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientIdentifier;
use App\Modules\Patients\Presentation\Http\Requests\SavePatientRequest;
use App\Modules\Reception\Application\Services\ReceptionDraftService;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PatientController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = $this->organizationId($request);
        $query = Patient::query()->where('organization_id', $organizationId)->with('identifiers')->latest();
        $term = trim((string) $request->query('q'));
        if ($term !== '') {
            $normalized = NormalizesBrazilianData::name($term);
            $digits = NormalizesBrazilianData::digits($term);
            $query->where(function ($query) use ($normalized, $digits): void {
                $query->where('normalized_name', 'like', "%{$normalized}%")
                    ->orWhere('medical_record_number', 'like', "%{$normalized}%");
                if ($digits !== null) {
                    $query->orWhereHas('identifiers', fn ($query) => $query->where('normalized_value', 'like', "%{$digits}%"));
                }
            });
        }

        return view('patients.index', ['patients' => $query->paginate(15)->withQueryString(), 'term' => $term]);
    }

    public function search(Request $request, RecordAuditEventAction $audit): JsonResponse
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        $organizationId = $this->organizationId($request);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:255']]);
        $term = trim($data['q']);
        $normalized = NormalizesBrazilianData::name($term);
        $digits = NormalizesBrazilianData::digits($term);

        $patients = Patient::query()
            ->where('organization_id', $organizationId)
            ->with('identifiers')
            ->where('status', 'active')
            ->where(function ($query) use ($normalized, $digits): void {
                $query->where('normalized_name', 'like', "%{$normalized}%")
                    ->orWhere('medical_record_number', 'like', "%{$normalized}%");
                if ($digits !== null) {
                    $query->orWhereHas('identifiers', fn ($query) => $query->where('normalized_value', 'like', "%{$digits}%"));
                }
            })
            ->limit(10)
            ->get();

        $results = [];
        foreach ($patients as $patient) {
            $identifiers = [];
            foreach ($patient->identifiers as $identifier) {
                $identifiers[] = $this->serializeIdentifier($identifier);
            }
            $results[] = [
                'public_id' => $patient->public_id,
                'medical_record_number' => $patient->medical_record_number,
                'name' => $patient->displayName(),
                'birth_date' => $patient->birthDateLabel(),
                'is_provisional' => (bool) $patient->is_provisional,
                'identifiers' => $identifiers,
            ];
        }
        $audit->execute(
            'patient.searched',
            $request,
            $user,
            [
                'query_fingerprint' => hash('sha256', $normalized),
                'result_count' => count($results),
            ],
            (int) $unit->getKey(),
        );

        return response()->json(['data' => $results]);
    }

    public function create(): View
    {
        return view('patients.create');
    }

    public function store(
        SavePatientRequest $request,
        SavePatientAction $action,
        RecordAuditEventAction $audit,
        ReceptionDraftService $drafts,
    ): RedirectResponse {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        $patient = $action->execute($request->validated(), $user, (int) $unit->getKey());
        $audit->execute('patient.created', $request, $user, [], (int) $unit->getKey(), (int) $patient->getKey());

        if ($request->boolean('return_to_reception')) {
            return redirect()->route('reception.create', ['patient' => $patient->public_id])
                ->withInput($drafts->pull($request, (int) $unit->getKey()))
                ->with('success', 'Paciente cadastrado e selecionado para a recepção.');
        }

        return redirect()->route('patients.show', $patient)->with('success', 'Paciente cadastrado com sucesso.');
    }

    public function show(Request $request, Patient $patient, LogPatientAccessAction $log): View
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        $this->ensurePatientOrganization($request, $patient);
        $log->execute($request, $user, $patient, (int) $unit->getKey(), 'record_view');

        return view('patients.show', ['patient' => $patient->load([
            'identifiers',
            'contacts',
            'addresses',
            'guardians',
            'allergies' => fn ($query) => $query->latest('recorded_at'),
            'conditions' => fn ($query) => $query->latest(),
            'medications' => fn ($query) => $query->latest('recorded_at'),
            'socialHistory',
            'encounters' => fn ($query) => $query->latest()->limit(10),
        ])]);
    }

    public function edit(Request $request, Patient $patient): View
    {
        $this->ensurePatientOrganization($request, $patient);

        return view('patients.edit', ['patient' => $patient->load(['identifiers', 'contacts', 'addresses', 'guardians'])]);
    }

    public function update(
        SavePatientRequest $request,
        Patient $patient,
        SavePatientAction $action,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        $this->ensurePatientOrganization($request, $patient);
        $action->execute($request->validated(), $user, (int) $unit->getKey(), $patient);
        $audit->execute('patient.updated', $request, $user, [], (int) $unit->getKey(), (int) $patient->getKey());

        return redirect()->route('patients.show', $patient)->with('success', 'Cadastro atualizado com sucesso.');
    }

    /** @return array{type: string, value: string} */
    private function serializeIdentifier(PatientIdentifier $identifier): array
    {
        return ['type' => $identifier->typeValue(), 'value' => $identifier->maskedValue()];
    }

    private function organizationId(Request $request): int
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit && (int) $unit->organization_id > 0, 403);

        return (int) $unit->organization_id;
    }

    private function ensurePatientOrganization(Request $request, Patient $patient): void
    {
        abort_unless((int) $patient->organization_id === $this->organizationId($request), 404);
    }
}
