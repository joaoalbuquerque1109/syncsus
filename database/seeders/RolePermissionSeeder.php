<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'administrator' => [
            'patients.view', 'patients.create', 'patients.update', 'patients.clinical_history',
            'encounters.open', 'encounters.cancel',
            'queues.view', 'queues.call', 'queues.transfer',
            'triage.view', 'triage.start', 'triage.complete', 'triage.addendum',
            'medical.view', 'medical.start', 'medical.complete', 'medical.prescribe',
            'medical.issue_documents', 'reports.view', 'audit.view',
            'administration.manage', 'units.access_all',
        ],
        'receptionist' => [
            'patients.view', 'patients.create', 'patients.update',
            'encounters.open', 'encounters.cancel', 'queues.view',
        ],
        'triage_professional' => [
            'patients.view', 'patients.clinical_history', 'queues.view', 'queues.call', 'queues.transfer',
            'triage.view', 'triage.start', 'triage.complete', 'triage.addendum',
            'encounters.cancel_clinical',
        ],
        'doctor' => [
            'patients.view', 'patients.clinical_history', 'queues.view', 'queues.call', 'medical.view',
            'medical.start', 'medical.complete', 'medical.prescribe',
            'medical.issue_documents', 'encounters.cancel_clinical',
        ],
        'manager' => ['queues.view', 'reports.view', 'audit.view', 'administration.manage'],
        'auditor' => ['audit.view', 'reports.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        collect(self::ROLE_PERMISSIONS)
            ->flatten()
            ->unique()
            ->each(fn (string $permission) => Permission::findOrCreate($permission, 'web'));

        foreach (self::ROLE_PERMISSIONS as $roleName => $rolePermissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
