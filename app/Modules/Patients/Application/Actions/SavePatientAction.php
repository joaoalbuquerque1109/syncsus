<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Application\Services\NumberSequenceService;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class SavePatientAction
{
    public function __construct(private NumberSequenceService $sequences) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $user, int $healthUnitId, ?Patient $patient = null): Patient
    {
        return DB::transaction(function () use ($data, $user, $healthUnitId, $patient): Patient {
            $organizationId = (int) HealthUnit::query()->whereKey($healthUnitId)->value('organization_id');
            abort_unless(
                $organizationId > 0
                    && ($user->isPlatformAdministrator() || (int) $user->organization_id === $organizationId),
                403,
            );
            abort_if($patient !== null && (int) $patient->organization_id !== $organizationId, 404);

            $patient ??= new Patient([
                'organization_id' => $organizationId,
                'medical_record_number' => sprintf('P%08d', $this->sequences->next('patient_mrn')),
                'created_by' => $user->getKey(),
                'status' => PatientStatus::Active,
            ]);

            $patient->fill([
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

            $this->syncIdentifiers($patient, $data);
            $this->syncContacts($patient, $data);
            $this->syncAddress($patient, $data);
            $this->syncGuardian($patient, $data);

            return $patient->fresh(['identifiers', 'contacts', 'addresses', 'guardians']) ?? $patient;
        });
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
