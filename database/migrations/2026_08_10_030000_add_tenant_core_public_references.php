<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, array<string, array{string, string}>> */
    private array $references = [
        'departments' => ['health_unit_public_id' => ['health_unit_id', 'health_units']],
        'entry_types' => ['organization_public_id' => ['organization_id', 'organizations']],
        'arrival_methods' => ['organization_public_id' => ['organization_id', 'organizations']],
        'encounters' => [
            'patient_public_id' => ['patient_id', 'patients'],
            'health_unit_public_id' => ['health_unit_id', 'health_units'],
            'assigned_specialty_public_id' => ['assigned_specialty_id', 'specialties'],
        ],
        'queues' => [
            'health_unit_public_id' => ['health_unit_id', 'health_units'],
            'specialty_public_id' => ['specialty_id', 'specialties'],
        ],
        'panels' => ['health_unit_public_id' => ['health_unit_id', 'health_units']],
        'triage_assessments' => [],
        'medical_consultations' => ['specialty_public_id' => ['specialty_id', 'specialties']],
        'clinical_notes' => ['specialty_public_id' => ['specialty_id', 'specialties']],
        'referrals' => ['specialty_public_id' => ['specialty_id', 'specialties']],
        'exam_orders' => [
            'organization_public_id' => ['organization_id', 'organizations'],
            'health_unit_public_id' => ['health_unit_id', 'health_units'],
        ],
        'documents' => ['health_unit_public_id' => ['health_unit_id', 'health_units']],
        'medical_certificates' => ['health_unit_public_id' => ['health_unit_id', 'health_units']],
        'laboratory_integrations' => [
            'organization_public_id' => ['organization_id', 'organizations'],
            'health_unit_public_id' => ['health_unit_id', 'health_units'],
        ],
        'laboratory_order_transmissions' => [
            'organization_public_id' => ['organization_id', 'organizations'],
            'health_unit_public_id' => ['health_unit_id', 'health_units'],
        ],
        'health_unit_exams' => ['health_unit_public_id' => ['health_unit_id', 'health_units']],
        'exam_catalog_import_candidates' => [
            'organization_public_id' => ['organization_id', 'organizations'],
            'suggested_exam_public_id' => ['suggested_exam_id', 'exams'],
        ],
        'exam_group_import_conflicts' => ['organization_public_id' => ['organization_id', 'organizations']],
        'medical_shift_attendances' => [
            'organization_public_id' => ['organization_id', 'organizations'],
            'health_unit_public_id' => ['health_unit_id', 'health_units'],
        ],
    ];

    public function up(): void
    {
        Schema::table('specialties', function (Blueprint $blueprint): void {
            $blueprint->ulid('public_id')->nullable()->after('id');
        });

        DB::table('specialties')->select('id')->orderBy('id')->chunkById(500, function ($specialties): void {
            foreach ($specialties as $specialty) {
                DB::table('specialties')->where('id', $specialty->id)->update([
                    'public_id' => (string) Str::ulid(),
                ]);
            }
        });

        Schema::table('specialties', function (Blueprint $blueprint): void {
            $blueprint->ulid('public_id')->nullable(false)->change();
            $blueprint->unique('public_id');
        });

        foreach ($this->references as $table => $references) {
            if ($references === []) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($references): void {
                foreach (array_keys($references) as $publicColumn) {
                    $blueprint->ulid($publicColumn)->nullable()->index();
                }
            });
        }

        foreach ($this->references as $table => $references) {
            foreach ($references as $publicColumn => [$legacyColumn, $coreTable]) {
                $this->backfill($table, $legacyColumn, $publicColumn, $coreTable);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->references, true) as $table => $references) {
            if ($references === []) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($references): void {
                $blueprint->dropColumn(array_keys($references));
            });
        }

        Schema::table('specialties', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['public_id']);
            $blueprint->dropColumn('public_id');
        });
    }

    private function backfill(
        string $table,
        string $legacyColumn,
        string $publicColumn,
        string $coreTable,
    ): void {
        DB::table($table)->whereNotNull($legacyColumn)->orderBy('id')->chunkById(500, function ($records) use (
            $table,
            $legacyColumn,
            $publicColumn,
            $coreTable,
        ): void {
            $publicIds = DB::table($coreTable)
                ->whereIn('id', $records->pluck($legacyColumn)->filter()->unique()->all())
                ->pluck('public_id', 'id');
            foreach ($records as $record) {
                DB::table($table)->where('id', $record->id)->update([
                    $publicColumn => $publicIds[$record->{$legacyColumn}] ?? null,
                ]);
            }
        });
    }
};
