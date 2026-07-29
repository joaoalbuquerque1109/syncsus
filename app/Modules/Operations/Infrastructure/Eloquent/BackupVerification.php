<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BackupVerification extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
