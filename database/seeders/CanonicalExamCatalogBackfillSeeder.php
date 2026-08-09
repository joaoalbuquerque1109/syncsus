<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Laboratory\Application\Actions\BackfillCanonicalExamCatalogAction;
use Illuminate\Database\Seeder;

final class CanonicalExamCatalogBackfillSeeder extends Seeder
{
    public function run(BackfillCanonicalExamCatalogAction $action): void
    {
        $action->execute();
    }
}
