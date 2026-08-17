<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Services;

use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use Illuminate\Database\Eloquent\Collection;

final class CatalogReader
{
    public function exam(?string $publicId, mixed $legacyId = null): ?Exam
    {
        if (filled($publicId)) {
            return Exam::query()->where('public_id', $publicId)->first();
        }

        return $legacyId === null ? null : Exam::query()->find($legacyId);
    }

    /**
     * @param  list<string>  $publicIds
     * @return Collection<int, Exam>
     */
    public function exams(array $publicIds): Collection
    {
        return Exam::query()->whereIn('public_id', $publicIds)->get();
    }

    /** @return list<int> */
    public function activeExamIdsForOrganization(int $organizationId): array
    {
        return Exam::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<string> */
    public function activeExamPublicIdsForOrganization(int $organizationId): array
    {
        return Exam::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->pluck('public_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }
}
