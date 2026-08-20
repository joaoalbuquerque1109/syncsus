<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Presentation\Http\Requests\EmployeeRegistrationRequest;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegisterEmployeeAction
{
    public function execute(EmployeeRegistrationRequest $request, HealthUnit $unit): User
    {
        $data = $request->validated();

        return DB::connection('core')->transaction(function () use ($data, $unit): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower((string) $data['email']),
                'cpf' => $data['cpf'],
                'birth_date' => $data['birth_date'],
                'password' => $data['password'],
                'organization_id' => $unit->organization_id,
                'default_health_unit_id' => $unit->getKey(),
                'is_active' => true,
                'must_change_password' => false,
            ]);
            $user->healthUnits()->attach($unit->getKey());
            $user->syncRoles([$data['role']]);

            if (in_array($data['role'], EmployeeRegistrationRequest::CLINICAL_ROLES, true)) {
                $this->createProfessionalProfile($user, $unit, $data);
            }

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    private function createProfessionalProfile(User $user, HealthUnit $unit, array $data): void
    {
        $professional = HealthProfessional::query()->create([
            'organization_id' => $unit->organization_id,
            'user_id' => $user->getKey(),
            'created_by' => $user->getKey(),
            'institutional_code' => 'INST-'.mb_strtoupper(Str::random(8)),
            'profession_type' => $data['role'] === 'doctor' ? 'doctor' : 'nurse',
            'full_name' => $data['name'],
            'cpf' => $data['cpf'],
            'birth_date' => $data['birth_date'],
            'email' => mb_strtolower((string) $data['email']),
            'is_active' => true,
        ]);
        $professional->healthUnits()->attach($unit->getKey());
        $professional->registrations()->create([
            'organization_id' => $unit->organization_id,
            'council_type' => mb_strtoupper((string) $data['council_type']),
            'registration_number' => $data['registration_number'],
            'state' => mb_strtoupper((string) $data['registration_state']),
            'is_primary' => true,
            'is_active' => true,
        ]);
    }
}
