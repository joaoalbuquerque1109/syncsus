# Roteiro — Arquitetura Core + Banco por Unidade

> **Superado por `docs/SYNC_HOSP_CORE_UNIT_DB_MASTER_PLAN.md`.** Mantido como registro
> histórico do primeiro levantamento; o plano mestre reverifica e amplia o conteúdo
> abaixo, incluindo correções a lacunas encontradas aqui (ver seção 1 do plano mestre).

> Documento de planejamento e registro de levantamento. Nenhum código foi alterado ao produzi-lo. Este NÃO é um prompt de implementação — é um roteiro faseado com decisões pendentes que precisam de resposta antes de qualquer fase virar código.

## 1. Por que este documento existe

`docs/SYNC_HOSP_MODELO_BANCO_RESUMIDO.md` propõe dividir o banco único atual em `sync_hosp_core` (organizações, unidades, usuários, profissionais, identidade de paciente) + um banco MySQL por unidade (`sync_hosp_u0001`, `sync_hosp_u0002`...) para todo o dado clínico/operacional. Essa proposta nunca foi implementada — o sistema roda inteiro hoje num único banco, com isolamento por coluna (`organization_id`/`health_unit_id`).

A decisão de seguir com essa migração foi confirmada como deliberada (preferência arquitetural de longo prazo + redução de blast radius/segurança), não uma reação a uma exigência pontual. Isso justifica um levantamento sério antes de qualquer código — o resultado abaixo (dois levantamentos de código independentes) mostra que **isto é uma iniciativa de vários meses**, não uma fase do mesmo porte das anteriores (catálogo de exames, N+1 de fila, redução de lock em documentos).

## 2. Números concretos do levantamento

- **64 relacionamentos Eloquent** cruzam a fronteira Core/Unidade proposta hoje via FK direta — impossível de manter como está, porque MySQL/Eloquent não fazem FK nem join nativo entre conexões de banco diferentes.
- `whereHas('encounter.patient', ...)` (busca de paciente na tela de fila, `app/Modules/Queues/Presentation/Http/Controllers/QueueController.php:87-90`) **quebra por completo**, não degrada — `whereHas` compila como subquery correlacionada na mesma conexão; não existe fallback automático.
- `Patient` hoje **não corresponde** ao modelo da proposta: `patient_contacts`, `patient_addresses`, `patient_allergies`, `patient_conditions`, `patient_guardians`, `patient_medications`, `patient_social_histories` são todos compartilhados por `organization_id`, não por unidade. A proposta exige que isso vire por unidade (`unit_patients`) — uma **decisão clínica/de produto**, não só técnica (ver seção 4.1).
- `AuditLog` hoje é um único model/tabela (`app/Modules/Audit/Infrastructure/Eloquent/AuditLog.php`) servindo os dois papéis que a proposta separa (`security_audit_logs` no Core + `audit_logs` na Unidade).
- Só existem **2 jobs agendados reais** no scheduler hoje (`synclab:dispatch-pending`, `synclab:dispatch-received-results`, `routes/console.php:153-159`), e **ambos fazem query sem nenhum filtro de unidade** — são o exemplo mais concreto e contido do que precisa virar "fan-out por unidade" no futuro.
- `config/database.php`, `tests/TestCase.php` e as 38 migrations atuais não têm **nenhum** alicerce para múltiplas conexões — é greenfield nesse ponto, não há nada parcialmente pronto para reaproveitar.

## 3. O que este documento é (e o que não é)

É um **roteiro faseado com pontos de decisão explícitos** — não um prompt único para o Codex implementar tudo. Cada fase da seção 6 seria, quando chegar a hora, detalhada num documento próprio (`docs/CODEX_CORE_UNIT_DB_FASE_N.md`) com o mesmo nível de precisão (arquivos exatos, testes, prompt pronto pro Codex) usado nos planos anteriores — escrito **depois** que a fase anterior estiver validada e as decisões relevantes respondidas, não tudo de uma vez.

---

## 4. Decisões que precisam de resposta do time antes de qualquer código

### 4.1 Dado clínico do paciente: por unidade ou compartilhado?

A proposta original recomenda "local por unidade" (§21.1/§21.2 de `SYNC_HOSP_MODELO_BANCO_RESUMIDO.md`), mas isso significa, na prática: uma alergia, condição ou medicação registrada na Unidade A **não aparece automaticamente** para um profissional atendendo o mesmo paciente na Unidade B. Hoje é o oposto — esses dados são compartilhados por organização, visíveis em qualquer unidade da mesma rede.

Essa é uma decisão com implicação de segurança do paciente, não só de modelagem de dados. Precisa de aval de alguém com responsabilidade clínica no projeto, não só arquitetural.

### 4.2 Catálogos ainda ambíguos

A proposta original já deixava em aberto (§21.3): `risk_levels`, `entry_types`, `arrival_methods`, catálogo de exame/documento. O levantamento atual encontrou outros na mesma situação, que não existiam quando a proposta foi escrita: `DiagnosisCode` (CID-10), `SusProcedure`, e toda a família do catálogo canônico de exames (`Exam`, `ExamGroup`, `ExamMapping`, `LaboratoryIntegration`, `LaboratoryExam`, `HealthUnitExam`, etc.).

Para cada um: fica no Core com leitura cross-connection, ou é duplicado/sincronizado em cada banco de unidade? A resposta muda o desenho de sincronização inteiro — `SusProcedure`/`DiagnosisCode` são catálogos nacionais que fazem sentido ficar centralizados; já `HealthUnitExam`/`LaboratoryExam` são inerentemente por unidade na arquitetura atual (ver `docs/CODEX_EXAM_GROUPS_AND_SUS_CATALOG_PLAN.md`) e precisam de uma decisão própria.

### 4.3 `AuditLog` dividido, e o pivot profissional↔unidade sem integridade referencial possível

`AuditLog` precisa virar dois models/tabelas (identidade no Core, clínico na Unidade). Perguntas em aberto: algum evento precisa aparecer nos dois? Como fica a consulta de auditoria hoje unificada (`app/Modules/Audit/Presentation/Http/Controllers/AuditTrailController.php`) quando os dados estão em conexões diferentes?

Separadamente: `health_professional_queue` e `health_professional_service_point` são pivots que hoje ligam uma linha Core (`HealthProfessional`) a uma linha que seria Unidade (`Queue`/`ServicePoint`). Depois do split, nenhum banco consegue impor integridade referencial nisso via FK — precisa de uma estratégia explícita (referência por `public_id` + rotina de verificação de integridade, por exemplo), não só "trocar o tipo da coluna".

### 4.4 Estratégia de migração do dado já existente

Hoje há um banco único com dado real (pacientes, atendimentos, documentos, auditoria). Big-bang (todas as unidades de uma vez) ou unidade-por-unidade? Recomendo unidade-por-unidade com uma unidade piloto primeiro (ver Fase 3 na seção 6) — reduz o raio de dano de qualquer erro de migração a uma única unidade em vez do sistema inteiro.

**Sem essas quatro decisões, não faz sentido especificar migrations/arquivos em detalhe — qualquer plano de implementação escrito hoje seria baseado em suposição, não em decisão.**

---

## 5. Inventário técnico completo (para não perder o levantamento já feito)

### 5.1 Classificação Core vs Unidade

**Core** (ficaria em `sync_hosp_core`): `Organization`, `HealthUnit`, `Specialty`, `User`, `HealthProfessional`, `ProfessionalRegistration`, `Patient`, `PatientIdentifier`. Pivots Core↔Core sem impacto: `health_professional_specialty`, `health_professional_health_unit`.

**Unidade** (ficaria em `sync_hosp_uXXXX`): `Department`, `Room`, `ServicePoint`, `PatientContact`, `PatientAddress`, `PatientGuardian`, `PatientAllergy`, `PatientCondition`, `PatientMedication`\*, `PatientSocialHistory`\*, `PatientAccessLog`, `Encounter`, `ReceptionRecord`, `EncounterStatusHistory`, `EncounterCompanion`\*, `IdempotencyKey`\*, `NumberSequence`\*, `Queue`, `QueueEntry`, `QueueCall`, `QueueEntryHistory`\*, `QueueTransfer`\*, `QueueSequence`\*, `Panel`\*, `TriageAssessment`, `VitalSignMeasurement`, `TriageAddendum`\*, `MedicalConsultation`, `Diagnosis`, `ClinicalNote`, `Prescription`, `PrescriptionItem`, `ExamOrder`, `ExamOrderItem`, `ExamResult`, `PhysicalExam`\*, `EncounterDestination`\*, `MedicalAddendum`\*, `Referral`\*, `ClinicalDocument`, `DocumentVersion`, `MedicalCertificate`\*, `LaboratoryOrderTransmission`\*, `LaboratoryResultIngestion`\*, `LaboratoryTransmissionAttempt`\*, `MedicalShiftAttendance`\*.

(\* = não citado explicitamente na proposta original, classificado por inferência de formato/uso — confirmar caso a caso na decisão 4.2 quando aplicável.)

**Ambíguos** (decisão 4.2): `RiskLevel`, `EntryType`, `ArrivalMethod`, `DiagnosisCode`, `SusProcedure`, `Exam`, `ExamGroup`, `ExamGroupItem`, `ExamMapping`, `ExamCatalogImportCandidate`, `ExamGroupImportConflict`, `LaboratoryMaterial`, `LaboratoryIntegration`, `HealthUnitExam`, `LaboratoryExam`, `LaboratoryExamComponent`, `TriageProtocol`/`TriageFlowchart`/`TriageDiscriminator`/`VitalSignRange`.

### 5.2 Os 64 relacionamentos cross-boundary (Unidade → Core)

**Patients** (8): `PatientAddress.patient()`, `PatientAllergy.patient()`, `PatientCondition.patient()`, `PatientContact.patient()`, `PatientGuardian.patient()`, `PatientMedication.patient()`+`recordedBy()`, `PatientSocialHistory.patient()`+`recordedBy()`, `PatientAccessLog.user()`+`patient()`+`healthUnit()`.

**Reception** (3): `Encounter.patient()` — o mais crítico do sistema —, `Encounter.healthUnit()`, `Encounter.assignedSpecialty()`.

**Queues** (8): `Queue.healthUnit()`, `Queue.specialty()`, `Queue.professionals()` (BelongsToMany via pivot `health_professional_queue`), `QueueCall.caller()`, `QueueEntry.assignedUser()`, `QueueEntryHistory.performer()`, `QueueTransfer.transferredBy()`, `Panel.healthUnit()`.

**Triage** (3): `TriageAssessment.professional()`, `VitalSignMeasurement.recordedBy()`, `TriageAddendum.author()`.

**Medical** (17): `MedicalConsultation.professional()`+`specialty()`, `Diagnosis.diagnosedBy()`, `ClinicalNote.author()`+`specialty()`, `MedicalAddendum.author()`, `EncounterDestination.recordedBy()`, `ExamOrder.requestedBy()`+`createdBy()`+`cancelledBy()`+`organization()`+`healthUnit()` (mais o hook `booted()` que já faz lookup cross-boundary em tempo de escrita, `ExamOrder.php:24-49`), `ExamResult.recordedBy()`, `Prescription.professional()`, `Referral.requestedBy()`+`specialty()`.

**Documents** (6): `ClinicalDocument.healthUnit()`+`patient()`+`creator()`+`voidedBy()`, `DocumentVersion.creator()`, `MedicalCertificate.healthUnit()`+`patient()`+`issuer()`.

**Laboratory** (10): `LaboratoryOrderTransmission.organization()`+`healthUnit()`, `LaboratoryIntegration.organization()`+`healthUnit()`, `HealthUnitExam.healthUnit()`+`enabledBy()`, `ExamCatalogImportCandidate.organization()`+`resolvedBy()`, `ExamGroupImportConflict.organization()`+`resolvedBy()`, `ExamMapping.mappedBy()`, `Exam.organization()`, `ExamGroup.organization()`.

**Professionals** (3): `MedicalShiftAttendance.organization()`+`healthUnit()`+`user()`.

**Audit** (3): `AuditLog.user()`+`healthUnit()`+`patient()`.

**Reverso — pivot Core↔Unidade sem integridade possível** (3): `HealthProfessional.queues()`, `HealthProfessional.servicePoints()`, `ServicePoint.professionals()` — mesmos pivots `health_professional_queue`/`health_professional_service_point`, ver decisão 4.3.

### 5.3 `with()`/`whereHas()` que cruzam a fronteira hoje (amostra representativa, não exaustiva)

- `MedicalConsultationController.php:80-92` — `encounter.patient.identifiers/allergies/conditions/medications/socialHistory`.
- `ReceptionController.php:123-127` — `encounter.load(['patient.identifiers','healthUnit',...])`.
- `QueueController.php:63-69` — `assignedUser`, `encounter.patient...`.
- `QueueController.php:87-90` — `whereHas('encounter.patient', ...)`, quebra por completo (não degrada).
- `PublicPanelController.php:34,61-66` — `entry.encounter.patient` no endpoint de polling público de painel.
- `ClinicalDocumentController.php:46,111` — `patient`, `healthUnit.organization`, `creator`, `voidedBy`, `versions.creator`, inclusive na página pública de verificação de documento.

### 5.4 Infraestrutura sem nenhum alicerce hoje

- **`EnsureActiveHealthUnit.php`** — resolve unidade ativa e grava em `$request->attributes`/sessão, mas não configura conexão nenhuma. É o ponto natural onde a troca de conexão entraria (fase 0).
- **`config/database.php`** — só as conexões padrão do Laravel, nenhuma conexão nomeada por unidade, nenhum resolvedor dinâmico.
- **Jobs** (`SubmitLaboratoryOrderJob`, `ProcessSynclabExamResultJob`) — construtor recebe só um ID numérico, `handle()`/`failed()` reconsultam pela conexão padrão. Precisariam carregar contexto de unidade no payload.
- **Scheduler** (`routes/console.php:153-159`) — os 2 jobs agendados reais fazem query global sem filtro de unidade (ver seção 2).
- **Testes** (`tests/TestCase.php`) — fixtures (`createHealthUnit`, `registerDoctor`, etc.) misturam Core e Unidade na mesma chamada contra uma única conexão sqlite `:memory:`. 35 arquivos de teste usam `RefreshDatabase` sem nenhum conceito de múltiplas conexões.
- **Migrations** — 38 arquivos numa pasta só, sem nenhuma separação/tag Core vs Unidade.
- **Seeders** — `DatabaseSeeder.php` roda tudo numa passada só contra uma conexão. `OperationalCatalogSeeder.php` é o exemplo mais claro: itera organizações (Core) e depois itera unidades (`foreach (HealthUnit::query()->get() as $unit) { $this->seedUnit($unit); }`) tudo na mesma conexão/transação — precisaria virar duas passadas distintas.

---

## 6. Roteiro faseado

```text
FASE 0 — Fundação de resolução de conexão (sem mover dado nenhum)
  Objetivo: provar o mecanismo de troca de conexão por unidade, sem risco de dado.
  - Cada model explícito sobre sua camada (Core vs Unidade), decidida a partir
    do inventário da seção 5.1.
  - EnsureActiveHealthUnit.php ganha a responsabilidade de resolver a conexão
    da unidade ativa.
  - Toda conexão "de unidade" continua apontando pro MESMO banco físico por
    enquanto — multi-connection que ainda não separa nada fisicamente.
  - Corrige, um por um, os 64 relacionamentos da seção 5.2 (viram referência
    por public_id + lookup explícito no Core) e os 2 jobs agendados sem
    filtro de unidade — sem risco de perda de dado, banco físico continua
    único.
  IMPACTO: nenhuma migração de dado. Risco: baixo.

FASE 1 — Redesenho de testes
  tests/TestCase.php e as fixtures precisam entender Core vs Unidade antes de
  confiarmos nos testes da Fase 0/2.

FASE 2 — Decisão e migração do dado de paciente (maior risco)
  Resolve a decisão 4.1, introduz unit_patients, migra contatos/endereços/
  alergias/condições/medicações/histórico social de "compartilhado por
  organização" para "por unidade" — com plano de dado explícito para o que
  já existe hoje.

FASE 3 — Provisionamento físico, piloto com UMA unidade
  Banco físico separado de verdade, só para uma unidade piloto, resto ainda
  no banco único. Valida o ciclo completo (migration, seed, conexão
  dinâmica, backup, jobs) num raio de dano pequeno.

FASE 4 — Rollout para as demais unidades + descomissionar suposições de
  banco único (dashboards cross-unidade com fan-out, backup/ops, AuditLog
  dividido em definitivo).
```

Cada fase, quando chegar a hora, ganha seu próprio `docs/CODEX_CORE_UNIT_DB_FASE_N.md` com arquivos exatos, testes e prompt pronto para o Codex — escrito depois da fase anterior validada e das decisões relevantes da seção 4 respondidas.

## 7. Próximo passo concreto

Nenhum código nesta rodada. O próximo passo depende de vocês responderem as 4 decisões da seção 4 — a partir disso, escrevo o `docs/CODEX_CORE_UNIT_DB_FASE_0.md` com o mesmo nível de detalhe usado nos planos anteriores.
