<?php

declare(strict_types=1);

namespace App\Modules\Documents\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentVersion extends TenantModel
{
    protected $guarded = [];

    /** @return BelongsTo<ClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ClinicalDocument::class, 'document_id');
    }

    public function resolveCreator(): ?User
    {
        return User::query()->find($this->created_by);
    }

    protected function casts(): array
    {
        return ['structured_content' => 'array'];
    }
}
