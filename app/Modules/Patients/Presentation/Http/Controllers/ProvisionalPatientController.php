<?php

declare(strict_types=1);

namespace App\Modules\Patients\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Application\Actions\CreateProvisionalPatientAction;
use App\Modules\Patients\Presentation\Http\Requests\ProvisionalPatientRequest;
use App\Modules\Reception\Application\Services\ReceptionDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class ProvisionalPatientController extends Controller
{
    public function create(): View
    {
        return view('patients.provisional');
    }

    public function store(
        ProvisionalPatientRequest $request,
        CreateProvisionalPatientAction $action,
        RecordAuditEventAction $audit,
        ReceptionDraftService $drafts,
    ): RedirectResponse {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        $patient = $action->execute($request->validated(), $user, (int) $unit->getKey());
        $audit->execute('patient.provisional_created', $request, $user, [], (int) $unit->getKey(), (int) $patient->getKey());

        return redirect()->route('reception.create', ['patient' => $patient->public_id])
            ->withInput($drafts->pull($request, (int) $unit->getKey()))
            ->with('warning', 'Cadastro provisório criado. Complete a identificação assim que possível.');
    }
}
