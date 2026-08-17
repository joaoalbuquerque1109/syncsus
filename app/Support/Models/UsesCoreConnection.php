<?php

declare(strict_types=1);

namespace App\Support\Models;

trait UsesCoreConnection
{
    protected $connection = 'core';
}
