<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;
use App\Modules\Medical\Infrastructure\Eloquent\SusProcedure;
use Database\Seeders\RolePermissionSeeder;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class ExamGroupManagementTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_manager_can_search_canonical_exams_and_create_an_audited_group(): void
    {
        $unit = $this->createHealthUnit('GROUP-MANAGEMENT');
        $otherUnit = $this->createHealthUnit('GROUP-FOREIGN');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->manager($unit);
        SusProcedure::query()->create([
            'code' => '0202020380',
            'description' => 'Hemograma completo',
            'is_active' => true,
        ]);
        $hemogram = $this->exam($unit->organization_id, 'Contagem de células sanguíneas', '0202020380');
        $glucose = $this->exam($unit->organization_id, 'Glicose');
        $this->exam($otherUnit->organization_id, 'Exame sigiloso de outra organização');
        $this->exam($unit->organization_id, 'Hemograma inativo', null, false);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($manager)->withSession($session)
            ->get(route('administration.exam-groups.index'))
            ->assertOk()
            ->assertSee('Grupos de exames')
            ->assertSee('Novo grupo');

        $this->actingAs($manager)->withSession($session)
            ->getJson(route('administration.exam-groups.search-exams', ['q' => 'Hemograma']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $hemogram->getKey())
            ->assertJsonPath('data.0.procedure_code', '0202020380')
            ->assertJsonPath('data.0.sus_description', 'Hemograma completo');

        $this->actingAs($manager)->withSession($session)
            ->post(route('administration.exam-groups.store'), [
                'name' => ' Pré-operatório ',
                'items' => [
                    ['exam_id' => $hemogram->getKey()],
                    ['exam_id' => $glucose->getKey()],
                ],
                'is_active' => '1',
            ])
            ->assertRedirect(route('administration.exam-groups.index'));

        $group = ExamGroup::query()->sole();
        $this->assertSame('Pré-operatório', $group->name);
        $this->assertSame('PRE OPERATORIO', $group->normalized_name);
        $this->assertSame(
            [$hemogram->getKey(), $glucose->getKey()],
            $group->items()->orderBy('display_order')->pluck('exam_id')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->getKey(),
            'health_unit_id' => $unit->getKey(),
            'action' => 'laboratory.exam_group_saved',
        ]);
    }

    public function test_group_rejects_foreign_exams_and_an_empty_composition(): void
    {
        $unit = $this->createHealthUnit('GROUP-VALIDATION');
        $otherUnit = $this->createHealthUnit('GROUP-VALIDATION-OTHER');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->manager($unit);
        $foreignExam = $this->exam($otherUnit->organization_id, 'Exame externo');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($manager)->withSession($session)
            ->from(route('administration.exam-groups.index'))
            ->post(route('administration.exam-groups.store'), [
                'name' => 'Grupo inválido',
                'items' => [['exam_id' => $foreignExam->getKey()]],
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('items.0.exam_id');

        $this->actingAs($manager)->withSession($session)
            ->from(route('administration.exam-groups.index'))
            ->post(route('administration.exam-groups.store'), [
                'name' => 'Grupo vazio',
                'items' => [],
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('items');

        $this->assertDatabaseCount('exam_groups', 0);
    }

    public function test_normalized_duplicate_name_returns_a_friendly_validation_error(): void
    {
        $unit = $this->createHealthUnit('GROUP-DUPLICATE');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->manager($unit);
        $exam = $this->exam($unit->organization_id, 'Hemograma');
        $session = ['active_health_unit_id' => $unit->getKey()];
        ExamGroup::query()->create([
            'organization_id' => $unit->organization_id,
            'name' => 'Pré-operatório',
            'normalized_name' => 'PRE OPERATORIO',
        ])->items()->create(['exam_id' => $exam->getKey(), 'display_order' => 0]);

        $this->actingAs($manager)->withSession($session)
            ->from(route('administration.exam-groups.index'))
            ->post(route('administration.exam-groups.store'), [
                'name' => 'PRE OPERATORIOS',
                'items' => [['exam_id' => $exam->getKey()]],
                'is_active' => '1',
            ])
            ->assertRedirect(route('administration.exam-groups.index'))
            ->assertSessionHasErrors([
                'name' => 'Já existe um grupo de exames com este nome nesta organização.',
            ]);

        $this->assertDatabaseCount('exam_groups', 1);
    }

    public function test_unauthorized_user_is_forbidden_and_cross_organization_edit_is_not_found(): void
    {
        $unit = $this->createHealthUnit('GROUP-ACCESS');
        $otherUnit = $this->createHealthUnit('GROUP-ACCESS-OTHER');
        $this->seed(RolePermissionSeeder::class);
        $receptionist = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $receptionist->assignRole('receptionist');
        $manager = $this->manager($unit);
        $exam = $this->exam($unit->organization_id, 'Hemograma');
        $foreignExam = $this->exam($otherUnit->organization_id, 'Glicose');
        $foreignGroup = ExamGroup::query()->create([
            'organization_id' => $otherUnit->organization_id,
            'name' => 'Grupo externo',
            'normalized_name' => 'GRUPO EXTERNO',
        ]);
        $foreignGroup->items()->create(['exam_id' => $foreignExam->getKey(), 'display_order' => 0]);
        $payload = [
            'name' => 'Grupo restrito',
            'items' => [['exam_id' => $exam->getKey()]],
            'is_active' => '1',
        ];
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($receptionist)->withSession($session)
            ->post(route('administration.exam-groups.store'), $payload)
            ->assertForbidden();

        $this->actingAs($manager)->withSession($session)
            ->put(route('administration.exam-groups.update', $foreignGroup), $payload)
            ->assertNotFound();
    }

    public function test_edit_replaces_items_preserves_order_and_can_deactivate_the_group(): void
    {
        $unit = $this->createHealthUnit('GROUP-EDIT');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->manager($unit);
        $examA = $this->exam($unit->organization_id, 'Exame A');
        $examB = $this->exam($unit->organization_id, 'Exame B');
        $examC = $this->exam($unit->organization_id, 'Exame C');
        $group = ExamGroup::query()->create([
            'organization_id' => $unit->organization_id,
            'name' => 'Grupo inicial',
            'normalized_name' => 'GRUPO INICIAL',
        ]);
        $group->items()->create(['exam_id' => $examA->getKey(), 'display_order' => 0]);
        $group->items()->create(['exam_id' => $examB->getKey(), 'display_order' => 1]);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($manager)->withSession($session)
            ->get(route('administration.exam-groups.index', ['edit' => $group->public_id]))
            ->assertOk()
            ->assertSee('Editar grupo')
            ->assertSee('Exame A');

        $this->actingAs($manager)->withSession($session)
            ->put(route('administration.exam-groups.update', $group), [
                'name' => 'Grupo atualizado',
                'items' => [
                    ['exam_id' => $examC->getKey()],
                    ['exam_id' => $examB->getKey()],
                ],
                'is_active' => '0',
            ])
            ->assertRedirect(route('administration.exam-groups.index'));

        $group->refresh();
        $this->assertSame('Grupo atualizado', $group->name);
        $this->assertFalse($group->is_active);
        $this->assertSame(
            [$examC->getKey(), $examB->getKey()],
            $group->items()->orderBy('display_order')->pluck('exam_id')->all(),
        );
        $this->assertDatabaseMissing('exam_group_items', [
            'exam_group_id' => $group->getKey(),
            'exam_id' => $examA->getKey(),
        ]);
    }

    private function manager(HealthUnit $unit): User
    {
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');

        return $manager;
    }

    private function exam(
        int $organizationId,
        string $name,
        ?string $procedureCode = null,
        bool $isActive = true,
    ): Exam {
        return Exam::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'sus_procedure_code' => $procedureCode,
            'is_active' => $isActive,
        ]);
    }
}
