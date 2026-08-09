<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Services;

use App\Modules\Documents\Application\Contracts\PdfRenderer;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Documents\Infrastructure\Eloquent\DocumentVersion;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * @phpstan-type RenderedVersion array{
 *     version_number: int,
 *     structured_content: array<string, mixed>,
 *     rendered_html_path: string,
 *     pdf_path: string,
 *     file_hash: string,
 *     size_bytes: int,
 *     html: string,
 *     pdf: string
 * }
 */
final readonly class ClinicalDocumentVersionService
{
    public function __construct(private PdfRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $content
     * @return RenderedVersion
     */
    public function render(
        ClinicalDocument $document,
        array $content,
        int $versionNumber,
    ): array {
        $document->loadMissing([
            'healthUnit.organization',
            'patient.identifiers',
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

        return [
            'version_number' => $versionNumber,
            'structured_content' => $content,
            'rendered_html_path' => "{$directory}/v{$versionNumber}.html",
            'pdf_path' => "{$directory}/v{$versionNumber}.pdf",
            'file_hash' => hash('sha256', $pdf),
            'size_bytes' => strlen($pdf),
            'html' => $html,
            'pdf' => $pdf,
        ];
    }

    /** @param RenderedVersion $rendered */
    public function persist(
        ClinicalDocument $document,
        array $rendered,
        User $user,
        ?string $reason = null,
    ): DocumentVersion {
        $disk = Storage::disk('local_private');
        if (! $disk->put($rendered['rendered_html_path'], $rendered['html'])) {
            throw new RuntimeException('Não foi possível armazenar o HTML do documento.');
        }
        if (! $disk->put($rendered['pdf_path'], $rendered['pdf'])) {
            throw new RuntimeException('Não foi possível armazenar o PDF do documento.');
        }

        return $document->versions()->create([
            'version_number' => $rendered['version_number'],
            'structured_content' => $rendered['structured_content'],
            'rendered_html_path' => $rendered['rendered_html_path'],
            'pdf_path' => $rendered['pdf_path'],
            'file_hash' => $rendered['file_hash'],
            'size_bytes' => $rendered['size_bytes'],
            'created_by' => $user->getKey(),
            'reason' => $reason,
        ]);
    }

    /** @param RenderedVersion $rendered */
    public function discardIfUnpersisted(array $rendered): void
    {
        $persisted = DocumentVersion::query()
            ->where('rendered_html_path', $rendered['rendered_html_path'])
            ->where('pdf_path', $rendered['pdf_path'])
            ->exists();
        if (! $persisted) {
            Storage::disk('local_private')->delete([
                $rendered['rendered_html_path'],
                $rendered['pdf_path'],
            ]);
        }
    }
}
