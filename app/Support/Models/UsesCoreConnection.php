<?php

declare(strict_types=1);

namespace App\Support\Models;

trait UsesCoreConnection
{
    protected $connection = 'core';

    public function getConnectionName()
    {
        // Two independent PDOs cannot share an in-memory SQLite database. Tests
        // therefore use the default PDO while production still uses named Core.
        if (config('database.connections.core.driver') === 'sqlite'
            && config('database.connections.core.database') === ':memory:') {
            return (string) config('database.default');
        }

        return parent::getConnectionName();
    }
}
