<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Documents\Application\Actions\GenerateSourceClinicalDocumentAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use App\Modules\Medical\Infrastructure\Eloquent\Referral;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SourceClinicalDocumentController extends Controller
{
    public function prescription(Request $request, MedicalConsultation $consultation, Prescription $prescription, GenerateSourceClinicalDocumentAction $action): RedirectResponse
    {
        [$user, $unit] = $this->context($request, $consultation);
        $document = $action->prescription($consultation, $prescription, $user, $unit, $request);

        return redirect()->route('documents.show', $document)->with('success', 'PDF da prescrição gerado com segurança.');
    }

    public function examOrder(Request $request, MedicalConsultation $consultation, ExamOrder $order, GenerateSourceClinicalDocumentAction $action): RedirectResponse
    {
        [$user, $unit] = $this->context($request, $consultation);
        $document = $action->examOrder($consultation, $order, $user, $unit, $request);

        return redirect()->route('documents.show', $document)->with('success', 'PDF da solicitação de exames gerado com segurança.');
    }

    public function referral(Request $request, MedicalConsultation $consultation, Referral $referral, GenerateSourceClinicalDocumentAction $action): RedirectResponse
    {
        [$user, $unit] = $this->context($request, $consultation);
        $document = $action->referral($consultation, $referral, $user, $unit, $request);

        return redirect()->route('documents.show', $document)->with('success', 'PDF do encaminhamento gerado com segurança.');
    }

    /** @return array{User, HealthUnit} */
    private function context(Request $request, MedicalConsultation $consultation): array
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        abort_unless($consultation->encounter()->where('health_unit_id', $unit->getKey())->exists(), 404);
        if ($consultation->statusEnum()->value === 'draft') {
            abort_unless($user->canManageClinicalRecordOwnedBy($consultation->professional_id), 403);
        }

        return [$user, $unit];
    }
}
