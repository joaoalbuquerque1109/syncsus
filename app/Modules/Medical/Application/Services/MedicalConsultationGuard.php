<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Validation\ValidationException;

final class MedicalConsultationGuard
{
    public function lockDraft(
        MedicalConsultation $consultation,
        User $user,
        HealthUnit $unit,
        int $expectedVersion,
    ): MedicalConsultation {
        $locked = MedicalConsultation::query()
            ->with('encounter')
            ->whereKey($consultation->getKey())
            ->whereHas('encounter', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
            ->lockForUpdate()
            ->firstOrFail();
        if ($locked->statusEnum() !== MedicalConsultationStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'O atendimento foi finalizado e não pode ser alterado. Registre um adendo.',
            ]);
        }
        if ($locked->professional_id !== $user->getKey()) {
            throw ValidationException::withMessages([
                'professional' => 'Somente o médico responsável pode alterar este atendimento.',
            ]);
        }
        if ($locked->version() !== $expectedVersion) {
            throw ValidationException::withMessages([
                'version' => 'O atendimento foi atualizado em outra sessão. Recarregue a página.',
            ]);
        }

        return $locked;
    }

    public function increment(MedicalConsultation $consultation): void
    {
        $consultation->increment('lock_version');
    }
}
