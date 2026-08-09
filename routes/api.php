<?php

declare(strict_types=1);

use App\Modules\Laboratory\Presentation\Http\Controllers\SynclabResultWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/laboratory/synclab/results', SynclabResultWebhookController::class)
    ->middleware(['throttle:60,1', 'synclab.results'])
    ->name('api.v1.laboratory.synclab.results.store');
