<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Application\Services\ClinicalDocumentCidService;
use App\Modules\Documents\Application\Services\ClinicalDocumentVersionService;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Documents\Infrastructure\Eloquent\DocumentVersion;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** @phpstan-import-type RenderedVersion from ClinicalDocumentVersionService */
final readonly class CreateDocumentVersionAction
{
    public function __construct(
        private ClinicalDocumentVersionService $versions,
        private ClinicalDocumentCidService $cid,
        private RecordAuditEventAction $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        ClinicalDocument $document,
        array $data,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): DocumentVersion {
        $current = ClinicalDocument::query()
            ->with(['encounter', 'currentVersion', 'versions'])
            ->whereKey($document->getKey())
            ->where('health_unit_id', $unit->getKey())
            ->firstOrFail();
        $this->validateDocument($current);
        $number = ((int) $current->versions->max('version_number')) + 1;
        $content = $this->cid->normalize([
            ...($current->currentVersion->structured_content ?? []),
            ...collect($data)->except(['reason'])->all(),
        ]);
        $rendered = $this->versions->render($current, $content, $number);

        try {
            return DB::transaction(function () use ($current, $data, $user, $unit, $request, $number, $rendered): DocumentVersion {
                $locked = ClinicalDocument::query()
                    ->with('encounter')
                    ->whereKey($current->getKey())
                    ->where('health_unit_id', $unit->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->validateDocument($locked);
                $latestNumber = (int) ($locked->versions()->max('version_number') ?? 0);
                if ($latestNumber !== $number - 1) {
                    throw ValidationException::withMessages([
                        'version' => 'O documento foi alterado por outro usuário. Recarregue a página e tente novamente.',
                    ]);
                }

                $version = $this->versions->persist(
                    $locked,
                    $rendered,
                    $user,
                    (string) $data['reason'],
                );
                $locked->update(['current_version_id' => $version->getKey()]);
                $this->audit->execute(
                    'document.version_created',
                    $request,
                    $user,
                    ['document' => $locked->public_id, 'version' => $number],
                    (int) $unit->getKey(),
                    (int) $locked->patient_id,
                    (int) $locked->encounter_id,
                );

                return $version;
            });
        } catch (Throwable $exception) {
            $this->versions->discardIfUnpersisted($rendered);

            throw $exception;
        }
    }

    private function validateDocument(ClinicalDocument $document): void
    {
        if ($document->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'Documento anulado não pode receber nova versão.']);
        }
        if ($document->typeEnum()->isSourceManaged()) {
            throw ValidationException::withMessages([
                'status' => 'Este documento deve ser atualizado no registro clínico que o originou.',
            ]);
        }
    }
}
