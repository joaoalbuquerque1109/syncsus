<?php

declare(strict_types=1);

namespace App\Modules\Queues\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Application\Actions\ManageQueueEntryAction;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Queues\Presentation\Http\Requests\QueueEntryActionRequest;
use Illuminate\Http\JsonResponse;

final class QueueEntryController extends Controller
{
    public function call(QueueEntryActionRequest $request, QueueEntry $entry, ManageQueueEntryAction $action): JsonResponse
    {
        [$user, $unit] = $this->context($request);
        $result = $action->call($entry, $request->integer('version'), (string) $request->input('service_point'), $user, $unit, $request);

        return $this->response($result, 'Senha chamada no painel.');
    }

    public function recall(QueueEntryActionRequest $request, QueueEntry $entry, ManageQueueEntryAction $action): JsonResponse
    {
        [$user, $unit] = $this->context($request);
        $result = $action->call($entry, $request->integer('version'), (string) $request->input('service_point'), $user, $unit, $request, true);

        return $this->response($result, 'Senha rechamada no painel.');
    }

    public function start(QueueEntryActionRequest $request, QueueEntry $entry, ManageQueueEntryAction $action): JsonResponse
    {
        [$user, $unit] = $this->context($request);
        $result = $action->startService($entry, $request->integer('version'), $user, $unit, $request);

        return $this->response($result, 'Atendimento iniciado.');
    }

    public function absent(QueueEntryActionRequest $request, QueueEntry $entry, ManageQueueEntryAction $action): JsonResponse
    {
        [$user, $unit] = $this->context($request);
        $result = $action->markAbsent($entry, $request->integer('version'), (string) $request->input('reason'), $user, $unit, $request);

        return $this->response($result, 'Ausência registrada.');
    }

    public function returnToQueue(QueueEntryActionRequest $request, QueueEntry $entry, ManageQueueEntryAction $action): JsonResponse
    {
        [$user, $unit] = $this->context($request);
        $result = $action->returnToQueue($entry, $request->integer('version'), (string) $request->input('reason'), $user, $unit, $request);

        return $this->response($result, 'Senha devolvida à fila.');
    }

    public function transfer(QueueEntryActionRequest $request, QueueEntry $entry, ManageQueueEntryAction $action): JsonResponse
    {
        [$user, $unit] = $this->context($request);
        $result = $action->transfer(
            $entry,
            $request->integer('version'),
            (string) $request->input('destination_queue'),
            (string) $request->input('reason'),
            $request->boolean('preserve_priority', true),
            $user,
            $unit,
            $request,
        );

        return $this->response($result, 'Paciente transferido para a fila de destino.');
    }

    /** @return array{User, HealthUnit} */
    private function context(QueueEntryActionRequest $request): array
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);

        return [$user, $unit];
    }

    private function response(QueueEntry $entry, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'entry' => [
                'public_id' => $entry->public_id,
                'version' => $entry->version(),
                'status' => $entry->statusEnum()->value,
            ],
        ]);
    }
}
