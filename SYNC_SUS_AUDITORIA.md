# SYNC SUS — AUDITORIA DO REPOSITÓRIO

> Auditoria estática, somente leitura. Nenhum arquivo de código foi alterado. Documento gerado em 2026-08-08.
> Metodologia: leitura direta do repositório + 5 linhas de investigação paralela (mapa funcional, segurança/tenant isolation, banco de dados/concorrência, cobertura de testes, config/dependências/integrações), com verificação manual dos achados mais críticos contra o código-fonte antes de serem listados aqui.

---

## 1. Resumo executivo

O SyncSUS é um monólito modular Laravel 13 / PHP 8.3 bem estruturado (13 módulos com camadas Application/Domain/Infrastructure/Presentation), multi-tenant por `organization_id`/`health_unit_id` num único banco MySQL. O nível de disciplina em isolamento de tenant é **acima da média**: quase todo controller sensível re-valida a unidade ativa, e a camada de Actions reforça isso com `lockForUpdate()` + re-scoping por `health_unit_id`, mesmo quando o controller não faz a checagem explícita — um padrão de defesa em profundidade real, não acidental. Não há Policies do Laravel; autorização é via `spatie/laravel-permission` (middleware `permission:*`) + `Gate::before` para admin de plataforma — funcional, mas concentra toda a matriz de permissões em `routes/web.php`.

Não foi encontrada nenhuma vulnerabilidade P0 (SQLi, XSS, CSRF bypass, upload inseguro — todos limpos). Foi confirmado **um IDOR real** (vazamento de PII de paciente entre organizações via `ReceptionController::create`), um **bug de constraint de banco** que permite estourar um erro 500 não tratado e vazar indiretamente a existência de um paciente em outra organização (CPF/CNS únicos globalmente), e uma **exposição de nome completo de paciente em painel público** que ignora a configuração de privacidade da unidade. Nenhum destes é catastrófico isoladamente, mas juntos justificam tratamento antes de produção dado o contexto hospitalar/LGPD.

Pontos fortes confirmados: transações e locks otimistas corretos nos fluxos críticos (fila, triagem, consulta, sequenciais), integração Synclab bem construída (credenciais criptografadas, idempotência, retry/backoff, auditoria sanitizada), Dompdf hardened (sem PHP/remote/JS), sem segredos versionados, sem uso de `env()` fora de config, sem `dd()`/debug esquecido em produção. Testes cobrem bem os fluxos principais (Feature tests), mas a ingestão de resultados de exame do Synclab não tem nenhuma implementação/teste — é escopo declaradamente não implementado (`synclab_contract.php`), não um bug oculto.

---

## 2. Arquitetura atual

```text
Blade + Alpine.js (resources/views, resources/js)
   ↓  (100% server-rendered; sem routes/api.php — endpoints JSON também em routes/web.php)
routes/web.php  →  middleware (auth, active.unit, permission:*, password.changed)
   ↓
Presentation/Http/Controllers  (finos na maioria dos módulos)
   ↓
Application/Actions | Application/Services | Application/Queries
   ↓  (DB::transaction + lockForUpdate nos fluxos críticos)
Infrastructure/Eloquent (Models por módulo)
   ↓
MySQL (único banco, colunas organization_id/health_unit_id como tenant key)

Integrações externas: Laboratory/Infrastructure/Synclab/SynclabClient → Http:: facade → Synclab API (jobs assíncronos com retry/backoff)
```

13 módulos: `Administration, Audit, Documents, Identity, Laboratory, Medical, Operations, Patients, Professionals, Queues, Reception, Reports, Triage`. Cada um segue Application/Domain(quando aplicável)/Infrastructure/Presentation. Não há Policies do Laravel nem Gates nomeados por recurso — autorização via `spatie/laravel-permission` no nível de rota, mais `Gate::before` (`app/Providers/AppServiceProvider.php:29`) liberando tudo para `isPlatformAdministrator()`.

---

## 3. Estrutura funcional

| Módulo | Existe? | Completo? | Backend | Frontend | Banco | Segurança | Observações |
|---|---|---|---|---|---|---|---|
| Autenticação (Identity) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | Rate limit, senha forte, sessão em DB, limite de sessões concorrentes |
| Usuários/Permissões (Identity/Administration) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | spatie/laravel-permission; sem self-registro |
| Profissionais/Médicos (Professionals) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | Multi-unidade e multi-especialidade suportados |
| Hospitais/Unidades (Administration) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | CNES único por unidade |
| Pacientes (Patients) | Sim | Completo | ✅ | ✅ | ✅ | ⚠️ | Ver Achado de segurança #1 (IDOR) e Achado de BD #1 (unique global de CPF/CNS) |
| Recepção (Reception) | Sim | Completo | ✅ | ✅ | ✅ | ⚠️ | Idempotência de abertura de atendimento; ver Achado #1 |
| Triagem (Triage) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | Modelo Protocolo→Fluxograma→Discriminador (estilo Manchester) |
| Atendimento médico (Medical) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | Maior módulo; diagnósticos, prescrições, exames, encaminhamentos, evoluções |
| Prontuário/Histórico do paciente | Sim | Completo | ✅ | ✅ | ✅ | ✅ | `PatientAccessLog` audita todo acesso a prontuário |
| Prescrições/Receitas/Atestados (Documents+Medical) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | Versionamento, verificação pública por código, anulação (void) auditada |
| Solicitação de exames (Medical→Laboratory) | Sim | Parcial | ✅ | ✅ | ✅ | ✅ | Envio ao Synclab implementado e resiliente |
| **Resultado de exames** | Parcial | **Ausente/não implementado** | ❌ | ❌ | ✅ (schema existe) | — | `ExamResult` existe no schema mas não há controller/rota/ingestão; `synclab_contract.php` marca como `not_implemented` |
| Painel de chamadas / filas (Queues) | Sim | Completo | ✅ | ✅ | ✅ | ⚠️ | Ver Achado de BD #8 (exposição de nome no painel público) |
| Auditoria (Audit) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | `AuditLog` + `PatientAccessLog`; sem teste unitário dedicado (ver seção Testes) |
| Administração/Permissões (Administration) | Sim | Completo | ✅ | ✅ | ✅ | ✅ | Troca de unidade pelo admin global implementada e testada |
| Relatórios (Reports) | Sim | Completo | ✅ | ✅ | ✅ (agregação) | ✅ | CSV/PDF com throttle de exportação |

---

## 4. Banco de dados

### Mapa simplificado

```text
Organization
 └── HealthUnit (unique cnes_code, unique org+code)
      ├── User (belongsToMany HealthUnit; organization_id nullable p/ admin global)
      ├── HealthProfessional (unique org+cpf) → ProfessionalRegistration, Specialty, ServicePoint
      ├── Department → Room → ServicePoint
      ├── Queue → QueueEntry → QueueCall, QueueEntryHistory, QueueTransfer
      └── Panel (belongsToMany Queue)

Patient (organization-scoped)
 ├── PatientIdentifier (CPF/CNS — UNIQUE GLOBAL, não escopado por organização — ver Achado 1)
 ├── PatientContact, PatientAddress, PatientGuardian
 ├── PatientAllergy, PatientCondition, PatientMedication, PatientSocialHistory
 └── Encounter (hub central)
      ├── ReceptionRecord, EncounterCompanion, EncounterStatusHistory
      ├── QueueEntry
      ├── TriageAssessment (unique per encounter) → VitalSignMeasurement, TriageAddendum
      ├── MedicalConsultation (unique per encounter)
      │    ├── Diagnosis, PhysicalExam, ClinicalNote (thread self-referencial)
      │    ├── Prescription → PrescriptionItem (+ histórico de substituição)
      │    ├── ExamOrder → ExamOrderItem → ExamResult (não populado — ver acima)
      │    └── Referral, MedicalAddendum, EncounterDestination
      └── ClinicalDocument → DocumentVersion (+ MedicalCertificate)

LaboratoryIntegration (credenciais encrypted) → LaboratoryMaterial/Exam/ExamComponent
 └── LaboratoryOrderTransmission (idempotency_key unique, lease pattern) → LaboratoryTransmissionAttempt

AuditLog, PatientAccessLog, BackupLog, BackupVerification
Spatie: roles, permissions, model_has_*
```

Padrão geral: `ulid public_id` + `id` interno em quase todas as tabelas, FKs com `restrict/cascade/nullOnDelete` bem escolhidos por caso, sem soft-deletes (modelo de apêndice/histórico em vez de exclusão lógica — adequado para dado clínico). 34 usos de `DB::transaction` mapeados, cobrindo corretamente todos os fluxos multi-write críticos revisados.

### Problemas identificados

**Achado 1 (P1 — confirmado):** `database/migrations/2026_07_24_020100_create_patients_tables.php:62` — `patient_identifiers` tem `unique(['type','normalized_value'])` **sem escopo por organização**, mas `patients.organization_id` é `NOT NULL` e o resto do sistema é rigorosamente multi-tenant. Duas organizações não conseguem cadastrar pacientes com o mesmo CPF/CNS: a segunda tentativa gera `QueryException` (erro 1062) **não tratada** em `SavePatientAction::syncIdentifiers()` (`app/Modules/Patients/Application/Actions/SavePatientAction.php:82-95`), resultando em HTTP 500 em vez de mensagem de validação — e revelando indiretamente que aquele CPF já existe em *alguma* organização (vazamento sutil entre tenants, mesmo sem expor dados).
*Correção recomendada:* escopar a unicidade por `organization_id`, ou, se a intenção é identidade nacional única (comum em CNES/SUS), tratar a exceção com mensagem amigável + fluxo de merge/dedup entre organizações.

**Achado 2 (P2):** `queue_entries` (`2026_07_24_020200_create_reception_tables.php:122`) tem `unique(['queue_id','ticket_number','entered_at'])`. Como `entered_at` tem granularidade de timestamp, essa constraint praticamente nunca bloqueia nada — o invariante real ("senha única por fila por dia") depende inteiramente de `lockForUpdate()` em `QueueTicketService`, sem rede de segurança no banco.

**Achado 3 (P3):** `2026_07_28_000400_add_global_administrator_identity.php:14-17` reverte `users.organization_id` para nullable (para suportar admin de plataforma). A unique composta `(organization_id, email)` permite múltiplos admins de plataforma com o mesmo email, já que `NULL` é tratado como distinto em unique composto no MySQL.

**Achado 4 (P2 — performance):** `app/Modules/Queues/Presentation/Http/Controllers/QueueController.php:27-35` — N+1: uma query por fila para `servicePoints` em vez de eager loading, em tela operacional acessada continuamente.

**Achado 5 (P2 — concorrência/performance):** `app/Modules/Documents/Application/Services/ClinicalDocumentVersionService.php:33-43` — renderização Dompdf (CPU-bound) + escrita em disco ocorrem **dentro** de `DB::transaction` que já segura `lockForUpdate()` sobre a linha pai (`ClinicalDocument`/`Prescription`/`ExamOrder`/`Referral`). Contraria a diretriz de preferir PDF em fila e prolonga o lock sob concorrência.

**Achado 6 (P3):** `TriageAssessment.cancelled_at` e `Encounter.observation_started_at` não estão no array `$casts` (`immutable_datetime`), inconsistente com os demais campos de timestamp dos mesmos models.

**Achado 7 (P2 — integridade de dado clínico):** `app/Modules/Medical/Infrastructure/Eloquent/PrescriptionItem.php` — `dose` é `decimal(10,3)` no banco mas não é castado, retornando como string; risco em comparações/formatação de dosagem.

**Achado 8 (P1 — LGPD):** `app/Modules/Queues/Presentation/Http/Controllers/PublicPanelController.php:61-66` — o endpoint público (sem autenticação, polling ~2s) exibe **sempre** o nome completo do paciente via `PanelIdentificationMode::FullName` fixo, **ignorando** `panels.identification_mode` (que existe no schema/enum mas está desativado no código, com comentário "preservado para reativação futura"). Uma unidade que configure "somente senha" continua expondo nome completo do paciente publicamente.

**Achado 9 (P3):** `documents.current_version_id` (auto-referência circular para `document_versions.id`) não tem FK — usado corretamente no código, mas sem proteção de integridade referencial no banco.

---

## 5. Segurança (organizado por severidade)

### P1
- **IDOR — vazamento de PII de paciente entre organizações.** `app/Modules/Reception/Presentation/Http/Controllers/ReceptionController.php:39`: `Patient::query()->with('identifiers')->where('public_id', $request->query('patient'))->first()` **sem filtro de `organization_id`**, diferente de todo outro lookup de paciente no sistema (contraste com `PatientController::ensurePatientOrganization()`). Um usuário da Organização A pode fazer `GET /reception/open?patient=<public_id de paciente da Org B>` e ver nome completo, data de nascimento, número de prontuário e identificadores mascarados renderizados na tela (`resources/views/reception/create.blade.php`). O POST subsequente (`OpenEncounterAction.php:68`) já revalida `organization_id` e bloqueia a escrita — portanto é vazamento de leitura, não permite criar atendimento cross-tenant. Ainda assim, é exposição real de identidade/PII de paciente, o dado mais sensível listado no CLAUDE.md do projeto. **Confirmado por leitura direta do código.**
  *Correção:* aplicar `->where('organization_id', $unit->organization_id)` (ou equivalente) antes do `->first()`, igual ao padrão já usado em `PatientController`.
- Ver também Achado de BD #1 (constraint global de CPF/CNS) e #8 (painel público expondo nome de paciente) acima — ambos com forte componente de segurança/privacidade.

### P2
- **Mass assignment — risco estrutural, sem exploração ativa encontrada.** `protected $guarded = [];` em ~67 models por todo o projeto, sem allowlist no nível de model. Mitigado hoje porque nenhuma instância de `Model::create($request->all())`/`->fill($request->all())` foi encontrada (todas as Actions usam allowlist manual de input) — mas não há defesa em profundidade caso um `create($request->validated())` futuro relaxe `rules()`. `User` já usa o padrão mais seguro (`#[Fillable([...])]`), vale estender a Patient/ClinicalDocument/Prescription.
- **Rate limit de login por IP.** `LoginRequest::throttleKey()` combina `unit_code|email|ip` — um atacante distribuído (múltiplos IPs) não é bloqueado por conta.
- **Cookie de sessão / HTTPS forçado dependem de variável de ambiente ser setada corretamente no deploy.** Default de código (sem `.env`) para `SESSION_SECURE_COOKIE` é `null` (não força secure) e `SYNC_SUS_REQUIRE_HTTPS` é `false` em `config/sync_sus.php`. O `.env.example` já traz os valores corretos (`true`/`true`), mas nada no código impede um deploy que omita essas variáveis de voltar a aceitar sessão em texto claro. *Não foi possível confirmar o valor real do `.env` de produção — fora do escopo desta auditoria.*

### P3
- CSP inclui `unsafe-eval` incondicionalmente (`app/Http/Middleware/SecurityHeaders.php:32`), provavelmente exigido pelo Alpine.js "core build" (usa `new Function()`); migrar para o build CSP do Alpine eliminaria a necessidade.
- `trustProxies` retorna `'*'` sempre que variáveis Railway estão presentes (`bootstrap/app.php` + `app/Support/DeploymentNetworkConfiguration.php`) — aceitável em PaaS single-entry, mas amplo se a app for exposta diretamente à internet.

### Áreas revisadas e consideradas limpas (sem achados)
- **SQL Injection:** todo uso de `DB::raw`/`whereRaw`/`orderByRaw`/`selectRaw` é parametrizado ou estático. Nenhuma concatenação de input em SQL.
- **XSS:** zero uso de `{!! !!}` em Blade, zero `x-html` no Alpine.js. Dompdf com `isPhpEnabled`/`isRemoteEnabled`/`isJavascriptEnabled` = `false`.
- **CSRF:** nenhuma exclusão de `VerifyCsrfToken` encontrada; todas as rotas mutáveis estão no grupo `web`.
- **Upload de arquivos:** funcionalidade não existe no sistema hoje — item não aplicável.
- **Logs/dados sensíveis:** o sistema usa tabelas de auditoria dedicadas (`AuditLog`, `PatientAccessLog`) com sanitização (`AuditContextSanitizer`) em vez de log de arquivo cru; nenhum `Log::`/`logger()` carregando dado clínico foi encontrado.
- **Autenticação:** rate limiting, política de senha forte (12+ caracteres, maiúsculas/números/símbolos), limite de sessões concorrentes, sem self-registro.
- **Autorização:** nenhuma rota mutável sem `permission:*` middleware ou checagem `->can()`/`abort_unless` em controller. *Não foi auditado o seed de roles/permissões (quem tem qual permissão) — recomenda-se checagem em runtime/BD.*
- **Segredos versionados:** nenhum segredo encontrado em `.env.example` (todos os campos sensíveis estão em branco); nenhum outro arquivo versionado com credenciais aparentes.

---

## 6. Bugs encontrados

```
1) Arquivo: app/Modules/Reception/Presentation/Http/Controllers/ReceptionController.php
   Linha: 39
   Problema: lookup de Patient por public_id sem filtro de organization_id
   Impacto: vazamento de PII de paciente entre organizações (leitura)
   Como reproduzir: GET /reception/open?patient=<public_id de paciente de outra organização>, autenticado como usuário de organização diferente
   Correção: adicionar ->where('organization_id', $unit->organization_id) antes do first()
```

```
2) Arquivo: app/Modules/Patients/Application/Actions/SavePatientAction.php
   Linha: ~82-95 (syncIdentifiers)
   Problema: QueryException (violação de unique global em patient_identifiers) não tratada
   Impacto: HTTP 500 ao tentar cadastrar paciente com CPF/CNS já usado em OUTRA organização; vazamento indireto de existência do registro
   Como reproduzir: cadastrar paciente com CPF X na Organização A; tentar cadastrar outro paciente com o mesmo CPF X na Organização B
   Correção: escopar a constraint por organization_id, ou tratar a exceção com mensagem de validação + fluxo de dedup
```

```
3) Arquivo: app/Modules/Queues/Presentation/Http/Controllers/PublicPanelController.php
   Linha: ~61-66
   Problema: identification_mode do painel é ignorado; nome completo do paciente sempre exibido publicamente
   Impacto: exposição de identidade de paciente em tela pública sem autenticação, mesmo quando a unidade configurou "somente senha"
   Como reproduzir: configurar panels.identification_mode diferente de FullName; acessar GET /panels/{panel}/state sem autenticação; nome completo ainda aparece
   Correção: usar $panel->identificationMode() em vez do valor fixo
```

Nenhum outro bug concreto (rota quebrada, import incorreto, model incompatível com migration, tipo incorreto) foi confirmado nos módulos investigados. Os itens de menor impacto (casts ausentes, FK ausente em auto-referência, constraint de fila ineficaz) estão detalhados na seção 4.

---

## 7. Problemas arquiteturais

- **Ausência de Policies do Laravel.** Toda autorização por recurso está em `middleware('permission:...')` dentro de `routes/web.php`, concentrando a matriz de permissões inteira em um único arquivo. Funciona hoje, mas dificulta auditoria/testagem unitária de regras de autorização à medida que o sistema cresce. Não é um bug — é uma escolha arquitetural válida, mas vale reavaliar se o número de permissões continuar crescendo.
- **Uso inconsistente de Form Requests.** O módulo `Operations` não tem nenhum Form Request; `Administration` (Catalog/UserManagement) valida via `Request` genérico inline no controller, divergindo do padrão documentado em `IMPLEMENTATION_STATUS.md` ("validação HTTP está em Form Requests") e seguido rigorosamente em Medical/Triage/Documents.
- **Classe órfã:** `app/Modules/Identity/Presentation/Http/Requests/SwitchHealthUnitRequest.php` não foi encontrada em uso em `routes/web.php` (a troca de unidade ativa hoje passa por `ActiveHealthUnitController::update` com `Request` genérico).
- **Fronteiras de módulo pouco nítidas:** `LaboratoryExamSearchController` reside fisicamente em `Medical`, não em `Laboratory`; `FlowConfigurationController` reside em `Queues` mas atende URLs `/administration/flow`. Não quebra nada (rotas resolvem corretamente), mas confunde a navegação por módulo.
- **Documentação desatualizada:** `docs/SYNC_HOSP_MODELO_BANCO_RESUMIDO.md` descreve uma arquitetura de **banco separado por unidade** que não foi implementada (o código real usa banco único com colunas de tenant) — deveria ser marcado como proposta descartada ou removido para não confundir. `IMPLEMENTATION_STATUS.md` está desatualizado (não menciona o módulo Laboratory/Synclab, que está implementado e é substancial).
- **Duplicação leve no frontend:** `resources/js/exam-order-items.js` e `resources/js/prescription-items.js` replicam quase a mesma lógica de add/remove item (limite de 30, mínimo 1). Baixo impacto dado o tamanho dos arquivos, mas merece um util compartilhado se um terceiro formulário de itens surgir.

---

## 8. Performance

| Item | Impacto | Local |
|---|---|---|
| N+1 em listagem de filas | Médio | `QueueController.php:27-35` |
| Geração de PDF síncrona dentro de transação com lock | Médio | `ClinicalDocumentVersionService.php:33-43` |
| Resposta do Synclab sem validação de schema | Baixo | `SynclabClient.php:46-56` |

Fora esses pontos, Reports/Audit/Patients e a maioria de Queues/Reception usam eager loading e paginação consistentemente — não foi encontrado carregamento de tabela inteira em memória nos módulos revisados.

---

## 9. LGPD / dados sensíveis

| Critério | Avaliação |
|---|---|
| Controle de acesso | Adequado (tenant scoping disciplinado, permission-based) — ressalva no Achado #1 (IDOR) |
| Rastreabilidade | Adequado — `AuditLog` + `PatientAccessLog` cobrem ações críticas |
| Minimização de dados | Adequado — nenhum log de arquivo cru com dado clínico; audit context sanitizado |
| Retenção | Não foi possível determinar — nenhuma política de retenção/expurgo encontrada no código |
| Exclusão/anonimização | Não implementado — modelo é apêndice/histórico (sem soft-delete/anonimização); pode ser aceitável para prontuário clínico (retenção legal), mas não há mecanismo para direito de exclusão de dados administrativos não-clínicos caso a LGPD exija |
| Acesso administrativo | Adequado — admin de plataforma tem bypass total via `Gate::before`, mas ações continuam auditadas |
| Exportação | Existe (`Reports`), com `throttle:exports` — não avaliado se exportação registra quem exportou o quê (recomenda-se confirmar) |
| Exposição pública indevida | **Risco relevante** — Achado #8 (painel público expõe nome completo ignorando configuração da unidade) |
| Vazamento cross-tenant | **Risco relevante** — Achado #1 (IDOR) e Achado de BD #1 (constraint global de CPF/CNS) |

Classificação geral: **precisa melhorar** — nada crítico/irreversível encontrado, mas os três achados P1 têm componente direto de exposição de identidade de paciente.

---

## 10. Testes

| Área | Unit | Feature | Integration | Security | Observação |
|---|---|---|---|---|---|
| Autenticação | ❌ | ✅ | — | ✅ | `AuthenticationTest.php` |
| Autorização | ❌ | ✅ | — | ✅ | `RolePermissionTest.php`, `PlatformGovernanceTest.php` |
| Isolamento entre unidades | ❌ | ✅ | ⚠️ | ✅ | `TenantEntryCancellationAndVisibilityTest.php`, `HealthUnitScopeTest.php` |
| Pacientes | ❌ | ✅ | — | — | `PatientManagementTest.php`, `ExpandedRegistrationsTest.php` |
| Recepção | ❌ | ✅ | — | — | `ReceptionOpeningTest.php` |
| Triagem | ❌ | ✅ | — | — | `TriageFlowTest.php` |
| Atendimento médico | ❌ | ✅ | ✅ | — | `MedicalConsultationFlowTest.php`, `IntegratedCareJourneyTest.php` |
| Prescrições/receitas/atestados | ❌ | ✅ | — | — | `DocumentsAndReportsTest.php`, `ClinicalCorrectionTest.php` |
| Solicitação de exames (Synclab, saída) | ✅ | ✅ | ✅ | — | `SynclabOrderSubmissionTest.php` + testes Unit |
| **Resultado de exames** | ❌ | ❌ | ❌ | — | **Sem cobertura — funcionalidade não implementada** |
| Documentos (PDF) | ❌ | ✅ | — | ✅ | `DocumentsAndReportsTest.php` |
| Painel de chamadas / filas | ❌ | ✅ | — | ✅ | `QueueFlowTest.php` |
| Auditoria | ❌ | ⚠️ | — | ✅ | Sem teste dedicado; coberto incidentalmente em outros testes |
| Permissões/administração | ❌ | ✅ | — | ✅ | Inclui troca de unidade pelo admin global |

Setup: PHPUnit 12, SQLite `:memory:`, `RefreshDatabase` por classe. Apenas `UserFactory` existe — demais entidades são construídas via helpers de `TestCase` ou `::create()` ad hoc. Playwright cobre apenas um spec de layout/assets (`layout.spec.js`), não fluxos funcionais.

**Lacunas mais importantes:** (1) ingestão de resultado de exame — zero cobertura porque a feature não existe; (2) auditoria não tem teste unitário dedicado, apesar de ser um requisito central do CLAUDE.md do projeto; (3) sem stress test de concorrência cross-unidade em fila/painel.

---

## 11. Funcionalidades faltantes

**Se este sistema fosse para produção amanhã:**

### Obrigatório antes de produção
- Corrigir o IDOR de PII em `ReceptionController::create` (Achado #1, seção 5).
- Corrigir a constraint global de `patient_identifiers` e o erro 500 não tratado (Achado de BD #1).
- Corrigir a exposição de nome completo no painel público, respeitando `identification_mode` (Achado de BD #8).
- Confirmar, no ambiente de produção real, que `SESSION_SECURE_COOKIE=true` e `SYNC_SUS_REQUIRE_HTTPS=true` estão de fato definidos (o código não força isso na ausência da variável).
- Decidir e documentar o escopo real da ingestão de resultados de exame — hoje é uma lacuna funcional (não apenas de teste): pedidos são enviados ao Synclab mas nada recebe/exibe o resultado.
- Adicionar teste dedicado de auditoria (garantir que ações críticas geram `AuditLog` corretamente, incluindo casos de falha).

### Importante
- Corrigir a constraint ineficaz de `queue_entries.ticket_number` (Achado de BD #2).
- Mover geração de PDF para fora de transações com lock, ou para fila (Achado de BD #5).
- Corrigir N+1 em `QueueController` (Achado de BD #4).
- Adicionar cast decimal em `PrescriptionItem.dose` (Achado de BD #7).
- Diversificar rate limiting de login além de IP (Achado seção 5).
- Confirmar existência de rotina de restore testada para os backups (`BackupLog`/`BackupVerification` existem no schema, mas não foi avaliado se há teste de restore real).
- Observabilidade: não foi identificado APM/error tracking (ex. Sentry) no código — recomenda-se confirmar se existe fora do repositório.

### Recomendado
- Estender o padrão `#[Fillable]` (hoje só em `User`) para models sensíveis (Patient, ClinicalDocument, Prescription) como defesa em profundidade contra mass assignment.
- Padronizar uso de Form Requests em `Administration` e `Operations`.
- Adicionar FK em `documents.current_version_id`.
- Consolidar `exam-order-items.js`/`prescription-items.js`.
- Adicionar factories para os principais models de teste (hoje só `UserFactory`).
- Atualizar/remover `IMPLEMENTATION_STATUS.md` e `docs/SYNC_HOSP_MODELO_BANCO_RESUMIDO.md` (desatualizados/descrevem arquitetura não implementada).
- Remover `SwitchHealthUnitRequest.php` órfão, se de fato não usado.

### Futuro
- Ampliar Playwright para fluxos funcionais completos (hoje é só smoke de layout).
- Migrar CSP para o build "CSP" do Alpine.js e remover `unsafe-eval`.
- Testes de concorrência cross-unidade para fila/painel.
- Validação de schema tipado para respostas do Synclab.

---

## 12. Código morto / duplicado

- `SwitchHealthUnitRequest.php` (Identity) parece não referenciado em nenhuma rota — candidato a remoção, mas confirmar antes se há uso indireto.
- Nenhum controller órfão encontrado (todos os 30 controllers são referenciados em `routes/web.php`); nenhuma rota aponta para método inexistente.
- Nenhum `dd()`/`dump()`/`var_dump()`/TODO/FIXME esquecido em `app/`.
- Nenhuma migration com padrão "coluna adicionada e removida logo depois" fora de blocos `down()`.
- Duplicação leve entre `exam-order-items.js` e `prescription-items.js` (seção 7).

---

## 13. Integrações

**Synclab (laboratório)** é a integração mais bem construída do sistema:
- HTTP via `Http::` facade, timeouts/retry lidos de `config('sync_sus.synclab.*')` (nunca `env()` em código de negócio).
- Credenciais (`username`/`password`) persistidas com cast `encrypted` (`LaboratoryIntegration.php:66-67`).
- Validação de URL HTTPS e CNES de 7 dígitos antes de qualquer chamada.
- Job idempotente (`ShouldBeUnique`, backoff `[60,300,900]`, `retryUntil` 6h), lock otimista por `worker_token` + `lockForUpdate()`, recuperação de transmissões "presas".
- Auditoria sanitizada (payload de paciente não vai para log de arquivo, só para colunas dedicadas de request/response — rastreabilidade correta, não exposição).
- Falhas de conexão lançam exceção (não engolidas silenciosamente); 429/5xx tratados como retryable.

Única ressalva: resposta do Synclab é usada sem validação de schema tipado (P3, baixo impacto hoje porque a integração é outbound-only). Escopo de recebimento de resultado/amostra/código de barras está explicitamente marcado `not_implemented` em `synclab_contract.php` — ver seção 11.

Nenhuma outra integração externa (DATASUS, etc.) foi encontrada no código.

---

## 14. Produção / infraestrutura

- **Docker:** containers rodam como `www-data` (non-root), `read_only: true`, `cap_drop: ALL`, `no-new-privileges`, healthchecks presentes em app/mysql/redis/nginx, sem segredos hardcoded. Nenhum problema encontrado.
- **`railway.json`:** `preDeployCommand` roda `php artisan db:seed --force` em todo deploy — mitigado porque seeders sensíveis (`DemoDataSeeder`) são guardados por `isProduction()`/config, mas os seeders de catálogo sempre rodam; depende de serem idempotentes (`updateOrCreate`), não auditados individualmente aqui.
- **`config/app.php`/`.env.example`:** `APP_DEBUG=false` por padrão — correto.
- **Rate limiting, trusted hosts, HSTS, X-Frame-Options, CSP** implementados em `AppServiceProvider`/`SecurityHeaders` — bem cobertos, com a ressalva de `unsafe-eval` (P3).
- **Dependências:** `composer.json` usa `minimum-stability: dev` com `prefer-stable: true` (risco baixo, mas atenção em novos requires sem constraint explícita); `dompdf` fixado em versão exata (`3.1.6`), inconsistente com o resto do arquivo. `package.json` — dependências atuais e sem duplicação (Alpine.js, Axios, Tailwind v4, Vite 7, Playwright) — nenhuma abandonada.
- Nenhum uso de `env()` fora de `config/` em código de negócio (única exceção aceitável: `bootstrap/app.php`, que roda antes do config estar disponível).

---

## 15. Top 10 riscos

| # | Severidade | Problema | Arquivo | Impacto |
|---|---|---|---|---|
| 1 | P1 | IDOR — paciente de outra organização visível via `public_id` | `ReceptionController.php:39` | Vazamento de PII de paciente entre tenants |
| 2 | P1 | Constraint global de CPF/CNS causa 500 não tratado + vazamento indireto de existência cross-org | `SavePatientAction.php:~82-95`, migration `create_patients_tables.php:62` | Erro não tratado + vazamento sutil de dado entre organizações |
| 3 | P1 | Painel público sempre mostra nome completo, ignorando configuração de privacidade da unidade | `PublicPanelController.php:61-66` | Exposição pública não autenticada de identidade de paciente |
| 4 | P1 | Ingestão de resultado de exame não implementada | `ExamResult` (schema apenas), `synclab_contract.php` | Lacuna funcional crítica para um módulo de laboratório |
| 5 | P2 | Mass assignment sem allowlist no nível de model (`$guarded=[]` em ~67 models) | vários `Infrastructure/Eloquent/*.php` | Sem exploração ativa hoje; ausência de defesa em profundidade |
| 6 | P2 | PDF gerado sincronamente dentro de transação com `lockForUpdate` | `ClinicalDocumentVersionService.php:33-43` | Lock prolongado em registro clínico compartilhado sob concorrência |
| 7 | P2 | Rate limit de login inclui IP na chave — brute force distribuído não é bloqueado por conta | `LoginRequest.php:30-63` | Autenticação fraca contra ataques distribuídos |
| 8 | P2 | `SESSION_SECURE_COOKIE`/`SYNC_SUS_REQUIRE_HTTPS` sem enforcement de código na ausência da env var | `config/session.php`, `config/sync_sus.php` | Sessão pode trafegar sem HTTPS se deploy omitir a variável |
| 9 | P2 | N+1 em listagem de filas | `QueueController.php:27-35` | Performance degradada em tela operacional de uso contínuo |
| 10 | P2 | Sem teste dedicado de auditoria, apesar de ser requisito central do projeto | `tests/Feature/*` (cobertura só incidental) | Regressões na trilha de auditoria podem passar despercebidas |

---

## 16. Plano de ação

### P0
Nenhum item P0 confirmado.

### P1
1. Corrigir IDOR em `ReceptionController::create` — escopar lookup de paciente por `organization_id`.
2. Corrigir constraint de `patient_identifiers` (escopar por organização ou tratar exceção com UX adequada) em `SavePatientAction`.
3. Corrigir `PublicPanelController` para respeitar `panels.identification_mode`.
4. Decidir/documentar/implementar o fluxo de recebimento de resultado de exame (ou formalizar como "fora de escopo do MVP" com decisão explícita registrada).

### P2
1. Adicionar allowlist (`$fillable`/`#[Fillable]`) em models sensíveis (Patient, ClinicalDocument, Prescription) como defesa em profundidade.
2. Mover renderização/gravação de PDF para fora de transações com lock (ou para Job).
3. Diversificar chave de rate limiting de login além de IP.
4. Confirmar/documentar enforcement de `SESSION_SECURE_COOKIE`/`SYNC_SUS_REQUIRE_HTTPS` no ambiente real de produção.
5. Corrigir N+1 em `QueueController::index`.
6. Adicionar cast decimal em `PrescriptionItem.dose`.
7. Adicionar teste dedicado de auditoria (`AuditLogTest`).
8. Corrigir constraint ineficaz de `queue_entries.ticket_number`.

### P3
1. Adicionar casts ausentes (`cancelled_at`, `observation_started_at`).
2. Adicionar FK em `documents.current_version_id`.
3. Padronizar Form Requests em `Administration`/`Operations`.
4. Remover/confirmar `SwitchHealthUnitRequest.php` órfão.
5. Atualizar/remover documentação desatualizada (`IMPLEMENTATION_STATUS.md`, `docs/SYNC_HOSP_MODELO_BANCO_RESUMIDO.md`).
6. Consolidar `exam-order-items.js`/`prescription-items.js`.
7. Migrar CSP para build "CSP" do Alpine.js.
8. Adicionar factories para models principais de teste.
9. Ampliar Playwright para fluxos funcionais.
10. Validar schema tipado de resposta do Synclab.

---

## 17. Ordem recomendada de implementação

```text
FASE 1 — Segurança crítica e privacidade de paciente
  - IDOR em ReceptionController
  - Painel público ignorando identification_mode
  - Enforcement de HTTPS/cookie seguro em produção

FASE 2 — Banco e integridade
  - Constraint de patient_identifiers + tratamento de exceção
  - Constraint de queue_entries.ticket_number
  - Casts ausentes, FK de documents.current_version_id

FASE 3 — Fluxos hospitalares
  - Decisão/implementação de ingestão de resultado de exame

FASE 4 — Auditoria
  - Teste dedicado de AuditLog
  - Confirmar auditoria de exportações de relatório

FASE 5 — Testes
  - Ampliar Playwright para fluxos funcionais
  - Factories para models principais
  - Testes de concorrência cross-unidade

FASE 6 — Performance
  - N+1 em QueueController
  - Mover geração de PDF para fora de transações com lock

FASE 7 — Infraestrutura e dívida técnica
  - Allowlist de mass assignment em models sensíveis
  - Padronizar Form Requests
  - Limpeza de documentação e código órfão
  - CSP sem unsafe-eval
```
