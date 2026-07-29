<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Actions;

use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientAccessLog;
use Illuminate\Http\Request;

final class LogPatientAccessAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    public function execute(Request $request, User $user, Patient $patient, int $healthUnitId, string $type): void
    {
        PatientAccessLog::query()->create([
            'user_id' => $user->getKey(),
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $healthUnitId,
            'access_type' => $type,
            'purpose' => 'assistência',
            'route_name' => (string) $request->route()?->getName(),
            'occurred_at' => now(),
        ]);
        $this->audit->execute(
            'patient.viewed',
            $request,
            $user,
            ['access_type' => $type],
            $healthUnitId,
            (int) $patient->getKey(),
        );
    }
}
