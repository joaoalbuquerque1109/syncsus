<?php

declare(strict_types=1);

namespace App\Modules\Documents\Infrastructure\Pdf;

use App\Modules\Documents\Application\Contracts\PdfRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;

final class DompdfRenderer implements PdfRenderer
{
    public function render(string $html): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('chroot', [resource_path(), public_path()]);
        $options->set('tempDir', storage_path('framework/cache'));
        $options->set('fontCache', storage_path('framework/cache'));

        $renderer = new Dompdf($options);
        $renderer->setPaper('A4');
        $renderer->loadHtml($html, 'UTF-8');
        $renderer->render();

        return $renderer->output();
    }
}
