<?php

declare(strict_types=1);

use App\Modules\Laboratory\Application\Actions\DispatchPendingLaboratoryTransmissionsAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('synclab:dispatch-pending', function (
    DispatchPendingLaboratoryTransmissionsAction $action,
): void {
    $this->info($action->execute().' transmissao(oes) Synclab encaminhada(s) para a fila.');
})->purpose('Encaminha requisicoes laboratoriais pendentes para o worker Synclab.');

Schedule::command('synclab:dispatch-pending')
    ->everyMinute()
    ->withoutOverlapping();
