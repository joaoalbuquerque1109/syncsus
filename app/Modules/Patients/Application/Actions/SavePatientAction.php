<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Application\Services\PatientMedicalRecordNumberService;
use App\Modules\Patients\Application\Services\UnitPatientRegistry;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientOperationKey;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SavePatientAction
{
    private const CREATE_ROUTE = 'patients.store';

    public function __construct(
        private PatientMedicalRecordNumberService $medicalRecordNumbers,
        private UnitPatientRegistry $unitPatients,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $user, int $healthUnitId, ?Patient $patient = null): Patient
    {
        $unit = HealthUnit::query()->findOrFail($healthUnitId);
        $organizationId = (int) $unit->organization_id;
        abort_unless(
            $user->isPlatformAdministrator() || (int) $user->organization_id === $organizationId,
            403,
        );
        abort_if($patient !== null && (int) $patient->organization_id !== $organizationId, 404);

        $operationKeyId = null;
        if ($patient === null) {
            $idempotencyKey = $this->idempotencyKey($data);
            $hashPayload = Arr::except($data, ['idempotency_key']);
            ksort($hashPayload);
            $requestHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR));

            [$patient, $operationKeyId] = DB::connection('core')->transaction(function () use (
                $data,
                $user,
                $healthUnitId,
                $organizationId,
                $idempotencyKey,
                $requestHash,
            ): array {
                $existingKey = PatientOperationKey::query()
                    ->where('user_id', $user->getKey())
                    ->where('route_name', self::CREATE_ROUTE)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingKey !== null) {
                    if (! hash_equals((string) $existingKey->request_hash, $requestHash)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'Este formulário já foi usado com dados diferentes. Recarregue a página.',
                        ]);
                    }

                    return [
                        Patient::query()->where('public_id', $existingKey->patient_public_id)->firstOrFail(),
                        (int) $existingKey->getKey(),
                    ];
                }

                $record = $this->persistCorePatient(
                    $data,
                    $user,
                    $healthUnitId,
                    $organizationId,
                );
                $operationKey = PatientOperationKey::query()->create([
                    'user_id' => $user->getKey(),
                    'route_name' => self::CREATE_ROUTE,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'patient_public_id' => $record->public_id,
                    'status' => 'pending',
                ]);

                return [$record, (int) $operationKey->getKey()];
            });
        } else {
            $patient = DB::connection('core')->transaction(fn (): Patient => $this->persistCorePatient(
                $data,
                $user,
                $healthUnitId,
                $organizationId,
                $patient,
            ));
        }

        $this->unitPatients->ensure($patient, $unit);
        DB::connection((string) $patient->contacts()->getModel()->getConnectionName())->transaction(
            function () use ($patient, $data): void {
                $this->syncContacts($patient, $data);
                $this->syncAddress($patient, $data);
                $this->syncGuardian($patient, $data);
            },
        );

        if ($operationKeyId !== null) {
            PatientOperationKey::query()->whereKey($operationKeyId)->update(['status' => 'completed']);
        }

        return $patient->fresh(['identifiers', 'contacts', 'addresses', 'guardians']) ?? $patient;
    }

    /** @param array<string, mixed> $data */
    private function persistCorePatient(
        array $data,
        User $user,
        int $healthUnitId,
        int $organizationId,
        ?Patient $patient = null,
    ): Patient {
        $record = $patient ?? new Patient([
            'organization_id' => $organizationId,
            'medical_record_number' => $this->medicalRecordNumbers->next(),
            'created_by' => $user->getKey(),
            'status' => PatientStatus::Active,
        ]);

        $record->fill([
            ...Arr::only($data, [
                'full_name', 'social_name', 'birth_date', 'estimated_age', 'estimated_age_range',
                'sex', 'gender_identity', 'race_color', 'ethnicity', 'nationality', 'birth_city',
                'birth_city_ibge_code', 'marital_status', 'number_of_children', 'is_disabled',
                'blood_type', 'mother_name', 'mother_unknown', 'father_name', 'father_unknown',
                'education_level', 'occupation', 'administrative_notes',
            ]),
            'normalized_name' => NormalizesBrazilianData::name((string) $data['full_name']),
            'normalized_mother_name' => NormalizesBrazilianData::name($data['mother_name'] ?? null),
            'reference_health_unit_id' => $healthUnitId,
            'is_provisional' => false,
            'updated_by' => $user->getKey(),
        ])->save();

        $this->syncIdentifiers($record, $data);

        return $record;
    }

    /** @param array<string, mixed> $data */
    private function idempotencyKey(array $data): string
    {
        $key = $data['idempotency_key'] ?? null;
        if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 64) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A chave de idempotência do formulário é obrigatória.',
            ]);
        }

        return $key;
    }

    /** @param array<string, mixed> $data */
    private function syncIdentifiers(Patient $patient, array $data): void
    {
        $patient->identifiers()->delete();
        $definitions = [
            'cpf' => [],
            'cns' => [],
            'rg' => [
                'issuer' => $data['rg_issuer'] ?? null,
                'issuer_state' => $data['rg_issuer_state'] ?? null,
                'issued_at' => $data['rg_issued_at'] ?? null,
            ],
            'passport' => [
                'issuer' => $data['passport_issuer'] ?? null,
                'issuer_state' => $data['passport_issuer_state'] ?? null,
                'issued_at' => $data['passport_issued_at'] ?? null,
            ],
        ];

        foreach ($definitions as $type => $metadata) {
            $value = in_array($type, ['cpf', 'cns'], true)
                ? NormalizesBrazilianData::digits($data[$type] ?? null)
                : $this->normalizedDocument($data[$type] ?? null);
            if ($value !== null) {
                $patient->identifiers()->create([
                    'type' => $type,
                    'normalized_value' => $value,
                    'display_value' => $value,
                    'is_primary' => $type === 'cpf',
                    ...$metadata,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function syncContacts(Patient $patient, array $data): void
    {
        $patient->contacts()->delete();
        foreach (['mobile', 'phone', 'phone2', 'phone3', 'email'] as $type) {
            $value = $data[$type] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $patient->contacts()->create([
                    'type' => $type,
                    'value' => trim($value),
                    'normalized_value' => $type === 'email'
                        ? mb_strtolower(trim($value))
                        : NormalizesBrazilianData::phone($value),
                    'is_primary' => $type === 'mobile' || ($type === 'phone' && blank($data['mobile'] ?? null)),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function syncAddress(Patient $patient, array $data): void
    {
        $patient->addresses()->delete();
        if (($data['address_unknown'] ?? false) || collect([
            'postal_code', 'state', 'city', 'district', 'street', 'number',
        ])->contains(fn (string $field): bool => filled($data[$field] ?? null))) {
            $patient->addresses()->create([
                ...Arr::only($data, [
                    'postal_code', 'state', 'city', 'district', 'street', 'number',
                    'city_ibge_code', 'complement', 'reference', 'area_type',
                ]),
                'is_primary' => true,
                'is_unknown' => (bool) ($data['address_unknown'] ?? false),
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function syncGuardian(Patient $patient, array $data): void
    {
        $patient->guardians()->delete();
        if (filled($data['guardian_name'] ?? null)) {
            $patient->guardians()->create([
                'guardian_type' => 'legal',
                'full_name' => $data['guardian_name'],
                'cpf' => NormalizesBrazilianData::digits($data['guardian_cpf'] ?? null),
                'relationship' => $data['guardian_relationship'] ?? 'responsável',
                'phone' => $data['guardian_phone'] ?? null,
                'responsibility_reason' => $data['guardian_reason'] ?? null,
            ]);
        }

        if (filled($data['financial_guardian_name'] ?? null)) {
            $patient->guardians()->create([
                'guardian_type' => 'financial',
                'full_name' => $data['financial_guardian_name'],
                'cpf' => NormalizesBrazilianData::digits($data['financial_guardian_cpf'] ?? null),
                'relationship' => $data['financial_guardian_relationship'] ?? 'responsável financeiro',
                'phone' => $data['financial_guardian_phone'] ?? null,
                'responsibility_reason' => $data['financial_guardian_reason'] ?? null,
            ]);
        }
    }

    private function normalizedDocument(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        return $normalized !== '' ? $normalized : null;
    }
}
