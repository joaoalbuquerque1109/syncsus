<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Documents\Application\Actions\IssueMedicalCertificateAction;
use App\Modules\Documents\Presentation\Http\Requests\IssueMedicalCertificateRequest;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Http\RedirectResponse;

final class MedicalCertificateController extends Controller
{
    public function store(
        IssueMedicalCertificateRequest $request,
        MedicalConsultation $consultation,
        IssueMedicalCertificateAction $action,
    ): RedirectResponse {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);
        abort_unless($consultation->encounter()->where('health_unit_id', $unit->getKey())->exists(), 404);
        if ($consultation->statusEnum()->value === 'draft') {
            abort_unless($user->canManageClinicalRecordOwnedBy($consultation->professional_id), 403);
        }

        $certificate = $action->execute($consultation, $request->validated(), $user, $unit, $request);

        return redirect()->route('medical.show', ['consultation' => $consultation, 'tab' => 'certificates'])
            ->with('success', "Atestado {$certificate->public_id} emitido e PDF armazenado com segurança.");
    }
}
