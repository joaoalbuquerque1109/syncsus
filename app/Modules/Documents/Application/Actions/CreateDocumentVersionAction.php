<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Application\Services\ClinicalDocumentVersionService;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Documents\Infrastructure\Eloquent\DocumentVersion;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateDocumentVersionAction
{
    public function __construct(
        private ClinicalDocumentVersionService $versions,
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
        return DB::transaction(function () use ($document, $data, $user, $unit, $request): DocumentVersion {
            $locked = ClinicalDocument::query()
                ->with(['encounter', 'currentVersion', 'versions'])
                ->whereKey($document->getKey())
                ->where('health_unit_id', $unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'Documento anulado não pode receber nova versão.']);
            }
            $number = ((int) $locked->versions->max('version_number')) + 1;
            $content = [
                ...($locked->currentVersion->structured_content ?? []),
                ...collect($data)->except(['reason'])->all(),
            ];
            $version = $this->versions->create(
                $locked,
                $content,
                $user,
                $number,
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
    }
}
