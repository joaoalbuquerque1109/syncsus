<?php

declare(strict_types=1);

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Operations\Infrastructure\Eloquent\BackupLog;
use App\Modules\Operations\Infrastructure\Eloquent\BackupVerification;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class OperationsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isPlatformAdministrator(), 403);
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);
        $privatePath = storage_path('app/private');
        $freeBytes = disk_free_space($privatePath);

        return view('operations.index', [
            'latestBackup' => BackupLog::query()->latest('finished_at')->first(),
            'latestVerification' => BackupVerification::query()->with('verifiedBy')->latest('finished_at')->first(),
            'backups' => BackupLog::query()->latest('started_at')->limit(20)->get(),
            'verifications' => BackupVerification::query()->with('verifiedBy')->latest('started_at')->limit(20)->get(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'pendingJobs' => DB::table('jobs')->count(),
            'connectedPanels' => Panel::query()
                ->where('health_unit_id', $unit->getKey())
                ->where('last_heartbeat_at', '>=', now()->subMinute())->count(),
            'freeStorageBytes' => $freeBytes === false ? null : $freeBytes,
        ]);
    }
}
