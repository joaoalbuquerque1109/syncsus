# Plano Mestre — Arquitetura Core + Banco por Unidade

> **Atualização de implementação (2026-08-09):** as Fases 0, 1 e 2 foram autorizadas
> e implementadas. O control plane provider-neutral da Fase 3 também foi implementado;
> a execução em uma unidade piloto real permanece uma decisão operacional explícita.
> Consulte `docs/CODEX_CORE_UNIT_DB_FASE_0.md`,
> `docs/CODEX_CORE_UNIT_DB_FASE_1.md`, `docs/CODEX_CORE_UNIT_DB_FASE_2.md` e
> `docs/CODEX_CORE_UNIT_DB_FASE_3.md`. As restrições
> históricas abaixo registram o contexto em que o plano foi escrito, não o estado atual.

> Documento de planejamento. Nenhum código, migration ou configuração foi alterado ao
> produzi-lo. Supersede `docs/CODEX_CORE_UNIT_DB_ROADMAP.md` (mantido como registro
> histórico do primeiro levantamento; ver nota no topo daquele arquivo). Este documento
> não deve ser executado por um agente de implementação — o artefato executável é
> `docs/CODEX_CORE_UNIT_DB_FASE_0.md`, e mesmo esse não deve ser implementado nesta
> rodada.

## 0. Sumário executivo

O SyncHosp roda hoje inteiro num único banco MySQL, com isolamento por coluna
(`organization_id`/`health_unit_id`). `docs/SYNC_HOSP_MODELO_BANCO_RESUMIDO.md` propõe
dividir isso em `sync_hosp_core` (identidade, organizações, unidades, profissionais,
paciente) + um banco físico por unidade (`sync_hosp_uXXXX`) para todo o dado
clínico/operacional. Essa proposta é uma decisão arquitetural deliberada (preferência de
longo prazo + redução de blast radius/segurança), confirmada pelo usuário — não uma
reação a uma exigência pontual, contratual ou regulatória.

Este plano mestre re-verifica o levantamento técnico anterior contra o estado atual do
repositório, incorpora os princípios arquiteturais obrigatórios definidos para esta
iniciativa (seção 2), amplia a matriz de queries quebradas com achados novos (seção 6),
identifica um bloqueio estrutural não documentado anteriormente — descoberta de tenant em
endpoints públicos (seção 7) — e organiza tudo num roteiro de 8 fases (seção 21).

**Conclusão inalterada da rodada anterior**: isto é uma iniciativa de vários meses, não
uma fase do porte das anteriores (catálogo de exames, N+1 de fila). O maior risco não é
técnico — é o de tratar a Fase 2 (dado de paciente) como um refactor de schema quando na
verdade é uma mudança de comportamento clínico (alergia registrada numa unidade deixa de
aparecer automaticamente noutra). As cinco decisões bloqueantes foram respondidas nesta
rodada (seção 22) — prontuário local por unidade com identidade global no Core, catálogos
híbridos, `AuditLog` dividido sem duplicação automática, migração unidade-por-unidade com
piloto, e criptografia/fingerprint de CPF/CNS. Nenhuma fase de código deve começar sem
autorização explícita adicional do usuário — as decisões desbloqueiam o planejamento, não
a execução (ver `docs/CODEX_CORE_UNIT_DB_FASE_0.md`).

---

## 1. Correções frente ao levantamento anterior

Por instrução explícita, nenhuma premissa é aceita sem reverificação. Re-executei buscas
diretas no código (não reaproveitei conclusões antigas sem checagem) e encontrei quatro
pontos que precisam de correção ou adição em relação ao `docs/CODEX_CORE_UNIT_DB_ROADMAP.md`
anterior:

### 1.1 A matriz de queries quebradas estava incompleta

O roadmap anterior (seção 5.3) listava só uma amostra representativa, com um único exemplo
"quebra por completo" (`QueueController.php:87-90`). Uma varredura direta por
`whereHas`/`withCount`/`join`/`leftJoin` em todo `app/Modules` (38 arquivos com
`whereHas`/`has`, 5 com `join`/`leftJoin`) encontrou pelo menos três padrões adicionais,
igualmente quebrados, que não estavam documentados:

- `Queue::whereHas('healthUnit', ...)` — `HealthProfessionalController.php:110`
- `ServicePoint::whereHas('room.department.healthUnit', ...)` — `HealthProfessionalController.php:118`
- `Queue::whereHas('professionals', ...)` / `QueueVisibilityService.php:37-90` — via pivot `health_professional_queue`

Esses três são modelos que ficariam do lado **Unidade** (`Queue`, `ServicePoint`) fazendo
`whereHas` contra modelos/pivots que ficariam do lado **Core** (`HealthUnit`,
`HealthProfessional` via pivot). Isso é o mesmo tipo de quebra do exemplo já conhecido do
`QueueController`, só que em telas de gestão de profissionais/service points, não de fila.
Consequência prática: a lista de "arquivos a corrigir na Fase 0" cresce; ver matriz
completa na seção 6.

### 1.2 Descoberta de tenant em endpoints públicos não estava no levantamento anterior

Nenhuma versão anterior deste plano mencionava os dois endpoints públicos existentes
(`routes/web.php:42-47`, `PublicPanelController` e
`ClinicalDocumentController::verify`). Ambos resolvem a entidade **sem nenhum contexto de
tenant na requisição**:

- `Panel` é resolvido por route-model-binding usando `public_id` como route key
  (`Panel::getRouteKeyName()`), sem sessão nem header de unidade.
- `ClinicalDocumentController::verify()` (linha 108-116) faz
  `ClinicalDocument::query()->where('verification_code', $verificationCode)->firstOrFail()`
  — busca global, sem filtro de unidade, porque o código de verificação é a única coisa
  que o visitante anônimo tem.

Isso é um bloqueio estrutural que o levantamento anterior não capturou e que a proposta
original também não trata. Detalhado na seção 7 — é tratado aqui como adição obrigatória
ao escopo, não como nota lateral.

### 1.3 `PatientIdentifier` não tem criptografia nem fingerprint hoje

Verificado diretamente no model (`app/Modules/Patients/Infrastructure/Eloquent/PatientIdentifier.php`):
os únicos casts são `type` (enum), `issued_at`/`verified_at` (datas) e `is_primary`
(boolean). O campo usado para busca e máscara é `normalized_value`, em texto plano, sem
cast `encrypted` e sem coluna de fingerprint/hash. Qualquer seção deste plano que trate
"reaproveitar criptografia/fingerprint existente" para o desenho de busca cross-tenant de
paciente (seção 8) está partindo de uma premissa incorreta — é trabalho greenfield, não
uma extensão de algo já implementado.

### 1.4 Convenção de ADR já existe — não é uma pasta `docs/adr/`

O projeto já registra decisões arquiteturais em `docs/DECISIONS.md`, um arquivo único com
entradas numeradas `ADR-001` a `ADR-010+` (Laravel 13/PHP 8.5, monólito modular, unidade
ativa na sessão revalidada por requisição, auditoria síncrona de identidade, etc.). Por
`CLAUDE.md` ("reuse existing conventions before introducing new abstractions"), o
Entregável B deste plano usa essa convenção — propostas de ADR redigidas no formato que já
existe, para o time colar em `docs/DECISIONS.md` quando ratificar (seção 20) — e não uma
nova estrutura de pasta `docs/adr/`.

### 1.5 Confirmações (sem divergência)

Re-verificados e confirmados sem alteração: os 64 relacionamentos cross-boundary da
Fase de identidade/paciente (seção 5.2 do roadmap anterior), `Patient` e seus 7 modelos
filhos compartilhados por `organization_id` (não por unidade), `AuditLog` como model único
conflando os dois papéis, os 2 jobs agendados sem filtro de unidade
(`routes/console.php:153-159`), ausência total de infraestrutura multi-conexão em
`config/database.php`/`tests/TestCase.php`/migrations, e ausência de Laravel
Horizon/Octane no `composer.json` (relevante para a seção 18 — não há workers persistentes
hoje, então o risco de vazamento de tenant em processo de longa duração é preventivo, não
uma mitigação de algo já em produção).

---

## 2. Princípios arquiteturais obrigatórios

Estes princípios não são negociáveis por conveniência de implementação em nenhuma fase
futura — uma fase que exigir violar um destes deve ser redesenhada, não a exceção
aprovada silenciosamente.

1. **Nenhuma FK cross-database.** MySQL não impõe (nem Eloquent resolve) integridade
   referencial entre conexões diferentes. Toda referência Unidade→Core ou Core→Unidade é
   por `public_id` (string), resolvida por lookup explícito na conexão certa — nunca por
   `whereHas`/`with()`/join implícito atravessando conexões.
2. **Nenhuma transação distribuída.** Não há 2PC entre Core e Unidade. Toda operação que
   escreve nas duas conexões (ex.: criar profissional no Core + vínculo com fila na
   Unidade) precisa ser desenhada como idempotente/retentável/reconciliável — nunca como
   "as duas escritas têm que acontecer atomicamente juntas".
3. **Resolução de tenant fail-closed.** Se o tenant/unidade não puder ser resolvido com
   certeza (sessão inválida, unidade inativa, tenant em `SHADOW`/`VALIDATING`, ausência de
   header em contexto de API), a requisição é rejeitada — nunca cai para uma conexão
   "default" como se fosse segura. Isso vale inclusive para jobs e comandos de console.
4. **Autorização antes da resolução de tenant.** A verificação de que o usuário
   autenticado tem vínculo ativo com a unidade/organização acontece antes de qualquer
   query ser disparada na conexão daquela unidade — nunca depois, e nunca inferida do
   sucesso da própria query (isso é a diferença entre um 403 e um IDOR). Ver seção 19.
5. **Sem duplicar registro clínico no Core.** Core guarda identidade e metadados de
   roteamento (a quem pertence, em qual unidade); nunca uma cópia do dado clínico em si.
6. **Migração sem perda e sem downtime forçado.** Todo estado de dado (schema e linhas)
   passa por um ciclo de vida explícito com rollback definido antes de qualquer cutover
   (seção 11) — nunca um `ALTER`/`DROP` direto em produção como parte de uma fase.
7. **Sem introduzir infraestrutura nova sem necessidade demonstrada.** Kafka/RabbitMQ/
   Horizon/Octane não entram neste plano; a fila já existente (`database`/`redis` queue
   driver do Laravel) e os comandos agendados já existentes bastam para o fan-out
   tenant-aware da seção 10, a menos que uma fase futura demonstre concretamente que não
   bastam.

---

## 3. Componentes de infraestrutura propostos

Nenhum destes existe hoje (confirmado na seção 1.5). Os quatro primeiros (`TenantContext`,
`TenantResolver`, `TenantConnectionManager`, `CoreModel`/`TenantModel`) são a base da
Fase 0. `tenant_databases` **não é** — só é criada na Fase 3, quando existe de fato um
ciclo de vida por unidade a rastrear; na Fase 0, toda unidade resolve para a mesma conexão
física, então o `TenantConnectionManager` não precisa de nenhuma tabela de apoio (ver
`docs/CODEX_CORE_UNIT_DB_FASE_0.md`, seção 1). O índice de descoberta pública, por outro
lado, é necessário desde a Fase 0 (os 2 endpoints públicos já existem em produção hoje).

- **`TenantContext`** — objeto de request-scope (singleton por requisição) contendo a
  unidade ativa resolvida e o nome da conexão Eloquent correspondente. Populado por
  `EnsureActiveHealthUnit` (hoje só grava em `$request->attributes`/sessão — vira o ponto
  de entrada da resolução de conexão). Imutável após populado; uma tentativa de trocar de
  unidade no meio de uma requisição é um erro de programação, não um caso a suportar.
- **`TenantResolver`** — interface com uma implementação inicial que lê a unidade ativa
  já resolvida por `EnsureActiveHealthUnit` (sessão revalidada por requisição, conforme
  ADR-003 já existente) e traduz para o nome de conexão. Fail-closed: sem unidade ativa
  válida, não resolve — lança exceção, não retorna um default.
- **`TenantConnectionManager`** — resolve, a partir do resultado do `TenantResolver`, qual
  entrada de `config('database.connections')` usar. Na Fase 0, todo nome de conexão de
  unidade aponta para o **mesmo banco físico único** (só isola no nível lógico do
  Connection Manager, não fisicamente) — isso é o que permite corrigir os 64+3
  relacionamentos sem nenhum risco de perda de dado antes de qualquer separação física
  real (Fase 3).
- **`CoreModel` / `TenantModel`** — classes-base abstratas. `CoreModel` fixa
  `$connection = 'core'`. `TenantModel` sobrescreve `getConnectionName()` para ler do
  `TenantContext` corrente em vez de uma string fixa — nenhum model de unidade pode ser
  instanciado fora de um `TenantContext` resolvido (fail-closed também aqui: um
  `TenantModel` usado num job/comando sem contexto lança exceção em vez de cair no banco
  default).
- **`tenant_databases`** — nova entidade Core (não existe hoje) representando o ciclo de
  vida de cada banco de unidade. Ver seção 11 e 12.
- **Índice de descoberta pública** — nova entidade/tabela Core mapeando identificadores
  públicos usados em rotas anônimas (`public_id` de `Panel`, `verification_code` de
  `ClinicalDocument`, e qualquer futuro identificador exposto sem sessão) para a unidade
  proprietária. Ver seção 7.

---

## 4. Classificação Core vs Unidade (reconfirmada)

Sem alteração de conteúdo frente ao levantamento anterior — reconfirmada por leitura
direta dos models nesta rodada, não copiada sem checagem.

**Core** (`sync_hosp_core`): `Organization`, `HealthUnit`, `Specialty`, `User`,
`HealthProfessional`, `ProfessionalRegistration`, `Patient`, `PatientIdentifier`. Pivots
Core↔Core: `health_professional_specialty`, `health_professional_health_unit`.

**Unidade** (`sync_hosp_uXXXX`): `Department`, `Room`, `ServicePoint`, `PatientContact`,
`PatientAddress`, `PatientGuardian`, `PatientAllergy`, `PatientCondition`,
`PatientMedication`, `PatientSocialHistory`, `PatientAccessLog`, `Encounter`,
`ReceptionRecord`, `EncounterStatusHistory`, `EncounterCompanion`, `IdempotencyKey`,
`NumberSequence`, `Queue`, `QueueEntry`, `QueueCall`, `QueueEntryHistory`,
`QueueTransfer`, `QueueSequence`, `Panel`, `TriageAssessment`, `VitalSignMeasurement`,
`TriageAddendum`, `MedicalConsultation`, `Diagnosis`, `ClinicalNote`, `Prescription`,
`PrescriptionItem`, `ExamOrder`, `ExamOrderItem`, `ExamResult`, `PhysicalExam`,
`EncounterDestination`, `MedicalAddendum`, `Referral`, `ClinicalDocument`,
`DocumentVersion`, `MedicalCertificate`, `LaboratoryOrderTransmission`,
`LaboratoryResultIngestion`, `LaboratoryTransmissionAttempt`, `MedicalShiftAttendance`.

**Catálogos, classificação resolvida (seção 22.2)**:

- **Core** (canônico/normativo): `DiagnosisCode`, `SusProcedure`, `Specialty` (já Core),
  `Exam` (canônico), `ExamGroup` (canônico), `RiskLevel` (canônico).
- **Tenant** (operacional): `EntryType`, `ArrivalMethod`, `HealthUnitExam`,
  `LaboratoryIntegration`, `LaboratoryExam`, `LaboratoryExamComponent`, `ExamMapping`,
  configuração de `RiskLevel` (ativação/prioridade/protocolo), `ExamGroupItem`,
  `ExamCatalogImportCandidate`, `ExamGroupImportConflict`, `LaboratoryMaterial`,
  `TriageProtocol`/`TriageFlowchart`/`TriageDiscriminator`/`VitalSignRange`.

A validar contra os models e regras atuais como item da Fase 0 (não exige migração de
dado — os catálogos operacionais já são `health_unit_id`-scoped hoje).

---

## 5. Matriz final de entidades (Entregável C)

Estratégias possíveis: **Direta** (model vira `CoreModel`/`TenantModel` sem mudança de
forma), **Referência por public_id** (FK vira coluna string + lookup explícito),
**Split** (model se divide em dois, um por camada), **Catálogo replicado** (linha mestra
no Core, cópia local sincronizada por unidade), **Catálogo centralizado** (permanece só no
Core, lido cross-connection via serviço, nunca via Eloquent relation).

| Model / Tabela | Hoje | Futuro | Public ID | FK cross-db? | Estratégia |
|---|---|---|---|---|---|
| `Organization` | única conexão | Core | sim | — | Direta |
| `HealthUnit` | única conexão | Core | sim | — | Direta |
| `User` | única conexão | Core | sim | — | Direta |
| `HealthProfessional` | única conexão | Core | sim | — | Direta |
| `Patient`/`PatientIdentifier` | única conexão, `organization_id` | Core (identidade) | sim | hoje N/A | Direta |
| `PatientContact`/`Address`/`Guardian`/`Allergy`/`Condition`/`Medication`/`SocialHistory` | única conexão, `organization_id` (compartilhado) | **Split**: `UnitPatient` + família por Unidade (decisão 22.1) | sim | hoje `patient_id` FK direta | Split — migração de dado é Fase 2, não Fase 0 |
| `Encounter` | única conexão | Unidade | sim | hoje `patient_id` FK direta | Referência por public_id (`patient_public_id` + lookup Core) |
| `Queue`/`QueueEntry`/`Panel` | única conexão | Unidade | sim | hoje `health_unit_id`, pivot `health_professional_queue` | Referência por public_id; pivot vira tabela de vínculo sem FK, reconciliada (seção 13) |
| `TriageAssessment`/`MedicalConsultation`/`Prescription`/`ExamOrder`/`Referral`/`Diagnosis` | única conexão | Unidade | sim | hoje `professional_id`/`patient_id` FK direta | Referência por public_id |
| `ClinicalDocument`/`DocumentVersion`/`MedicalCertificate` | única conexão | Unidade | sim (`public_id` e `verification_code`) | hoje `patient_id`/`creator_id` FK direta | Referência por public_id + entrada no índice de descoberta pública (seção 7) |
| `AuditLog` | única conexão, model único | **Split**: `security_audit_logs` (Core: login/logout/troca de unidade/permissão) + `audit_logs` (Unidade: eventos clínicos/documentais) | sim | hoje `user_id`/`patient_id`/`health_unit_id` FK direta | Split (seção 9) |
| `LaboratoryOrderTransmission`/`LaboratoryResultIngestion` | única conexão | Unidade | sim | hoje `organization_id`/`health_unit_id` FK direta | Referência por public_id |
| `Exam`/`ExamGroup` (canônico) | única conexão, por organização | Core, catálogo centralizado (decisão 22.2) | sim | hoje `organization_id` | Catálogo centralizado, leitura por serviço dedicado |
| `ExamMapping`/`LaboratoryIntegration`/`HealthUnitExam`/`LaboratoryExam` | única conexão, por unidade | Unidade (já é por unidade na prática hoje) | sim | hoje `health_unit_id` FK direta | Referência por public_id |
| `SusProcedure`/`DiagnosisCode` (CID-10) | única conexão, catálogo nacional | Core, catálogo centralizado | não aplicável (são catálogos, não entidades de tenant) | — | Catálogo centralizado |
| `RiskLevel` (canônico) | única conexão | Core, catálogo centralizado (decisão 22.2) | não aplicável | — | Catálogo centralizado |
| configuração de `RiskLevel`/`EntryType`/`ArrivalMethod` | única conexão | Unidade (decisão 22.2) | não aplicável | — | Catálogo replicado/operacional por unidade |
| `tenant_databases` (novo) | não existe | Core | sim | — | Direta (nova entidade, seção 11) |
| índice de descoberta pública (novo) | não existe | Core | — (é o próprio índice) | — | Direta (nova entidade, seção 7) |

---

## 6. Matriz de queries quebradas (Entregável D)

Todas as linhas abaixo foram confirmadas por leitura direta do arquivo nesta rodada
(não é uma lista herdada sem checagem). Prioridade conforme pedido: `whereHas`/joins
primeiro, depois eager loading/subqueries/policies.

| Arquivo | Método | Query atual | Lado A | Lado B | Problema | Estratégia |
|---|---|---|---|---|---|---|
| `Queues/.../QueueController.php:87-91` | busca de fila | `orWhereHas('encounter.patient', ...)` + `orWhereHas('identifiers', ...)` | `QueueEntry` (Unidade) | `Patient`/`PatientIdentifier` (Core) | `whereHas` compila subquery correlacionada — não existe cross-connection | Reescrever como 2 passos: buscar `patient_id`s no Core primeiro (por nome/documento), depois filtrar `QueueEntry` por `encounter.patient_id IN (...)` na Unidade |
| `Professionals/.../HealthProfessionalController.php:110` | listagem de filas por unidade | `Queue::whereHas('healthUnit', fn where organization_id)` | `Queue` (Unidade) | `HealthUnit` (Core) | mesma classe de quebra | `HealthUnit` já é conhecido no request (`$unit`); filtrar `Queue::where('health_unit_id', $unit->getKey())` direto, sem `whereHas` |
| `Professionals/.../HealthProfessionalController.php:118` | listagem de service points | `ServicePoint::whereHas('room.department.healthUnit', ...)` | `ServicePoint`→`Room`→`Department` (Unidade) | `HealthUnit` (Core) | encadeamento de 3 relações termina em Core | Mesma correção — `$unit` já resolvido, filtrar direto na conexão de Unidade sem atravessar para `HealthUnit` |
| `Professionals/.../HealthProfessionalController.php:120` | filtro por filas já carregadas | `ServicePoint::whereHas('queues', fn whereIn ids)` | `ServicePoint` (Unidade) | `Queue` (Unidade) | **não quebra** — ambos ficam na mesma conexão de Unidade | Nenhuma ação — confirmar na Fase 0 e manter |
| `Queues/.../QueueVisibilityService.php:37,62,88` | visibilidade de fila por profissional | `Queue::whereHas('professionals', fn whereKey)` | `Queue` (Unidade) | `HealthProfessional` via pivot `health_professional_queue` (Core) | pivot liga Unidade↔Core; `whereHas` sobre `BelongsToMany` cross-connection não resolve | Resolver a lista de `queue_id`s do profissional primeiro no Core (ler o pivot, que também precisa deixar de ser FK — seção 13), depois `whereIn('id', $queueIds)` na Unidade |
| `Queues/.../PublicPanelController.php` (`show`/`state`/`heartbeat`) | resolução de painel público | route-model-binding por `public_id`, depois `entry.encounter.patient` | `Panel`/`QueueEntry` (Unidade) | `Patient` (Core) + descoberta de tenant | **dois problemas empilhados**: (1) não há como saber em qual banco de Unidade está o `Panel` sem um índice Core; (2) depois de resolvido, `entry.encounter.patient` ainda cruza para o Core | Ver seção 7 para (1); para (2), mesma técnica de lookup em dois passos |
| `Documents/.../ClinicalDocumentController.php:108-116` (`verify`) | verificação pública de documento | `ClinicalDocument::where('verification_code', $code)->firstOrFail()` + `with(['healthUnit.organization','patient',...])` | `ClinicalDocument` (Unidade) | descoberta de tenant + `Patient`/`HealthUnit` (Core) | busca **global** sem nenhum filtro de unidade — pior caso: exigiria fan-out em N bancos por requisição pública anônima | Índice de descoberta pública (seção 7) resolve a unidade a partir do `verification_code` antes de abrir a conexão; `with()` cross-boundary resolvido em dois passos |
| `Audit/.../AuditTrailQuery.php:104,110` | filtro de trilha de auditoria por paciente/atendimento | `whereHas('patient', ...)`, `whereHas('encounter', ...)` | `AuditLog` (model único, sem reclassificação nesta fase) | `Patient` (Core) / `Encounter` (Unidade) | quebra desde a Fase 0 — `Patient` já é `CoreModel`, independente de `AuditLog` já ter sido dividido em `security_audit_logs`/`audit_logs` (isso é Fase 4 e não é pré-requisito desta correção) | Resolver `patient_id`/`public_id` no Core primeiro, filtrar `AuditLog.patient_id IN (...)`/`AuditLog.encounter_id IN (...)` depois — corrigido na Fase 0 |
| `Documents/.../GenerateSourceClinicalDocumentAction.php` / `IssueClinicalDocumentAction.php` | emissão de documento clínico | `$consultation->encounter->patient_id`, grava `patient_id` direto no `ClinicalDocument` | `MedicalConsultation`/`Encounter` (Unidade) | `Patient` (Core) | não é `whereHas`, mas é gravação de FK direta cross-boundary no `forceFill` (`IssueClinicalDocumentAction.php:95`) | Passa a gravar `patient_public_id` (string) em vez de assumir FK; lookup do `patient_id` interno (se necessário para índice local) feito explicitamente, não implícito |
| `Reports/.../OperationalDashboardQuery.php:92` | dashboard operacional | `EncounterDestination::whereHas('encounter', fn where health_unit_id)` | `EncounterDestination` (Unidade) | `Encounter` (Unidade) | **não quebra** — mesma conexão | Nenhuma ação |
| `Reports/.../EncounterReportQuery.php:66,69` | relatório de atendimentos | `whereHas('medicalConsultation', ...)`, `whereHas('destination', ...)` | `Encounter` (Unidade) | `MedicalConsultation`/`EncounterDestination` (Unidade) | **não quebra** — mesma conexão | Nenhuma ação |
| `Reports/.../ReportController.php:35`, `AuditTrailController.php:30`, `AvailableDoctorQuery.php:20-30`, `SwitchActiveHealthUnitAction.php:23,28` | listagens auxiliares | `User::whereHas('healthUnits', ...)`, `HealthUnit::whereHas('organization', ...)` | `User`/`HealthUnit` (Core) | `HealthUnit`/`Organization` (Core) | **não quebra** — tudo Core↔Core | Nenhuma ação |
| `Administration/.../CatalogManagementController.php:46` | catálogo de exames por unidade | `LaboratoryExam::whereHas('integration', fn where organization_id, health_unit_id)` | `LaboratoryExam` (Unidade) | `LaboratoryIntegration` (Unidade, 1 por unidade+provedor) | **não quebra** — ambos ficam na mesma conexão de Unidade | Nenhuma ação |
| `Patients/.../PatientController.php:37,64` | busca de paciente | `orWhereHas('identifiers', ...)` | `Patient` (Core) | `PatientIdentifier` (Core) | **não quebra** — Core↔Core, independente da decisão da seção 22.1 | Nenhuma ação |
| `Queues/.../ManageQueueEntryAction.php:327` | trava de entrada de fila | `whereHas('queue', fn where health_unit_id)` | `QueueEntry` (Unidade) | `Queue` (Unidade) | **não quebra** — mesma conexão | Nenhuma ação |

Conclusão prática: das ~14 ocorrências relevantes encontradas, **6 quebram de fato** sob
o split proposto (as marcadas na coluna "Problema" sem "não quebra"), e todas seguem o
mesmo padrão de correção — resolver o lado Core primeiro, filtrar por chave/lista no lado
Unidade depois, nunca `whereHas`/`with()` atravessando conexão.

---

## 7. Descoberta de tenant em endpoints públicos (achado novo, seção 1.2)

Hoje existem exatamente dois grupos de rota sem sessão/autenticação
(`routes/web.php:42-47`):

- `GET /panels/{panel}`, `GET /panels/{panel}/state`, `POST /panels/{panel}/heartbeat` —
  painel de senha exibido em telas públicas da recepção.
- `GET /document-verification/{verificationCode}` — verificação pública de documento
  clínico por QR code/código impresso.

Sob um banco por unidade, **nenhum dos dois pode ser resolvido sem antes saber qual banco
consultar** — e a única informação disponível é um identificador opaco (`public_id` ou
`verification_code`) gerado pela própria Unidade. As opções são:

1. **Fan-out**: consultar todos os N bancos de unidade até achar o registro. Descartado —
   cresce linearmente com o número de unidades, é uma rota pública (superfície de negação
   de serviço), e viola o princípio de "sem transação/consulta distribuída" do espírito
   geral deste plano.
2. **Índice de descoberta no Core** (recomendado): uma tabela `public_lookup_index` (ou
   nome equivalente) no Core, gravada **no momento da criação** da entidade na Unidade —
   `(public_id ou verification_code, tipo_de_entidade, tenant_database_id)`. A rota
   pública consulta esse índice primeiro (uma query Core, barata, indexada), resolve a
   conexão, só então abre a conexão de Unidade certa para buscar o registro real.

Essa escrita dupla (Unidade grava a entidade + Core grava a entrada no índice) é
exatamente o tipo de operação coberta pelo princípio 2 da seção 2 — não é uma transação
atômica entre bancos; é uma escrita na Unidade seguida de uma escrita idempotente no Core
(chave única no índice pelo próprio `public_id`/`verification_code`, safe-to-retry). Uma
falha entre as duas escritas deixa a entidade "invisível" ao endpoint público até
reconciliação (seção 13) — degrada para "documento não encontrado", nunca para exposição
cross-tenant.

Isso precisa entrar na Fase 0 como item explícito: todo model com rota pública
(`Panel`, `ClinicalDocument` hoje; qualquer novo uso futuro de `HasPublicId` em rota sem
sessão) passa a gravar no índice como parte do fluxo de criação.

---

## 8. `Patient`: estratégia e criptografia/fingerprint (decidido)

Confirmado na seção 1.3: não há criptografia nem fingerprint em `PatientIdentifier` hoje —
era um desenho greenfield. As decisões 22.1 (prontuário por unidade, identidade no Core) e
22.5 (criptografia + fingerprint HMAC) resolvem este ponto: `Patient`/`PatientIdentifier`
permanecem Core (identidade compartilhada, busca por nome/documento continua uma query
Core simples), e `PatientIdentifier` ganha `encrypted_value` + `fingerprint` HMAC
determinístico conforme especificado na seção 22.5 — implementado antes ou durante a
Fase 2, não na Fase 0.

`UnitPatient` (Tenant, dado clínico por unidade — decisão 22.1) referencia o paciente pelo
`patient_public_id` (string), nunca por FK. Uma busca que precise saber "em quais unidades
este paciente já foi atendido" usa um índice Core de participação
(`patient_public_id` → lista de `tenant_connection`), povoado no mesmo padrão de escrita
dupla idempotente da seção 7 — não uma relation cross-connection.

---

## 9. `AuditLog`: redesenho Core/Unidade (decidido, seção 22.3)

Confirmado: hoje é um único model (`app/Modules/Audit/Infrastructure/Eloquent/AuditLog.php`)
com colunas de duas origens de migration diferentes — `user_id`/`health_unit_id` (uma
migration) e `patient_id`/`encounter_id` (outra, adicionada depois). Isso já é um sinal de
que o model estava crescendo para cobrir dois papéis distintos antes mesmo desta proposta.

**Split decidido** (sem duplicação automática de evento — separação por responsabilidade):

- **`security_audit_logs`** (Core) — login, logout, falha de autenticação, alteração de
  senha, alteração de usuário, alteração de role/permissão, criação/remoção de vínculo com
  unidade, mudança de unidade ativa, provisionamento de tenant, mudanças administrativas
  globais. Consistente com ADR-004 já existente (auditoria síncrona de identidade) —
  continua síncrona, continua no Core.
- **`audit_logs`** e, quando necessário, **`patient_access_logs`** (Unidade) — acesso ao
  prontuário, criação de atendimento, triagem, consulta, diagnóstico, prescrição,
  solicitação de exame, resultado, documento clínico, alteração de dado clínico.

Exemplo: trocar a unidade ativa gera `ACTIVE_HEALTH_UNIT_CHANGED` só no Core — **não** gera
automaticamente um segundo evento no Tenant de destino; se, em seguida, o usuário acessar
o prontuário de um paciente naquela unidade, esse acesso (evento distinto) é que é
registrado no `audit_logs`/`patient_access_logs` do Tenant.

**Correlação sem duplicação**: eventos relacionados carregam `request_id`/`correlation_id`
+ `user_public_id`/`health_unit_id`, permitindo reconstruir a sequência (troca de unidade
no Core → acesso ao prontuário no Tenant B, mesmo `correlation_id`) sem gravar o mesmo
evento duas vezes.

**Consulta unificada** (`AuditTrailController`/`AuditTrailQuery`, hoje uma tela só): deixa
de ser uma query única. Vira duas consultas independentes (Core para eventos de
identidade, Unidade ativa para eventos clínicos), agregadas na mesma tela por
`correlation_id` quando aplicável — sem tentar uni-las num único result set ordenado por
timestamp cross-connection. Se ordenação/paginação global cross-unidade for exigida pelo
produto no futuro, isso é uma decisão de reporting (seção 15: fan-out explícito e
assíncrono, não uma query síncrona por request).

O filtro por paciente (`AuditTrailQuery.php:104`, já mapeado na matriz da seção 6) segue o
padrão de dois passos: resolve `patient_id`/`public_id` no Core, filtra `audit_logs` na
Unidade.

**Pivot sem integridade referencial possível**: `health_professional_queue` e
`health_professional_service_point` ligam uma linha Core (`HealthProfessional`) a uma
linha Unidade (`Queue`/`ServicePoint`). Depois do split, nenhuma FK garante a integridade.
Estratégia: a tabela de vínculo (renomeada ou mantida) vive na conexão de **Unidade**
(porque `Queue`/`ServicePoint` são Unidade), referenciando o profissional por
`professional_public_id` (string), não por FK. Integridade é garantida por: (a) o vínculo
só é criado através de uma Action que já validou a existência do profissional no Core no
mesmo request; (b) uma rotina de reconciliação periódica (seção 13) que detecta vínculos
órfãos (public_id sem profissional Core correspondente) e os reporta — não os apaga
silenciosamente.

---

## 10. Jobs e scheduler: fan-out tenant-aware

Confirmado: só 2 jobs agendados reais hoje (`routes/console.php:153-159`,
`synclab:dispatch-pending` e `synclab:dispatch-received-results`), ambos fazendo query
global sem filtro de unidade, e os 2 jobs de fila (`SubmitLaboratoryOrderJob`,
`ProcessSynclabExamResultJob`) recebendo só um ID numérico no construtor.

**Padrão proposto** (sem introduzir Kafka/RabbitMQ, princípio 7 da seção 2):

- Comando agendado passa a, primeiro, listar as unidades ativas elegíveis (query Core,
  barata), depois despachar **um job por unidade** — cada job abre sua própria conexão de
  Unidade via `TenantContext` explícito (não herdado de request, já que jobs não têm
  request). Isso é o "fan-out" mencionado no roadmap anterior, mas agora nomeado como
  padrão explícito a ser reaproveitado em qualquer scheduler futuro, não uma solução
  pontual para os 2 comandos atuais.
- Jobs de fila passam a receber `(string $tenantConnection, int|string $entityId)` ou
  equivalente no construtor — nunca só o ID. `handle()` abre o `TenantContext` explicitado
  no payload antes de tocar em qualquer `TenantModel`. Um job cujo payload não resolve
  para uma unidade válida falha (fail-closed, princípio 3) e vai para a fila de falhas —
  nunca executa contra a conexão default.
- Idempotência dos jobs (já é convenção no módulo Laboratory via
  `LaboratoryTransmissionAttempt`/lease pattern) se mantém — é o mesmo princípio já usado
  ali, generalizado.

---

## 11. Ciclo de vida de migração e `tenant_databases`

Nova entidade Core `tenant_databases`, um registro por unidade (não necessariamente 1:1
com `health_units` no início — várias unidades podem compartilhar um banco físico durante
a transição, ver Fase 4):

| Estado | Significado | Conexão usada pela aplicação |
|---|---|---|
| `LEGACY` | unidade ainda 100% no banco único atual | conexão default (comportamento de hoje) |
| `SHADOW` | banco físico de unidade provisionado, recebendo escrita espelhada, mas leitura ainda vem do LEGACY | aplicação lê do LEGACY, escreve nos dois (double-write idempotente) |
| `VALIDATING` | double-write concluído, reconciliação (seção 13) rodando, leitura ainda no LEGACY | idem SHADOW |
| `CUTOVER` | leitura e escrita movidas para o banco de unidade; LEGACY passa a ser só histórico/rollback | conexão de unidade dedicada |
| `TENANT` | estado estável, LEGACY não é mais consultado, pode ser arquivado | conexão de unidade dedicada |
| `ROLLBACK` | estado de emergência — reverte para LEGACY a partir de qualquer estado acima de `SHADOW` | conexão default, banco de unidade congelado para investigação |

`ROLLBACK` é alcançável a partir de `SHADOW`, `VALIDATING` ou `CUTOVER` — nunca a partir de
`TENANT` sem uma decisão explícita separada (a essa altura, reverter significa perder
escritas feitas só no banco de unidade). Transição de estado é uma operação administrativa
auditada (consistente com o espírito de ADR-005 já existente, que trata redefinição de
senha como ação administrativa auditada, não automática).

---

## 12. Provisionamento de nova unidade

Fluxo para quando uma unidade nova (não migração de uma existente) entra no sistema
depois que a arquitetura estiver madura:

1. Registro `tenant_databases` criado em estado `SHADOW` diretamente (não passa por
   `LEGACY`, porque não há dado legado a migrar).
2. Banco físico provisionado (schema completo via migrations "de Unidade", ver
   separação de migrations na Fase 1/seção 14).
3. Seed de catálogos replicados (se a decisão da seção 22.2 optar por replicar em vez de
   centralizar) a partir do Core.
4. Health-check de conectividade antes de expor a unidade para login de usuários.
5. Transição direta para `TENANT` (sem `VALIDATING`, já que não há dado legado a
   reconciliar).

---

## 13. Reconciliação e detecção de drift de schema

Duas preocupações distintas, ambas sem tooling hoje:

- **Reconciliação de dado**: durante `SHADOW`/`VALIDATING`, comparar contagens/hashes
  entre o banco LEGACY e o banco de Unidade para o mesmo período, e entre índices de
  descoberta pública (seção 7)/pivots sem FK (seção 9) e as entidades que deveriam
  referenciar. Roda como comando agendado, reporta divergência — não corrige
  automaticamente (correção automática de dado clínico divergente é uma decisão humana).
- **Drift de schema**: com N bancos físicos por unidade, migrations precisam rodar
  identicamente em todos. Proposta: migrations "de Unidade" ficam numa pasta própria
  (`database/migrations/tenant/`, separada das "de Core"), aplicadas via um comando que
  itera `tenant_databases` em estado `CUTOVER`/`TENANT` e roda contra cada conexão,
  registrando sucesso/falha por unidade — uma unidade que falha não bloqueia as demais,
  mas fica sinalizada como fora de sincronia até corrigida.

---

## 14. Testes: redesenho antes do refactor pesado

Confirmado: `tests/TestCase.php` mistura fixtures Core e Unidade na mesma chamada contra
uma única conexão SQLite `:memory:`; 35+ arquivos de teste dependem de `RefreshDatabase`
sem nenhum conceito de múltiplas conexões.

Esta é a razão pela qual a Fase 1 (redesenho de testes) precisa vir **antes** de qualquer
correção em massa dos 64+3 relacionamentos cross-boundary da Fase 0 ganhar confiança — do
contrário, a Fase 0 estaria sendo validada por um harness que não consegue nem representar
o problema que ela resolve (um teste com tudo numa conexão só nunca vai pegar um
`whereHas` cross-connection quebrado, porque na Fase 0 o `TenantConnectionManager` ainda
aponta tudo pro mesmo banco físico — o teste só ganha valor real a partir da Fase 3, mas a
fixture precisa já existir desde a Fase 0 para não represar trabalho).

Proposta: `tests/TestCase.php` ganha `createCoreFixtures()`/`createTenantFixtures()`
separados (em vez dos atuais `createHealthUnit`/`registerDoctor`/etc. que fazem os dois
implicitamente), e a suíte de testes passa a rodar com **duas** conexões SQLite
`:memory:` nomeadas (`core`/`tenant_test`) desde a Fase 0 — mesmo que ambas apontem para
bancos físicos diferentes só logicamente, isso já obriga todo teste que hoje faz
`Patient::factory()->has(Encounter::factory())` (cross-connection implícito) a ser
reescrito, expondo os pontos quebrados de forma determinística antes da Fase 2/3.

---

## 15. Reporting e dashboards cross-unidade

`OperationalDashboardQuery`/`EncounterReportQuery`/`ReportController` hoje consultam
livremente através dos módulos numa única conexão. Depois do split, um relatório
"todas as unidades" não pode mais ser uma query síncrona por request (seria fan-out
síncrono em N conexões dentro do tempo de resposta HTTP).

Proposta (não implementar agora, registrar como restrição de design): relatórios
cross-unidade migram para um padrão assíncrono — job agendado por unidade escreve um
resumo/agregado numa tabela de relatório no Core (ou num data mart separado), e a tela de
relatório lê esse agregado pré-computado, nunca fan-out ao vivo. Relatórios de uma única
unidade continuam síncronos, na conexão daquela unidade, sem mudança de padrão.

---

## 16. Backup e restore por unidade

Hoje: um `mysqldump`/snapshot cobre o sistema inteiro. Depois do split: backup por unidade
é, na prática, uma vantagem da arquitetura (raio de restauração menor), mas exige:
inventário de quais backups pertencem a qual `tenant_databases`, e um runbook de restore
que não restaure um banco de Unidade para um ponto no tempo anterior a uma referência
Core ainda válida (ex.: restaurar Unidade para antes de um profissional ter sido
desativado no Core criaria uma inconsistência lógica, não impedida por FK). Não
detalhado em profundidade neste plano — item da Fase 3/4, depende de qual provedor de
banco for usado para os bancos físicos por unidade (decisão ainda não tomada).

---

## 17. Observabilidade

Nenhuma métrica/log hoje distingue conexão de Unidade. Requisitos mínimos para a Fase 0:
todo log de erro de conexão inclui qual `tenant_database`/conexão estava ativa; todo job
tenant-aware (seção 10) loga a unidade no início/fim, não só o ID da entidade; um comando
`tenant:status` (novo) reporta o estado de cada `tenant_databases` (seção 11) e o resultado
da última reconciliação (seção 13) — dá visibilidade operacional sem depender de olhar
banco a banco manualmente.

---

## 18. Workers persistentes (Octane/Horizon)

Confirmado (seção 1.5): nenhum dos dois está em uso hoje. Isso remove o risco imediato de
vazamento de `TenantContext` entre requisições num worker de longa duração (PHP-FPM/
`php artisan serve` recriam o processo a cada request, então não há estado residual).
Registrado aqui como **restrição preventiva**, não mitigação de risco ativo: se
Octane/Horizon forem adotados no futuro, `TenantContext` **não pode** ser um singleton de
processo — precisa ser resetado explicitamente no início de cada requisição/job
(`booting`/`terminating` hooks do Octane), e isso deve ser revisitado antes da adoção, não
depois de um incidente.

---

## 19. Segurança: IDOR e autorização antes da resolução de tenant

Princípio 4 da seção 2 aplicado concretamente: hoje, `EnsureActiveHealthUnit` já revalida
o vínculo do usuário com a unidade a cada requisição (ADR-003 existente) — isso é
exatamente a ordem certa e deve ser preservada, não invertida, quando a resolução de
conexão for adicionada ao mesmo middleware. O risco a evitar explicitamente na Fase 0: um
model `TenantModel` sendo consultado (ex. num Form Request ou Policy que resolve
route-model-binding antes do middleware de unidade rodar) usando uma conexão ainda não
validada. Isso exige revisar a ordem de middleware/route-model-binding para os módulos que
hoje fazem binding automático de model (`ClinicalDocument $document`,
`MedicalConsultation $consultation`, etc. — vistos nesta rodada em
`ClinicalDocumentController.php`) — o binding precisa acontecer **depois** que
`TenantContext` estiver resolvido e autorizado, não antes. Isso é um achado de risco a
detalhar no `docs/CODEX_CORE_UNIT_DB_FASE_0.md`, não resolvido neste plano.

---

## 20. ADRs propostos (para `docs/DECISIONS.md`, convenção existente)

Não adicionados ao arquivo agora — são propostas para o time ratificar. Numeração
contínua a partir da última entrada existente (`ADR-010`), a confirmar no momento da
inclusão real.

> **ADR-011 — Banco por unidade via conexões nomeadas, sem FK cross-database**
> A separação Core/Unidade é implementada com conexões Eloquent nomeadas resolvidas por
> `TenantContext`/`TenantResolver`, nunca por FK entre bancos. Referências cross-boundary
> usam `public_id`. Motivo: MySQL não garante integridade referencial entre conexões;
> tentar simular isso manualmente é mais frágil que assumir a ausência de FK desde o
> desenho.

> **ADR-012 — Migração de unidade em ciclo de vida explícito com rollback**
> Toda unidade migrada do banco único para um banco físico próprio passa pelos estados
> `LEGACY → SHADOW → VALIDATING → CUTOVER → TENANT`, com `ROLLBACK` disponível a partir de
> qualquer estado anterior a `TENANT`. Migração unidade-por-unidade, nunca big-bang.
> Motivo: reduz o raio de dano de um erro de migração a uma única unidade.

> **ADR-013 — Descoberta de tenant em rotas públicas via índice central**
> Rotas sem sessão que resolvem entidade por identificador opaco (`public_id`,
> `verification_code`) consultam um índice central no Core antes de abrir conexão de
> Unidade, nunca fan-out em todos os bancos. Motivo: fan-out numa rota pública anônima é
> superfície de negação de serviço e viola o princípio de não ter consulta distribuída
> síncrona.

> **ADR-014 — Prontuário clínico por unidade, identidade global no Core**
> `Patient`/`PatientIdentifier` permanecem no Core (identidade compartilhada entre
> unidades); todo dado clínico e operacional (`UnitPatient` e família — contatos,
> endereços, alergias, condições, medicações, histórico social — mais atendimento,
> triagem, consulta, prescrição, exame, documento) passa a ser local por unidade. Uma
> informação clínica registrada numa unidade não aparece automaticamente noutra. Motivo:
> isolamento clínico entre unidades, menor blast radius, independência operacional —
> decisão confirmada com responsabilidade clínica, não só arquitetural (seção 22.1).
> Informação crítica interoperável (alergia grave, alerta) fica para uma estrutura futura
> dedicada (`PatientSafetySummary`), fora do escopo desta migração.

> **ADR-015 — Catálogos híbridos: canônico no Core, operacional no Tenant**
> `DiagnosisCode`, `SusProcedure`, `Specialty`, `Exam`/`ExamGroup` canônicos ficam no Core,
> lidos por serviço de leitura dedicado. `HealthUnitExam`, `LaboratoryIntegration`,
> `LaboratoryExam`, `LaboratoryExamComponent`, `ExamMapping`, configuração de `RiskLevel`,
> `EntryType`, `ArrivalMethod` ficam no Tenant. Motivo: evita tanto duplicar catálogos
> nacionais/normativos em cada unidade quanto forçar configuração operacional específica
> de unidade a viver centralizada (seção 22.2).

> **ADR-016 — `AuditLog` dividido por responsabilidade, sem duplicação automática**
> `security_audit_logs` (Core, eventos de identidade) e `audit_logs`/`patient_access_logs`
> (Tenant, eventos clínicos) não espelham o mesmo evento nos dois lados; correlação via
> `correlation_id` quando necessário. Motivo: evita ambiguidade sobre qual lado é a fonte
> de verdade de um evento e mantém cada escrita de auditoria numa única conexão (seção
> 22.3).

> **ADR-017 — CPF/CNS: criptografia autenticada + fingerprint HMAC determinístico**
> `PatientIdentifier.encrypted_value` substitui o texto plano atual; busca/deduplicação via
> `fingerprint` (`HMAC-SHA256(secret, tipo:valor_normalizado)`), nunca hash simples sem
> segredo. Aplicado antes ou durante a Fase 2. Motivo: CPF/CNS têm espaço de busca pequeno
> — hash sem segredo é vulnerável a enumeração (seção 22.5).

---

## 21. Roteiro de fases (revisado)

```text
FASE 0 — Fundação de resolução de conexão (sem mover dado nenhum)
  TenantContext/TenantResolver/TenantConnectionManager, CoreModel/TenantModel,
  todas as conexões de Unidade apontando para o MESMO banco físico ainda.
  Corrige os 64+3 relacionamentos cross-boundary confirmados (seção 6) e os 2
  jobs agendados sem filtro de unidade (seção 10). Inclui o índice de
  descoberta pública (seção 7) desde já, porque os 2 endpoints públicos já
  existem em produção. Revisão de ordem middleware/binding (seção 19).
  IMPACTO: nenhuma migração de dado. Risco: baixo.

FASE 1 — Redesenho de testes (seção 14)
  Fixtures Core/Unidade separadas, 2 conexões nomeadas no harness de teste.
  Pré-requisito de confiança para validar a Fase 0 e todas as seguintes.

FASE 2 — Migração do dado de paciente (maior risco; decisão 22.1 já tomada: Split)
  Introduz UnitPatient* por unidade, migra contatos/endereços/alergias/
  condições/medicações/histórico social de "compartilhado por organização"
  para "por unidade", com plano de dado explícito para o que já existe hoje
  (decisão de qual unidade "herda" cada registro histórico ambíguo precisa
  de critério definido antes da execução, não durante). Inclui a
  criptografia + fingerprint de PatientIdentifier (decisão 22.5) e o índice
  Core de participação (seção 8).

FASE 3 — `tenant_databases`: provisionamento físico, piloto com UMA unidade
  Ciclo LEGACY→SHADOW→VALIDATING→CUTOVER→TENANT executado de ponta a ponta
  numa única unidade piloto. Reconciliação (seção 13) validada em produção
  antes de qualquer outra unidade.

FASE 4 — Rollout para as demais unidades
  Repete a Fase 3 unidade por unidade. AuditLog dividido em definitivo
  (seção 9) só é finalizado depois que todas as unidades relevantes estão
  em `TENANT` (evita manter os dois formatos em paralelo por mais tempo que
  o necessário).

FASE 5 — Descomissionamento de suposições de banco único
  Reporting cross-unidade migra para o padrão assíncrono (seção 15). Backup/
  restore por unidade (seção 16) formalizado. Observabilidade (seção 17)
  madura o suficiente para operação sem olhar banco a banco manualmente.

FASE 6 — Migrations "de Unidade" com detecção de drift (seção 13)
  Pode começar em paralelo à Fase 4/5 uma vez que a Fase 0 já separou a
  pasta de migrations; não depende de todas as unidades estarem migradas.

FASE 7 — Provisionamento de unidade nova nativo (seção 12)
  Só faz sentido depois que o ciclo de vida da Fase 3/4 estiver validado em
  produção com dado real — provisionar "do zero" é o caso fácil, não deve
  ser otimizado antes do caso difícil (migração de dado existente) estar
  resolvido. Desenho preliminar em `docs/CODEX_CORE_UNIT_DB_FASE_7.md`:
  criação automática de banco disparada por `ProvisionTenantAction`
  (hoje o único ponto de criação de `HealthUnit`, já restrito a
  `isPlatformAdministrator()`), usando uma credencial MySQL dedicada e
  restrita por padrão de nome (`GRANT ... ON \`sync_hosp_u%\`.*`), nunca a
  credencial de runtime do app — autorização de aplicação (quem aciona) e
  privilégio de credencial (o que a conexão pode fazer) são camadas
  diferentes, e só restringir a primeira não elimina o risco de a segunda
  vazar por qualquer outro caminho da aplicação. Reaproveita 100% do
  mecanismo já validado na Fase 3 (`TenantDatabaseLifecycle`,
  `TenantDatabaseProvisioner`, `TenantSchemaHardener`,
  `TenantPilotDataSynchronizer`, `TenantDatabaseReconciler`), sem alterar a
  máquina de estados.
```

Cada fase, ao ser iniciada, ganha seu próprio `docs/CODEX_CORE_UNIT_DB_FASE_N.md`. Fases 0
a 3 já estão implementadas (`docs/CODEX_CORE_UNIT_DB_FASE_0.md` a
`docs/CODEX_CORE_UNIT_DB_FASE_3.md`, mais as correções pós-auditoria
`docs/CODEX_CORE_UNIT_DB_FASE_2_FIXES.md` e `docs/CODEX_CORE_UNIT_DB_FASE_3_FIXES.md`).
A Fase 7 tem um desenho preliminar (`docs/CODEX_CORE_UNIT_DB_FASE_7.md`), mas **não deve
ser executada nesta rodada nem antes da Fase 3/4 estarem validadas em produção com dado
real** — o próprio documento condiciona sua implementação de verdade a esse marco.

---

## 22. Decisões bloqueantes (resolvidas)

> As cinco decisões abaixo foram respondidas pelo time. Ficam registradas aqui como fonte
> de verdade; as seções 4, 5, 8, 9, 20 e 21 foram atualizadas para refletir cada uma.
> Nenhuma delas altera os princípios obrigatórios da seção 2 (sem FK cross-database, sem
> transação distribuída, resolução fail-closed).

### 22.1 Dado clínico do paciente: por unidade ou compartilhado?

**Decisão: manter o prontuário clínico por unidade.**

A identidade global do paciente permanece no `sync_hosp_core`; os dados clínicos e
operacionais ficam exclusivamente no banco da unidade responsável pelo atendimento.

```text
CORE
Patient
PatientIdentifier

TENANT
UnitPatient
PatientContact
PatientAddress
PatientGuardian
PatientAllergy
PatientCondition
PatientMedication
PatientSocialHistory

Encounter
Triage
MedicalConsultation
Prescription
ExamOrder
ExamResult
ClinicalDocument
...
```

Uma informação clínica registrada na Unidade A não é automaticamente incorporada ao
prontuário da Unidade B. Essa escolha é deliberada e preserva isolamento clínico entre
unidades, menor blast radius, independência operacional, backup/restore por unidade,
possibilidade de mover tenants para servidores separados, e separação clara entre
identidade e prontuário. **O Core não se torna um prontuário clínico global.**

**Segurança do paciente**: informações críticas (alergias graves, alertas) podem ser
relevantes num atendimento em outra unidade. Isso não é resolvido compartilhando o
prontuário inteiro — fica registrada como evolução futura uma estrutura dedicada,
`PatientSafetySummary`, restrita a informações críticas interoperáveis, sujeita a regras
próprias de produto, acesso, consentimento, auditoria e governança clínica. Não faz parte
desta migração e não bloqueia a Fase 0.

### 22.2 Catálogos ambíguos: centralizados ou replicados?

**Decisão: arquitetura híbrida — catálogo canônico global no Core, configuração
operacional no Tenant.**

```text
CORE
define "o que existe"

TENANT
define "o que esta unidade utiliza e como utiliza"
```

**Core (canônico/normativo)**: `DiagnosisCode`, `SusProcedure`, `Specialty`, `Exam`
(canônico), `ExamGroup` (canônico), `RiskLevel` (canônico). Consulta a partir de um Tenant
sempre por serviço de leitura explícito — nunca Eloquent relation/JOIN/`whereHas`/FK entre
Tenant e Core.

**Tenant (operacional)**: `HealthUnitExam`, `LaboratoryIntegration`, `LaboratoryExam`,
`LaboratoryExamComponent`, `ExamMapping`, configuração de `RiskLevel` (ativação,
prioridade, tempo-alvo, protocolo), `EntryType`, `ArrivalMethod`.

Exemplo: `Exam` "Hemograma" (`public_id = EXAM-001`) existe uma vez no Core; cada
`HealthUnitExam` de Tenant referencia esse `exam_public_id` para decidir se está habilitado
e como mapeia para o catálogo do laboratório local (`LaboratoryExam.external_code`) — sem
FK, por `public_id`.

Tabela final de classificação:

| Entidade | Destino |
|---|---|
| `DiagnosisCode` | Core |
| `SusProcedure` | Core |
| `Specialty` | Core |
| `Exam` canônico | Core |
| `ExamGroup` canônico | Core |
| `RiskLevel` canônico | Core |
| configuração de `RiskLevel` | Tenant |
| `EntryType` | Tenant |
| `ArrivalMethod` | Tenant |
| `HealthUnitExam` | Tenant |
| `LaboratoryIntegration` | Tenant |
| `LaboratoryExam` | Tenant |
| `LaboratoryExamComponent` | Tenant |
| `ExamMapping` | Tenant |

A validar contra os models e regras atuais antes da implementação (item de Fase 0, ver
`docs/CODEX_CORE_UNIT_DB_FASE_0.md`).

### 22.3 `AuditLog` dividido: algum evento precisa aparecer nos dois lados?

**Decisão: não duplicar automaticamente o mesmo evento nos dois bancos.** A separação
segue a responsabilidade do evento, não uma cópia espelhada.

**Core — `security_audit_logs`**: login, logout, falha de autenticação, alteração de
senha, alteração de usuário, alteração de role/permissão, criação/remoção de vínculo com
unidade, mudança de unidade ativa, provisionamento de tenant, mudanças administrativas
globais.

**Tenant — `audit_logs` e, quando necessário, `patient_access_logs`**: acesso ao
prontuário, criação de atendimento, triagem, consulta, diagnóstico, prescrição,
solicitação de exame, resultado, documento clínico, alteração de dado clínico.

Exemplo: trocar a unidade ativa gera `ACTIVE_HEALTH_UNIT_CHANGED` no Core; isso **não**
gera automaticamente um segundo evento no Tenant de destino. Se, em seguida, o usuário
acessar o prontuário de um paciente naquela unidade, **esse** evento (acesso ao
prontuário) é registrado no `audit_logs`/`patient_access_logs` do Tenant.

**Correlação sem duplicação**: eventos relacionados usam `request_id`/`correlation_id` +
`user_public_id`/`health_unit_id` (ambos já presentes no padrão de IDs do projeto). Isso
permite reconstruir a sequência (troca de unidade no Core → acesso ao prontuário no Tenant
B, mesmo `correlation_id`) sem duplicar fisicamente o evento. A tela de auditoria unificada
(seção 9) agrega Core + Tenant por essa correlação, não por um único result set gravado
nos dois lados.

### 22.4 Estratégia de migração do dado já existente: big-bang ou por unidade?

**Decisão: migração gradual, unidade por unidade, começando por uma unidade piloto.**
Big-bang descartado.

```text
LEGACY → SHADOW → VALIDATING → CUTOVER → TENANT
                                   |
                                   v (se problema)
                                ROLLBACK → LEGACY
```

A unidade piloto valida, de ponta a ponta: provisionamento, migrations, seeders,
resolução dinâmica de conexão, migração de pacientes, prontuário, filas, triagem,
atendimento, prescrições, exames, Synclab, documentos, auditoria, scheduler, jobs, backup,
restore, reconciliação e rollback. O rollout das demais unidades só começa depois da
unidade piloto validada, e continua sendo feito individualmente — reduz o blast radius e
permite interromper a migração sem afetar hospitais ainda no modelo legado.

### 22.5 Criptografia/fingerprint dos identificadores do paciente

**Decisão: adotar criptografia do valor e fingerprint determinístico** para pesquisa e
deduplicação. Aplicada antes ou durante a Fase 2 (quando `Patient`/`PatientIdentifier` são
tratados) — **não** é escopo da Fase 0 (consistente com a seção 8, que já confirmara não
haver criptografia/fingerprint hoje).

Estrutura recomendada:

```text
patient_identifiers
  id
  public_id
  patient_id
  identifier_type      -- CPF, CNS, ...
  encrypted_value       -- criptografia autenticada
  fingerprint           -- HMAC determinístico, não SHA256 simples
  created_at
  updated_at
```

- **Normalização** antes de cifrar/gerar fingerprint (ex. `123.456.789-00` → `12345678900`),
  mesmo princípio já usado hoje em `normalized_value`.
- **`encrypted_value`**: identificador protegido por criptografia autenticada, recuperável
  só pela aplicação autorizada.
- **`fingerprint`**: usado para busca/comparação/deduplicação. Não usar `SHA256(CPF)`
  isolado — CPF/CNS têm espaço de busca pequeno, vulnerável a enumeração. Usar HMAC
  determinístico com segredo gerenciado fora do banco (config/secret manager):
  `HMAC-SHA256(secret, tipo + ":" + valor_normalizado)`.
- **Busca**: normaliza → HMAC → `WHERE identifier_type = ? AND fingerprint = ?`, sem
  descriptografar todos os registros.
- **Unicidade**: quando a regra de negócio permitir, `UNIQUE(identifier_type, fingerprint)`
  em vez de sobre o valor aberto — só depois de uma reconciliação dos dados existentes
  (CPF/CNS duplicado, identificador inválido, paciente duplicado, registro sem
  identificador). Especificação exata (gestão de chave, rotação) fica para o planejamento
  da Fase 2, não deste documento.

### 22.6 Síntese das decisões

```text
1. Identidade do paciente é global (Core).
2. Prontuário clínico é local da unidade (Tenant).
3. O Core não se torna um prontuário clínico global.
4. Informações críticas compartilháveis: evolução futura via PatientSafetySummary,
   não faz parte desta migração.
5. Catálogos normativos/canônicos ficam no Core; configuração operacional no Tenant.
6. O mesmo evento de auditoria não é duplicado automaticamente entre Core e Tenant;
   correlação via correlation_id.
7. Migração unidade por unidade, com piloto antes do rollout.
8. CPF/CNS: valor criptografado + fingerprint HMAC determinístico, decidido para a Fase 2.
9. Nada acima altera os princípios da seção 2: sem FK Core↔Tenant, sem transação
   distribuída, resolução de tenant fail-closed.
```

---

## 23. Riscos residuais não resolvidos por este plano

- Critério de herança de registro histórico ambíguo na Fase 2: um `PatientContact`/
  `PatientAllergy`/etc. hoje compartilhado por `organization_id` pode ter sido criado a
  partir de qualquer unidade da organização — decidir para qual unidade cada registro
  existente migra (data do último acesso? unidade de criação, se rastreável? decisão
  manual por paciente?) não foi definido pela decisão 22.1 e precisa de critério explícito
  antes da execução da Fase 2, não durante.
- Gestão e rotação da chave HMAC usada no fingerprint de CPF/CNS (decisão 22.5) — onde
  fica armazenada, quem tem acesso, o que acontece com fingerprints já gravados se a chave
  rotacionar — não especificado neste plano, fica para o planejamento da Fase 2.
- Provedor/topologia física dos bancos por unidade (mesma instância MySQL com bancos
  lógicos separados vs. instâncias físicas separadas) — muda drasticamente o desenho de
  backup (seção 16) e não foi decidido.
- Custo operacional de rodar migrations em N conexões (seção 13) em ambiente de baixo
  recurso — não medido.
- Nenhum SLA/orçamento de tempo foi definido para o roteiro de 8 fases — é
  deliberadamente não estimado neste documento, por ser uma iniciativa de escopo ainda
  parcialmente bloqueado por decisões de produto.

---

## 24. Prompt futuro para o Codex

Não incluído neste documento. Está em `docs/CODEX_CORE_UNIT_DB_FASE_0.md` (Entregável E),
escrito com o mesmo nível de detalhe usado nos planos anteriores (arquivos exatos, testes,
critérios de aceite) — mas, por instrução explícita, **não deve ser executado nesta
rodada**. Só deve ser entregue ao Codex depois que as decisões da seção 22 tiverem
resposta e o usuário autorizar explicitamente o início da implementação.
