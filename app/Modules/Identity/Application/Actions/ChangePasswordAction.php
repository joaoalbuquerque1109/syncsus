<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class ChangePasswordAction
{
    public function __construct(private RecordAuditEventAction $recordAuditEvent) {}

    public function execute(User $user, string $password, Request $request): void
    {
        DB::connection('core')->transaction(function () use ($user, $password, $request): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            DB::connection((string) config('session.connection', 'core'))->table('sessions')
                ->where('user_id', $user->getKey())
                ->where('id', '!=', $request->session()->getId())
                ->delete();

            $this->recordAuditEvent->execute(
                action: 'user.password_changed',
                request: $request,
                user: $user,
                healthUnitId: $request->session()->get('active_health_unit_id'),
            );
        });
    }
}
