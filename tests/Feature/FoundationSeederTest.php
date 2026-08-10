<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class FoundationSeederTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_foundation_seed_is_idempotent_and_creates_configured_administrator(): void
    {
        config()->set('sync_sus.admin', [
            'name' => 'Administrador Demonstrativo',
            'email' => 'admin@example.test',
            'password' => 'Seed#Password2026',
        ]);

        $this->seed(DatabaseSeeder::class);
        $administrator = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $administrator->update([
            'password' => 'Changed#Password2026',
            'must_change_password' => false,
        ]);
        $this->seed(DatabaseSeeder::class);

        $administrator = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('health_units', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('health_unit_user', 0);
        $this->assertTrue($administrator->hasRole('administrator'));
        $this->assertFalse($administrator->must_change_password);
        $this->assertNull($administrator->organization_id);
        $this->assertSame(1, $administrator->platform_admin_slot);
        $this->assertTrue(Hash::check('Changed#Password2026', (string) $administrator->password));
    }
}
