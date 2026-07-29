<?php

declare(strict_types=1);

namespace App\Modules\Queues\Application\Services;

use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Domain\Enums\PanelIdentificationMode;

final class PanelPatientNameFormatter
{
    public function format(Patient $patient, PanelIdentificationMode $mode): ?string
    {
        if ($mode === PanelIdentificationMode::TicketOnly) {
            return null;
        }

        $source = $mode === PanelIdentificationMode::SocialFirstInitial && filled($patient->social_name)
            ? (string) $patient->social_name
            : (string) $patient->full_name;
        $parts = preg_split('/\s+/', trim($source)) ?: [];
        if ($parts === []) {
            return null;
        }

        $first = $parts[0];
        $last = $parts[count($parts) - 1];

        if (in_array($mode, [PanelIdentificationMode::FirstNameInitial, PanelIdentificationMode::SocialFirstInitial], true)) {
            return count($parts) > 1
                ? $first.' '.mb_substr($last, 0, 1).'.'
                : $first;
        }
        if ($mode === PanelIdentificationMode::FirstAndLast) {
            return count($parts) > 1 ? $first.' '.$last : $first;
        }

        return $source;
    }
}
