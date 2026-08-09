<?php

declare(strict_types=1);

namespace App\Support\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use PopulatesCorePublicReferences;

    public function getConnectionName()
    {
        // Explicit `Model::on($connection)` is reserved for infrastructure that
        // already resolved a tenant through a public index or job payload.
        return parent::getConnectionName() ?? app(TenantContext::class)->connectionName();
    }

    public function refresh()
    {
        if (! $this->exists) {
            return $this;
        }

        $reloadable = collect($this->relations)
            ->keys()
            ->filter(fn (string $relation): bool => method_exists($this, $relation))
            ->all();
        $this->setRawAttributes(
            $this->newQueryWithoutScopes()
                ->whereKey($this->getKey())
                ->useWritePdo()
                ->firstOrFail()
                ->getAttributes(),
        );
        $this->unsetRelations();
        $this->load($reloadable);
        $this->syncOriginal();

        return $this;
    }

    /**
     * @template TCore of CoreModel
     *
     * @param  class-string<TCore>  $model
     * @return TCore|null
     */
    protected function resolveCoreReference(
        string $model,
        string $publicColumn,
        string $legacyColumn,
    ): ?CoreModel {
        $publicId = $this->getAttribute($publicColumn);
        if (filled($publicId)) {
            return $model::query()->where('public_id', $publicId)->first();
        }

        $legacyId = $this->getAttribute($legacyColumn);

        return filled($legacyId) ? $model::query()->find($legacyId) : null;
    }
}
