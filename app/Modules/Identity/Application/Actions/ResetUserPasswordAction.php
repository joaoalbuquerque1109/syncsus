<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ResetUserPasswordAction
{
    public function execute(
        User $actor,
        User $target,
        string $temporaryPassword,
        ?int $healthUnitId = null,
        string $source = 'console',
    ): void {
        if (! $actor->is_active || ! $actor->can('administration.manage')) {
            throw new AuthorizationException('O usuário operador não pode redefinir senhas.');
        }

        if (! $actor->isPlatformAdministrator()
            && (int) $actor->organization_id !== (int) $target->organization_id) {
            throw new AuthorizationException('O usuario pertence a outra organizacao.');
        }

        DB::transaction(function () use ($actor, $target, $temporaryPassword, $healthUnitId, $source): void {
            $target->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'password_changed_at' => now(),
            ])->save();

            DB::table('sessions')->where('user_id', $target->getKey())->delete();

            AuditLog::query()->create([
                'user_id' => $actor->getKey(),
                'health_unit_id' => $healthUnitId ?? $actor->default_health_unit_id,
                'action' => 'user.password_reset_by_administrator',
                'auditable_type' => User::class,
                'auditable_id' => $target->getKey(),
                'changed_fields' => ['password' => 'redacted', 'must_change_password' => true],
                'context' => [
                    'source' => $source,
                    'target_public_id' => $target->public_id,
                ],
                'occurred_at' => now(),
            ]);
        });
    }
}
