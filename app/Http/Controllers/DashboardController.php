<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Professionals\Application\Services\MedicalDutyService;
use App\Modules\Reports\Application\Queries\OperationalDashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        OperationalDashboardQuery $dashboard,
        MedicalDutyService $medicalDuty,
    ): View {
        $unit = $this->unit($request);

        return view('dashboard.index', [
            'metrics' => $dashboard->metrics($unit),
            'activeEncounters' => $dashboard->activeEncounters($unit, $request->user()?->can('patients.view') ?? false),
            'availableDoctors' => $medicalDuty->availableDoctors($unit),
        ]);
    }

    public function metrics(Request $request, OperationalDashboardQuery $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->metrics($this->unit($request))])
            ->header('Cache-Control', 'no-store, private');
    }

    public function activeEncounters(Request $request, OperationalDashboardQuery $dashboard): JsonResponse
    {
        $unit = $this->unit($request);

        return response()->json([
            'data' => $dashboard->activeEncounters($unit, $request->user()?->can('patients.view') ?? false),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function state(Request $request, OperationalDashboardQuery $dashboard): JsonResponse
    {
        $unit = $this->unit($request);

        return response()->json([
            'data' => [
                'metrics' => $dashboard->metrics($unit),
                'active_encounters' => $dashboard->activeEncounters(
                    $unit,
                    $request->user()?->can('patients.view') ?? false,
                ),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }
}
