<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class VoidClinicalDocumentAction
{
    public function __construct(private RecordAuditEventAction $audit) {}

    public function execute(
        ClinicalDocument $document,
        string $reason,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): ClinicalDocument {
        return DB::transaction(function () use ($document, $reason, $user, $unit, $request): ClinicalDocument {
            $locked = ClinicalDocument::query()
                ->whereKey($document->getKey())
                ->where('health_unit_id', $unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'O documento já está anulado.']);
            }
            $locked->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $user->getKey(),
                'void_reason' => $reason,
            ]);
            $this->audit->execute(
                'document.voided',
                $request,
                $user,
                ['document' => $locked->public_id, 'reason' => $reason],
                (int) $unit->getKey(),
                (int) $locked->patient_id,
                (int) $locked->encounter_id,
            );

            return $locked;
        });
    }
}
