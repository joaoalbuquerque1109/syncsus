# Fase 7 — Provisionamento nativo de unidade nova

> Documento de planejamento. Nenhum código foi alterado ao produzi-lo nem na revisão.
> **Não deve ser implementado nesta rodada nem antes da Fase 3/4 estarem validadas em
> produção com dado real, nem antes da Fase 6 estar concluída** (dependência técnica, não
> só cronológica — ver seção "Pré-requisitos"). Revisado em 2026-08-10 depois de uma
> auditoria externa que encontrou 8 lacunas na primeira versão; todas tratadas abaixo, com
> a fonte de cada correção citada. Nenhuma delas é cosmética — cobrem privilégio de banco,
> uma janela real de uso indevido, e uma transação que hoje não faz o que o texto anterior
> dizia que fazia.

## Objetivo

Quando um administrador de plataforma cria uma organização/unidade nova
(`ProvisionTenantAction`, hoje o único ponto do código que insere `HealthUnit` — confirmado
por busca no código, e há teste garantindo que uma organização não ganha uma segunda
unidade por esse fluxo), o sistema cria automaticamente o banco físico dedicado dela, roda
as migrations e leva a unidade ao estado `TENANT` — sem passo manual de DBA.

## Pré-requisitos (bloqueantes, não apenas recomendados)

1. **Fase 6 concluída** — `database/migrations/tenant/` precisa existir e o provisionador
   precisar rodar só esse subconjunto. Detalhe na seção "Por que a Fase 6 é obrigatória".
2. **Fase 3/4 validadas em produção** com pelo menos uma unidade real, sem intervenção
   manual fora do runbook documentado.
3. **`ProvisionTenantAction` corrigida** antes desta fase ser implementada — a ação, hoje,
   tem um problema de atomicidade que a Fase 7 tornaria mais visível, mas que já existe
   independentemente dela. Detalhe na seção "Correção prévia necessária em
   `ProvisionTenantAction`".

## Correção prévia necessária em `ProvisionTenantAction` (achado da auditoria, não é escopo desta fase)

`ProvisionTenantAction::execute()` chama `DB::transaction(function () { ... })` **sem
especificar conexão** — isso abre transação na conexão *default* do Laravel
(`config('database.default')`). Só que dentro do closure ele escreve `Organization`,
`HealthUnit` e `User` — todos `CoreModel`/`UsesCoreConnection`, ou seja, resolvem para a
conexão nomeada `'core'`, que é uma conexão (um PDO) **diferente** da default, mesmo que
hoje, em produção, ambas apontem para o mesmo banco físico. `BEGIN`/`COMMIT`/`ROLLBACK`
disparados num PDO não afetam o outro. Na prática, os `Model::create()` dentro desse
closure fazem autocommit individual — se `$this->catalogs->bootstrap()` (que grava em
`Specialty`, Core, **e** `ArrivalMethod`, Tenant — confirmado lendo
`OrganizationCatalogBootstrapper::bootstrap()`) falhar depois que `Organization` e
`HealthUnit` já foram criados, não há rollback nenhum: fica uma organização/unidade
parcialmente criada, sem o catch/rethrow do `DB::transaction()` desfazer nada.

Isso **já existe hoje**, independente da Fase 7 — é a mesma classe de problema que motivou
a correção de `SavePatientAction` na Fase 2 (duas conexões, uma "transação" que só cobre
uma delas). A Fase 7 só torna o problema mais grave, porque adiciona mais um passo
(disparar o job de provisionamento) em cima de uma base que já não é atômica.

**Correção recomendada, a ser detalhada e implementada antes desta fase**: trocar
`DB::transaction(...)` por `DB::connection('core')->transaction(...)` explícito (cobre
`Organization`/`HealthUnit`/`User`/a metade Core de `bootstrap()`), e tratar a metade
Tenant de `bootstrap()` (hoje `ArrivalMethod`, potencialmente mais no futuro) com o mesmo
padrão de duas transações + idempotência que `SavePatientAction` já usa — não com uma
transação única, porque isso continua sendo impossível entre conexões diferentes. Isto é
uma correção pequena e autocontida; deve virar um `docs/CODEX_..._FIXES.md` próprio antes
da Fase 7, não ser resolvida por dentro deste documento.

## Por que a Fase 6 é obrigatória, não só recomendada

`TenantDatabaseProvisioner::provision()`, hoje, roda:

```php
Artisan::call('migrate', ['--database' => $connectionName, '--force' => true, '--no-interaction' => true]);
```

Sem `--path`. Isso executa **todas** as migrations do projeto — inclusive as que criam
`patients`, `users`, `organizations` etc. — contra o banco dedicado. Na Fase 3 (piloto
único, manual, supervisionado) isso é aceitável: uma unidade, um schema levemente inchado,
fácil de inspecionar manualmente. Na Fase 7 (automática, repetida a cada unidade nova, sem
supervisão), cada banco novo herdaria uma cópia vazia crescente do schema inteiro do Core
— confuso, e sem necessidade. A Fase 7 não deve começar antes de `database/migrations/`
estar separado em `core/`/`tenant/` (Fase 6) e o provisionador rodar só
`--path=database/migrations/tenant`.

## Por que "só admin cria unidade" não resolve sozinho o privilégio de banco

Autorização de aplicação (`ProvisionTenantRequest::authorize()`, hoje já exige
`isPlatformAdministrator()` — confirmado no código) restringe **quem aciona a rota**. Não
restringe **o que a credencial de banco pode fazer**: se a conexão MySQL usada para
`CREATE DATABASE` for a mesma que a aplicação usa para todo o resto, qualquer requisição —
inclusive as que não têm nada a ver com criar unidade — passa a rodar numa conexão com
esse privilégio. Uma injeção de SQL numa tela qualquer, uma dependência vulnerável, um
`.env` vazado: nenhum desses caminhos passa pela autorização de admin, mas herdaria o
privilégio de banco se a credencial for compartilhada. A solução é uma credencial
dedicada, com o menor privilégio que o MySQL consegue expressar — não abrir mão da
automação.

## Mecanismo

### Três credenciais, não uma — corrigido depois da auditoria externa

A versão anterior usava uma única credencial com `CREATE, ALTER, DROP` para tudo. Isso é
mais privilégio do que a etapa descrita precisa (`ALTER`/`DROP` não são usados em nenhum
passo aqui) e mistura três responsabilidades que deveriam ter credenciais separadas:

1. **`tenant_provisioning`** — só `CREATE`, usada uma única vez por unidade, só para
   `CREATE DATABASE IF NOT EXISTS`.
2. **Credencial de runtime, compartilhada e estática** (não uma por unidade — ver por quê
   na seção seguinte) — `SELECT/INSERT/UPDATE/DELETE`, é a que `tenant_template` já usa
   hoje.
3. A etapa de `migrate` (DDL — `CREATE TABLE`, `ALTER TABLE`) roda com a **mesma**
   credencial de provisionamento, já que ela precisa de DDL de qualquer forma para
   `CREATE DATABASE`; não vale a pena uma quarta credencial só para isso.

No MySQL (ação de infraestrutura, pré-requisito documentado aqui, fora do código Laravel —
mesmo espírito do que a Fase 3 já assume para o perfil do piloto), configurada **uma vez**,
não por unidade:

```sql
CREATE USER 'tenant_provisioner'@'%' IDENTIFIED BY '...';
GRANT CREATE ON `sync\_hosp\_u%`.* TO 'tenant_provisioner'@'%';

CREATE USER 'tenant_runtime'@'%' IDENTIFIED BY '...';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON `sync\_hosp\_u%`.* TO 'tenant_runtime'@'%';
```

### Correção do achado crítico: escaping do curinga

A versão anterior usava `sync_hosp_u%` sem escapar o `_`. No padrão de nome de banco do
`GRANT`, `_` e `%` são curingas de `LIKE` — um `_` não escapado casa com **qualquer
caractere único**, não só um `_` literal, então `sync_hosp_u%` na prática casa com
`syncXhospXu...` para qualquer par de caracteres X, não só com nomes que começam
literalmente com `sync_hosp_u`. O padrão correto escapa o `_`: `` `sync\_hosp\_u%` `` —
só o `%` final continua sendo curinga de verdade.

**Ressalva que fica registrada, não resolvida aqui**: em MySQL 8.x, com a variável de
sistema `partial_revokes` habilitada, o modelo de privilégio recomendado passa a ser
privilégio global + revogação pontual por schema, não `GRANT` com curinga de nome de
banco — a documentação trata o padrão de curinga em nível de banco como legado. Antes de
configurar isso de verdade, confirmar a versão exata do MySQL usada na imagem Docker do
Railway e se `partial_revokes` vem habilitado por padrão nela; se vier, o modelo acima
precisa ser adaptado (provavelmente para `GRANT CREATE ON *.*` restrito por outro
mecanismo, ou emitindo um `GRANT` específico e não-curinga a cada `CREATE DATABASE`, na
mesma transação de provisionamento). Isto é uma verificação de ambiente, não uma decisão
de arquitetura — fica pendente até alguém confirmar a versão real do MySQL em uso.

### Por que a credencial de runtime é compartilhada e estática (resolve o achado "falta definir credencial de runtime")

A versão anterior não dizia como a credencial de runtime de cada unidade nova chegaria à
aplicação sem reiniciar/editar `.env` — `TenantConnectionManager::dedicatedConnectionName()`
hoje exige que `connection_profile` aponte para uma entrada já existente em
`TENANT_DATABASE_PROFILES` (JSON estático, lido na config).

Resolvido não criando uma credencial nova por unidade: o `GRANT` acima já dá à credencial
`tenant_runtime` acesso a **qualquer** banco que bater com o padrão de nome — inclusive
bancos que ainda não existem no momento em que o `GRANT` foi emitido. Isso significa que,
assim que `tenant_provisioning` roda `CREATE DATABASE`, a credencial `tenant_runtime` já
consegue usá-lo, sem nenhum `GRANT` adicional por unidade. O perfil de conexão para
unidades provisionadas nativamente passa a ser um único perfil constante (`"auto"`) com
usuário/senha fixos (`TENANT_RUNTIME_DB_USERNAME`/`PASSWORD`, variáveis de ambiente
normais, sem rotação por unidade) — só o `database_name` varia por unidade, e isso já é
suportado hoje por `TenantConnectionManager::dedicatedConnectionName()` via
`Config::set()` + `DB::purge()` em runtime, sem reiniciar o processo.

### `TenantDatabaseAutoProvisioner` (novo serviço)

Reaproveita, sem reescrever, tudo que a Fase 3 já validou (169 testes em cima do
mecanismo):

1. Conexão `tenant_provisioning` executa `CREATE DATABASE IF NOT EXISTS <nome>` — nome
   derivado do `public_id` da unidade (ex. `sync_hosp_u{ulid em minúsculo}`), nunca do
   `id` autoincremento.
2. `TenantDatabaseLifecycle::register()` — `LEGACY`, perfil `"auto"`.
3. `TenantDatabaseProvisioner::provision()` — migrations **só de `database/migrations/tenant/`**
   (Fase 6) → `SHADOW`.
4. `TenantPilotDataSynchronizer::synchronize()` — carga inicial (o pouco que já existe no
   legado para essa unidade nesse momento).
5. `TenantDatabaseReconciler::reconcile()` → `VALIDATING` → `recordContinuityEvidence()` →
   nova reconciliação → `CUTOVER` → `TENANT`.

**Sem atalho na máquina de estados.** A versão anterior propunha pular `VALIDATING`/`CUTOVER`
"porque é unidade nova, não tem dado a arriscar" — decidido não fazer isso: usar o ciclo
completo, já testado, é mais seguro do que abrir uma exceção nova em
`TenantDatabaseState::canTransitionTo()`. O custo de rodar os passos extras numa unidade
quase vazia é segundos.

### Ator do lifecycle (achado: "job não tem `User $actor` exigido")

`TenantDatabaseLifecycle::register/transition/recordReconciliation/recordContinuityEvidence`
exigem todos um `User $actor`, e `authorize()` checa `isPlatformAdministrator()` ou
organização+permissão — confirmado lendo o código. A versão anterior deste documento dizia
que o job carregaria só o `public_id` da unidade, sem resolver de onde viria o ator.

Correção: `ProvisionTenantAction` precisa passar a receber o admin autenticado como
parâmetro explícito (hoje não recebe — a autorização acontece só na camada HTTP, via
`ProvisionTenantRequest::authorize()`, e a action em si não sabe quem a chamou). O job de
auto-provisionamento carrega **dois** identificadores: `health_unit_public_id` e
`actor_user_public_id` (o admin que criou a unidade), e usa esse ator em todas as chamadas
ao `TenantDatabaseLifecycle`. Isso é parte da correção prévia em `ProvisionTenantAction`
descrita acima — não dá para resolver só dentro do job.

### Unidade não fica utilizável antes do job terminar (achado: "unidade ativa antes de TENANT")

`ProvisionTenantAction`, hoje, cria `HealthUnit` com `is_active => true` imediatamente, e
o manager já é criado e vinculado na mesma transação — ou seja, alguém consegue logar e
selecionar essa unidade como ativa antes de qualquer banco dedicado existir. Como a Fase 7
propõe um job assíncrono (`afterCommit`), existiria uma janela real em que a unidade "nova"
estaria, na prática, operando pela conexão legada — o oposto do que o resto deste documento
assume.

Correção: `ProvisionTenantAction` passa a criar `HealthUnit` com `is_active => false`. O
último passo do `TenantDatabaseAutoProvisioner` — só depois de `TENANT` confirmado — é que
flipa `is_active => true`. Até lá, `EnsureActiveHealthUnit` (que já filtra
`where('is_active', true)`) torna a unidade invisível para login/seleção, reaproveitando um
mecanismo que já existe em vez de inventar uma coluna nova de status. Falha no meio do job
deixa a unidade inativa e visível em `tenant:status`/`tenant_database_events` para
intervenção manual — não é um estado "quebrado e escondido", é um estado "não pronto e
visível".

**A verificar quando esta fase for detalhada de verdade**: se algum outro fluxo depende de
`HealthUnit.is_active` ser `true` no momento da criação (ex. algo em
`OrganizationCatalogBootstrapper` ou nos relacionamentos do manager) — não encontrei nada
na leitura atual do código, mas não foi auditado com esse propósito específico.

### Transação/outbox do disparo do job

`ProvisionTenantAction::execute()` (já corrigida para `DB::connection('core')->transaction()`,
ver seção de pré-requisito) despacha `ProvisionTenantDatabaseJob::dispatch(...)->afterCommit()`
— o padrão já usado em `SubmitLaboratoryOrderJob`. Não é um commit distribuído: é uma
escrita Core (criar a unidade, inativa) seguida de um job assíncrono e idempotente/retentável,
igual ao princípio 2 do plano mestre já pede.

## O que fica em aberto para quando esta fase for detalhada de verdade

1. **Versão exata do MySQL/configuração de `partial_revokes`** na imagem Docker do
   Railway — determina se o modelo de `GRANT` com curinga escapado funciona como descrito
   ou precisa de outro mecanismo (ver seção de escaping acima).
2. **Rotação das credenciais `tenant_provisioning`/`tenant_runtime`** — mesma pergunta já
   registrada como risco residual para a chave HMAC de CPF/CNS (seção 23 do plano mestre),
   agora aplicada a mais duas credenciais.
3. **Evidência de continuidade (backup/restore) por unidade nova**: exigir teste de
   restore específico a cada unidade (caro, repetido) ou aceitar uma evidência "o pipeline
   de backup/restore genérico deste ambiente já foi verificado" registrada uma vez? A
   guarda de `CUTOVER` em `TenantDatabaseLifecycle` não muda de qualquer forma — só a
   fonte da evidência que ela exige.
4. **Ação de "adicionar unidade a uma organização existente"** — hoje não existe no
   código (confirmado: `ProvisionTenantAction` é o único ponto de criação de `HealthUnit`,
   e há teste garantindo que uma organização não ganha segunda unidade por esse fluxo).
   Quando esse fluxo existir, precisa passar pelo mesmo job e pelo mesmo ator explícito.

## Fora de escopo

- Qualquer implementação de código nesta rodada — nem a correção prévia de
  `ProvisionTenantAction`, nem o serviço de auto-provisionamento em si.
- Alterar `TenantDatabaseState::canTransitionTo()` ou qualquer guarda de `CUTOVER` já
  validado na Fase 3.
- Criar a ação de "adicionar unidade a organização existente" — não existe hoje.
- Resolver a pergunta de `partial_revokes`/versão do MySQL — depende de confirmação de
  ambiente, não de decisão de arquitetura.
- Decidir a política de evidência de continuidade por unidade nova (item 3 acima).

## Critério para começar esta fase de verdade

1. `ProvisionTenantAction` corrigida (transação Core explícita, ator explícito, `is_active`
   inicial `false`) e commitada, com seus próprios testes.
2. Fase 6 concluída (`database/migrations/tenant/` existe e o provisionador usa `--path`).
3. Pelo menos uma unidade real ter completado `LEGACY→...→TENANT` pela Fase 3/4 sem
   intervenção manual fora do runbook documentado.
4. Versão do MySQL em produção confirmada, e o modelo de `GRANT` validado contra ela.

Só então este documento deve virar um `docs/CODEX_CORE_UNIT_DB_FASE_7_IMPL.md` com o mesmo
nível de detalhe (arquivos exatos, migration da nova conexão, testes, prompt pronto pro
Codex) usado nas fases anteriores.
