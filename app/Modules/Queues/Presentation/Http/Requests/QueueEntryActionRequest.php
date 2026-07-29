<?php

declare(strict_types=1);

namespace App\Modules\Queues\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class QueueEntryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $route = (string) $this->route()?->getName();
        $rules = ['version' => ['required', 'integer', 'min:1']];

        if (in_array($route, ['queue-entries.call', 'queue-entries.recall'], true)) {
            $rules['service_point'] = ['required', 'string', 'exists:service_points,public_id'];
        }
        if ($route === 'queue-entries.absent') {
            $rules['confirmation'] = ['accepted'];
            $rules['reason'] = ['required', 'string', 'min:3', 'max:255'];
        }
        if ($route === 'queue-entries.return') {
            $rules['reason'] = ['required', 'string', 'min:3', 'max:255'];
        }
        if ($route === 'queue-entries.transfer') {
            $rules['destination_queue'] = ['required', 'string', 'exists:queues,public_id'];
            $rules['reason'] = ['required', 'string', 'min:3', 'max:255'];
            $rules['preserve_priority'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['preserve_priority' => $this->boolean('preserve_priority', true)]);
    }
}
