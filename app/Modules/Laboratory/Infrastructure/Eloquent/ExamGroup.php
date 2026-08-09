<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ExamGroup extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ExamGroupItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ExamGroupItem::class)->orderBy('display_order')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
