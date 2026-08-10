<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.start') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'referral_type' => ['required', Rule::in(['internal', 'external'])],
            'specialty_id' => ['nullable', 'integer', 'exists:core.specialties,id'],
            'destination' => ['required', 'string', 'max:255'],
            'recipient_professional' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:5', 'max:4000'],
            'clinical_summary' => ['required', 'string', 'min:10', 'max:8000'],
            'priority' => ['required', Rule::in(['routine', 'urgent', 'emergency'])],
            'diagnostic_hypothesis' => ['nullable', 'string', 'max:2000'],
            'guidance' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
