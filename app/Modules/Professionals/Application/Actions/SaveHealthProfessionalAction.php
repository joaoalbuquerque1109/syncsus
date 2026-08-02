<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class SaveHealthProfessionalAction
{
    /** @param array<string, mixed> $data */
    public function execute(
        array $data,
        User $actor,
        ?HealthProfessional $professional = null,
    ): HealthProfessional {
        return DB::transaction(function () use ($data, $actor, $professional): HealthProfessional {
            $organizationId = $actor->isPlatformAdministrator()
                ? (int) HealthUnit::query()->whereKey($data['health_unit_ids'][0] ?? 0)->value('organization_id')
                : (int) $actor->organization_id;
            abort_unless($organizationId > 0, 403);
            abort_if($professional !== null
                && (int) $professional->organization_id !== $organizationId, 404);
            $professional ??= new HealthProfessional([
                'organization_id' => $organizationId,
                'created_by' => $actor->getKey(),
            ]);
            $professional->fill([
                ...Arr::only($data, [
                    'user_id', 'institutional_code', 'profession_type', 'treatment_name',
                    'full_name', 'social_name', 'cpf', 'birth_date', 'sex', 'identity_number',
                    'identity_issuer', 'identity_state', 'cnes_code', 'phone', 'mobile',
                    'secondary_phone', 'email', 'secondary_email', 'postal_code', 'state',
                    'city', 'city_ibge_code', 'district', 'street', 'street_number',
                    'address_complement', 'notes', 'is_active',
                ]),
                'updated_by' => $actor->getKey(),
            ])->save();

            $professional->healthUnits()->sync($data['health_unit_ids']);
            $this->syncSpecialties($professional, $data);
            $this->syncRegistrations($professional, $data);
            $professional->queues()->sync($data['queue_ids'] ?? []);
            $professional->servicePoints()->sync($data['service_point_ids'] ?? []);

            return $professional->fresh(['user', 'healthUnits', 'specialties', 'registrations', 'queues', 'servicePoints'])
                ?? $professional;
        });
    }

    /** @param array<string, mixed> $data */
    private function syncSpecialties(HealthProfessional $professional, array $data): void
    {
        $primary = (int) ($data['primary_specialty_id'] ?? 0);
        $rqe = is_array($data['specialty_rqe'] ?? null) ? $data['specialty_rqe'] : [];
        $registeredAt = is_array($data['specialty_registered_at'] ?? null)
            ? $data['specialty_registered_at']
            : [];
        $payload = [];
        foreach ($data['specialty_ids'] ?? [] as $specialtyId) {
            $id = (int) $specialtyId;
            $payload[$id] = [
                'rqe_number' => filled($rqe[$id] ?? null) ? $rqe[$id] : null,
                'registered_at' => filled($registeredAt[$id] ?? null) ? $registeredAt[$id] : null,
                'is_primary' => $id === $primary,
            ];
        }
        $professional->specialties()->sync($payload);
    }

    /** @param array<string, mixed> $data */
    private function syncRegistrations(HealthProfessional $professional, array $data): void
    {
        $professional->registrations()->delete();
        foreach ($data['registrations'] ?? [] as $index => $registration) {
            $professional->registrations()->create([
                ...Arr::only($registration, [
                    'council_type', 'registration_number', 'state', 'issued_at', 'expires_at',
                ]),
                'organization_id' => $professional->organization_id,
                'council_type' => mb_strtoupper((string) $registration['council_type']),
                'state' => mb_strtoupper((string) $registration['state']),
                'is_primary' => (bool) ($registration['is_primary'] ?? $index === 0),
                'is_active' => true,
            ]);
        }
    }
}
