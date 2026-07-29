<?php

declare(strict_types=1);

namespace App\Modules\Documents\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentVersion extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<ClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ClinicalDocument::class, 'document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['structured_content' => 'array'];
    }
}
