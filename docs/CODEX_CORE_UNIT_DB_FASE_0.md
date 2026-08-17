# Fase 0 — Fundação de resolução de conexão (Core + Unidade)

> **NÃO EXECUTAR NESTA RODADA.** Este documento existe para ficar pronto quando o time
> autorizar explicitamente o início da implementação, depois que as decisões bloqueantes
> da seção 22 de `docs/SYNC_HOSP_CORE_UNIT_DB_MASTER_PLAN.md` tiverem resposta. Nenhuma
> migration, model ou config deste documento foi criada. Ver também princípios
> arquiteturais obrigatórios (master plan, seção 2) — todo item abaixo deve respeitá-los.

## Objetivo desta fase

Provar o mecanismo de resolução de conexão por unidade **sem mover nenhum dado e sem
separar nenhum banco fisicamente**. Ao final da Fase 0, toda conexão "de Unidade" ainda
aponta para o mesmo banco físico único de hoje — o que muda é que a aplicação passa a
tratar Core e Unidade como conexões logicamente distintas, e todo relacionamento que
cruza essa fronteira deixa de depender de FK/`whereHas`/`with()` implícito. Isso permite
validar 100% do risco de refactor de código antes de qualquer risco de perda de dado
(que só começa na Fase 3).

## Pré-requisitos

As 5 decisões bloqueantes do master plan (seção 22) já foram respondidas. Efeito prático
sobre o escopo desta fase:

- **Decisão 22.1 (dado de paciente, Split)**: não muda o escopo da Fase 0. `Patient`/
  `PatientIdentifier` permanecem `CoreModel`; `PatientContact`/`Allergy`/`Condition`/
  `Medication`/`SocialHistory`/`Address`/`Guardian` **continuam no formato atual**
  (compartilhado por `organization_id`, sem reclassificar para `TenantModel`) até a
  Fase 2 — a Fase 0 não move nem duplica dado de paciente, e reclassificar esses models
  antes da Fase 2 criaria uma inconsistência (eles ainda não são per-unit no schema).
- **Decisão 22.2 (catálogos híbridos)**: **muda** o escopo da Fase 0 — ao contrário do que
  o rascunho anterior deste documento assumia, a classificação já é definitiva, não
  provisória. `Exam`/`ExamGroup`/`DiagnosisCode`/`SusProcedure`/`RiskLevel` (canônico) são
  `CoreModel`; `HealthUnitExam`/`LaboratoryIntegration`/`LaboratoryExam`/
  `LaboratoryExamComponent`/`ExamMapping`/configuração de `RiskLevel`/`EntryType`/
  `ArrivalMethod` são `TenantModel` — sem necessidade de migração de dado, porque os
  catálogos operacionais já são `health_unit_id`-scoped hoje. Isso entra na seção "Escopo"
  como item novo (ver item 3-bis abaixo).
- Autorização explícita do usuário para começar a escrever código — esta fase **não deve
  começar só porque este documento existe**.

## Escopo

### 1. Infraestrutura de conexão

- `config/database.php`: adicionar entradas de conexão nomeadas `core` e um template para
  conexões de unidade (ex. `tenant_uXXXX`), todas apontando para as mesmas credenciais/
  banco físico do `DB_CONNECTION` atual nesta fase. Nenhuma conexão nova de fato — é
  metadado de roteamento.
- `App\Support\Tenancy\TenantContext` — classe de request/job scope (não singleton de
  container simples; precisa suportar reset explícito por requisição/job, pensando já na
  restrição da seção 18 do master plan mesmo sem Octane hoje). Contém: `HealthUnit`
  ativo resolvido, nome da conexão correspondente. Fail-closed: método de leitura lança
  `TenantContextNotResolvedException` se chamado antes de populado.
- `App\Support\Tenancy\TenantResolver` (interface) + implementação inicial que lê do
  `EnsureActiveHealthUnit` já existente (não duplica a revalidação de vínculo — reaproveita
  a que já roda, ADR-003).
- `App\Support\Tenancy\TenantConnectionManager` — resolve nome de conexão a partir do
  `HealthUnit` corrente. Nesta fase, sempre retorna a conexão default (todas as unidades
  "compartilham" fisicamente o mesmo banco). **Não depende da entidade `tenant_databases`**
  — essa só é criada na Fase 3, quando há de fato um ciclo de vida por unidade a
  consultar; antes disso não existe nada a olhar, e a implementação desta fase pode
  devolver sempre a mesma conexão sem tabela de apoio nenhuma. O código consumidor não
  pode saber que a resposta é sempre igual; a interface já deve ser a definitiva, só a
  implementação é trivial nesta fase.
- `App\Support\Models\CoreModel` (abstract) — fixa `$connection`.
- `App\Support\Models\TenantModel` (abstract) — sobrescreve `getConnectionName()` para
  consultar `TenantContext` corrente; lança se não resolvido.
- `EnsureActiveHealthUnit.php`: depois de resolver e revalidar a unidade ativa (fluxo
  atual inalterado), popula `TenantContext` antes de a requisição prosseguir. Middleware
  continua fail-closed (comportamento atual preservado, só adiciona a população de
  contexto).

### 2. Índice de descoberta pública (master plan, seção 7)

- Nova tabela Core `public_lookup_index` (`public_id_or_code`, `entity_type`,
  `tenant_connection`, timestamps). Chave única em `public_id_or_code`.
- `Panel` e `ClinicalDocument`: no fluxo de criação, grava entrada correspondente no
  índice (escrita idempotente, chave única evita duplicata em retry).
- **Backfill obrigatório**: comando artisan (`tenant:backfill-public-lookup-index` ou
  equivalente) que popula o índice para todo `Panel`/`ClinicalDocument` **já existente**
  antes do índice passar a ser a única via de resolução. Sem isso, subir a Fase 0 em
  produção quebraria a verificação pública de todo documento já emitido e todo painel já
  cadastrado — o oposto do objetivo de "nenhuma regressão de comportamento". Rodar o
  backfill é pré-requisito de deploy desta fase, não um nice-to-have.
- `PublicPanelController` e `ClinicalDocumentController::verify()`: passam a consultar o
  índice primeiro para resolver a conexão, só então buscar o registro na conexão certa.
  Nesta fase o "achar a conexão certa" sempre resolve para a mesma conexão física, mas o
  código já fica pronto para quando isso deixar de ser verdade (Fase 3+).

### 3. Correção dos relacionamentos cross-boundary confirmados (master plan, seções 1.1 e 6)

Para cada um dos 64 relacionamentos originais (roadmap anterior, seção 5.2) **mais** os 3
novos confirmados nesta rodada:

- `Queue::whereHas('healthUnit', ...)` (`HealthProfessionalController.php:110`)
- `ServicePoint::whereHas('room.department.healthUnit', ...)` (`HealthProfessionalController.php:118`)
- `Queue::whereHas('professionals', ...)` / `QueueVisibilityService.php`

E os já mapeados como efetivamente quebrados na matriz do master plan (seção 6):
`QueueController.php:87-91`, `PublicPanelController` (`entry.encounter.patient`),
`ClinicalDocumentController.php` (`with(['patient', 'healthUnit.organization', ...])`),
`IssueClinicalDocumentAction.php` (`forceFill` de `patient_id`/gravação FK direta), **e
`AuditTrailQuery.php:104,110`** (`whereHas('patient', ...)`/`whereHas('encounter', ...)`).

**Correção a este documento (autoauditoria pós-implementação)**: a versão anterior deste
documento excluía `AuditTrailQuery.php` do escopo da Fase 0, com o argumento de que a
query só quebraria depois do split de `AuditLog` (Fase 4). Esse argumento estava errado —
o conserto (`whereHas('patient', ...)` → resolver `Patient` primeiro, filtrar por
`patient_id IN (...)` depois) corrige a relação `AuditLog → Patient`, que já é
cross-boundary desde a Fase 0 porque `Patient` já é `CoreModel` desde já, **independente**
de `AuditLog` continuar sendo uma tabela única ou não. O split de `AuditLog` em
`security_audit_logs`/`audit_logs` continua sendo Fase 4 (não mexe na tabela nem na
classificação do model `AuditLog` em si) — mas a correção da *query* contra `Patient` é
ortogonal a isso e pertence à Fase 0, junto com todas as outras.

Padrão de correção uniforme: FK direta vira coluna `*_public_id` (string); relação
Eloquent nativa (`belongsTo`/`whereHas`) é substituída por um método explícito
(`resolvePatient(): ?Patient`) que faz o lookup na conexão Core, documentado como
cross-connection lookup, não como relation. `whereHas`/`with()` cross-boundary vira
consulta em dois passos (resolver IDs/public_ids no lado Core primeiro, filtrar por
lista no lado Unidade depois) — exatamente como especificado na coluna "Estratégia" da
matriz do master plan.

### 3-bis. Classificação definitiva de catálogos (master plan, seção 22.2)

- `Exam`, `ExamGroup`, `DiagnosisCode`, `SusProcedure`, `RiskLevel` (canônico) viram
  `CoreModel`.
- `HealthUnitExam`, `LaboratoryIntegration`, `LaboratoryExam`, `LaboratoryExamComponent`,
  `ExamMapping`, configuração de `RiskLevel`, `EntryType`, `ArrivalMethod` viram
  `TenantModel`.
- Todo acesso de um `TenantModel` a um catálogo `CoreModel` canônico (ex.: `HealthUnitExam`
  resolvendo o `Exam` que referencia) passa por um serviço de leitura dedicado
  (`CatalogReader`/equivalente), nunca por Eloquent relation — consistente com o padrão de
  lookup em dois passos da seção 6 do master plan.
- Sem migração de dado nesta fase: os catálogos operacionais já são `health_unit_id`-scoped
  hoje, então a reclassificação é só de conexão/model, não de schema.

### 4. Jobs e scheduler (master plan, seção 10)

- `SubmitLaboratoryOrderJob`/`ProcessSynclabExamResultJob`: construtor passa a receber
  também o identificador de conexão de unidade (nesta fase, sempre resolve para a mesma
  conexão física, mas o payload já carrega a informação).
- `routes/console.php:153-159`: os 2 comandos agendados passam a, primeiro, listar
  unidades elegíveis (query Core) e despachar um job por unidade, em vez de uma query
  global sem filtro.

### 5. Ordem de middleware/binding (master plan, seção 19)

- Auditar `ClinicalDocumentController`, `MedicalConsultationController` e demais
  controllers com route-model-binding automático (`ClinicalDocument $document`,
  `MedicalConsultation $consultation`) para garantir que `TenantContext` já está resolvido
  e autorizado **antes** do binding tentar resolver o model. Onde isso não for verdade
  hoje (binding acontece na resolução de rota, antes de qualquer middleware customizado
  rodar, dependendo de onde `EnsureActiveHealthUnit` está registrado no grupo de rota),
  documentar e corrigir a ordem — sem mudar o comportamento de autorização já existente,
  só garantindo que a ordem está correta.

### 6. Testes (dependência da Fase 1, mas fixtures mínimas entram aqui)

- `tests/TestCase.php`: adicionar `createCoreFixtures()`/`createTenantFixtures()` como
  métodos novos, sem remover os helpers atuais ainda (a remoção/substituição completa é
  escopo da Fase 1). Um teste de integração por relacionamento corrigido, verificando que
  o lookup cross-connection retorna o mesmo resultado que a relation Eloquent antiga
  retornava (regressão zero de comportamento, só de mecanismo).

## Fora de escopo (explicitamente, para não expandir a fase)

- Qualquer separação física de banco (Fase 3).
- Entidade `tenant_databases` e seu ciclo de vida (Fase 3) — Fase 0 não precisa dela, ver
  seção 1.
- Qualquer migração de dado de paciente (Fase 2).
- Split de `AuditLog` em duas tabelas (`security_audit_logs`/`audit_logs`), Fase 4 —
  depende de Fase 0 estar validada. Não inclui a correção da query de
  `AuditTrailQuery.php` contra `Patient`/`Encounter`, que É escopo da Fase 0 (ver seção 3).
- Criptografia/fingerprint de `PatientIdentifier` (decisão 22.5, não bloqueante, não
  incluída aqui).
- Reporting assíncrono (Fase 5).

## Critérios de aceite

- Todos os relacionamentos da lista da seção 3 substituídos pelo padrão de lookup
  explícito, com teste de regressão de comportamento (mesmo resultado, mecanismo novo).
- Nenhum `whereHas`/`with()` remanescente que atravesse a fronteira Core/Unidade conforme
  a matriz do master plan (seção 6) — confirmável por uma varredura final repetindo as
  buscas usadas para produzir essa matriz. Inclui `AuditTrailQuery.php:104,110` (ver
  correção na seção 3).
- Backfill do índice de descoberta pública executado e confirmado: todo `Panel` e
  `ClinicalDocument` existente antes da Fase 0 tem entrada correspondente — verificação
  pública de documentos já emitidos e painéis já cadastrados continua funcionando sem
  interrupção.
- `PublicPanelController` e `ClinicalDocumentController::verify()` resolvendo via índice
  de descoberta pública, não mais por query global direta.
- Os 2 comandos agendados despachando por unidade, não mais em query global sem filtro.
- Suíte de testes existente (156 testes confirmados passando na última execução) continua
  100% verde — nenhuma regressão de comportamento, só de mecanismo interno.
- `phpstan analyse` sem novos erros introduzidos.

## Prompt para o Codex (a ser entregue somente após autorização explícita)

```
Contexto: SyncHosp está iniciando a Fase 0 da migração para arquitetura Core + Banco por
Unidade, documentada em docs/SYNC_HOSP_CORE_UNIT_DB_MASTER_PLAN.md e detalhada em
docs/CODEX_CORE_UNIT_DB_FASE_0.md. Esta fase NÃO move nenhum dado nem separa bancos
fisicamente — só introduz a infraestrutura de resolução de conexão e corrige os
relacionamentos cross-boundary para não dependerem mais de FK/whereHas/with() implícito
entre o que será "Core" e o que será "Unidade".

Leia os dois documentos acima por completo antes de começar. Implemente exatamente o
escopo da seção "Escopo" de docs/CODEX_CORE_UNIT_DB_FASE_0.md, nesta ordem:
1. Infraestrutura de conexão (TenantContext, TenantResolver, TenantConnectionManager,
   CoreModel, TenantModel) — sem nenhuma conexão física nova ainda, e sem depender da
   entidade tenant_databases (essa só existe a partir da Fase 3).
2. Índice de descoberta pública + backfill dos Panel/ClinicalDocument já existentes +
   os 2 endpoints públicos existentes. O backfill é pré-requisito de deploy, não opcional.
3. Classificação definitiva de catálogos (CoreModel vs TenantModel, seção 3-bis).
4. Correção de cada relacionamento cross-boundary da matriz (master plan, seção 6) e da
   lista da seção 3 deste documento, incluindo AuditTrailQuery.php:104,110 — um commit
   por módulo, não um commit único gigante.
5. Jobs e scheduler tenant-aware.
6. Auditoria de ordem de middleware/binding.
7. Fixtures mínimas de teste + um teste de regressão por relacionamento corrigido.

Não toque em nada fora deste escopo (ver "Fora de escopo" no documento, incluindo o split
de AuditLog e a entidade tenant_databases). Não altere o comportamento observável de
nenhuma tela — o objetivo é mecanismo interno, não funcionalidade nova. Rode a suíte
completa e phpstan antes de considerar qualquer item concluído.
```
