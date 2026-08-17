<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Services;

final class SynclabOutboundContract
{
    public function includesPublicIdentifiers(): bool
    {
        return (bool) config('sync_sus.synclab.public_identifiers_enabled', false);
    }

    public function version(): string
    {
        $key = $this->includesPublicIdentifiers() ? 'public_identifiers' : 'legacy';

        return (string) config("synclab_contract.versions.{$key}");
    }
}
