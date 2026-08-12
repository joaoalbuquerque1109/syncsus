# Correção — transações cross-conexão no catálogo de laboratório

> Documento de planejamento. Nenhum código foi alterado ao produzi-lo. Escrito depois de
> validar regressão contra um banco SQLite de arquivo único (a topologia real de hoje,
> Core e Tenant no mesmo arquivo físico) — a suíte de testes usa duas conexões `:memory:`
> fisicamente separadas e por isso nunca detectou este problema.

## Achado

`php artisan db:seed` falha de forma determinística com `SQLSTATE[HY000]: General error: 5
database is locked` ao rodar `CanonicalExamCatalogBackfillSeeder`. Causa: quatro Actions do
módulo Laboratory abrem `DB::transaction(function () {...})` **sem especificar conexão**
enquanto, dentro do mesmo closure, criam um `Exam` ou `ExamGroup` — ambos `CoreModel` desde
a decisão 22.2 do plano mestre — junto com leitura/escrita de models Tenant
(`LaboratoryExam`, `ExamMapping`, `HealthUnitExam`, `ExamCatalogImportCandidate`,
`ExamGroupImportConflict`, itens de grupo). `DB::transaction()` sem conexão abre a
transação na conexão *default*; os models Core resolvem para a conexão nomeada `'core'` —
um PDO diferente, mesmo quando os dois apontam para o mesmo arquivo físico (hoje, sempre).

Em SQLite, duas conexões diferentes não conseguem manter escrita aberta simultaneamente no
mesmo arquivo — a transação externa (Tenant) já está seguindo lock de escrita quando o
`Exam::create()`/`ExamGroup::create()` (Core) tenta escrever, e trava. Em MySQL produção
isso não trava (InnoDB tem lock por linha/conexão), mas o mesmo defeito estrutural persiste
de forma silenciosa: a "transação" nunca cobriu as escritas Core, então uma falha no meio
do fluxo pode deixar dado parcialmente criado sem rollback — mesma classe de bug já
corrigida em `SavePatientAction` (Fase 2) e `ProvisionTenantAction` (Fase 7).

## Arquivos afetados

1. `app/Modules/Laboratory/Application/Actions/BackfillCanonicalExamCatalogAction.php`
2. `app/Modules/Laboratory/Application/Actions/ResolveExamCatalogCandidateAction.php`
3. `app/Modules/Laboratory/Application/Actions/ResolveExamGroupImportConflictAction.php`
4. `app/Modules/Laboratory/Application/Actions/ImportExamGroupsAction.php`

Os três últimos são acionados por telas reais de administração (resolução de matching de
catálogo Synclab, importação de grupos de exames por CSV) — não é só o seed de dev que
quebra.

## Padrão de correção

**Regra geral: a escrita Core (`Exam::create()`/`ExamGroup::create()`) nunca pode acontecer
enquanto uma transação Tenant estiver aberta na mesma chamada.** Isso vale mesmo sem
`DB::transaction()` explícito em volta dela — o problema não é a ausência de um wrapper na
escrita Core, é a transação Tenant já aberta segurando o arquivo. A escrita Core precisa
acontecer estritamente **antes** de abrir a transação Tenant, ou estritamente **depois**
dela commitar.

### 1. `BackfillCanonicalExamCatalogAction` e `ImportExamGroupsAction` (processamento em lote)

Remover o `DB::transaction(...)` externo por completo. As escritas já são naturalmente
idempotentes (`ExamMapping::where(...)->first()` antes de criar, `firstOrCreate` para
disponibilidade/conflito, verificação de grupo existente antes de criar) — cada iteração
do laço já é segura para reexecução independente. A "atomicidade tudo-ou-nada" que o
`DB::transaction()` externo prometia nunca existiu de fato (já não cobria as escritas
Core), então removê-lo não tira nenhuma garantia real — só formaliza o que já era
verdade: cada linha processada é durável e retomável por conta própria, não há reversão
conjunta do lote inteiro.

### 2. `ResolveExamCatalogCandidateAction` (ação única, precisa de lock)

Reordenar: decidir **antes** de abrir a transação/lock se a decisão `'create'` vai exigir
um `Exam` novo (isso só depende dos parâmetros de entrada e de uma leitura não travada do
candidato — não depende do lock). Se sim, criar o `Exam` (Core) primeiro, fora de qualquer
transação Tenant. Só então abrir `DB::connection($tenantConnection)->transaction(...)`,
travar o candidato (`lockForUpdate()`), **revalidar** que a decisão ainda é legal contra o
estado travado mais recente (o mesmo `match_status`/`resolution` que hoje já é checado
depois do lock), e prosseguir com `ExamMapping`/`HealthUnitExam`/`$candidate->save()`/
auditoria — tudo Tenant, numa única transação de verdade agora.

**Trade-off explícito, registrado em vez de escondido**: mover a criação do `Exam` para
antes do lock abre uma janela estreita em que, sob concorrência (dois admins resolvendo o
mesmo candidato ao mesmo tempo), o primeiro cria o `Exam` e o segundo, ao revalidar depois
do lock, descobre que o candidato já foi resolvido — o `Exam` do segundo fica órfão, sem
mapping. Isso não é um problema de segurança nem de integridade (não há dado incorreto
visível a ninguém), só um registro de catálogo não utilizado. Aceitável porque: (a) o
código atual já não tinha proteção real contra duplicidade de `Exam` nessa mesma
situação (não existe unicidade em `Exam` hoje), e (b) catálogo de exame é dado de baixo
volume e curadoria administrativa, não fluxo de alta concorrência.

### 3. `ResolveExamGroupImportConflictAction` (mesmo padrão do item 2)

`ExamGroup::create()` só acontece quando `$conflict->resolveGroup()` (leitura, não
travada) retorna `null`. Mover essa criação para antes de abrir a transação Tenant, pelo
mesmo motivo e com o mesmo trade-off do item 2 (grupo órfão em caso raro de corrida —
aceitável pelas mesmas razões).

**Complemento encontrado pelo teste de arquivo único durante a implementação:**
`SyncExamGroupItemsAction` grava `ExamGroupItem`, que atualmente também é `CoreModel`.
Mesmo movendo apenas `ExamGroup::create()`, sincronizar os itens depois de abrir a
transação Tenant torna o snapshot SQLite obsoleto e a atualização final do conflito ainda
falha com `database is locked`. A chamada de sincronização dos itens deve, portanto,
acontecer junto da preparação Core, antes da transação Tenant. A action
`SyncExamGroupItemsAction` em si permanece inalterada.

## Lacuna de cobertura de teste (achado à parte, também a corrigir)

A suíte atual não conseguiria detectar este problema nem depois de corrigido, porque
`RefreshCoreAndTenantDatabase` sempre usa duas conexões `:memory:` fisicamente distintas —
nunca disputam lock no mesmo arquivo. Novo teste dedicado, fora desse trait, usando SQLite
de **arquivo único temporário** para as conexões `core` e `tenant_test`/default
simultaneamente (reproduzindo a topologia real de produção/dev de hoje) — prova que:

1. Antes da correção, `BackfillCanonicalExamCatalogAction` (ou uma das outras três)
   lança `QueryException` de lock ao processar um registro que precisa criar `Exam`.
2. Depois da correção, o mesmo cenário completa sem erro, e o resultado (contagens,
   registros criados) é idêntico ao que já era validado pelos testes existentes.

Esse teste deve ficar isolado (não usar o trait padrão) justamente para não esconder de
novo o mesmo tipo de regressão atrás de duas conexões `:memory:` que nunca colidem.

## Fora de escopo

- Qualquer mudança em `SyncExamGroupItemsAction`, `RecordLaboratoryCatalogAuditAction`,
  `CatalogReader` ou nos modelos `Exam`/`ExamGroup`/`ExamMapping` em si — só a estrutura de
  transação das quatro Actions listadas.
- `CreateProvisionalPatientAction.php` — tem o mesmo `DB::transaction()` sem conexão, mas
  só contém uma escrita Core dentro (sem escrita Tenant simultânea), então não trava nem
  perde atomicidade de fato; é só um wrapper que não protege nada. Vale simplificar depois,
  não é uma regressão funcional agora.
- Resolver se `Exam`/`ExamGroup` deveriam ganhar alguma constraint de unicidade para
  fechar de vez a janela de corrida descrita nos itens 2 e 3 — registrado como possível
  melhoria futura, não bloqueia esta correção.

## Critérios de aceite

- `php artisan db:seed` completa sem erro contra um banco SQLite de arquivo único (não só
  contra `:memory:`).
- `php artisan laboratory:backfill-exam-catalog` idem.
- Novo teste de arquivo único prova que o cenário de lock não ocorre mais nos quatro
  fluxos, e que o comportamento funcional (contagens, registros criados) não mudou.
- Suíte completa existente (`php artisan test`) continua 100% verde — nenhuma regressão de
  comportamento, só de mecanismo interno.
- `phpstan analyse` e `pint --test` sem novos problemas.

## Prompt para o Codex

```
Contexto: BackfillCanonicalExamCatalogAction, ResolveExamCatalogCandidateAction,
ResolveExamGroupImportConflictAction e ImportExamGroupsAction abrem DB::transaction() sem
conexão explícita enquanto criam Exam/ExamGroup (CoreModel) junto com escrita/leitura de
models Tenant do catálogo de laboratório. Isso trava com "database is locked" em SQLite de
arquivo único (reproduzido rodando php artisan db:seed) e, em MySQL, deixa de ser atômico
silenciosamente — mesma classe de bug já corrigida em SavePatientAction e
ProvisionTenantAction nesta base de código.

Leia docs/CODEX_LABORATORY_CATALOG_TRANSACTION_FIXES.md por completo antes de começar.
Implemente exatamente o "Padrão de correção" descrito:
1. BackfillCanonicalExamCatalogAction e ImportExamGroupsAction: remover o
   DB::transaction() externo, confiando na idempotência já existente de cada escrita.
2. ResolveExamCatalogCandidateAction e ResolveExamGroupImportConflictAction: mover a
   criação do Exam/ExamGroup para antes de abrir a transação/lock Tenant, revalidando a
   decisão contra o registro travado antes de prosseguir.
3. Novo teste dedicado usando SQLite de arquivo único (não o trait
   RefreshCoreAndTenantDatabase padrão) provando que os quatro fluxos não travam mais e
   que o comportamento funcional não mudou.

Não toque em CreateProvisionalPatientAction.php nem em nenhum outro arquivo fora da lista
— fora de escopo desta correção. Rode a suíte completa, phpstan e pint antes de considerar
concluído, além de confirmar manualmente que `php artisan db:seed` completa contra um
banco de arquivo único.
```
