<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const EXPECTED_MATRIX = [
        'administrator' => [
            'administration.manage', 'audit.view', 'encounters.cancel', 'encounters.open',
            'medical.complete', 'medical.issue_documents', 'medical.prescribe', 'medical.start',
            'medical.view', 'patients.clinical_history', 'patients.create',
            'laboratory.orders.cancel', 'laboratory.orders.create', 'laboratory.orders.view',
            'patients.update', 'patients.view', 'queues.call', 'queues.transfer', 'queues.view',
            'reports.view', 'triage.addendum',
            'triage.complete', 'triage.start', 'triage.view', 'units.access_all',
        ],
        'receptionist' => [
            'encounters.cancel', 'encounters.open', 'patients.create', 'patients.update',
            'patients.view', 'queues.view',
            'laboratory.orders.cancel', 'laboratory.orders.create', 'laboratory.orders.view',
        ],
        'triage_professional' => [
            'encounters.cancel_clinical', 'patients.clinical_history', 'patients.view', 'queues.call', 'queues.transfer',
            'queues.view', 'triage.addendum', 'triage.complete', 'triage.start', 'triage.view',
        ],
        'doctor' => [
            'encounters.cancel_clinical', 'medical.complete', 'medical.issue_documents', 'medical.prescribe', 'medical.start',
            'medical.view', 'patients.clinical_history', 'patients.view', 'queues.call', 'queues.view',
            'laboratory.orders.cancel', 'laboratory.orders.create', 'laboratory.orders.view',
        ],
        'manager' => ['administration.manage', 'audit.view', 'laboratory.orders.view', 'queues.view', 'reports.view'],
        'auditor' => ['audit.view', 'reports.view'],
    ];

    public function test_clinical_and_administrative_boundaries_are_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $receptionist = Role::findByName('receptionist');
        $doctor = Role::findByName('doctor');
        $manager = Role::findByName('manager');
        $auditor = Role::findByName('auditor');

        $this->assertTrue($receptionist->hasPermissionTo('encounters.open'));
        $this->assertFalse($receptionist->hasPermissionTo('medical.view'));
        $this->assertTrue($doctor->hasPermissionTo('medical.prescribe'));
        $this->assertFalse($doctor->hasPermissionTo('administration.manage'));
        $this->assertTrue($manager->hasPermissionTo('reports.view'));
        $this->assertFalse($manager->hasPermissionTo('patients.update'));
        $this->assertTrue($auditor->hasPermissionTo('audit.view'));
    }

    public function test_complete_role_permission_matrix_has_no_implicit_excess_privileges(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (self::EXPECTED_MATRIX as $roleName => $expectedPermissions) {
            $actual = Role::findByName($roleName)->permissions->pluck('name')->sort()->values()->all();
            $expected = collect($expectedPermissions)->sort()->values()->all();
            $this->assertSame($expected, $actual, "Matriz divergente para {$roleName}.");
        }
    }
}
