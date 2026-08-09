# CODEX — CRUD de Grupos de Exames + Catálogo de Referência SUS

> Documento de planejamento. Nenhum código, migration ou refactor foi aplicado ao produzir este arquivo. Todas as afirmações sobre o estado atual foram verificadas diretamente no código-fonte.

## 1. Contexto — por que essas duas fases andam juntas

Duas lacunas identificadas em análises anteriores do módulo `Laboratory`:

1. **Grupos de exames sem cadastro manual.** `ExamGroup`/`ExamGroupItem` já existem no banco e no domínio, mas só nascem por importação de CSV via comando de terminal — não existe controller, rota ou view (confirmado por busca no código: zero referência a `ExamGroup` fora da camada Application/Infrastructure).
2. **Código SUS sem tabela de referência.** O SyncHosp guarda `sus_procedure_code` como string solta em `Exam`/`LaboratoryExam`, pareada manualmente por um array PHP hardcoded (`SynclabExamCatalogSeeder::procedureCodes()`, ~57 pares). O Synlab, em comparação, tem uma FK de verdade (`exames_tipos.idtabela_procedimentos_sus`) para uma tabela SIGTAP completa.

Não são a mesma funcionalidade, mas se cruzam num ponto real: a tela de cadastro de grupo de exames (Fase B) precisa de um widget de busca de exame — se a tabela de referência SUS (Fase A) já existir, esse widget pode mostrar a descrição SUS validada ao lado de cada resultado, em vez de só o código solto. Por isso o pedido de planejar as duas juntas faz sentido tecnicamente, não é só conveniência de agenda.

**Fonte de dados SIGTAP — já localizada, não precisa ser obtida externamente.** Confirmei em `C:\tortoise_dir\app\Lib\DadosPadraoTabelaProcedimentoSUS.php` (992 KB, 5.028 linhas) um array PHP com **4.996 procedimentos SIGTAP** (`codigo`, `complexidade`, `sexo`, `idade_minima`, `idade_maxima`, `descricao`), seedado de forma idempotente na tabela `tabela_procedimentos_sus` do Synlab via `C:\tortoise_dir\app\Lib\DadosPadrao.php:16-17`. Essa é a fonte a usar — **não** um arquivo a ser baixado do DATASUS. Detalhes exatos de caminho e formato de extração estão na seção 2.7. Único cuidado: o arquivo tem data de modificação de 31/07 deste ano — é um snapshot de uma edição do SIGTAP, não necessariamente a mais recente (o SIGTAP é reeditado mensalmente pelo governo); tratar como ponto de partida, não como fonte que se atualiza sozinha.

---

## 2. Fase A — Catálogo de referência de procedimentos SUS

### 2.1 Precedente a reaproveitar (não inventar nada novo)

O SyncHosp já resolve exatamente este problema para CID-10. É a receita a copiar:

| Peça | Onde já existe para CID-10 |
|---|---|
| Tabela global, sem tenant | `diagnosis_codes` (`id`, `code`, `description`, `is_active`) — migration `2026_07_24_050000_create_medical_care_tables.php:13-19` |
| Fonte de dados versionada | `database/data/cid10/*.csv` |
| Importação | `database/seeders/MedicalCatalogSeeder.php` |
| Busca/autocomplete | `app/Modules/Medical/Presentation/Http/Controllers/DiagnosisCodeSearchController.php` |
| Uso em formulário clínico | FK (`diagnoses.diagnosis_code_id`), não string solta |

### 2.2 Schema proposto

```text
sus_procedures (nova, global, sem organization_id)
  id
  code            (string, 10 dígitos, unique)
  description     (string)
  complexity      (string curto, nullable — espelha "complexidade" do Synlab: 0-3)
  sex_restriction (string curto, nullable — M/F/I/N)
  minimum_age_months (unsigned int, nullable)
  maximum_age_months (unsigned int, nullable)
  is_active       (boolean, default true, indexado)
  timestamps
```

**Decisão**: não replicar `tabela_exames_sus` do Synlab (código+descrição+**valor**) — é uma tabela orientada a preço/faturamento, e o SyncHosp não trata cobrança/convênio em nenhum lugar do sistema hoje. Replicar sem um uso real seria dado morto.

### 2.3 Decisão: manter `sus_procedure_code` como string, não virar FK obrigatória

Duas opções avaliadas:
- **String + validação contra a tabela nova (recomendado)**: `Exam.sus_procedure_code`/`LaboratoryExam.sus_procedure_code` continuam exatamente como estão — nenhuma migração de dado existente, nenhuma mudança no payload já enviado ao Synclab (que usa o código como string simples). Ganha-se autocomplete/validação na hora de cadastrar, sem risco de quebrar o que já está em produção.
- **FK de verdade** (`sus_procedure_id`): mais fiel ao modelo do Synlab, mas exige migrar dado existente e tocar em todo código que hoje lê `sus_procedure_code` como string (`MatchSynclabExamCatalogAction`, `CatalogManagementController`, `BackfillCanonicalExamCatalogAction`, o payload do Synclab). Maior risco para um ganho que a opção acima já entrega.

Este plano segue a primeira opção.

### 2.4 Seeder — cuidado com uma armadilha já vivida neste projeto

`MedicalCatalogSeeder` (CID-10) tem uma asserção rígida de contagem exata (`count($catalog) !== 1835` lança exceção). Essa é **a mesma fragilidade** que já identificamos e corrigimos no `SynclabExamCatalogSeeder` (que tinha `123` fixo e quebrava toda vez que o fornecedor mudava o catálogo). Como o SIGTAP é reeditado mensalmente pelo governo, **não repetir esse padrão rígido aqui** — validar um piso mínimo plausível (ex.: `count() < 4000` como sinal de arquivo truncado, já que a extração conhecida da seção 2.7 tem 4.996 linhas) em vez de um número exato, ou apenas logar a contagem sem falhar a seed.

### 2.5 Busca/autocomplete

`SusProcedureSearchController` — cópia direta do padrão de `DiagnosisCodeSearchController.php` (busca por prefixo do código ou trecho da descrição, prioriza match exato via `orderByRaw`).

### 2.6 Efeito colateral: aposenta o array hardcoded

Com a tabela real existindo, `SynclabExamCatalogSeeder::procedureCodes()` deixa de ser necessário — o pareamento de código SUS no catálogo Synclab passa a consultar `sus_procedures` em vez de uma lista curada à mão de ~57 entradas.

### 2.7 Fonte de dados exata — onde extrair e para onde levar

**Fonte (fora do repositório SyncHosp, leitura única, não é dependência de runtime):**
```text
C:\tortoise_dir\app\Lib\DadosPadraoTabelaProcedimentoSUS.php
```
Dentro desse arquivo, o array `$aProcedimentos` (declarado em `criarTabelaProcedimentos()`) contém 4.996 entradas no formato:
```php
["codigo" => "0101010010", "complexidade" => "N", "sexo" => "N", "idade_minima" => "9999", "idade_maxima" => "9999", "descricao" => "ATIVIDADE EDUCATIVA / ORIENTAÇÃO EM GRUPO NA ATENÇÃO PRIMÁRIA"],
```
As chaves usadas na origem mapeiam 1:1 para as colunas propostas na seção 2.2 (`codigo`→`code`, `complexidade`→`complexity`, `sexo`→`sex_restriction`, `idade_minima`→`minimum_age_months`, `idade_maxima`→`maximum_age_months`, `descricao`→`description`). Nota: `idade_minima`/`idade_maxima` = `"9999"` no arquivo de origem é um valor sentinela do Synlab para "não se aplica" — ao migrar, tratar `9999` como `null`, não como um valor literal de meses.

**Destino (dentro do repositório SyncHosp, é isso que o seeder de fato lê em runtime):**
```text
database/data/sus_procedures/procedures.csv
```
Mesma convenção já usada para CID-10 (`database/data/cid10/*.csv`): arquivo versionado e comitado no repositório, cabeçalho na primeira linha.

**Passo de extração (executar uma única vez, não faz parte do código de produção):** ler `C:\tortoise_dir\app\Lib\DadosPadraoTabelaProcedimentoSUS.php`, extrair o array `$aProcedimentos` e gravar como CSV em `database/data/sus_procedures/procedures.csv`. Pode ser um script PHP/Node avulso rodado uma vez pelo próprio Codex durante a implementação — **não deve virar um comando artisan nem uma dependência do seeder**, que só pode ler do CSV já commitado. Isso garante que o seeder funciona em qualquer máquina/ambiente de deploy, mesmo sem `C:\tortoise_dir` presente (ele só existe nesta máquina de desenvolvimento).

---

## 3. Fase B — CRUD manual de grupos de exames

### 3.1 Decisão de arquitetura: controller dedicado

`CatalogManagementController.php` (`app/Modules/Administration/Presentation/Http/Controllers/`) é o padrão existente de cadastro administrativo (`specialties`, `arrival-methods`, `entry-types`, `health-units`, `laboratory-exams`) — todos entidades de **campo único**. `ExamGroup` é grupo + lista de itens (um-para-muitos), mais parecido com o padrão de lista repetível já usado em `resources/js/exam-order-items.js`/`prescription-items.js` do que com um formulário de catálogo simples.

**Decisão**: controller novo (`ExamGroupManagementController`), reaproveitando as mesmas convenções de autorização/auditoria/tenant-scoping do `CatalogManagementController`, sem forçar a lógica de itens dentro do `match($catalog)` genérico existente.

### 3.2 Peça adicional: busca de exame canônico, não `LaboratoryExam`

`ExamGroupItem.exam_id` referencia `Exam` (canônico, por organização), não `LaboratoryExam` (por integração/unidade). O widget de busca já existente (`LaboratoryExamSearchController`) busca no modelo errado para este caso. Precisa de um endpoint novo (`search-exams`) buscando em `Exam::query()->where('organization_id', ...)->where('is_active', true)`.

**Ponto de integração com a Fase A**: se `sus_procedures` já existir, esse endpoint de busca pode fazer um `leftJoin`/lookup leve por `sus_procedure_code` e devolver a descrição SUS validada junto do resultado (puramente cosmético, sem alterar a regra de negócio do grupo). Se a Fase A ainda não tiver sido implementada, o endpoint simplesmente não mostra esse campo extra — nenhuma dependência bloqueante entre as duas fases.

### 3.3 Rotas

```text
GET    administration/exam-groups                  → index (lista + formulário)
POST   administration/exam-groups                  → store
PUT    administration/exam-groups/{examGroup}       → update
GET    administration/exam-groups/search-exams      → busca de Exam canônico (JSON)
```

### 3.4 Controller / Form Request / Action

- `ExamGroupManagementController::index()`: resolve `$unit` do request (mesmo padrão de `CatalogManagementController::unit()`), lista `ExamGroup::where('organization_id', $unit->organization_id)->with('items.exam')->orderBy('name')->paginate(...)`.
- `SaveExamGroupRequest`: `name` obrigatório; `items` array `min:1`, cada item com `exam_id` existente da mesma organização; `is_active` boolean.
- Autorização: mesma regra já usada em `ResolveExamGroupImportConflictAction::authorize()` — `isPlatformAdministrator()` OU (mesma organização E `can('administration.manage')`).
- `SaveExamGroupAction` (nova), em `DB::transaction()`:
  1. calcula `normalized_name` via `ExamNameNormalizer::normalize()` (já existe, mesmo serviço usado por `ImportExamGroupsAction`) e cria/atualiza o `ExamGroup`;
  2. captura violação da constraint única `exam_group_organization_name_unique` e devolve erro de validação amigável (não deixar estourar `QueryException` — mesma lição já registrada sobre `patient_identifiers` na auditoria geral do projeto);
  3. sincroniza `ExamGroupItem` reaproveitando a lógica de `ResolveExamGroupImportConflictAction::replaceItems()` (extrair para um lugar comum em vez de duplicar);
  4. audita via `RecordLaboratoryCatalogAuditAction` (já usada por `ImportExamGroupsAction`/`ResolveExamGroupImportConflictAction`), evento `laboratory.exam_group_saved`.

### 3.5 View

Página dedicada `resources/views/administration/exam-groups/index.blade.php` (não uma aba dentro de `administration.catalogs.index`, que é pensada para campo único). Componente Alpine novo `resources/js/exam-group-items.js`, copiando a estrutura de `exam-order-items.js`.

---

## 4. Ordem recomendada

```text
FASE A — Catálogo de referência SUS
  Pré-requisito: nenhum bloqueio externo — fonte já localizada em
  C:\tortoise_dir\app\Lib\DadosPadraoTabelaProcedimentoSUS.php (ver seção 2.7)
  1. Extrair o array de origem para database/data/sus_procedures/procedures.csv (passo único)
  2. Migration sus_procedures
  3. Seeder de importação lendo o CSV commitado (sem asserção rígida de contagem)
  4. SusProcedureSearchController
  5. (opcional, baixo risco) autocomplete no formulário de laboratory-exams do CatalogManagementController

↓ validar (testes + seed local) ↓

FASE B — CRUD de grupos de exames
  1. ExamGroupManagementController + SaveExamGroupRequest + SaveExamGroupAction
  2. Rotas administration/exam-groups
  3. View + componente Alpine exam-group-items.js
  4. (opcional, só se Fase A já estiver pronta) exibir descrição SUS validada no picker de exame
```

As duas fases são **independentes e podem ser implementadas em qualquer ordem** — a única dependência é a integração cosmética opcional do fim da Fase B, que degrada graciosamente se a Fase A ainda não existir.

---

## 5. Arquivos previstos

**Fase A — criar:**
- `database/migrations/XXXX_create_sus_procedures_table.php`
- `app/Modules/Medical/Infrastructure/Eloquent/SusProcedure.php` (ou em `Laboratory`, a definir — é catálogo médico geral, mas hoje só é consumido por exame laboratorial; sugiro `Medical` para ficar ao lado de `DiagnosisCode`, mesmo domínio de "catálogos de referência clínica")
- `database/data/sus_procedures/procedures.csv` (extraído de `C:\tortoise_dir\app\Lib\DadosPadraoTabelaProcedimentoSUS.php`, ver seção 2.7 — 4.996 linhas esperadas)
- Seeder de importação (`SusProcedureCatalogSeeder` novo, ou extensão de `MedicalCatalogSeeder` — a decidir no momento da implementação; dado o volume similar ao de CID-10, o mesmo padrão de leitura em chunks já usado lá se aplica direto)
- `app/Modules/Medical/Presentation/Http/Controllers/SusProcedureSearchController.php`
- `tests/Feature/SusProcedureCatalogTest.php`

**Fase A — alterar:**
- `routes/web.php` (rota de busca)
- `database/seeders/DatabaseSeeder.php` (encadear o novo seeder)
- (opcional) `SynclabExamCatalogSeeder.php`/`MatchSynclabExamCatalogAction.php` — trocar `procedureCodes()` hardcoded por consulta à tabela real

**Fase B — criar:**
- `app/Modules/Laboratory/Presentation/Http/Controllers/ExamGroupManagementController.php`
- `app/Modules/Laboratory/Presentation/Http/Requests/SaveExamGroupRequest.php`
- `app/Modules/Laboratory/Application/Actions/SaveExamGroupAction.php`
- `resources/views/administration/exam-groups/index.blade.php`
- `resources/js/exam-group-items.js`
- `tests/Feature/ExamGroupManagementTest.php`

**Fase B — alterar:**
- `routes/web.php` (grupo de rotas `administration/exam-groups`)
- ponto de registro dos componentes Alpine (confirmar onde `exam-order-items.js` é registrado antes de implementar)

**Reaproveitar sem alterar (ambas as fases):**
- `ExamNameNormalizer`, `RecordLaboratoryCatalogAuditAction`, padrão de `replaceItems()` de `ResolveExamGroupImportConflictAction`, padrão de `DiagnosisCodeSearchController`, `ExamGroupItem::booted()` (validação de mesma organização já existe)

---

## 6. Testes

**Fase A** (`tests/Feature/SusProcedureCatalogTest.php`):
1. Seeder importa o arquivo fonte sem exigir contagem exata (só um piso mínimo).
2. Busca por código exato e por trecho de descrição retorna resultados corretos, ordenados com match exato primeiro.
3. Registro inativo (`is_active=false`) não aparece na busca.

**Fase B** (`tests/Feature/ExamGroupManagementTest.php`):
1. Admin cria grupo com 2+ exames da própria organização — sucesso, itens persistidos na ordem enviada.
2. Exame de outra organização — rejeitado.
3. Nome duplicado (mesmo normalizado) na mesma organização — erro de validação amigável, não 500.
4. Usuário sem `administration.manage` — 403.
5. Usuário de outra organização editando grupo alheio — 404/403.
6. Editar grupo existente: adicionar um item, remover outro — reflete corretamente.
7. Grupo com 0 itens — erro de validação (`items` `min:1`).

---

## 7. Riscos e invariantes a preservar

- **Não repetir a asserção de contagem rígida** (lição já paga duas vezes neste projeto: CID-10 e catálogo Synclab original).
- **Isolamento por organização** em `ExamGroup`/`ExamGroupItem` já é garantido por schema (`exam_group_organization_name_unique`) e pelo hook `ExamGroupItem::booted()` — os testes da Fase B devem confirmar, não reimplementar essa checagem em outro lugar.
- **`sus_procedures` é catálogo global, sem tenant** — não faz sentido escopar por organização (é uma tabela nacional, igual `diagnosis_codes`).
- **Não alterar o payload já enviado ao Synclab** — `sus_procedure_code` continua string; a Fase A não introduz nenhuma mudança de contrato externo.

---

## 8. Backlog — fora do escopo deste plano

- Uso do grupo na tela de solicitação de exame (expansão em `ExamOrderItem`) — depende de decisão de produto sobre unidades que não habilitaram todos os itens do grupo (`HealthUnitExam`).
- Tela de revisão de conflito de importação de grupo (`ResolveExamGroupImportConflictAction` continua só via comando artisan).
- Migrar `sus_procedure_code` para FK de verdade (avaliado e descartado por ora — ver seção 2.3).
- Replicar `tabela_exames_sus` (preço/faturamento) — sem uso real no SyncHosp hoje.

---

# PROMPT PARA O CODEX — FASE A

```text
Contexto: SyncHosp (Laravel 13/PHP 8.3) guarda sus_procedure_code como string solta em
Exam e LaboratoryExam (app/Modules/Laboratory/Infrastructure/Eloquent/), sem tabela de
referência por trás. Existe um precedente direto no próprio projeto para catálogos de
referência clínica globais: diagnosis_codes (CID-10), com:
  - migration em database/migrations/2026_07_24_050000_create_medical_care_tables.php:13-19
  - seeder database/seeders/MedicalCatalogSeeder.php (lê CSVs de database/data/cid10/*.csv)
  - busca em app/Modules/Medical/Presentation/Http/Controllers/DiagnosisCodeSearchController.php

Implemente SOMENTE a Fase A:

0. FONTE DE DADOS — leia isto antes de tudo, para não errar de onde vem o dado:
   A fonte real do catálogo SIGTAP é o arquivo local (fora deste repositório):
   C:\tortoise_dir\app\Lib\DadosPadraoTabelaProcedimentoSUS.php
   Dentro dele, o array $aProcedimentos em criarTabelaProcedimentos() tem 4.996 entradas
   no formato ["codigo" => "...", "complexidade" => "...", "sexo" => "...",
   "idade_minima" => "...", "idade_maxima" => "...", "descricao" => "..."].
   NÃO baixe nada do DATASUS, NÃO invente dados, NÃO gere uma amostra menor — extraia
   esse array real. Escreva um script avulso de extração (PHP ou Node, rodado uma única
   vez, não commitado como comando artisan nem dependência de runtime) que leia esse
   arquivo e grave o resultado em database/data/sus_procedures/procedures.csv (dentro
   DESTE repositório, syncsus), com cabeçalho `code,complexity,sex_restriction,
   minimum_age_months,maximum_age_months,description` — mesma convenção de
   database/data/cid10/*.csv (CSV entre aspas). Trate o valor sentinela "9999" em
   idade_minima/idade_maxima como NULL, não como 9999 meses literais. O seeder da Fase A
   (item 3 abaixo) só pode ler desse CSV já commitado — nunca deve depender de
   C:\tortoise_dir existir em outra máquina/ambiente de deploy. Se por algum motivo
   C:\tortoise_dir não estiver acessível no ambiente onde você for implementar, PARE e
   avise em vez de inventar ou baixar dados de outra fonte.

1. Migration criando sus_procedures (global, SEM organization_id): id, code (string 10,
   unique), description, complexity (string curta, nullable), sex_restriction (string
   curta, nullable), minimum_age_months (unsigned int, nullable), maximum_age_months
   (unsigned int, nullable), is_active (boolean, default true, indexado), timestamps.

2. Model App\Modules\Medical\Infrastructure\Eloquent\SusProcedure (mesmo padrão simples
   de DiagnosisCode.php — $guarded=[], cast de is_active).

3. Seeder de importação lendo database/data/sus_procedures/procedures.csv (o arquivo já
   commitado pelo passo 0, não o arquivo de origem em C:\tortoise_dir). IMPORTANTE: não
   use uma asserção de contagem exata como database/seeders/MedicalCatalogSeeder.php:46-48
   faz — isso já quebrou duas vezes neste projeto quando a fonte de dados mudou de tamanho
   (ver histórico de correção em SynclabExamCatalogSeeder). Valide um piso mínimo plausível
   (ex.: rejeitar se count() < 4000, já que a extração conhecida tem 4.996 linhas), não um
   número exato.

4. App\Modules\Medical\Presentation\Http\Controllers\SusProcedureSearchController, cópia
   do padrão de DiagnosisCodeSearchController.php (busca por prefixo de código ou trecho
   de descrição, prioriza match exato, filtra is_active=true, limit 20).

5. Rota de busca em routes/web.php, mesmo grupo/middleware das outras rotas de busca
   autenticadas do sistema (ex.: medical.laboratory-exams.search).

6. Encadear o novo seeder em database/seeders/DatabaseSeeder.php.

7. Testes em tests/Feature/SusProcedureCatalogTest.php cobrindo: seeder importa sem exigir
   contagem exata; busca por código exato prioriza sobre busca por descrição; registro
   inativo não aparece na busca.

NÃO altere Exam.sus_procedure_code nem LaboratoryExam.sus_procedure_code para virar FK —
continuam string, por decisão já registrada (ver docs/CODEX_EXAM_GROUPS_AND_SUS_CATALOG_PLAN.md
seção 2.3). NÃO altere o payload enviado ao Synclab. NÃO toque em ExamGroup/ExamGroupItem
(isso é a Fase B, um prompt separado).

Depois de implementar, rode:
  php artisan test --filter=SusProcedureCatalogTest
  php artisan test (suíte completa)
  vendor/bin/phpstan analyse --memory-limit=1G

PARE depois de validar a Fase A. Não implemente a Fase B no mesmo prompt.
```

---

# PROMPT PARA O CODEX — FASE B

```text
Contexto: SyncHosp tem ExamGroup/ExamGroupItem já implementados no banco e no domínio
(app/Modules/Laboratory/Infrastructure/Eloquent/ExamGroup.php, ExamGroupItem.php,
migration 2026_08_08_000200_create_exam_catalog_matching_and_groups.php), mas só podem
ser criados por importação de CSV via comando artisan (ImportExamGroupsAction,
laboratory:import-exam-groups). Não existe controller, rota ou view para cadastro manual
— confirme isso antes de começar (grep por ExamGroup fora de Application/Infrastructure
deve retornar vazio).

Implemente SOMENTE a Fase B:

1. App\Modules\Laboratory\Presentation\Http\Requests\SaveExamGroupRequest: valida name
   (obrigatório, string), items (array, min:1, cada item com exam_id existente e
   pertencente à mesma organização do usuário autenticado), is_active (boolean).

2. App\Modules\Laboratory\Application\Actions\SaveExamGroupAction: dentro de
   DB::transaction(), calcula normalized_name via App\Modules\Laboratory\Application\
   Services\ExamNameNormalizer::normalize() (já existe, mesmo serviço usado por
   ImportExamGroupsAction.php), cria/atualiza o ExamGroup, sincroniza ExamGroupItem
   (reaproveite a lógica de ResolveExamGroupImportConflictAction::replaceItems(),
   app/Modules/Laboratory/Application/Actions/ResolveExamGroupImportConflictAction.php
   linhas 123-137 — extraia para um método/trait compartilhado em vez de duplicar), e
   audita via RecordLaboratoryCatalogAuditAction (mesma Action já usada por
   ImportExamGroupsAction/ResolveExamGroupImportConflictAction), evento
   'laboratory.exam_group_saved'. Capture violação da constraint única
   exam_group_organization_name_unique e devolva ValidationException amigável em vez de
   deixar estourar QueryException.

3. App\Modules\Laboratory\Presentation\Http\Controllers\ExamGroupManagementController:
   - index(): resolve $unit via $request->attributes->get('active_health_unit') (mesmo
     padrão de CatalogManagementController::unit()), lista
     ExamGroup::where('organization_id', $unit->organization_id)->with('items.exam')
     ->orderBy('name')->paginate(...).
   - store()/update(): autoriza com a mesma regra de
     ResolveExamGroupImportConflictAction::authorize() (isPlatformAdministrator() OU
     (mesma organização E can('administration.manage'))), chama SaveExamGroupAction.
   - searchExams(): endpoint de busca em Exam::query()->where('organization_id',
     $unit->organization_id)->where('is_active', true)->where(...busca por nome...),
     mesmo formato de resposta JSON (id/label) usado por
     app/Modules/Medical/Presentation/Http/Controllers/LaboratoryExamSearchController.php
     -- NÃO reaproveite esse controller diretamente, ele busca em LaboratoryExam
     (escopado por unidade/integração), o modelo errado aqui; Exam é escopado por
     organização.

4. Rotas em routes/web.php, prefixo administration/exam-groups:
   GET  /administration/exam-groups                -> index
   POST /administration/exam-groups                -> store
   PUT  /administration/exam-groups/{examGroup}     -> update
   GET  /administration/exam-groups/search-exams    -> searchExams

5. View resources/views/administration/exam-groups/index.blade.php: lista de grupos
   existentes + formulário de criação/edição com busca-e-adiciona-linha de exame.

6. Componente Alpine resources/js/exam-group-items.js, copiando a estrutura de
   resources/js/exam-order-items.js (limite de itens, adicionar/remover linha, _key
   para x-for), apontando para o endpoint search-exams novo. Registre no mesmo ponto
   onde exam-order-items.js/prescription-items.js já são registrados.

7. Testes em tests/Feature/ExamGroupManagementTest.php cobrindo: criação com sucesso;
   exame de outra organização rejeitado; nome duplicado normalizado gera erro de
   validação (não 500); usuário sem permissão recebe 403; usuário de outra organização
   não acessa grupo alheio; edição adiciona/remove item corretamente; grupo com 0 itens
   é rejeitado.

NÃO toque na tela de solicitação de exame (uso do grupo no pedido médico/recepção é
fora de escopo, ver docs/CODEX_EXAM_GROUPS_AND_SUS_CATALOG_PLAN.md seção 8). NÃO crie
tela de revisão de conflito de importação. Se a Fase A (sus_procedures) já estiver
implementada quando você fizer isto, pode enriquecer searchExams() com a descrição SUS
validada via lookup por sus_procedure_code -- se não estiver, não bloqueie por isso,
simplesmente não inclua esse campo extra.

Depois de implementar, rode:
  php artisan test --filter=ExamGroupManagementTest
  php artisan test (suíte completa)
  vendor/bin/phpstan analyse --memory-limit=1G

PARE depois de validar a Fase B.
```
