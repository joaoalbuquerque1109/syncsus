<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Actions\CancelLaboratoryOrderAction;
use App\Modules\Laboratory\Application\Actions\RetryLaboratoryOrderTransmissionAction;
use App\Modules\Laboratory\Application\Services\LaboratoryOrderAccessService;
use App\Modules\Laboratory\Presentation\Http\Requests\CancelLaboratoryOrderRequest;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class LaboratoryOrderController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->ensureCanListOrders($request);
        $unit = $this->unit($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:pending,cancelled'],
            'origin' => ['nullable', 'in:reception,medical'],
        ]);
        $forcedOrigin = $user->isPlatformAdministrator() ? null : 'reception';
        $search = trim(str_replace(['%', '_'], '', (string) ($filters['q'] ?? '')));
        $patientIds = $search === '' ? [] : Patient::query()
            ->where('full_name', 'like', '%'.$search.'%')
            ->orWhere('medical_record_number', 'like', '%'.$search.'%')
            ->pluck('id')->all();
        $userIds = $search === '' ? [] : User::query()
            ->where('name', 'like', '%'.$search.'%')
            ->pluck('id')->all();
        $orders = ExamOrder::query()
            ->with([
                'encounter', 'items', 'laboratoryTransmissions',
            ])
            ->where('organization_id', $unit->organization_id)
            ->where('health_unit_id', $unit->getKey())
            ->when($forcedOrigin !== null, fn ($query) => $query->where('origin', $forcedOrigin))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($forcedOrigin === null && ($filters['origin'] ?? null), fn ($query, $origin) => $query->where('origin', $origin))
            ->when($search !== '', function ($query) use ($search, $patientIds, $userIds): void {
                $query->where(function ($inner) use ($search, $patientIds, $userIds): void {
                    $inner->where('id', ctype_digit($search) ? (int) $search : 0);
                    if ($patientIds !== []) {
                        $inner->orWhereHas('encounter', fn ($encounters) => $encounters->whereIn('patient_id', $patientIds));
                    }
                    if ($userIds !== []) {
                        $inner->orWhereIn('requested_by', $userIds);
                    }
                });
            })
            ->latest('requested_at')
            ->paginate(10)
            ->withQueryString();
        $this->hydrateCoreReferences($orders->getCollection());

        return view('laboratory.orders.index', compact('orders', 'filters'));
    }

    public function show(
        Request $request,
        ExamOrder $order,
        LaboratoryOrderAccessService $access,
    ): View {
        $unit = $this->unit($request);
        $this->ensureUnit($order, $unit);
        $user = $request->user();
        abort_unless($user instanceof User && $access->canView($user, $order, $unit), 403);

        $order->load([
            'encounter', 'items.laboratoryExam', 'items.result',
            'laboratoryTransmissions.integration', 'laboratoryTransmissions.attempts',
        ]);
        $this->hydrateCoreReferences(new Collection([$order]));

        return view('laboratory.orders.show', [
            'order' => $order,
            'backUrl' => $this->contextualReturnUrl($order, $user),
            'canViewClinicalDetails' => $access->canViewClinicalDetails($user),
        ]);
    }

    public function cancel(
        CancelLaboratoryOrderRequest $request,
        ExamOrder $order,
        CancelLaboratoryOrderAction $action,
        LaboratoryOrderAccessService $access,
    ): RedirectResponse {
        $unit = $this->unit($request);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->ensureUnit($order, $unit);
        abort_unless($access->canCancel($user, $order, $unit), 403);
        $action->execute($order, (string) $request->input('reason'), $user, $unit, $request);

        return redirect()->to($this->contextualReturnUrl($order, $user))
            ->with('success', 'Requisição cancelada sem apagar o histórico.');
    }

    public function retry(
        Request $request,
        ExamOrder $order,
        RetryLaboratoryOrderTransmissionAction $action,
    ): RedirectResponse {
        $unit = $this->unit($request);
        $this->ensureUnit($order, $unit);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $action->execute($order, $unit, $user, $request);

        return redirect()->route('laboratory.orders.show', $order)->with(
            'success',
            'Requisição encaminhada novamente para a fila do Synclab.',
        );
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }

    private function ensureCanListOrders(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User && ($user->isPlatformAdministrator() || $user->hasRole('receptionist')),
            403,
        );

        return $user;
    }

    private function ensureUnit(ExamOrder $order, HealthUnit $unit): void
    {
        abort_unless(
            (int) $order->organization_id === (int) $unit->organization_id
            && (int) $order->health_unit_id === (int) $unit->getKey(),
            404,
        );
    }

    private function contextualReturnUrl(ExamOrder $order, User $user): string
    {
        if ($user->isPlatformAdministrator()) {
            return route('laboratory.orders.index');
        }

        if ($order->medical_consultation_id !== null) {
            return route('medical.show', [
                'consultation' => $order->medical_consultation_id,
                'tab' => 'exams',
            ]);
        }

        if ($order->origin === 'reception') {
            return route('reception.receipt', $order->encounter_id);
        }

        return $user->hasRole('manager')
            ? route('administration.synclab.edit')
            : route('dashboard');
    }

    /** @param Collection<int, ExamOrder> $orders */
    private function hydrateCoreReferences(Collection $orders): void
    {
        $patients = Patient::query()->with('identifiers')
            ->whereKey($orders->pluck('encounter.patient_id')->filter()->unique()->all())
            ->get()->keyBy(fn (Patient $patient): int => (int) $patient->getKey());
        $users = User::query()->with('professionalProfile.registrations')
            ->whereKey(collect($orders->pluck('requested_by'))
                ->merge($orders->pluck('created_by'))
                ->merge($orders->pluck('cancelled_by'))->filter()->unique()->all())
            ->get()->keyBy(fn (User $user): string => (string) $user->getKey());
        foreach ($orders as $order) {
            $order->encounter->setRelation('patient', $patients->get((int) $order->encounter->patient_id));
            $order->setRelation('requestedBy', $users->get((string) $order->requested_by));
            $order->setRelation('createdBy', $users->get((string) $order->created_by));
            $order->setRelation('cancelledBy', $users->get((string) $order->cancelled_by));
        }
    }
}
