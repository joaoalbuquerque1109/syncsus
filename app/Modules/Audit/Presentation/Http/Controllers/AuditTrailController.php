<?php

declare(strict_types=1);

namespace App\Modules\Audit\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Queries\AuditTrailQuery;
use App\Modules\Audit\Presentation\Http\Requests\AuditTrailRequest;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\View\View;

final class AuditTrailController extends Controller
{
    public function __invoke(AuditTrailRequest $request, AuditTrailQuery $trail): View
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);
        $filters = $request->validated();

        return view('audit.index', [
            'events' => $trail->events($unit, $filters),
            'accesses' => $trail->accesses($unit, $filters),
            'summary' => $trail->summary($unit, $filters),
            'actions' => $trail->actions($unit),
            'accessTypes' => $trail->accessTypes($unit),
            'filters' => $filters,
            'users' => User::query()
                ->whereHas('healthUnits', fn ($query) => $query->whereKey($unit->getKey()))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
