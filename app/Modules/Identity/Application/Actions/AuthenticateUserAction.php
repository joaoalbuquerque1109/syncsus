<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Application\Services\LimitConcurrentSessions;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final readonly class AuthenticateUserAction
{
    public function __construct(
        private RecordAuditEventAction $recordAuditEvent,
        private LimitConcurrentSessions $sessions,
    ) {}

    public function execute(LoginRequest $request): User
    {
        $request->ensureIsNotRateLimited();

        $unitCode = mb_strtoupper(trim((string) $request->string('unit_code')));
        $unitCodeDigits = preg_replace('/\D/', '', $unitCode);
        $isAdministrativeAccess = hash_equals(
            (string) config('sync_sus.admin.access_code'),
            $unitCode,
        );
        $email = mb_strtolower(trim((string) $request->string('email')));

        // O codigo digitado no login pode ser o CNES de uma unidade especifica
        // (nao so o da organizacao) - isso permite que um profissional com
        // vinculo em mais de uma unidade escolha exatamente qual delas entrar
        // nesta sessao, em vez de sempre cair na unidade padrao dele.
        $targetHealthUnit = $isAdministrativeAccess
            ? null
            : HealthUnit::query()
                ->where('is_active', true)
                ->where(function ($query) use ($unitCode, $unitCodeDigits): void {
                    $query->where('cnes_code', $unitCodeDigits)->orWhere('code', $unitCode);
                })
                ->whereHas('organization', fn ($query) => $query->where('is_active', true))
                ->first();
        $organization = $isAdministrativeAccess
            ? null
            : ($targetHealthUnit->organization ?? Organization::query()
                ->where('is_active', true)
                ->where(function ($query) use ($unitCode, $unitCodeDigits): void {
                    $query->where('cnes_code', $unitCodeDigits)->orWhere('code', $unitCode);
                })
                ->first());
        $user = $isAdministrativeAccess
            ? User::query()
                ->where('platform_admin_slot', 1)
                ->whereNull('organization_id')
                ->where('email', $email)
                ->first()
            : ($organization === null ? null : User::query()
                ->where('organization_id', $organization->getKey())
                ->where('email', $email)
                ->first());
        $selectedHealthUnit = $user === null ? null : $this->resolveHealthUnit($user, $targetHealthUnit);
        $auditHealthUnitId = $selectedHealthUnit?->getKey();
        $passwordIsValid = $user !== null && Hash::check((string) $request->string('password'), $user->password);

        $hasAccessContext = $user?->isPlatformAdministrator() === true
            || $selectedHealthUnit instanceof HealthUnit;
        if (! $passwordIsValid || ! $user->is_active || ! $hasAccessContext) {
            $request->recordFailedAttempt();
            $this->recordAuditEvent->execute(
                action: 'user.login_failed',
                request: $request,
                user: $user,
                context: [
                    'reason' => match (true) {
                        $user !== null && ! $user->is_active => 'inactive_user',
                        $user !== null && ! $hasAccessContext => 'unit_access_unavailable',
                        default => 'invalid_credentials',
                    },
                    'unit_code' => $unitCode,
                ],
                healthUnitId: $auditHealthUnitId,
            );

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        DB::connection('core')->transaction(function () use ($request, $user, $auditHealthUnitId): void {
            $user->forceFill(['last_login_at' => now()])->save();
            $this->recordAuditEvent->execute(
                'user.logged_in',
                $request,
                $user,
                healthUnitId: $auditHealthUnitId,
            );
        });

        Auth::login($user);
        $request->session()->regenerate();
        $this->sessions->enforce($user);
        $request->clearRateLimit();

        if ($selectedHealthUnit instanceof HealthUnit) {
            $request->session()->put('active_health_unit_id', $selectedHealthUnit->getKey());
            $request->session()->put('authenticated_organization_id', $user->organization_id);
        } else {
            $request->session()->forget(['active_health_unit_id', 'authenticated_organization_id']);
        }

        return $user;
    }

    private function resolveHealthUnit(User $user, ?HealthUnit $targetHealthUnit): ?HealthUnit
    {
        if ($user->isPlatformAdministrator()) {
            return null;
        }

        if ($targetHealthUnit !== null) {
            // O CNES digitado identificou uma unidade especifica: so entra
            // nela se o usuario realmente tiver esse vinculo. Nunca cai de
            // volta para a unidade padrao aqui - isso poderia colocar o
            // profissional silenciosamente numa unidade diferente da que ele
            // pediu, o exato problema de confusao entre plantoes que este
            // fluxo existe para evitar.
            $hasAccess = $user->healthUnits()
                ->whereKey($targetHealthUnit->getKey())
                ->where('health_units.is_active', true)
                ->exists();

            return $hasAccess ? $targetHealthUnit : null;
        }

        return $user->healthUnits()
            ->where('health_units.organization_id', $user->organization_id)
            ->where('health_units.is_active', true)
            ->whereHas('organization', fn ($query) => $query->where('is_active', true))
            ->orderByRaw('health_units.id = ? desc', [$user->default_health_unit_id])
            ->first();
    }
}
