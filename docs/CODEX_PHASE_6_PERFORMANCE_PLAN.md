# FASE 6 — PERFORMANCE IMPLEMENTATION PLAN

> Documento de planejamento. Nenhum código, migration ou refactor foi aplicado ao produzir este arquivo. Toda afirmação sobre o estado atual foi verificada diretamente no código-fonte e, para PERF-01, medida empiricamente (contagem real de queries, não estimativa).

## 1. Resumo

PERF-01 (N+1 em `QueueController::index`) está **[CONFIRMADO]** e foi medido: 20 filas geram **43 queries** (fórmula `3 + 2N`), porque `servicePointsFor()` roda uma query de `service_points` + uma de `rooms` por fila, dentro de um loop. A correção é um eager-load parametrizado no `QueueVisibilityService`, sem mudar nenhuma regra de visibilidade. PERF-02 é **[PARCIALMENTE CONFIRMADO]**: o problema é real, mas o arquivo/linha original da auditoria (`ClinicalDocumentVersionService.php:33-43`) não é onde o lock vive — o lock está nas três Actions chamadoras (`CreateDocumentVersionAction`, `IssueClinicalDocumentAction`, `GenerateSourceClinicalDocumentAction`), cada uma com um invariante diferente. A correção escolhida é síncrona (não fila): renderizar o PDF fora do lock e só escrever no banco/disco dentro de uma seção crítica curta, revalidando o invariante sob lock antes de gravar — sem nenhuma migration, reaproveitando o padrão de concorrência otimista (`lock_version`) já usado em Triage/Queues.

## 2. Validação dos achados

| Achado | Status | Evidência |
|---|---|---|
| PERF-01 | **[CONFIRMADO]** | `app/Modules/Queues/Presentation/Http/Controllers/QueueController.php:33-35` — `$queues->each(fn ($queue) => $queue->setRelation('servicePoints', $visibility->servicePointsFor($queue, $user)))`. Medido: 20 filas → 43 queries (script tinker, transação revertida, sem persistir dados) |
| PERF-02 | **[PARCIALMENTE CONFIRMADO]** | O padrão "transação + lock + Dompdf + Storage" existe, mas em 3 Actions diferentes, não na linha citada pela auditoria. `ClinicalDocumentVersionService.php` (`app/Modules/Documents/Application/Services/ClinicalDocumentVersionService.php`) é o serviço que renderiza — ele **não abre transação nem lock**; quem faz isso são os chamadores |

---

## 3. PERF-01 — Diagnóstico

Fluxo real: rota `queues.index` (`routes/web.php`) → `QueueController::index()` → `QueueVisibilityService::apply()` (1 query, filas da unidade) → `->with('department')` (1 query, eager load) → **loop** `$queues->each()` chamando `QueueVisibilityService::servicePointsFor($queue, $user)` por fila, que executa:
```php
$queue->servicePoints()->where('service_points.is_active', true)->with('room')->orderBy(...)->get();
```
Isso é **2 queries por fila** (uma para `service_points` via pivot `queue_service_point`, uma para `rooms` do eager-load `with('room')` daquela chamada isolada).

**[CONFIRMADO NO CÓDIGO, medido]**: com 20 filas ativas numa unidade, a tela executa **43 queries**:
- 1 query fixa: checagem de role (`hasAnyRole` → `roles`/`model_has_roles`) dentro de `hasBroadAccess()`
- 1 query: `queues` (`where health_unit_id, is_active, order by display_order`)
- 1 query: `departments` (eager load `with('department')`)
- 40 queries: 2 por fila × 20 filas (`service_points` + `rooms`)

Crescimento: **queries = 3 + 2N**, onde N = número de filas ativas na unidade. Linear, sem teto — uma unidade com 50 filas geraria 103 queries na mesma tela.

## 4. PERF-01 — Solução escolhida

**[DECISÃO TÉCNICA]**: eager loading parametrizado via `with()` com closure, mantendo a regra de visibilidade (acesso amplo vs. filtragem por profissional) **exatamente como está hoje**, só reescrita como uma constraint reutilizável em vez de uma query por fila.

Comparativo rápido:
- `load()` depois de já ter a coleção: mesmo número de queries que hoje se aplicado por item; só ajuda se aplicado uma vez sobre a coleção inteira (é isso que faremos, mas via `with()` na query original é mais direto no Eloquent atual).
- `withCount()`: não serve — precisamos dos registros de `servicePoints`, não uma contagem.
- `join`/subquery manual: resolveria, mas duplicaria a lógica de visibilidade (que já existe em `QueueVisibilityService`) em SQL cru, violando "reaproveitar convenções existentes" do projeto e tornando a regra de acesso mais difícil de auditar.
- **Escolhida**: expor a mesma lógica de `servicePointsFor()` como uma **closure de constraint** reutilizável, usada em `Queue::with(['servicePoints' => $constraint])`. O Eloquent resolve isso em exatamente 2 queries no total (uma para todos os `service_points` das filas carregadas via `whereIn`, uma para todos os `rooms` relacionados), independentemente de N.

## 5. PERF-01 — Arquivos afetados

- `app/Modules/Queues/Application/Services/QueueVisibilityService.php` — adicionar um novo método público, ex.: `servicePointsEagerLoadConstraint(User $user): Closure`, contendo a MESMA lógica hoje dentro de `servicePointsFor()` (filtro `is_active`, `with('room')`, `orderBy('name')`, e o `whereHas('professionals', ...)` condicional para usuários sem acesso amplo). `servicePointsFor()` **não muda e não é removido** — continua usado por `ensureCanAccessEntry`/`ensureCanUseServicePoint`/`applyEntryScope`, que operam sobre UMA fila por vez (não é N+1 ali).
- `app/Modules/Queues/Presentation/Http/Controllers/QueueController.php` — em `index()`, trocar o bloco `$queues->each(...)` (linhas 33-35) por `->with(['department', 'servicePoints' => $visibility->servicePointsEagerLoadConstraint($user)])` na query original (linha 27-32).

Nenhum outro arquivo muda. Nenhuma view muda (a coleção `servicePoints` continua disponível na mesma forma nas Blade views).

## 6. PERF-01 — Testes e benchmark

**Benchmark (medido, não estimado):**
```text
Queries antes:  3 + 2N   (20 filas → 43 queries, medido)
Queries depois: constante, independente de N (2 queries adicionais fixas: service_points + rooms,
                 em vez de 2N) — total esperado: 4 queries (role check + queues + department + service_points+rooms
                 combinados em eager load do Eloquent, tipicamente 2 queries mesmo com N grande)
```

**Teste novo — `tests/Feature/QueueFlowTest.php`** (arquivo já existe e já testa `route('queues.index')` na linha 39, mas sem asserção de contagem de queries):
Adicionar um teste dedicado, seguindo o mesmo padrão de fixtures já usado em `QueueFlowTest::context()`:
1. Criar N filas (ex.: 8) na mesma unidade, cada uma com 1-2 `service_points` ativos.
2. `DB::enableQueryLog()` antes da requisição, `DB::getQueryLog()` depois — mesmo mecanismo usado neste diagnóstico, sem dependência nova.
3. Requisitar `route('queues.index')` autenticado.
4. Assert: `count($log)` é **menor ou igual a um teto fixo baixo** (ex.: 6) — não pode crescer com N. Repetir com N diferente (ex.: 3 e 8) e assertar que a contagem **não muda** entre as duas execuções — essa é a prova de que o N+1 foi eliminado, mais robusta que um número absoluto isolado.
5. Reaproveitar a asserção de conteúdo já existente (nomes de fila, service points corretos) para garantir que a listagem continua correta.
6. Repetir o mesmo teste com um usuário SEM acesso amplo (profissional vinculado só a alguns `service_points`) para confirmar que a filtragem por profissional continua idêntica.

## 7. PERF-02 — Diagnóstico

`ClinicalDocumentVersionService::create()` (`app/Modules/Documents/Application/Services/ClinicalDocumentVersionService.php:19-55`) faz: `loadMissing` de relações → `View::make('documents.pdf')->render()` → `$this->renderer->render($html)` (Dompdf) → `Storage::put` (html e pdf) → `$document->versions()->create(...)`. **Este método não abre transação nem lock.** Confirmei os 3 pontos que efetivamente o chamam dentro de `DB::transaction()`, cada um com um invariante diferente:

| Chamador | Lock? | Sobre qual linha | Invariante protegido |
|---|---|---|---|
| `CreateDocumentVersionAction.php:34-74` | **Sim** — `lockForUpdate()` linha 39 | `ClinicalDocument` (documento existente) | `version_number` sequencial (`max+1`) e `current_version_id` sempre consistente |
| `IssueClinicalDocumentAction.php:56-89` | **Não** — nenhum `lockForUpdate()` | — (linha nova, criada na própria transação) | Nenhum — não há concorrência possível sobre uma linha que ainda não existe |
| `GenerateSourceClinicalDocumentAction.php:48-68` | **Sim** — `lockForUpdate()` linha 49 | `Prescription` / `ExamOrder` / `Referral` (registro de origem) | Idempotência: cada origem gera **no máximo um** `ClinicalDocument` (`document_id` null → preenchido uma única vez) |

Tipos de documento afetados, confirmados no código: `ClinicalDocument` (correção/nova versão via `CreateDocumentVersionAction`), e via `GenerateSourceClinicalDocumentAction`: `Prescription`, `ExamOrder`, `Referral` — exatamente os três citados na auditoria, confirmados em `GenerateSourceClinicalDocumentAction.php:26-39` (métodos `prescription()`, `examOrder()`, `referral()`).

**[FORA DO ESCOPO — descoberto durante a investigação]**: `IssueMedicalCertificateAction.php:32-83` também chama `IssueClinicalDocumentAction::executeStructured()` dentro de `DB::transaction()`, sem `lockForUpdate()` (mesmo perfil de `IssueClinicalDocumentAction` isolado — só duração de transação, sem risco de lock-wait). Não foi citado pela auditoria original nem pela lista de relações do enunciado (`ClinicalDocument; Prescription; ExamOrder; Referral`). Registrado em **BACKLOG — FORA DO ESCOPO** (seção 18).

## 8. Invariante protegido pelo lock

```text
INVARIANTE PROTEGIDO (CreateDocumentVersionAction):
  Duas requisições concorrentes de nova versão do MESMO documento nunca podem:
  (a) calcular o mesmo version_number, nem
  (b) deixar current_version_id apontando para uma versão que não é a mais recente aceita.

INVARIANTE PROTEGIDO (GenerateSourceClinicalDocumentAction):
  Uma prescrição/pedido de exame/encaminhamento nunca pode gerar dois ClinicalDocument
  distintos (idempotência de document_id).

IssueClinicalDocumentAction: nenhum invariante de concorrência a proteger — o documento
  e a primeira versão nascem juntos, na mesma transação, sobre uma linha nova.
```

## 9. Transaction boundary atual

**`CreateDocumentVersionAction::execute()`** (`app/Modules/Documents/Application/Actions/CreateDocumentVersionAction.php:34-74`):
```text
BEGIN
  SELECT ClinicalDocument ... FOR UPDATE          (linha 35-40)
  valida status/tipo                               (linha 41-48)
  version_number = max(versions.version_number)+1  (linha 49)
  ClinicalDocumentVersionService::create():
    View::make('documents.pdf')->render()          ← CPU, com lock aberto
    Dompdf render                                   ← CPU, com lock aberto
    Storage::put (html)                             ← I/O, com lock aberto
    Storage::put (pdf)                              ← I/O, com lock aberto
    INSERT document_versions                        (linha 45-54 do Service)
  UPDATE clinical_documents SET current_version_id   (linha 61)
  INSERT audit_logs                                  (linha 62-70)
COMMIT
```

**`GenerateSourceClinicalDocumentAction::generate()`** (`app/Modules/Documents/Application/Actions/GenerateSourceClinicalDocumentAction.php:48-68`):
```text
BEGIN
  SELECT Prescription|ExamOrder|Referral ... FOR UPDATE   (linha 49)
  verifica document_id ainda nulo                          (linha 52-55)
  IssueClinicalDocumentAction::executeStructured():
    BEGIN (savepoint, mesma conexão)
      INSERT clinical_documents                            (linha 58-68 do Issue)
      ClinicalDocumentVersionService::create():
        View::make + Dompdf render                          ← CPU, com lock DA ORIGEM aberto
        Storage::put x2                                      ← I/O, com lock DA ORIGEM aberto
        INSERT document_versions
      UPDATE clinical_documents SET current_version_id
      INSERT audit_logs
    COMMIT (savepoint)
  UPDATE prescriptions|exam_orders|referrals SET document_id  (linha 64)
COMMIT
```

## 10. Transaction boundary recomendado

**`CreateDocumentVersionAction`** — leitura otimista fora do lock, escrita curta e validada dentro dele, **sem retry automático** (mesmo padrão de rejeição por conflito já usado em `RecordVitalSignsAction`/`StartTriageAction` com `lock_version`):
```text
(sem transação)
  SELECT ClinicalDocument + versions (leitura simples)
  valida status/tipo (falha rápida se já inválido)
  version_number_esperado = max(versions.version_number)+1
  View::make + Dompdf render (inclui "Versão {version_number_esperado}")
  calcula hash

BEGIN (curta)
  SELECT ClinicalDocument ... FOR UPDATE
  revalida status/tipo
  SE max(versions.version_number) != version_number_esperado - 1:
    ROLLBACK, retorna erro de conflito (documento foi alterado por outro usuário)
  SENÃO:
    Storage::put (html, pdf)         ← só ocorre se a escrita vai mesmo acontecer
    INSERT document_versions
    UPDATE current_version_id
    INSERT audit_logs
COMMIT
```

**`GenerateSourceClinicalDocumentAction`** — mesma ideia, mas o "conflito" é só existência (idempotência), não numeração:
```text
(sem transação)
  SELECT Prescription|ExamOrder|Referral (leitura simples, sem lock)
  valida status (ex.: prescrição precisa estar 'finalized')
  monta $content (prescriptionContent/examOrderContent/referralContent)
  View::make + Dompdf render + hash

BEGIN (curta)
  SELECT origem ... FOR UPDATE
  SE document_id já preenchido (alguém venceu a corrida):
    COMMIT sem escrever nada novo, retorna o ClinicalDocument já existente
  SENÃO:
    INSERT clinical_documents
    Storage::put (html, pdf)
    INSERT document_versions
    UPDATE current_version_id
    UPDATE origem SET document_id
    INSERT audit_logs
COMMIT
```

**`IssueClinicalDocumentAction`** (chamada direta, sem lock hoje) — só reordena, sem lógica de conflito:
```text
(sem transação)
  View::make + Dompdf render + hash

BEGIN (curta)
  INSERT clinical_documents
  Storage::put (html, pdf)
  INSERT document_versions
  UPDATE current_version_id
  INSERT audit_logs
COMMIT
```

Em **nenhum** dos três fluxos o `Storage::put` ocorre antes de sabermos que a escrita no banco vai mesmo acontecer — isso elimina o risco de arquivo órfão sem precisar de arquivo temporário, rename, ou cleanup posterior (ver seção 13).

## 11. PERF-02 — Solução escolhida

**[DECISÃO TÉCNICA]**: **Solução A** (síncrona, fora da seção crítica) — não fila.

Justificativa direta às perguntas do enunciado: documentos médicos (receita, atestado, pedido de exame, encaminhamento) precisam existir e estar prontos para impressão/entrega **imediatamente** após a ação do profissional — é assim que o fluxo funciona hoje (o médico gera e já pode imprimir/entregar). Introduzir fila mudaria essa UX (documento "pendente" por alguns instantes) e criaria uma classe nova de estado intermediário e de falha (job perdido, retry, usuário vendo "gerando..." para uma ação clínica que hoje é instantânea) sem necessidade — o problema real (lock aberto durante CPU-bound work) se resolve completamente só reordenando o trabalho, sem async. A infraestrutura de fila já existe no projeto (usada pelo Synclab) mas adotá-la aqui seria sofisticação desnecessária para o problema medido.

## 12. PERF-02 — Arquivos afetados

- `app/Modules/Documents/Application/Services/ClinicalDocumentVersionService.php` — dividir `create()` em dois métodos:
  - `render(ClinicalDocument $document, array $content, int $versionNumber): array` (ou um DTO simples) — faz `loadMissing`, `View::make`, `Dompdf render`, calcula hash e os paths de destino. **Sem nenhuma escrita.**
  - `persist(ClinicalDocument $document, array $rendered, User $user, ?string $reason): DocumentVersion` — faz os dois `Storage::put` e o `INSERT document_versions`. Chamado **dentro** da transação curta.
- `app/Modules/Documents/Application/Actions/CreateDocumentVersionAction.php` — reestrutura `execute()` conforme seção 10; adiciona o caminho de rejeição por conflito de versão.
- `app/Modules/Documents/Application/Actions/IssueClinicalDocumentAction.php` — divide `executeStructured()` em `render(...)` (chama `ClinicalDocumentVersionService::render`) e `persist(ClinicalDocument $document, array $rendered, ...)`; `executeStructured()` publicamente continua existindo e com a mesma assinatura/comportamento (chama `render()` seguido de `DB::transaction(fn() => persist(...))`), para não quebrar quem já a chama diretamente.
- `app/Modules/Documents/Application/Actions/GenerateSourceClinicalDocumentAction.php` — reestrutura `generate()` para chamar `IssueClinicalDocumentAction::render(...)` fora da sua própria transação, e `IssueClinicalDocumentAction::persist(...)` dentro da transação curta que já protege a origem.

**Arquivos que NÃO devem ser alterados**: `app/Modules/Documents/Infrastructure/Pdf/DompdfRenderer.php`, `app/Modules/Documents/Infrastructure/Eloquent/*.php` (nenhuma migration, nenhuma coluna nova), `app/Modules/Documents/Application/Actions/IssueMedicalCertificateAction.php` (fora do escopo, seção 18), qualquer view Blade, qualquer rota.

## 13. Tratamento de falhas

Como `Storage::put` só ocorre depois de confirmado (sob o lock, na seção 10) que a escrita vai acontecer, os cenários do enunciado ficam cobertos sem necessidade de arquivo temporário/rename/compensação:

- **PDF falha ao renderizar** (Dompdf lança exceção): acontece **antes** de qualquer transação abrir. Nenhuma linha de banco tocada, nenhum arquivo escrito. A exceção simplesmente propaga como já acontece hoje.
- **Conflito de versão detectado sob lock** (`CreateDocumentVersionAction`): a transação curta faz rollback sem nunca ter chamado `Storage::put`; o PDF já renderizado (em memória) é descartado; retorna erro de validação pedindo para o usuário tentar novamente — mesmo padrão de UX já usado em `RecordVitalSignsAction`/`StartTriageAction` para conflito de `lock_version`.
- **Corrida vencida por outro processo** (`GenerateSourceClinicalDocumentAction`): a transação curta não escreve nada novo, retorna o documento que o outro processo já criou; o PDF renderizado localmente é descartado (nunca tocou disco).
- **Falha no `Storage::put` em si** (disco cheio, permissão): ocorre dentro da transação curta, antes do `INSERT document_versions` — a transação inteira faz rollback, nenhuma linha órfã fica no banco. Não há arquivo órfão porque o arquivo com esse nome nunca existiu antes desta tentativa.

## 14. Concorrência

Cenário do enunciado — usuário A e usuário B gerando versão simultaneamente do mesmo documento:
- Ambos leem `max(version_number)` sem lock, ambos podem calcular o mesmo número esperado (ex.: 3) e renderizar em paralelo — isso é aceitável, é trabalho de CPU redundante, não um problema de correção.
- Ambos tentam abrir a transação curta. O SGBD serializa o `SELECT ... FOR UPDATE`: o primeiro a chegar prossegue, cria a versão 3, libera o lock. O segundo, ao adquirir o lock, revalida `max(version_number)` e encontra 3 (não mais 2) — detecta o conflito, faz rollback, descarta seu PDF renderizado, retorna erro.
- Resultado: **nunca** duas versões com o mesmo número, **nunca** `current_version_id` sobrescrito incorretamente, **nenhum** arquivo sobrescrito (nomes de arquivo incluem `version_number`, e só o vencedor chega a escrever).
- Mesmo raciocínio para `GenerateSourceClinicalDocumentAction`: o segundo processo, ao adquirir o lock, encontra `document_id` já preenchido e não cria um segundo documento.

Teste de concorrência planejado (mesmo padrão dos testes existentes de `lock_version`, que simulam a corrida por interleaving determinístico em vez de threads reais — PHPUnit/SQLite não suportam concorrência real):
1. Chamar a Action uma vez até o ponto de "ter a versão esperada calculada", sem persistir.
2. Simular um segundo processo que persiste uma versão nova "por baixo" (ex.: chamando a Action normalmente uma vez, de ponta a ponta).
3. Deixar o primeiro processo (que já tinha um número de versão "desatualizado" calculado) prosseguir e assertar que ele é rejeitado com erro de conflito, e que o banco continua com exatamente uma versão nova (a do segundo processo), sem duplicidade.

## 15. Testes e regressão

Fluxos que usam a cadeia investigada, com teste existente e lacuna:

| Fluxo | Arquivo | Comportamento atual | Teste existente | Lacuna |
|---|---|---|---|---|
| Emitir documento clínico (relatório médico) | `IssueClinicalDocumentAction` via `ClinicalDocumentController` | Já testado ponta a ponta | `DocumentsAndReportsTest::test_document_pdf_is_private_versioned_verifiable_and_voidable` (linha 96) | Nenhuma — teste cobre criação, download, nova versão, void; continua válido sem mudança de asserção |
| Nova versão / correção de documento | `CreateDocumentVersionAction` | Testado no mesmo teste acima (linhas 144-181) | idem | Falta teste de **conflito de versão sob concorrência** (seção 14) |
| Prescrição/pedido de exame/encaminhamento → PDF | `GenerateSourceClinicalDocumentAction` | Testado | `DocumentsAndReportsTest::test_structured_records_generate_one_pdf_from_their_own_tabs` (linha 184) | Falta teste de **corrida de idempotência** (duas chamadas concorrentes para a mesma origem) |
| Anulação (void) | não usa `ClinicalDocumentVersionService` | Não afetado por esta fase | `ClinicalCorrectionTest::test_author_can_void_clinical_records_without_deleting_original_content` | Nenhuma — fora do escopo desta fase |

Checklist do enunciado, mapeado:
```text
[x] documento continua sendo criado — coberto por DocumentsAndReportsTest existente
[x] PDF continua sendo gerado — coberto (assertStringStartsWith('%PDF', ...))
[x] versionamento continua correto — coberto (assertSame(2, currentVersion->version_number))
[x] current_version_id continua correto — coberto
[x] hash continua correto — coberto (assertSame(hash('sha256', $pdf), ...))
[x] auditoria continua ocorrendo — coberto (assertDatabaseHas audit_logs)
[ ] falha na geração não deixa estado inconsistente — NOVO teste necessário (seção 13)
[ ] dois processos concorrentes não geram versão duplicada — NOVO teste necessário (seção 14)
[x] permissões continuam iguais — coberto (receptionist->assertForbidden())
```

## 16. Benchmark antes/depois

### N+1 (medido)
```text
Queries antes:  43  (20 filas, medido via DB::getQueryLog() em transação revertida)
Queries depois: esperado 4 (fixo, independente de N) — a validar com o mesmo script após a mudança
```

### PDF / lock
Não é possível medir de forma confiável a duração real do lock no SQLite local (não replica contenção do MySQL de produção). Em vez de inventar números, a validação é por **boundary da transação** (seções 9 e 10, já mapeadas linha a linha) e pelo teste de concorrência da seção 14, que prova a propriedade que importa (nenhuma duplicidade, nenhuma inconsistência) independentemente do tempo absoluto. Evidência indireta relevante: hoje a seção crítica inclui `View::make()->render()` + `Dompdf` + 2 `Storage::put()`; depois, a seção crítica contém apenas 1 `SELECT ... FOR UPDATE` + no máximo 2 `Storage::put()` + 1-2 `INSERT/UPDATE` — uma redução estrutural do trabalho sob lock, não apenas hipotética.

## 17. Impacto na arquitetura futura Core + Unit DB

```text
IMPACTO NA NOVA ARQUITETURA: nenhum.
```
Toda a mudança (PERF-01 e PERF-02) opera dentro de uma única conexão/transação, sobre tabelas que já pertencem ao mesmo agregado por unidade (`queues`/`service_points`/`rooms` em PERF-01; `clinical_documents`/`document_versions`/`prescriptions`/`exam_orders`/`referrals` em PERF-02). Nenhuma dependência cross-database é criada ou pressuposta; o padrão de lock curto + revalidação otimista funciona igual num banco único por unidade.

## 18. Backlog — fora do escopo

- `IssueMedicalCertificateAction.php:32-83` — mesmo padrão de PDF-em-transação (sem lock, só duração), descoberto durante a investigação, não citado pela auditoria original. Se beneficiaria do mesmo `render()`/`persist()` split de `IssueClinicalDocumentAction` (bastaria compor da mesma forma que `GenerateSourceClinicalDocumentAction`), mas não incluído nesta fase por não fazer parte do achado confirmado.
- `SynclabExamCatalogSeeder`/matching de catálogo — mencionado apenas para registrar que **SYNLAB IMPACT: NONE** nesta fase; nada aqui foi tocado nem precisa ser.

## 19. Plano para Codex — Fase 6A

Ver seção "PROMPT PARA O CODEX — FASE 6A" abaixo. Resumo executável:

```text
OBJETIVO: eliminar o N+1 de QueueController::index() sem mudar o resultado funcional.
PROBLEMA: 2 queries por fila (service_points + rooms) em vez de eager load único.
ARQUIVOS A ALTERAR:
  - app/Modules/Queues/Application/Services/QueueVisibilityService.php (novo método)
  - app/Modules/Queues/Presentation/Http/Controllers/QueueController.php (usa o novo método)
ARQUIVOS A CRIAR: nenhum
ARQUIVOS QUE NÃO DEVEM SER ALTERADOS: views de queues, QueueEntry, QueueController::entries()
COMPORTAMENTO ATUAL: N+1, 3+2N queries
COMPORTAMENTO NOVO: mesmo resultado, queries constantes independente de N
REGRAS QUE NÃO PODEM SER QUEBRADAS: regra de visibilidade por profissional idêntica;
  isolamento por unidade/organização idêntico
TESTES: novo teste em tests/Feature/QueueFlowTest.php (seção 6 deste documento)
CRITÉRIOS DE ACEITE: seção 24 deste documento (bloco N+1)
COMANDOS DE VALIDAÇÃO: php artisan test --filter=QueueFlowTest; vendor/bin/phpstan analyse --memory-limit=1G
RISCO DE REGRESSÃO: baixo — mudança isolada a 2 arquivos, sem migration, sem mudança de schema
ROLLBACK: reverter os 2 arquivos ao estado anterior (git revert do commit da 6A)
```

## 20. Plano para Codex — Fase 6B

Ver seção "PROMPT PARA O CODEX — FASE 6B" (a ser entregue somente após validação da 6A, conforme regra do enunciado — não incluído no prompt copiável abaixo, que cobre só 6A). Resumo para referência futura:

```text
OBJETIVO: reduzir a seção crítica de lock em CreateDocumentVersionAction e
  GenerateSourceClinicalDocumentAction, sem mudar o resultado funcional nem introduzir fila.
ARQUIVOS A ALTERAR:
  - app/Modules/Documents/Application/Services/ClinicalDocumentVersionService.php (split render/persist)
  - app/Modules/Documents/Application/Actions/CreateDocumentVersionAction.php
  - app/Modules/Documents/Application/Actions/IssueClinicalDocumentAction.php (split render/persist, mantém executeStructured())
  - app/Modules/Documents/Application/Actions/GenerateSourceClinicalDocumentAction.php
ARQUIVOS A CRIAR: nenhum (sem migration — current_version_id já é nullable, pdf_path só é
  escrito quando a persistência já é certa, sem necessidade de coluna de status)
TESTES: 2 novos testes de concorrência (seção 14 deste documento) + teste de falha de renderização
  (seção 13), adicionados a tests/Feature/DocumentsAndReportsTest.php
CRITÉRIOS DE ACEITE: seção 24 deste documento (bloco PDF/lock)
RISCO DE REGRESSÃO: médio — toca 4 arquivos de um fluxo clínico crítico (documentos, prescrições,
  exames, encaminhamentos); exige rodar a suíte completa antes de aceitar
ROLLBACK: reverter os 4 arquivos ao estado anterior
```

---

# PROMPT PARA O CODEX — FASE 6A

```text
Contexto: SyncHosp (Laravel 13/PHP 8.3) tem um N+1 confirmado e medido em
app/Modules/Queues/Presentation/Http/Controllers/QueueController.php, método index()
(linhas 27-35). Para cada fila retornada, o código chama
QueueVisibilityService::servicePointsFor($queue, $user) dentro de um loop
($queues->each(...), linhas 33-35), o que executa 2 queries adicionais por fila
(service_points + rooms). Medido com 20 filas: 43 queries no total (fórmula 3 + 2N).

Implemente SOMENTE a Fase 6A:

1. Em app/Modules/Queues/Application/Services/QueueVisibilityService.php, adicione um
   novo método público `servicePointsEagerLoadConstraint(User $user): \Closure` que
   retorna EXATAMENTE a mesma lógica de filtragem hoje usada dentro de
   servicePointsFor() (linhas 41-60 do arquivo atual): filtrar
   service_points.is_active = true, eager-load de 'room', order by
   service_points.name, e — quando hasBroadAccess($user) for falso — aplicar também
   o whereHas('professionals', fn ($q) => $q->whereKey($profile->getKey())) usando
   activeProfile($user). NÃO remova nem modifique servicePointsFor() — ela continua
   em uso por ensureCanAccessEntry(), ensureCanUseServicePoint() e applyEntryScope(),
   que operam sobre uma única fila (sem N+1) e não fazem parte deste achado. Extraia a
   lógica comum para um método privado se fizer sentido, mas sem mudar o comportamento
   público existente desses três métodos.

2. Em app/Modules/Queues/Presentation/Http/Controllers/QueueController.php, método
   index() (linhas 22-49): troque o `->with('department')` (linha 28) mais o bloco
   `$queues->each(...)` (linhas 33-35) por um único `->with(['department',
   'servicePoints' => $visibility->servicePointsEagerLoadConstraint($user)])` na query
   já existente (linhas 27-32), removendo o loop `$queues->each(...)` inteiramente. O
   resultado de `$queues` deve continuar tendo `servicePoints` disponível exatamente
   como antes (mesma coleção, mesmo conteúdo, mesma ordenação) para a view
   queues.index e para a lógica de `$selected` (linhas 36-41), que não mudam.

3. Adicione um teste em tests/Feature/QueueFlowTest.php que:
   a) cria N filas (teste com pelo menos 2 valores diferentes de N, ex. 3 e 8) na
      mesma unidade, cada uma com 1-2 service_points ativos, seguindo o padrão de
      fixtures já usado no método context() deste arquivo de teste;
   b) usa DB::enableQueryLog()/DB::getQueryLog() (Illuminate\Support\Facades\DB) antes
      e depois de uma requisição autenticada a route('queues.index');
   c) assert que a contagem de queries é igual para os dois valores de N testados
      (prova de que não escala mais com N) e está abaixo de um teto fixo baixo (ex.: 8);
   d) assert que os nomes das filas e dos service points retornados na view continuam
      corretos (reaproveite as asserções de conteúdo já usadas em outros testes deste
      arquivo);
   e) repita o mesmo teste com um usuário SEM acesso amplo (vinculado a um
      professionalProfile com poucos service_points atribuídos) para confirmar que a
      filtragem por profissional continua idêntica ao comportamento anterior.

Não altere nenhuma view Blade, nenhuma rota, nenhum outro controller ou model. Não
toque em QueueEntry, QueueController::entries(), nem em nenhum arquivo do módulo
Documents/Laboratory/Medical.

Depois de implementar, rode:
  php artisan test --filter=QueueFlowTest
  php artisan test (suíte completa, para garantir zero regressão)
  vendor/bin/phpstan analyse --memory-limit=1G

Critérios de aceite:
  [ ] N+1 eliminado — contagem de queries não cresce com o número de filas
  [ ] Resultado funcional da listagem (filas, service points, isolamento por unidade,
      filtragem por profissional) idêntico ao comportamento anterior
  [ ] Suíte completa passa, phpstan sem erros
  [ ] Nenhuma alteração fora dos 2 arquivos de produção + 1 arquivo de teste listados acima

PARE depois de validar a Fase 6A. Não implemente a Fase 6B (documentos/PDF/lock) neste
mesmo prompt — isso será um handoff separado, só depois da 6A ser validada.
```
