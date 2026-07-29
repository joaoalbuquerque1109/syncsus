<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Services;

use App\Modules\Documents\Application\Contracts\PdfRenderer;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Documents\Infrastructure\Eloquent\DocumentVersion;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

final readonly class ClinicalDocumentVersionService
{
    public function __construct(private PdfRenderer $renderer) {}

    /** @param array<string, mixed> $content */
    public function create(
        ClinicalDocument $document,
        array $content,
        User $user,
        int $versionNumber,
        ?string $reason = null,
    ): DocumentVersion {
        $document->loadMissing([
            'healthUnit.organization',
            'patient',
            'encounter',
            'consultation.professional.professionalProfile.registrations',
            'consultation.professional.professionalProfile.specialties',
        ]);
        $html = View::make('documents.pdf', [
            'document' => $document,
            'content' => $content,
            'versionNumber' => $versionNumber,
        ])->render();
        $pdf = $this->renderer->render($html);
        $directory = "documents/{$document->healthUnit->public_id}/{$document->public_id}";
        $htmlPath = "{$directory}/v{$versionNumber}.html";
        $pdfPath = "{$directory}/v{$versionNumber}.pdf";
        Storage::disk('local_private')->put($htmlPath, $html);
        Storage::disk('local_private')->put($pdfPath, $pdf);

        return $document->versions()->create([
            'version_number' => $versionNumber,
            'structured_content' => $content,
            'rendered_html_path' => $htmlPath,
            'pdf_path' => $pdfPath,
            'file_hash' => hash('sha256', $pdf),
            'size_bytes' => strlen($pdf),
            'created_by' => $user->getKey(),
            'reason' => $reason,
        ]);
    }
}
