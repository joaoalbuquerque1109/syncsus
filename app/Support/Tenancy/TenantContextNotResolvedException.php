<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use LogicException;

final class TenantContextNotResolvedException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Tenant context has not been resolved for the current request or job.');
    }
}
