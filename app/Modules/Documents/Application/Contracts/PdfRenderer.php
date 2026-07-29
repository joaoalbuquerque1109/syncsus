<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Contracts;

interface PdfRenderer
{
    public function render(string $html): string;
}
