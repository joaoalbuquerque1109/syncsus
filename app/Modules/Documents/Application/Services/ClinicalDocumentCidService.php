<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application\Services;

use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;

final class ClinicalDocumentCidService
{
    /** @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function normalize(array $content): array
    {
        if (! array_key_exists('include_cid', $content)) {
            return $content;
        }

        if (! (bool) $content['include_cid']) {
            unset(
                $content['cid_code_id'],
                $content['cid_code'],
                $content['cid_description'],
                $content['cid_text'],
                $content['cid_authorization'],
            );
            $content['include_cid'] = false;

            return $content;
        }

        // Documentos legados podem ter sido emitidos antes da vinculação ao catálogo.
        if (blank($content['cid_code_id'] ?? null) && filled($content['cid_text'] ?? null)) {
            $content['include_cid'] = true;
            $content['cid_authorization'] = true;

            return $content;
        }

        $code = DiagnosisCode::query()
            ->whereKey($content['cid_code_id'] ?? null)
            ->where('is_active', true)
            ->firstOrFail();

        $content['include_cid'] = true;
        $content['cid_authorization'] = true;
        $content['cid_code_id'] = $code->getKey();
        $content['cid_code'] = $code->code;
        $content['cid_description'] = $code->description;
        $content['cid_text'] = $code->code.' · '.$code->description;

        return $content;
    }
}
