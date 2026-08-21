<?php

declare(strict_types=1);

namespace App\Modules\Administration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Application\Actions\ProvisionTenantAction;
use App\Modules\Administration\Application\Actions\ToggleHealthUnitActiveAction;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Presentation\Http\Requests\ProvisionTenantRequest;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

final class TenantProvisioningController extends Controller
{
    public function index(Request $request): View
    {
        $this->actor($request);

        return view('administration.tenants.index', [
            'organizations' => Organization::query()
                ->with(['healthUnits', 'healthUnits.users.roles'])
                ->orderBy('trade_name')
                ->get(),
        ]);
    }

    public function store(
        ProvisionTenantRequest $request,
        ProvisionTenantAction $provision,
        RecordAuditEventAction $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $result = $provision->execute($request->validated(), $actor);
        $unit = $result['health_unit'];

        Cache::forget("syncsus:organization:{$result['organization']->getKey()}:has-manager");
        $audit->execute('organization.provisioned', $request, $actor, [
            'organization' => $result['organization']->public_id,
            'cnes' => $result['organization']->tenantIdentifier(),
            'manager' => $result['manager']->public_id,
        ], (int) $unit->getKey());

        return redirect()->route('administration.tenants.index')
            ->with('success', 'Unidade registrada. O banco dedicado está em provisionamento e a unidade permanecerá inativa até a validação final.');
    }

    public function toggleActive(
        Request $request,
        HealthUnit $healthUnit,
        ToggleHealthUnitActiveAction $toggleActive,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $unit = $toggleActive->execute($healthUnit, $actor, $request);

        return redirect()->route('administration.tenants.index')->with(
            'success',
            $unit->is_active ? 'Unidade ativada.' : 'Unidade desativada.',
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isPlatformAdministrator(), 403);

        return $actor;
    }
}
