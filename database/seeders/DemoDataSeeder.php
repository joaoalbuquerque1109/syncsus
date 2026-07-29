<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Documents\Application\Actions\IssueClinicalDocumentAction;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\Diagnosis;
use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use App\Modules\Medical\Infrastructure\Eloquent\EncounterDestination;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\PhysicalExam;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientAccessLog;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueCall;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Modules\Reception\Infrastructure\Eloquent\EncounterStatusHistory;
use App\Modules\Reception\Infrastructure\Eloquent\ReceptionRecord;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use App\Modules\Triage\Infrastructure\Eloquent\TriageDiscriminator;
use App\Modules\Triage\Infrastructure\Eloquent\TriageFlowchart;
use App\Modules\Triage\Infrastructure\Eloquent\TriageProtocol;
use App\Modules\Triage\Infrastructure\Eloquent\VitalSignMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'Demo#SyncSUS2026';

    /** @var array<string, array{name: string, role: string}> */
    private const DEMO_USERS = [
        'admin@syncsus.local' => ['name' => 'Administrador Demo', 'role' => 'administrator'],
        'recepcao@syncsus.local' => ['name' => 'Marina Recepção', 'role' => 'receptionist'],
        'triagem@syncsus.local' => ['name' => 'Enf. Lucas Triagem', 'role' => 'triage_professional'],
        'medico@syncsus.local' => ['name' => 'Dra. Camila Médica', 'role' => 'doctor'],
        'gestor@syncsus.local' => ['name' => 'Rafael Gestão', 'role' => 'manager'],
        'auditoria@syncsus.local' => ['name' => 'Beatriz Auditoria', 'role' => 'auditor'],
    ];

    /** @var list<array<string, mixed>> */
    private const PATIENTS = [
        ['record' => 'DEMO-0001', 'name' => 'Ana Souza Lima', 'birth_date' => '1992-03-18', 'sex' => 'female', 'phone' => '(85) 99900-1001', 'district' => 'Centro', 'blood_type' => 'O+'],
        ['record' => 'DEMO-0002', 'name' => 'Bruno Carvalho Nunes', 'birth_date' => '1985-11-02', 'sex' => 'male', 'phone' => '(85) 99900-1002', 'district' => 'Aldeota', 'blood_type' => 'A+'],
        ['record' => 'DEMO-0003', 'name' => 'Carla Menezes Rocha', 'birth_date' => '1971-06-27', 'sex' => 'female', 'phone' => '(85) 99900-1003', 'district' => 'Benfica', 'blood_type' => 'B+'],
        ['record' => 'DEMO-0004', 'name' => 'Daniel Oliveira Reis', 'birth_date' => '1953-01-14', 'sex' => 'male', 'phone' => '(85) 99900-1004', 'district' => 'Meireles', 'blood_type' => 'O-'],
        ['record' => 'DEMO-0005', 'name' => 'Elisa Ribeiro Santos', 'social_name' => 'Eli Ribeiro', 'birth_date' => '1998-09-09', 'sex' => 'female', 'phone' => '(85) 99900-1005', 'district' => 'Montese', 'blood_type' => 'AB+'],
        ['record' => 'DEMO-0006', 'name' => 'Felipe Martins Costa', 'birth_date' => '2001-12-21', 'sex' => 'male', 'phone' => '(85) 99900-1006', 'district' => 'Parangaba', 'blood_type' => 'A-'],
        ['record' => 'DEMO-0007', 'name' => 'Gabriela Alves Freitas', 'birth_date' => '1988-04-05', 'sex' => 'female', 'phone' => '(85) 99900-1007', 'district' => 'Papicu', 'blood_type' => 'O+'],
        ['record' => 'DEMO-0008', 'name' => 'Helena Castro Moura', 'birth_date' => '1964-08-30', 'sex' => 'female', 'phone' => '(85) 99900-1008', 'district' => 'Messejana', 'blood_type' => 'B-'],
        ['record' => 'DEMO-0009', 'name' => 'Igor Gomes Pereira', 'birth_date' => '2010-02-12', 'sex' => 'male', 'phone' => '(85) 99900-1009', 'district' => 'Cocó', 'blood_type' => 'A+'],
        ['record' => 'DEMO-0010', 'name' => 'Joana Barros Araújo', 'birth_date' => '1949-10-17', 'sex' => 'female', 'phone' => '(85) 99900-1010', 'district' => 'Fátima', 'blood_type' => 'O+'],
    ];

    /** @var list<array<string, mixed>> */
    private const SCENARIOS = [
        ['patient' => 0, 'number' => 'DEMO-ATD-001', 'status' => 'waiting_triage', 'minutes_ago' => 18, 'complaint' => 'Dor de cabeça iniciada hoje.', 'priority' => 'none'],
        ['patient' => 1, 'number' => 'DEMO-ATD-002', 'status' => 'in_triage', 'minutes_ago' => 35, 'complaint' => 'Mal-estar e tontura.', 'priority' => 'none', 'triage' => 'draft'],
        ['patient' => 2, 'number' => 'DEMO-ATD-003', 'status' => 'waiting_medical', 'minutes_ago' => 72, 'complaint' => 'Febre e dor no corpo há dois dias.', 'priority' => 'none', 'triage' => 'finalized', 'risk' => 'YELLOW'],
        ['patient' => 3, 'number' => 'DEMO-ATD-004', 'status' => 'waiting_medical', 'minutes_ago' => 48, 'complaint' => 'Dor no peito sem irradiação.', 'priority' => 'elderly', 'triage' => 'finalized', 'risk' => 'ORANGE'],
        ['patient' => 4, 'number' => 'DEMO-ATD-005', 'status' => 'in_medical_care', 'minutes_ago' => 96, 'complaint' => 'Dor abdominal e náusea.', 'priority' => 'none', 'triage' => 'finalized', 'risk' => 'YELLOW', 'medical' => 'draft'],
        ['patient' => 5, 'number' => 'DEMO-ATD-006', 'status' => 'under_observation', 'minutes_ago' => 210, 'complaint' => 'Falta de ar aos esforços.', 'priority' => 'none', 'triage' => 'finalized', 'risk' => 'ORANGE', 'medical' => 'finalized', 'destination' => 'observation'],
        ['patient' => 6, 'number' => 'DEMO-ATD-007', 'status' => 'discharged', 'minutes_ago' => 165, 'complaint' => 'Dor de garganta e coriza.', 'priority' => 'none', 'triage' => 'finalized', 'risk' => 'GREEN', 'medical' => 'finalized', 'destination' => 'discharge'],
        ['patient' => 7, 'number' => 'DEMO-ATD-008', 'status' => 'transferred', 'minutes_ago' => 300, 'complaint' => 'Trauma após queda com dor intensa.', 'priority' => 'elderly', 'triage' => 'finalized', 'risk' => 'RED', 'medical' => 'finalized', 'destination' => 'transfer'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Dados demonstrativos nunca podem ser carregados em produção.');
        }

        $unit = HealthUnit::query()->where('code', 'URGENCIA-CENTRAL')->firstOrFail();
        $users = $this->seedUsers($unit);
        $this->seedProfessionals($unit, $users);
        $this->seedExpandedPatientData($users['triage_professional']);

        if (Encounter::query()->where('encounter_number', 'DEMO-ATD-001')->exists()) {
            $this->command->warn('Dados demonstrativos já existem; nenhuma duplicação foi criada.');

            return;
        }

        $now = now()->toImmutable()->startOfMinute();
        $patients = $this->seedPatients($unit, $users['receptionist'], $now);
        $this->seedExpandedPatientData($users['triage_professional']);

        $consultations = DB::transaction(
            fn (): array => $this->seedCareJourney($unit, $users, $patients, $now),
        );

        $this->seedDocuments(
            $consultations['discharged'],
            $users['doctor'],
            $unit,
        );

        $this->command->info('SQLite demonstrativo populado com 6 usuários, 10 pacientes e 8 atendimentos.');
        $this->command->line('Acesso: admin@syncsus.local / '.self::PASSWORD);
    }

    /** @return array<string, User> */
    private function seedUsers(HealthUnit $unit): array
    {
        $users = [];

        foreach (self::DEMO_USERS as $email => $definition) {
            $isAdministrator = $definition['role'] === 'administrator';
            $user = $isAdministrator
                ? (User::query()->where('platform_admin_slot', 1)->first()
                    ?? User::query()->where('email', $email)->orderBy('id')->first()
                    ?? new User)
                : User::query()->firstOrNew([
                    'organization_id' => $unit->organization_id,
                    'email' => $email,
                ]);
            $user->fill([
                'email' => $email,
                'name' => $definition['name'],
                'password' => self::PASSWORD,
                'organization_id' => $isAdministrator ? null : $unit->organization_id,
                'platform_admin_slot' => $isAdministrator ? 1 : null,
                'default_health_unit_id' => $isAdministrator ? null : $unit->getKey(),
                'is_active' => true,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();
            if ($isAdministrator) {
                $user->healthUnits()->detach();
            } else {
                $user->healthUnits()->syncWithoutDetaching([$unit->getKey()]);
            }
            $user->syncRoles([$definition['role']]);
            $users[$definition['role']] = $user;
        }

        return $users;
    }

    /** @param array<string, User> $users */
    private function seedProfessionals(HealthUnit $unit, array $users): void
    {
        $definitions = [
            'doctor' => [
                'institutional_code' => 'MED-DEMO-001',
                'profession_type' => 'doctor',
                'treatment_name' => 'Dra.',
                'full_name' => 'Camila Andrade Médica',
                'cpf' => '52998224725',
                'council_type' => 'CRM',
                'registration_number' => '123456',
                'state' => 'CE',
            ],
            'triage_professional' => [
                'institutional_code' => 'ENF-DEMO-001',
                'profession_type' => 'nurse',
                'treatment_name' => 'Enf.',
                'full_name' => 'Lucas Ferreira Triagem',
                'cpf' => '11144477735',
                'council_type' => 'COREN',
                'registration_number' => '654321',
                'state' => 'CE',
            ],
        ];

        foreach ($definitions as $role => $definition) {
            $professional = HealthProfessional::query()->updateOrCreate(
                ['institutional_code' => $definition['institutional_code']],
                [
                    'organization_id' => $unit->organization_id,
                    'user_id' => $users[$role]->getKey(),
                    'profession_type' => $definition['profession_type'],
                    'treatment_name' => $definition['treatment_name'],
                    'full_name' => $definition['full_name'],
                    'cpf' => $definition['cpf'],
                    'cnes_code' => $unit->cnes_code,
                    'email' => $users[$role]->email,
                    'is_active' => true,
                    'created_by' => $users['administrator']->getKey(),
                    'updated_by' => $users['administrator']->getKey(),
                ],
            );
            $professional->healthUnits()->syncWithoutDetaching([$unit->getKey()]);
            $professional->registrations()->updateOrCreate(
                [
                    'organization_id' => $unit->organization_id,
                    'council_type' => $definition['council_type'],
                    'registration_number' => $definition['registration_number'],
                    'state' => $definition['state'],
                ],
                ['is_primary' => true, 'is_active' => true],
            );

            if ($role === 'doctor') {
                $specialty = Specialty::query()
                    ->where('organization_id', $unit->organization_id)
                    ->where('code', 'CLINICA')->first();
                if ($specialty !== null) {
                    $professional->specialties()->syncWithoutDetaching([
                        $specialty->getKey() => [
                            'rqe_number' => 'RQE-DEMO-001',
                            'registered_at' => '2020-01-15',
                            'is_primary' => true,
                        ],
                    ]);
                }
            }
        }
    }

    private function seedExpandedPatientData(User $recorder): void
    {
        $patients = Patient::query()->whereIn('medical_record_number', ['DEMO-0001', 'DEMO-0004'])->get();
        foreach ($patients as $patient) {
            $patient->medications()->firstOrCreate(
                ['medication_name' => 'Losartana 50 mg', 'status' => 'active'],
                [
                    'dosage' => '1 comprimido',
                    'frequency' => 'a cada 12 horas',
                    'route' => 'oral',
                    'source' => 'demo_seeder',
                    'notes' => 'Informação inteiramente fictícia.',
                    'recorded_by' => $recorder->getKey(),
                    'recorded_at' => now()->subDays(10),
                ],
            );
            $patient->socialHistory()->updateOrCreate(
                ['patient_id' => $patient->getKey()],
                [
                    'smoking_status' => 'never',
                    'alcohol_use' => 'occasional',
                    'other_substance_use' => null,
                    'notes' => 'Histórico fictício para demonstração.',
                    'recorded_by' => $recorder->getKey(),
                    'recorded_at' => now()->subDays(10),
                ],
            );
        }
    }

    /**
     * @return list<Patient>
     */
    private function seedPatients(HealthUnit $unit, User $receptionist, CarbonImmutable $now): array
    {
        $patients = [];

        foreach (self::PATIENTS as $index => $definition) {
            $patient = Patient::query()->updateOrCreate(
                ['medical_record_number' => $definition['record']],
                [
                    'organization_id' => $unit->organization_id,
                    'full_name' => $definition['name'],
                    'normalized_name' => $this->normalize((string) $definition['name']),
                    'social_name' => $definition['social_name'] ?? null,
                    'birth_date' => $definition['birth_date'],
                    'sex' => $definition['sex'],
                    'race_color' => 'not_informed',
                    'nationality' => 'Brasileira',
                    'is_disabled' => false,
                    'blood_type' => $definition['blood_type'],
                    'mother_name' => 'Responsável fictício '.($index + 1),
                    'normalized_mother_name' => $this->normalize('Responsável fictício '.($index + 1)),
                    'mother_unknown' => false,
                    'father_unknown' => true,
                    'reference_health_unit_id' => $unit->getKey(),
                    'is_provisional' => false,
                    'status' => 'active',
                    'created_by' => $receptionist->getKey(),
                    'updated_by' => $receptionist->getKey(),
                ],
            );
            $patient->contacts()->updateOrCreate(
                ['type' => 'phone', 'is_primary' => true],
                [
                    'value' => $definition['phone'],
                    'normalized_value' => preg_replace('/\D+/', '', (string) $definition['phone']),
                ],
            );
            $patient->addresses()->updateOrCreate(
                ['is_primary' => true],
                [
                    'country' => 'Brasil',
                    'state' => 'CE',
                    'city' => 'Fortaleza',
                    'district' => $definition['district'],
                    'street_type' => 'Rua',
                    'street' => 'Endereço exclusivamente demonstrativo',
                    'number' => (string) (100 + $index),
                    'area_type' => 'urban',
                    'is_unknown' => false,
                ],
            );

            if (in_array($index, [2, 5], true)) {
                $patient->allergies()->create([
                    'substance' => $index === 2 ? 'Dipirona' : 'Penicilina',
                    'reaction' => 'Urticária referida',
                    'severity' => 'moderate',
                    'status' => 'active',
                    'source' => 'registration',
                    'recorded_by' => $receptionist->getKey(),
                    'recorded_at' => $now->subDays(20),
                ]);
            }
            if (in_array($index, [3, 7, 9], true)) {
                $patient->conditions()->create([
                    'code' => 'I10',
                    'description' => 'Hipertensão arterial sistêmica',
                    'status' => 'active',
                    'notes' => 'Informação fictícia para demonstração.',
                    'recorded_by' => $receptionist->getKey(),
                ]);
            }

            $patients[] = $patient;
        }

        return $patients;
    }

    /**
     * @param  array<string, User>  $users
     * @param  list<Patient>  $patients
     * @return array{discharged: MedicalConsultation}
     */
    private function seedCareJourney(
        HealthUnit $unit,
        array $users,
        array $patients,
        CarbonImmutable $now,
    ): array {
        $entryType = EntryType::query()->where('organization_id', $unit->organization_id)
            ->where('code', 'EMERGENCY')->firstOrFail();
        $arrivalMethod = ArrivalMethod::query()->where('organization_id', $unit->organization_id)
            ->where('code', 'WALK_IN')->firstOrFail();
        $specialty = Specialty::query()->where('organization_id', $unit->organization_id)
            ->where('code', 'CLINICA')->firstOrFail();
        $triageQueue = Queue::query()->whereBelongsTo($unit)->where('code', 'QUEUE-TRIAGE')->firstOrFail();
        $medicalQueue = Queue::query()->whereBelongsTo($unit)->where('code', 'QUEUE-CLINIC')->firstOrFail();
        $triagePoint = $triageQueue->servicePoints()->firstOrFail();
        $medicalPoint = $medicalQueue->servicePoints()->firstOrFail();
        $protocol = TriageProtocol::query()->where('code', 'SYNC-TRIAGE')->firstOrFail();
        $flowchart = TriageFlowchart::query()->where('code', 'GENERAL')->firstOrFail();
        $discriminator = TriageDiscriminator::query()
            ->where('triage_flowchart_id', $flowchart->getKey())
            ->where('code', 'MODERATE')
            ->firstOrFail();
        $dischargedConsultation = null;

        foreach (self::SCENARIOS as $index => $scenario) {
            $arrivalAt = $now->subMinutes((int) $scenario['minutes_ago']);
            $risk = isset($scenario['risk'])
                ? RiskLevel::query()->where('code', $scenario['risk'])->firstOrFail()
                : null;
            $encounter = Encounter::query()->create([
                'encounter_number' => $scenario['number'],
                'patient_id' => $patients[(int) $scenario['patient']]->getKey(),
                'health_unit_id' => $unit->getKey(),
                'entry_type_id' => $entryType->getKey(),
                'arrival_method_id' => $arrivalMethod->getKey(),
                'current_status' => $scenario['status'],
                'risk_level_id' => $risk?->getKey(),
                'administrative_priority' => $scenario['priority'],
                'arrival_at' => $arrivalAt,
                'registration_at' => $arrivalAt->addMinutes(4),
                'triage_started_at' => isset($scenario['triage']) ? $arrivalAt->addMinutes(12) : null,
                'triage_finished_at' => ($scenario['triage'] ?? null) === 'finalized' ? $arrivalAt->addMinutes(24) : null,
                'medical_started_at' => isset($scenario['medical']) ? $arrivalAt->addMinutes(42) : null,
                'medical_finished_at' => ($scenario['medical'] ?? null) === 'finalized' ? $arrivalAt->addMinutes(72) : null,
                'observation_started_at' => ($scenario['destination'] ?? null) === 'observation' ? $arrivalAt->addMinutes(72) : null,
                'closed_at' => in_array($scenario['status'], ['discharged', 'transferred'], true) ? $arrivalAt->addMinutes(90) : null,
                'assigned_specialty_id' => isset($scenario['triage']) ? $specialty->getKey() : null,
                'current_department_id' => $this->currentDepartmentId($scenario, $triageQueue, $medicalQueue),
                'current_room_id' => $this->currentRoomId($scenario, $triagePoint, $medicalPoint),
                'created_by' => $users['receptionist']->getKey(),
                'closed_by' => in_array($scenario['status'], ['discharged', 'transferred'], true) ? $users['doctor']->getKey() : null,
            ]);
            ReceptionRecord::query()->create([
                'encounter_id' => $encounter->getKey(),
                'operator_id' => $users['receptionist']->getKey(),
                'origin' => 'Demanda espontânea',
                'entry_reason' => $scenario['complaint'],
                'reception_notes' => 'Registro criado exclusivamente para demonstração local.',
            ]);
            EncounterStatusHistory::query()->create([
                'encounter_id' => $encounter->getKey(),
                'from_status' => null,
                'to_status' => $scenario['status'],
                'reason' => 'Cenário fictício de demonstração',
                'metadata' => ['source' => 'demo_seeder'],
                'changed_by' => $users['receptionist']->getKey(),
                'changed_at' => $arrivalAt,
            ]);

            $triageEntry = $this->createQueueEntry(
                $encounter,
                $triageQueue,
                'T'.str_pad((string) ($index + 101), 3, '0', STR_PAD_LEFT),
                $this->triageQueueStatus($scenario),
                $risk === null ? 0 : (int) $risk->priority_weight,
                $arrivalAt->addMinutes(4),
                $triagePoint,
                $users['triage_professional'],
                isset($scenario['triage']),
                ($scenario['triage'] ?? null) === 'finalized',
            );

            if (($scenario['triage'] ?? null) !== null) {
                $triage = $this->createTriage(
                    $encounter,
                    $triageEntry,
                    $scenario,
                    $risk,
                    $protocol,
                    $flowchart,
                    $discriminator,
                    $medicalQueue,
                    $triagePoint,
                    $users['triage_professional'],
                    $arrivalAt,
                );
                $this->createVitalSigns($triage, $encounter, $users['triage_professional'], $arrivalAt, $index);
            }

            if (in_array($scenario['status'], ['waiting_medical', 'in_medical_care', 'under_observation', 'discharged', 'transferred'], true)) {
                $medicalEntry = $this->createQueueEntry(
                    $encounter,
                    $medicalQueue,
                    'C'.str_pad((string) ($index + 201), 3, '0', STR_PAD_LEFT),
                    $this->medicalQueueStatus($scenario),
                    $risk === null ? 0 : (int) $risk->priority_weight,
                    $arrivalAt->addMinutes(24),
                    $medicalPoint,
                    $users['doctor'],
                    isset($scenario['medical']),
                    ($scenario['medical'] ?? null) === 'finalized',
                );

                if (isset($scenario['medical'])) {
                    $consultation = $this->createConsultation(
                        $encounter,
                        $medicalEntry,
                        $scenario,
                        $specialty,
                        $medicalPoint,
                        $users['doctor'],
                        $arrivalAt,
                    );
                    $this->createClinicalContent($consultation, $encounter, $users['doctor'], $scenario, $arrivalAt);

                    if (isset($scenario['destination'])) {
                        $this->createDestination($consultation, $encounter, $scenario, $users['doctor'], $arrivalAt);
                    }
                    if ($scenario['status'] === 'discharged') {
                        $dischargedConsultation = $consultation;
                    }
                }
            }

            PatientAccessLog::query()->create([
                'user_id' => $users['receptionist']->getKey(),
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->getKey(),
                'health_unit_id' => $unit->getKey(),
                'access_type' => 'registration',
                'purpose' => 'Atendimento demonstrativo',
                'route_name' => 'demo.seed',
                'occurred_at' => $arrivalAt,
            ]);
            AuditLog::query()->create([
                'user_id' => $users['receptionist']->getKey(),
                'health_unit_id' => $unit->getKey(),
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->getKey(),
                'action' => 'encounter.demo_created',
                'changed_fields' => ['current_status' => $scenario['status']],
                'context' => ['source' => 'demo_seeder', 'synthetic' => true],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'SYNC SUS Demo Seeder',
                'occurred_at' => $arrivalAt,
            ]);
        }

        if (! $dischargedConsultation instanceof MedicalConsultation) {
            throw new RuntimeException('O cenário de alta demonstrativo não foi criado.');
        }

        return ['discharged' => $dischargedConsultation];
    }

    private function createQueueEntry(
        Encounter $encounter,
        Queue $queue,
        string $ticket,
        string $status,
        int $priorityWeight,
        CarbonImmutable $enteredAt,
        ServicePoint $servicePoint,
        User $professional,
        bool $called,
        bool $completed,
    ): QueueEntry {
        $entry = QueueEntry::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $queue->getKey(),
            'ticket_number' => $ticket,
            'priority_weight' => $priorityWeight,
            'status' => $status,
            'entered_at' => $enteredAt,
            'first_called_at' => $called ? $enteredAt->addMinutes(4) : null,
            'last_called_at' => $called ? $enteredAt->addMinutes(4) : null,
            'service_started_at' => $called ? $enteredAt->addMinutes(6) : null,
            'exited_at' => $completed ? $enteredAt->addMinutes(18) : null,
            'call_count' => $called ? 1 : 0,
            'service_point_id' => $called ? $servicePoint->getKey() : null,
            'assigned_user_id' => $called ? $professional->getKey() : null,
            'exit_reason' => $completed ? 'completed' : null,
        ]);

        if ($called) {
            QueueCall::query()->create([
                'queue_entry_id' => $entry->getKey(),
                'queue_id' => $queue->getKey(),
                'service_point_id' => $servicePoint->getKey(),
                'called_by' => $professional->getKey(),
                'call_type' => 'call',
                'call_number' => 1,
                'ticket_snapshot' => $ticket,
                'called_at' => $enteredAt->addMinutes(4),
            ]);
        }

        return $entry;
    }

    /** @param array<string, mixed> $scenario */
    private function createTriage(
        Encounter $encounter,
        QueueEntry $entry,
        array $scenario,
        ?RiskLevel $risk,
        TriageProtocol $protocol,
        TriageFlowchart $flowchart,
        TriageDiscriminator $discriminator,
        Queue $destinationQueue,
        ServicePoint $servicePoint,
        User $professional,
        CarbonImmutable $arrivalAt,
    ): TriageAssessment {
        $finalized = $scenario['triage'] === 'finalized';

        return TriageAssessment::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_entry_id' => $entry->getKey(),
            'professional_id' => $professional->getKey(),
            'service_point_id' => $servicePoint->getKey(),
            'status' => $scenario['triage'],
            'chief_complaint' => $scenario['complaint'],
            'symptom_onset' => 'Hoje',
            'brief_history' => 'História clínica resumida e totalmente fictícia.',
            'pain_scale' => $risk?->code === 'RED' ? 9 : 5,
            'has_reported_allergies' => false,
            'reported_allergies' => 'Nega alergias no momento da triagem.',
            'uses_medications' => false,
            'known_conditions' => 'Sem outras condições referidas.',
            'fall_risk' => 'low',
            'requires_isolation' => false,
            'violence_signs' => false,
            'initial_exam' => 'Paciente consciente, orientado e colaborativo.',
            'observations' => 'Conteúdo demonstrativo; não representa informação clínica real.',
            'triage_protocol_id' => $protocol->getKey(),
            'protocol_version' => $protocol->version,
            'triage_flowchart_id' => $flowchart->getKey(),
            'triage_discriminator_id' => $finalized ? $discriminator->getKey() : null,
            'risk_level_id' => $risk?->getKey(),
            'risk_justification' => $finalized ? 'Classificação fictícia para visualização do fluxo.' : null,
            'destination_queue_id' => $finalized ? $destinationQueue->getKey() : null,
            'routing_notes' => $finalized ? 'Encaminhar à clínica médica.' : null,
            'started_at' => $arrivalAt->addMinutes(12),
            'finalized_at' => $finalized ? $arrivalAt->addMinutes(24) : null,
            'finalized_by' => $finalized ? $professional->getKey() : null,
        ]);
    }

    private function createVitalSigns(
        TriageAssessment $triage,
        Encounter $encounter,
        User $professional,
        CarbonImmutable $arrivalAt,
        int $index,
    ): void {
        VitalSignMeasurement::query()->create([
            'triage_assessment_id' => $triage->getKey(),
            'encounter_id' => $encounter->getKey(),
            'recorded_by' => $professional->getKey(),
            'source' => 'triage',
            'measured_at' => $arrivalAt->addMinutes(16),
            'systolic_bp' => 118 + ($index * 3),
            'diastolic_bp' => 76 + $index,
            'heart_rate' => 74 + ($index * 2),
            'respiratory_rate' => 16 + ($index % 4),
            'temperature_c' => $index === 2 ? 38.2 : 36.6,
            'oxygen_saturation' => $index === 5 ? 93 : 98,
            'blood_glucose' => 92 + $index,
            'weight_kg' => 62 + $index,
            'height_cm' => 168,
            'bmi' => round((62 + $index) / (1.68 ** 2), 2),
            'pain_scale' => $triage->pain_scale,
            'glasgow_score' => 15,
            'clinical_alerts' => [],
            'technical_alerts' => [],
            'range_configuration_version' => '2026.1',
            'notes' => 'Sinais vitais fictícios para demonstração.',
        ]);
    }

    /** @param array<string, mixed> $scenario */
    private function createConsultation(
        Encounter $encounter,
        QueueEntry $entry,
        array $scenario,
        Specialty $specialty,
        ServicePoint $servicePoint,
        User $doctor,
        CarbonImmutable $arrivalAt,
    ): MedicalConsultation {
        $finalized = $scenario['medical'] === 'finalized';

        return MedicalConsultation::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_entry_id' => $entry->getKey(),
            'professional_id' => $doctor->getKey(),
            'specialty_id' => $specialty->getKey(),
            'room_id' => $servicePoint->room_id,
            'status' => $scenario['medical'],
            'chief_complaint' => $scenario['complaint'],
            'present_illness_history' => 'Evolução clínica fictícia registrada para demonstrar o prontuário.',
            'personal_history' => 'Sem antecedentes adicionais relevantes no cenário de demonstração.',
            'current_medications' => 'Nega uso contínuo.',
            'allergies_summary' => 'Conforme cadastro e triagem.',
            'review_of_systems' => 'Sem outras alterações referidas.',
            'additional_notes' => 'Este atendimento contém apenas dados sintéticos.',
            'conduct_summary' => $finalized ? 'Orientações, medidas de suporte e acompanhamento.' : null,
            'procedures_summary' => $finalized ? 'Avaliação clínica realizada.' : null,
            'guidance' => $finalized ? 'Retornar em caso de piora ou sinais de alarme.' : null,
            'requires_reassessment' => ($scenario['destination'] ?? null) === 'observation',
            'content_hash' => $finalized ? hash('sha256', (string) $scenario['number']) : null,
            'started_at' => $arrivalAt->addMinutes(42),
            'finalized_at' => $finalized ? $arrivalAt->addMinutes(72) : null,
            'finalized_by' => $finalized ? $doctor->getKey() : null,
        ]);
    }

    /** @param array<string, mixed> $scenario */
    private function createClinicalContent(
        MedicalConsultation $consultation,
        Encounter $encounter,
        User $doctor,
        array $scenario,
        CarbonImmutable $arrivalAt,
    ): void {
        PhysicalExam::query()->create([
            'medical_consultation_id' => $consultation->getKey(),
            'general_state' => 'Bom estado geral, hidratado e corado.',
            'consciousness' => 'Consciente e orientado.',
            'respiratory' => 'Murmúrio vesicular presente bilateralmente.',
            'cardiovascular' => 'Ritmo cardíaco regular, sem sopros.',
            'abdomen' => 'Plano, flácido e sem sinais de irritação peritoneal.',
            'neurological' => 'Sem déficits focais evidentes.',
            'free_text' => 'Exame físico demonstrativo.',
        ]);

        $diagnosisCode = DiagnosisCode::query()
            ->where('code', $scenario['risk'] === 'RED' ? 'R52' : 'R50.9')
            ->firstOrFail();
        Diagnosis::query()->create([
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => $consultation->getKey(),
            'diagnosis_code_id' => $diagnosisCode->getKey(),
            'code' => $diagnosisCode->code,
            'description' => $diagnosisCode->description,
            'diagnosis_type' => 'hypothesis',
            'is_primary' => true,
            'status' => 'active',
            'notes' => 'Hipótese diagnóstica fictícia.',
            'diagnosed_by' => $doctor->getKey(),
            'diagnosed_at' => $arrivalAt->addMinutes(58),
        ]);

        if (($scenario['destination'] ?? null) === 'discharge') {
            $prescription = Prescription::query()->create([
                'encounter_id' => $encounter->getKey(),
                'medical_consultation_id' => $consultation->getKey(),
                'professional_id' => $doctor->getKey(),
                'prescription_type' => 'simple',
                'status' => 'finalized',
                'general_instructions' => 'Manter hidratação e seguir orientações de alta.',
                'version' => 1,
                'finalized_at' => $arrivalAt->addMinutes(70),
            ]);
            $prescription->items()->create([
                'medication_name' => 'Medicamento demonstrativo',
                'presentation' => 'Comprimido',
                'concentration' => '500 mg',
                'dose' => 1,
                'dose_unit' => 'comprimido',
                'route' => 'oral',
                'frequency' => 'A cada 8 horas, se necessário',
                'duration_value' => 2,
                'duration_unit' => 'days',
                'quantity' => '6 comprimidos',
                'instructions' => 'Uso exclusivamente ilustrativo; não constitui prescrição real.',
                'is_immediate' => false,
                'is_as_needed' => true,
                'as_needed_condition' => 'Sintomas',
                'display_order' => 10,
            ]);
        }
    }

    /** @param array<string, mixed> $scenario */
    private function createDestination(
        MedicalConsultation $consultation,
        Encounter $encounter,
        array $scenario,
        User $doctor,
        CarbonImmutable $arrivalAt,
    ): void {
        $destination = (string) $scenario['destination'];
        EncounterDestination::query()->create([
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => $consultation->getKey(),
            'destination_type' => $destination,
            'reason' => match ($destination) {
                'observation' => 'Monitoramento e reavaliação clínica.',
                'transfer' => 'Necessidade de recurso de maior complexidade.',
                default => 'Melhora clínica e condições de seguimento domiciliar.',
            },
            'clinical_condition' => 'Estável no momento do registro demonstrativo.',
            'clinical_summary' => 'Resumo clínico composto exclusivamente por dados sintéticos.',
            'instructions' => 'Seguir as orientações fornecidas pela equipe.',
            'warning_signs' => 'Retornar imediatamente em caso de piora.',
            'follow_up' => $destination === 'discharge' ? 'Unidade básica de referência' : null,
            'destination_institution' => $destination === 'transfer' ? 'Hospital de Referência Fictício' : null,
            'destination_city' => $destination === 'transfer' ? 'Fortaleza' : null,
            'destination_department' => $destination === 'transfer' ? 'Traumatologia' : null,
            'transport_method' => $destination === 'transfer' ? 'Ambulância' : null,
            'details' => ['synthetic' => true],
            'recorded_by' => $doctor->getKey(),
            'occurred_at' => $arrivalAt->addMinutes(72),
        ]);
    }

    private function seedDocuments(
        MedicalConsultation $consultation,
        User $doctor,
        HealthUnit $unit,
    ): void {
        if (ClinicalDocument::query()
            ->where('medical_consultation_id', $consultation->getKey())
            ->where('document_type', 'discharge_guidance')
            ->exists()) {
            return;
        }

        $request = Request::create('/demo/seed', 'POST', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SYNC SUS Demo Seeder',
        ]);
        app(IssueClinicalDocumentAction::class)->execute(
            $consultation,
            [
                'document_type' => 'discharge_guidance',
                'title' => 'Orientações de alta — demonstração',
                'body' => "Documento gerado para demonstrar a emissão, o versionamento e a verificação de documentos.\n\nOs dados são inteiramente fictícios e este conteúdo não possui validade clínica.",
                'additional_information' => 'Ambiente local de demonstração do SYNC SUS.',
            ],
            $doctor,
            $unit,
            $request,
        );
    }

    /** @param array<string, mixed> $scenario */
    private function triageQueueStatus(array $scenario): string
    {
        return match ($scenario['status']) {
            'waiting_triage' => 'waiting',
            'in_triage' => 'in_service',
            default => 'completed',
        };
    }

    /** @param array<string, mixed> $scenario */
    private function medicalQueueStatus(array $scenario): string
    {
        return match ($scenario['status']) {
            'waiting_medical' => 'waiting',
            'in_medical_care' => 'in_service',
            default => 'completed',
        };
    }

    /** @param array<string, mixed> $scenario */
    private function currentDepartmentId(array $scenario, Queue $triageQueue, Queue $medicalQueue): ?int
    {
        if ($scenario['status'] === 'waiting_triage' || $scenario['status'] === 'in_triage') {
            return $triageQueue->department_id;
        }

        return in_array($scenario['status'], ['waiting_medical', 'in_medical_care', 'under_observation'], true)
            ? $medicalQueue->department_id
            : null;
    }

    /** @param array<string, mixed> $scenario */
    private function currentRoomId(
        array $scenario,
        ServicePoint $triagePoint,
        ServicePoint $medicalPoint,
    ): ?int {
        return match ($scenario['status']) {
            'in_triage' => $triagePoint->room_id,
            'in_medical_care', 'under_observation' => $medicalPoint->room_id,
            default => null,
        };
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
