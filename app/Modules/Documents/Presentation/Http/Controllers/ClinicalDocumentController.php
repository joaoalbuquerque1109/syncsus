<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Application\Actions\CreateDocumentVersionAction;
use App\Modules\Documents\Application\Actions\IssueClinicalDocumentAction;
use App\Modules\Documents\Application\Actions\VoidClinicalDocumentAction;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Documents\Presentation\Http\Requests\CreateDocumentVersionRequest;
use App\Modules\Documents\Presentation\Http\Requests\IssueClinicalDocumentRequest;
use App\Modules\Documents\Presentation\Http\Requests\VoidClinicalDocumentRequest;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ClinicalDocumentController extends Controller
{
    public function store(
        IssueClinicalDocumentRequest $request,
        MedicalConsultation $consultation,
        IssueClinicalDocumentAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureConsultationUnit($consultation, $unit);
        $document = $action->execute($consultation, $request->validated(), $user, $unit, $request);

        return redirect()->route('documents.show', $document)->with('success', 'Documento emitido e PDF armazenado com segurança.');
    }

    public function show(Request $request, ClinicalDocument $document): View
    {
        [, $unit] = $this->context($request);
        $this->ensureDocumentUnit($document, $unit);
        $document->load(['patient', 'encounter', 'healthUnit.organization', 'creator', 'voidedBy', 'currentVersion.creator', 'versions.creator']);

        return view('documents.show', ['document' => $document]);
    }

    public function download(
        Request $request,
        ClinicalDocument $document,
        RecordAuditEventAction $audit,
    ): BinaryFileResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureDocumentUnit($document, $unit);
        $document->loadMissing(['currentVersion', 'patient']);
        abort_unless($document->currentVersion !== null, 404);
        $path = $document->currentVersion->pdf_path;
        abort_unless(Storage::disk('local_private')->exists($path), 404);
        $audit->execute(
            'document.downloaded',
            $request,
            $user,
            ['document' => $document->public_id, 'version' => (int) $document->currentVersion->version_number],
            (int) $unit->getKey(),
            (int) $document->patient_id,
            (int) $document->encounter_id,
        );
        $filename = str($document->title)->slug().'_v'.$document->currentVersion->version_number.'.pdf';

        $response = response()->download(
            Storage::disk('local_private')->path($path),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function newVersion(
        CreateDocumentVersionRequest $request,
        ClinicalDocument $document,
        CreateDocumentVersionAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureDocumentUnit($document, $unit);
        $action->execute($document, $request->validated(), $user, $unit, $request);

        return back()->with('success', 'Nova versão emitida; as versões anteriores foram preservadas.');
    }

    public function void(
        VoidClinicalDocumentRequest $request,
        ClinicalDocument $document,
        VoidClinicalDocumentAction $action,
    ): RedirectResponse {
        [$user, $unit] = $this->context($request);
        $this->ensureDocumentUnit($document, $unit);
        $action->execute($document, (string) $request->input('reason'), $user, $unit, $request);

        return back()->with('success', 'Documento anulado. O histórico e os arquivos foram preservados.');
    }

    public function verify(string $verificationCode): View
    {
        $document = ClinicalDocument::query()
            ->with(['healthUnit.organization', 'patient', 'currentVersion'])
            ->where('verification_code', $verificationCode)
            ->firstOrFail();

        return view('documents.verify', ['document' => $document]);
    }

    /** @return array{User, HealthUnit} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);

        return [$user, $unit];
    }

    private function ensureConsultationUnit(MedicalConsultation $consultation, HealthUnit $unit): void
    {
        abort_unless($consultation->encounter()->where('health_unit_id', $unit->getKey())->exists(), 404);
    }

    private function ensureDocumentUnit(ClinicalDocument $document, HealthUnit $unit): void
    {
        abort_unless($document->health_unit_id === $unit->getKey(), 404);
    }
}
