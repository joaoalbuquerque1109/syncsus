<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;
use App\Support\Models\CoreModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Organization extends CoreModel
{
    use HasUlids;

    protected $guarded = [];

    /** @return HasMany<HealthUnit, $this> */
    public function healthUnits(): HasMany
    {
        return $this->hasMany(HealthUnit::class);
    }

    /** @return HasMany<Exam, $this> */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    /** @return HasMany<ExamGroup, $this> */
    public function examGroups(): HasMany
    {
        return $this->hasMany(ExamGroup::class);
    }

    public function tenantIdentifier(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->cnes_code);

        return is_string($digits) && strlen($digits) === 7 ? $digits : null;
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
