<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Application\Actions\RegisterEmployeeAction;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Presentation\Http\Requests\EmployeeRegistrationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class EmployeeRegistrationController extends Controller
{
    public function create(): View
    {
        return view('auth.register', [
            'roles' => Role::query()
                ->whereIn('name', EmployeeRegistrationRequest::ALLOWED_ROLES)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(
        EmployeeRegistrationRequest $request,
        RegisterEmployeeAction $register,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $unit = $request->resolvedHealthUnit();
        abort_if($unit === null, 422, 'CNES não encontrado ou unidade inativa.');

        $user = $register->execute($request, $unit);

        $audit->execute('user.self_registered', $request, null, [
            'registered_user' => $user->public_id,
            'role' => $request->validated('role'),
        ], (int) $unit->getKey());

        return redirect()->route('login')
            ->with('success', 'Cadastro concluído. Você já pode entrar com seu e-mail e senha.');
    }
}
