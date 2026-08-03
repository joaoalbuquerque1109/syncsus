<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Actions\CancelLaboratoryOrderAction;
use App\Modules\Laboratory\Presentation\Http\Requests\CancelLaboratoryOrderRequest;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class LaboratoryOrderController extends Controller
{
    public function index(Request $request): View
    {
        $unit = $this->unit($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:pending,cancelled'],
            'origin' => ['nullable', 'in:reception,medical'],
        ]);
        $search = trim(str_replace(['%', '_'], '', (string) ($filters['q'] ?? '')));
        $orders = ExamOrder::query()
            ->with([
                'encounter.patient', 'requestedBy.professionalProfile.registrations',
                'createdBy', 'items', 'laboratoryTransmissions',
            ])
            ->where('organization_id', $unit->organization_id)
            ->where('health_unit_id', $unit->getKey())
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['origin'] ?? null, fn ($query, $origin) => $query->where('origin', $origin))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('id', ctype_digit($search) ? (int) $search : 0)
                        ->orWhereHas('encounter.patient', fn ($patient) => $patient
                            ->where('full_name', 'like', '%'.$search.'%')
                            ->orWhere('medical_record_number', 'like', '%'.$search.'%'))
                        ->orWhereHas('requestedBy', fn ($user) => $user->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest('requested_at')
            ->paginate(10)
            ->withQueryString();

        return view('laboratory.orders.index', compact('orders', 'filters'));
    }

    public function show(Request $request, ExamOrder $order): View
    {
        $unit = $this->unit($request);
        $this->ensureUnit($order, $unit);

        return view('laboratory.orders.show', [
            'order' => $order->load([
                'encounter.patient.identifiers', 'requestedBy.professionalProfile.registrations',
                'createdBy', 'cancelledBy', 'items.laboratoryExam', 'laboratoryTransmissions.integration',
            ]),
        ]);
    }

    public function cancel(
        CancelLaboratoryOrderRequest $request,
        ExamOrder $order,
        CancelLaboratoryOrderAction $action,
    ): RedirectResponse {
        $unit = $this->unit($request);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $action->execute($order, (string) $request->input('reason'), $user, $unit, $request);

        return redirect()->route('laboratory.orders.index')->with('success', 'Requisição cancelada sem apagar o histórico.');
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }

    private function ensureUnit(ExamOrder $order, HealthUnit $unit): void
    {
        abort_unless(
            (int) $order->organization_id === (int) $unit->organization_id
            && (int) $order->health_unit_id === (int) $unit->getKey(),
            404,
        );
    }
}
