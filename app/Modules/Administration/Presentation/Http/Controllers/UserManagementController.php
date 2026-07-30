<?php

declare(strict_types=1);

namespace App\Modules\Administration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Application\Actions\ResetUserPasswordAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

final class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q'));
        $unit = $this->unit($request);

        return view('administration.users.index', [
            'users' => User::query()
                ->where('organization_id', $unit->organization_id)
                ->with(['roles', 'healthUnits', 'defaultHealthUnit', 'professionalProfile'])
                ->when($term !== '', fn ($query) => $query->where(
                    fn ($query) => $query->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%"),
                ))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'roles' => Role::query()->where('name', '!=', 'administrator')->orderBy('name')->get(),
            'healthUnits' => HealthUnit::query()
                ->where('organization_id', $unit->organization_id)
                ->where('is_active', true)->orderBy('name')->get(),
            'term' => $term,
        ]);
    }

    public function store(Request $request, RecordAuditEventAction $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        $unit = $this->unit($request);
        $data = $this->validateUser($request);
        $hasManager = User::query()
            ->where('organization_id', $unit->organization_id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'manager'))
            ->exists();
        if (! $hasManager && ! in_array('manager', $data['roles'], true)) {
            throw ValidationException::withMessages([
                'roles' => 'Cadastre primeiro o gestor obrigatório desta organização.',
            ]);
        }
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
            'organization_id' => $unit->organization_id,
            'default_health_unit_id' => $data['default_health_unit_id'],
            'is_active' => $request->boolean('is_active'),
            'must_change_password' => true,
        ]);
        $this->syncAccess($user, $data);
        $this->forgetManagerCache((int) $unit->organization_id);
        $audit->execute('user.created', $request, $actor, [
            'managed_user' => $user->public_id,
            'roles' => $data['roles'],
        ], (int) $unit->getKey());

        return back()->with('success', 'Usuário criado. A senha deverá ser alterada no primeiro acesso.');
    }

    public function update(
        Request $request,
        User $managedUser,
        RecordAuditEventAction $audit,
        ResetUserPasswordAction $resetPassword,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $unit = $this->unit($request);
        abort_unless((int) $managedUser->organization_id === (int) $unit->organization_id, 404);
        $data = $this->validateUser($request, $managedUser);
        $active = $request->boolean('is_active');
        $removesManager = $managedUser->hasRole('manager')
            && (! $active || ! in_array('manager', $data['roles'], true));
        if ($removesManager) {
            $otherManagers = User::query()
                ->where('organization_id', $unit->organization_id)
                ->where('is_active', true)
                ->where('id', '!=', $managedUser->getKey())
                ->whereHas('roles', fn ($query) => $query->where('name', 'manager'))
                ->exists();
            abort_unless($otherManagers, 422, 'A organização deve manter ao menos um gestor ativo.');
        }
        abort_if($actor->is($managedUser) && ! $active, 422, 'Você não pode desativar o próprio usuário.');

        $attributes = [
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'default_health_unit_id' => $data['default_health_unit_id'],
            'is_active' => $active,
        ];
        $managedUser->update($attributes);
        $this->syncAccess($managedUser, $data);
        $this->forgetManagerCache((int) $unit->organization_id);
        if (filled($data['password'] ?? null)) {
            $resetPassword->execute(
                $actor,
                $managedUser,
                (string) $data['password'],
                (int) $unit->getKey(),
                'web_administration',
            );
        } elseif (! $active) {
            DB::table('sessions')->where('user_id', $managedUser->getKey())->delete();
        }
        $audit->execute('user.updated', $request, $actor, [
            'managed_user' => $managedUser->public_id,
            'roles' => $data['roles'],
            'password_reset' => filled($data['password'] ?? null),
        ], (int) $unit->getKey());

        return back()->with('success', 'Usuário e acessos atualizados.');
    }

    /** @return array<string, mixed> */
    private function validateUser(Request $request, ?User $user = null): array
    {
        $unit = $this->unit($request);

        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users')->where('organization_id', $unit->organization_id)
                    ->ignore($user?->getKey()),
            ],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'required', 'string', Rule::notIn(['administrator']), Rule::exists('roles', 'name'),
            ],
            'health_unit_ids' => ['required', 'array', 'min:1'],
            'health_unit_ids.*' => [
                'integer',
                Rule::exists('health_units', 'id')
                    ->where('organization_id', $unit->organization_id)
                    ->where('is_active', true),
            ],
            'default_health_unit_id' => ['required', 'integer', Rule::in(array_map('intval', $request->input('health_unit_ids', [])))],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function syncAccess(User $user, array $data): void
    {
        $user->syncRoles($data['roles']);
        $user->healthUnits()->sync($data['health_unit_ids']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->can('administration.manage'), 403);

        return $actor;
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }

    private function forgetManagerCache(int $organizationId): void
    {
        Cache::forget("syncsus:organization:{$organizationId}:has-manager");
    }
}
