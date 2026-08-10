<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\Actions\ResetUserPasswordAction;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class AdminPasswordResetTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_administrator_can_reset_password_without_exposing_it_in_audit(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $unit = $this->createHealthUnit();
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $target = $this->createUserWithUnit($unit, ['must_change_password' => false]);

        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->getKey(),
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        app(ResetUserPasswordAction::class)->execute(
            $administrator,
            $target,
            'Temporary#Password2026',
        );

        $target->refresh();
        $this->assertTrue($target->must_change_password);
        $this->assertTrue(Hash::check('Temporary#Password2026', (string) $target->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->getKey()]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->getKey(),
            'auditable_id' => $target->getKey(),
            'action' => 'user.password_reset_by_administrator',
        ]);
        $this->assertStringNotContainsString(
            'Temporary#Password2026',
            (string) DB::table('audit_logs')->value('changed_fields'),
        );
    }

    public function test_user_without_administration_permission_cannot_reset_password(): void
    {
        $unit = $this->createHealthUnit();
        $actor = $this->createUserWithUnit($unit);
        $target = $this->createUserWithUnit($unit);
        $originalHash = (string) $target->password;

        $this->expectException(AuthorizationException::class);

        try {
            app(ResetUserPasswordAction::class)->execute($actor, $target, 'Temporary#Password2026');
        } finally {
            $this->assertSame($originalHash, (string) $target->fresh()?->password);
        }
    }
}
