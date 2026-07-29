<?php

declare(strict_types=1);

namespace App\Modules\Triage\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CompleteTriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('triage.complete') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'triage_protocol_id' => ['required', 'integer', 'exists:triage_protocols,id'],
            'triage_flowchart_id' => ['required', 'integer', 'exists:triage_flowcharts,id'],
            'triage_discriminator_id' => ['required', 'integer', 'exists:triage_discriminators,id'],
            'risk_level_id' => ['required', 'integer', 'exists:risk_levels,id'],
            'risk_justification' => ['required', 'string', 'min:10', 'max:4000'],
            'destination_queue_id' => ['required', 'integer', 'exists:queues,id'],
            'routing_notes' => ['nullable', 'string', 'max:4000'],
            'professional_confirmation' => ['accepted'],
        ];
    }
}
