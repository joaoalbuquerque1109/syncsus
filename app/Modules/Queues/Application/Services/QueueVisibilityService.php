<?php

declare(strict_types=1);

namespace App\Modules\Queues\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Application\Services\ProfessionalOperationalAssignments;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class QueueVisibilityService
{
    /** @var array<int, array{queues: list<int>, service_points: list<int>}> */
    private array $assignmentCache = [];

    public function __construct(private readonly ProfessionalOperationalAssignments $operationalAssignments) {}

    public function hasBroadAccess(User $user): bool
    {
        return $user->isPlatformAdministrator() || $user->hasAnyRole(['manager', 'receptionist']);
    }

    /** @param Builder<Queue> $query
     * @return Builder<Queue>
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($this->hasBroadAccess($user)) {
            return $query;
        }

        $profile = $this->activeProfile($user);
        if ($profile === null) {
            return $query->whereRaw('1 = 0');
        }

        $queueIds = $this->queueIds($profile);

        return $queueIds === [] ? $query->whereRaw('1 = 0') : $query->whereKey($queueIds);
    }

    /**
     * @return Collection<int, ServicePoint>
     */
    public function servicePointsFor(Queue $queue, User $user): Collection
    {
        $query = $queue->servicePoints()
            ->where('service_points.is_active', true)
            ->with('room')
            ->orderBy('service_points.name');

        if ($this->hasBroadAccess($user)) {
            return $query->get();
        }

        $profile = $this->activeProfile($user);
        if ($profile === null) {
            return new Collection;
        }

        $servicePointIds = $this->servicePointIds($profile);

        return $servicePointIds === [] ? new Collection : $query->whereKey($servicePointIds)->get();
    }

    public function servicePointsEagerLoadConstraint(User $user): Closure
    {
        $hasBroadAccess = $this->hasBroadAccess($user);
        $profile = $hasBroadAccess ? null : $this->activeProfile($user);
        $servicePointIds = $profile === null ? [] : $this->servicePointIds($profile);

        return static function ($query) use ($hasBroadAccess, $profile, $servicePointIds): void {
            $query->where('service_points.is_active', true)
                ->with('room')
                ->orderBy('service_points.name');

            if ($hasBroadAccess) {
                return;
            }

            if ($profile === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereKey($servicePointIds);
        };
    }

    /** @param Builder<QueueEntry> $query
     * @return Builder<QueueEntry>
     */
    public function applyEntryScope(Builder $query, Queue $queue, User $user): Builder
    {
        if ($this->hasBroadAccess($user)) {
            return $query;
        }

        $pointIds = $this->servicePointsFor($queue, $user)->modelKeys();

        return $query->where(function (Builder $entries) use ($pointIds, $user): void {
            $entries->whereNull('service_point_id')
                ->orWhere('assigned_user_id', $user->getKey());
            if ($pointIds !== []) {
                $entries->orWhereIn('service_point_id', $pointIds);
            }
        });
    }

    public function ensureCanAccess(Queue $queue, User $user): void
    {
        abort_unless($this->apply(Queue::query()->whereKey($queue->getKey()), $user)->exists(), 404);
    }

    public function ensureCanAccessEntry(QueueEntry $entry, User $user): void
    {
        $this->ensureCanAccess($entry->queue, $user);
        if ($this->hasBroadAccess($user) || $entry->service_point_id === null || $entry->assigned_user_id === $user->getKey()) {
            return;
        }

        abort_unless(
            $this->servicePointsFor($entry->queue, $user)->contains('id', $entry->service_point_id),
            404,
        );
    }

    public function ensureCanUseServicePoint(Queue $queue, ServicePoint $point, User $user): void
    {
        $this->ensureCanAccess($queue, $user);
        if ($this->hasBroadAccess($user)) {
            return;
        }

        abort_unless($this->servicePointsFor($queue, $user)->contains('id', $point->getKey()), 404);
    }

    private function activeProfile(User $user): ?HealthProfessional
    {
        $profile = $user->professionalProfile;

        return $profile instanceof HealthProfessional && $profile->is_active ? $profile : null;
    }

    /** @return list<int> */
    private function queueIds(HealthProfessional $profile): array
    {
        return $this->assignments($profile)['queues'];
    }

    /** @return list<int> */
    private function servicePointIds(HealthProfessional $profile): array
    {
        return $this->assignments($profile)['service_points'];
    }

    /** @return array{queues: list<int>, service_points: list<int>} */
    private function assignments(HealthProfessional $profile): array
    {
        $profileId = (int) $profile->getKey();
        if (isset($this->assignmentCache[$profileId])) {
            return $this->assignmentCache[$profileId];
        }

        return $this->assignmentCache[$profileId] = [
            'queues' => $this->operationalAssignments->queueIds($profile),
            'service_points' => $this->operationalAssignments->servicePointIds($profile),
        ];
    }
}
