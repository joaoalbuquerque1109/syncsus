<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Services\LaboratoryOrderAccessService;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\ExamResult;
use App\Modules\Patients\Application\Services\PatientIdentifierProtector;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LaboratoryResultController extends Controller
{
    public function index(Request $request, PatientIdentifierProtector $protector, LaboratoryOrderAccessService $access): View
    {
        [$user, $unit] = $this->context($request);
        $filters = $request->validate([
            'patient' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $term = trim((string) ($filters['patient'] ?? ''));
        $patientIds = [];
        if ($term !== '') {
            $normalized = NormalizesBrazilianData::name($term);
            $digits = NormalizesBrazilianData::digits($term);
            $fingerprints = $digits === null ? [] : $protector->fingerprintsForValue($digits);
            $patientQuery = Patient::query()
                ->where('organization_id', $unit->organization_id)
                ->where(function ($query) use ($normalized, $digits, $fingerprints): void {
                    $query->where('normalized_name', 'like', "%{$normalized}%")
                        ->orWhere('medical_record_number', 'like', "%{$normalized}%");
                    if ($digits !== null) {
                        $query->orWhereHas('identifiers', fn ($query) => $query
                            ->whereIn('fingerprint', $fingerprints)
                            ->orWhere(fn ($query) => $query
                                ->whereNull('fingerprint')
                                ->where('normalized_value', $digits)));
                    }
                });
            $patientIds = $patientQuery->pluck('id')->all();
        }

        $query = $access->applyVisibilityScope(ExamOrder::query(), $user, $unit)
            ->with(['encounter', 'items.result'])
            ->when($term !== '', fn ($query) => $query->whereHas(
                'encounter',
                fn ($encounters) => $encounters->whereIn('patient_id', $patientIds),
            ))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('requested_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('requested_at', '<=', $date))
            ->latest('requested_at');

        $orders = $query->paginate(15)->withQueryString();
        $this->hydrateCoreReferences($orders->getCollection());

        return view('laboratory.results.index', ['orders' => $orders, 'filters' => $filters]);
    }

    public function print(
        Request $request,
        ExamResult $result,
        RecordAuditEventAction $audit,
        LaboratoryOrderAccessService $access,
    ): StreamedResponse {
        [$user, $unit] = $this->context($request);
        $result->loadMissing('item.order.encounter');
        $order = $result->item->order;
        abort_unless($access->canView($user, $order, $unit), 403);
        abort_unless($result->result_pdf_path !== null && $result->result_pdf_disk !== null, 404);
        abort_unless(Storage::disk($result->result_pdf_disk)->exists($result->result_pdf_path), 404);

        $audit->execute(
            'laboratory.result_pdf_viewed',
            $request,
            $user,
            ['exam_order_item_id' => $result->exam_order_item_id],
            (int) $unit->getKey(),
            (int) $order->encounter->patient_id,
            (int) $order->encounter_id,
        );

        $filename = 'laudo_'.$result->public_id.'.pdf';

        // Storage::response() (não download()) mantém o PDF inline no navegador,
        // igual a como o médico já visualiza os demais exames em nova aba.
        $response = Storage::disk($result->result_pdf_disk)->response(
            $result->result_pdf_path,
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @return array{User, HealthUnit} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);

        return [$user, $unit];
    }

    /** @param Collection<int, ExamOrder> $orders */
    private function hydrateCoreReferences(Collection $orders): void
    {
        $patients = Patient::query()->with('identifiers')
            ->whereKey($orders->pluck('encounter.patient_id')->filter()->unique()->all())
            ->get()->keyBy(fn (Patient $patient): int => (int) $patient->getKey());
        $users = User::query()
            ->whereKey($orders->pluck('requested_by')->filter()->unique()->all())
            ->get()->keyBy(fn (User $user): string => (string) $user->getKey());
        foreach ($orders as $order) {
            $order->encounter->setRelation('patient', $patients->get((int) $order->encounter->patient_id));
            $order->setRelation('requestedBy', $users->get((string) $order->requested_by));
        }
    }
}
