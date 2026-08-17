<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) config('sync_sus.admin.name'));
        $email = mb_strtolower(trim((string) config('sync_sus.admin.email')));
        $password = (string) config('sync_sus.admin.password');

        if ($name === '' || $email === '' || $password === '') {
            if (App::environment('production')) {
                throw new RuntimeException(
                    'Defina SYNC_SUS_ADMIN_NAME, SYNC_SUS_ADMIN_EMAIL e SYNC_SUS_ADMIN_PASSWORD antes do seed.',
                );
            }

            $this->command->warn('Administrador não criado: configure as variáveis SYNC_SUS_ADMIN_*.');

            return;
        }

        if (! $this->isStrongPassword($password)) {
            throw new RuntimeException(
                'SYNC_SUS_ADMIN_PASSWORD deve ter 12 caracteres, maiúscula, minúscula, número e símbolo.',
            );
        }

        $user = User::query()->where('platform_admin_slot', 1)->first()
            ?? User::query()->where('email', $email)->orderBy('id')->first()
            ?? new User(['email' => $email]);
        if (! $user->exists) {
            $user->fill([
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
                'must_change_password' => false,
            ]);
        }
        $user->forceFill([
            'organization_id' => null,
            'platform_admin_slot' => 1,
            'default_health_unit_id' => null,
            'must_change_password' => false,
        ])->save();

        $user->healthUnits()->detach();
        $user->syncRoles(['administrator']);

        User::query()
            ->where('id', '!=', $user->getKey())
            ->whereHas('roles', fn ($query) => $query->where('name', 'administrator'))
            ->each(function (User $legacyAdministrator): void {
                $legacyAdministrator->removeRole('administrator');
                if ($legacyAdministrator->organization_id !== null) {
                    $legacyAdministrator->assignRole('manager');
                }
            });
    }

    private function isStrongPassword(string $password): bool
    {
        return mb_strlen($password) >= 12
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[^a-zA-Z\d]/', $password) === 1;
    }
}
