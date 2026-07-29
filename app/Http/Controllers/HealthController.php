<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'service' => 'sync-sus']);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::select('select 1');
            $probe = '.health-check';
            Storage::disk('local_private')->put($probe, now()->toIso8601String());
            Storage::disk('local_private')->delete($probe);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready']);
    }
}
