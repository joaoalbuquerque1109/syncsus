<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Illuminate\Database\Eloquent\Collection;

final class ProfessionalOperationalAssignments
{
    /** @param list<int> $queueIds
     * @param  list<int>  $servicePointIds
     */
    public function sync(HealthProfessional $professional, array $queueIds, array $servicePointIds): void
    {
        $this->syncTable($professional, 'health_professional_queue', 'queue_id', $queueIds, false);
        $this->syncTable(
            $professional,
            'health_professional_service_point',
            'service_point_id',
            $servicePointIds,
            false,
        );
    }

    /** @param list<int> $queueIds
     * @param  list<int>  $servicePointIds
     */
    public function syncWithoutDetaching(
        HealthProfessional $professional,
        array $queueIds,
        array $servicePointIds,
    ): void {
        $this->syncTable($professional, 'health_professional_queue', 'queue_id', $queueIds, true);
        $this->syncTable(
            $professional,
            'health_professional_service_point',
            'service_point_id',
            $servicePointIds,
            true,
        );
    }

    /** @return list<int> */
    public function queueIds(HealthProfessional $professional): array
    {
        return $this->ids($professional, 'health_professional_queue', 'queue_id');
    }

    /** @return list<int> */
    public function servicePointIds(HealthProfessional $professional): array
    {
        return $this->ids($professional, 'health_professional_service_point', 'service_point_id');
    }

    /** @param Collection<int, HealthProfessional> $professionals */
    public function hydrate(Collection $professionals): void
    {
        if ($professionals->isEmpty()) {
            return;
        }

        $professionalIds = $professionals->modelKeys();
        $connection = $professionals->firstOrFail()->getConnection();
        $queueLinks = $connection->table('health_professional_queue')
            ->whereIn('health_professional_id', $professionalIds)->get();
        $pointLinks = $connection->table('health_professional_service_point')
            ->whereIn('health_professional_id', $professionalIds)->get();
        $queues = Queue::query()->whereKey($queueLinks->pluck('queue_id')->unique()->all())->get()->keyBy('id');
        $points = ServicePoint::query()
            ->whereKey($pointLinks->pluck('service_point_id')->unique()->all())->get()->keyBy('id');

        foreach ($professionals as $professional) {
            $queueIds = $queueLinks->where('health_professional_id', $professional->getKey())->pluck('queue_id');
            $pointIds = $pointLinks->where('health_professional_id', $professional->getKey())->pluck('service_point_id');
            $professional->setRelation(
                'queues',
                $queues->only($queueIds->all())->values(),
            );
            $professional->setRelation(
                'servicePoints',
                $points->only($pointIds->all())->values(),
            );
        }
    }

    public function hydrateOne(HealthProfessional $professional): HealthProfessional
    {
        $this->hydrate(new Collection([$professional]));

        return $professional;
    }

    /** @param list<int> $ids */
    private function syncTable(
        HealthProfessional $professional,
        string $table,
        string $relatedColumn,
        array $ids,
        bool $withoutDetaching,
    ): void {
        $ids = array_values(array_unique(array_map(static fn (int $id): int => $id, $ids)));
        $query = $professional->getConnection()->table($table)
            ->where('health_professional_id', $professional->getKey());
        if (! $withoutDetaching) {
            $ids === [] ? $query->delete() : $query->whereNotIn($relatedColumn, $ids)->delete();
        }

        $now = now();
        foreach ($ids as $id) {
            $professional->getConnection()->table($table)->insertOrIgnore([
                'health_professional_id' => $professional->getKey(),
                $relatedColumn => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return list<int> */
    private function ids(HealthProfessional $professional, string $table, string $column): array
    {
        return $professional->getConnection()->table($table)
            ->where('health_professional_id', $professional->getKey())
            ->pluck($column)
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
