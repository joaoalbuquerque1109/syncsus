<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClinicalNote extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Specialty, $this> */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    /** @return BelongsTo<ClinicalNote, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_note_id');
    }

    protected function casts(): array
    {
        return [
            'clinical_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
