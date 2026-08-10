# Fase 7 — Provisionamento nativo de unidade nova

> Documento de planejamento. Nenhum código de auto-provisionamento foi implementado.
> **Não deve ser implementado nesta rodada nem antes da Fase 3/4 estarem validadas em
> produção com dado real, nem antes da Fase 6 estar concluída** (dependência técnica, não
> só cronológica — ver seção "Pré-requisitos"). Revisado pela segunda vez em 2026-08-10
> depois de uma segunda auditoria externa, que encontrou 6 lacunas na revisão anterior —
> duas delas (`Alto`) exigiram redesenho real do modelo de credenciais e da sequência de
> provisionamento, não só ajuste de texto.

## Estado da correção prévia em `ProvisionTenantAction`

**Parcialmente aplicada e commitada** (`DB::connection('core')->transaction(...)`
substituindo `DB::transaction(...)` sem conexão — commit já mesclado, com teste de
regressão provando que a organização deixa de ficar órfã quando algo falha depois da
unidade ser criada). Isso resolve a atomicidade das escritas Core.

**Ainda pendente**, e agora escopo explícito desta fase (não pode ser adiado para dentro do
job, pelos motivos nas seções "Ator do lifecycle" e "Registro durável antes do
provisionamento físico" abaixo):

1. `ProvisionTenantAction::execute()` passar a receber o admin autenticado como parâmetro
   explícito (hoje não recebe).
2. `ProvisionTenantAction` criar `HealthUnit` com `is_active => false`.
3. `ProvisionTenantAction` chamar `TenantDatabaseLifecycle::register()` **dentro da mesma
   transação Core**, antes de qualquer banco físico existir.

## Pré-requisitos (bloqueantes, não apenas recomendados)

1. **Fase 6 concluída** — `database/migrations/tenant/` precisa existir e o provisionador
   precisar rodar só esse subconjunto. Detalhe na seção "Por que a Fase 6 é obrigatória".
2. **Fase 3/4 validadas em produção** com pelo menos uma unidade real, sem intervenção
   manual fora do runbook documentado.
3. **Os três itens pendentes da seção anterior** implementados e commitados, com testes
   próprios.

## Por que "só admin cria unidade" não resolve sozinho o privilégio de banco

Autorização de aplicação (`ProvisionTenantRequest::authorize()`, hoje já exige
`isPlatformAdministrator()`) restringe **quem aciona a rota**. Não restringe **o que a
credencial de banco pode fazer**: se a conexão MySQL usada para criar bancos for a mesma
que a aplicação usa para todo o resto, qualquer requisição — inclusive as que não têm nada
a ver com criar unidade — passa a rodar numa conexão com esse privilégio.

## Modelo de credenciais (redesenhado após a segunda auditoria)

A revisão anterior propunha "três credenciais" no texto, mas só definia duas, e a segunda
(`tenant_runtime`) era ao mesmo tempo a credencial de todas as unidades **e** tinha
privilégio DDL descrito como "só DML" — contraditório, e confirmado incorreto: o
provisionador atual (`TenantDatabaseProvisioner::provision()`) roda `migrate` através da
conexão resolvida pelo `connection_profile` do `TenantDatabase` — ou seja, pela mesma
credencial de runtime, não por uma credencial de migração separada. Além disso, uma
credencial de runtime **compartilhada entre todas as unidades** anula boa parte do ganho de
isolamento da separação física: um vazamento ou uma injeção de SQL alcançável por essa
credencial expõe todos os hospitais da instância, não só um. Separação física de banco sem
separação de credencial reduz risco de bug de aplicação (a app só consulta o banco
resolvido pelo `TenantContext`), mas não reduz o risco de comprometimento no nível do
próprio banco.

**Modelo corrigido — duas credenciais, papéis genuinamente diferentes, sem uma terceira
fictícia:**

1. **`tenant_provisioning`** — credencial única, usada só pelo fluxo de
   auto-provisionamento, nunca pelo runtime normal da aplicação. Precisa de:
   - `CREATE` em `` `sync\_hosp\_u%` `` (curinga escapado, mesma ressalva de
     `partial_revokes` já registrada) — para `CREATE DATABASE`.
   - `CREATE USER` — privilégio **global** no MySQL, não pode ser restrito por padrão de
     nome de banco (o próprio motor não permite escopar isso). É o único privilégio desta
     lista sem contenção por padrão de nome; a mitigação é o uso extremamente raro e
     restrito desta credencial, não sua ausência.
   - `GRANT OPTION` sobre os privilégios que ela precisa repassar (abaixo) — para poder
     criar e conceder acesso à credencial por unidade, sem ela mesma nunca tocar dado de
     paciente diretamente.
2. **Credencial de runtime, uma por unidade, gerada dinamicamente** (não mais
   compartilhada) — resolve o achado de isolamento. Ver próxima seção.

### Credencial de runtime por unidade — geração e armazenamento

No momento do provisionamento de cada unidade, `tenant_provisioning` executa, na mesma
sequência que já cria o banco:

```sql
CREATE DATABASE IF NOT EXISTS `sync_hosp_u{ulid}`;
CREATE USER 'tenant_u{ulid}'@'%' IDENTIFIED BY '{senha aleatória, gerada}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON `sync_hosp_u{ulid}`.* TO 'tenant_u{ulid}'@'%';
```

Sem curinga nenhum aqui — é um nome de schema exato, então a ambiguidade de `_`/`%` e a
ressalva de `partial_revokes` **não se aplicam** a este grant (só ao grant amplo de
`tenant_provisioning` sobre si mesma, que continua sendo uma pendência de verificação de
ambiente, não resolvida neste documento). O mesmo usuário serve para `migrate` (precisa de
DDL) e para o runtime normal (DML) — não há necessidade de uma terceira credencial "só
migração", porque o escopo já está reduzido a uma única unidade; a única razão para
separar migração de runtime seria reduzir o alcance de um vazamento, e aqui o alcance já é
"uma unidade", o mínimo possível.

`tenant_databases` ganha duas colunas novas: `runtime_username` e
`encrypted_runtime_password` (cifrada com o `Encrypter` do Laravel — mesmo mecanismo já
usado em `PatientIdentifier.encrypted_value`, não um novo). `TenantConnectionManager::dedicatedConnectionName()`
passa a montar a configuração da conexão a partir dessas colunas em vez de um perfil
estático em `TENANT_DATABASE_PROFILES` — o "perfil" `TENANT_DATABASE_PROFILES` continua
existindo só para o piloto manual da Fase 3 (uma entrada, `pilot`), não para unidades
provisionadas nativamente.

**Efeito prático do vazamento de cada credencial**, para deixar o tradeoff explícito:

- `tenant_provisioning` vazada: quem a obtém pode criar bancos e usuários novos, mas não
  tem acesso direto a dado de paciente de nenhuma unidade já existente (as credenciais por
  unidade já geradas não ficam visíveis por essa credencial). Superfície pequena, mas
  privilégio real — por isso o uso precisa ser raro e auditado (todo uso já gera
  `tenant_database_events`, reaproveitando o que a Fase 3 já tem).
- Credencial de uma unidade vazada: quem a obtém alcança **só aquela unidade**. É
  exatamente o blast radius que motivou a arquitetura inteira.

## No MySQL (ação de infraestrutura, fora do código Laravel), configurada uma vez

```sql
CREATE USER 'tenant_provisioner'@'%' IDENTIFIED BY '...';
GRANT CREATE ON `sync\_hosp\_u%`.* TO 'tenant_provisioner'@'%';
GRANT CREATE USER ON *.* TO 'tenant_provisioner'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON `sync\_hosp\_u%`.* TO 'tenant_provisioner'@'%' WITH GRANT OPTION;
```

**Pendências de ambiente, não resolvidas aqui** (mesmas duas já registradas na revisão
anterior, ainda sem resposta):

- Confirmar a versão exata do MySQL na imagem Docker do Railway e se `partial_revokes`
  vem habilitado — determina se o `GRANT` com curinga escapado de `tenant_provisioning`
  funciona como descrito ou precisa de outro mecanismo.
- **Novo, apontado nesta rodada**: `'%'` como host de ambas as credenciais é o padrão mais
  aberto que o MySQL permite — a documentação do próprio MySQL recomenda não usar `'%'`
  em produção. Antes de configurar isso de verdade, restringir ao host/CIDR real de onde a
  aplicação se conecta (ex. rede interna do Railway) e exigir TLS na conexão. Isso vale
  para as duas credenciais, mas importa mais para `tenant_provisioning` (a mais sensível).

## Por que a Fase 6 é obrigatória, não só recomendada

`TenantDatabaseProvisioner::provision()`, hoje, roda `migrate` sem `--path` — executa
**todas** as migrations do projeto, inclusive as que criam tabela Core, contra o banco
dedicado. Aceitável no piloto único e manual da Fase 3; inaceitável numa fase que
provisiona repetidamente sem supervisão. A Fase 7 não começa antes de
`database/migrations/tenant/` existir e o provisionador rodar só esse `--path`.

## Registro durável antes do provisionamento físico (resolve o achado "outbox")

A revisão anterior criava o banco físico (`CREATE DATABASE`) **antes** de chamar
`TenantDatabaseLifecycle::register()`. Isso significa: se o processo cair entre os dois
passos, ou se o job nunca chegar a rodar (falha no enqueue depois do `afterCommit()`), não
existe nenhuma linha em `tenant_databases` — e `tenant:status`, que lista exclusivamente
essa tabela, não mostra nada. Um banco pode ficar órfão no MySQL sem nenhum rastro na
aplicação.

**Correção: inverter a ordem.** `TenantDatabaseLifecycle::register()` não toca o banco
físico — só grava a intenção (unidade, perfil, nome de banco planejado, estado `LEGACY`).
Não há razão para ele esperar o banco existir. Ele passa a ser chamado **dentro da mesma
transação Core de `ProvisionTenantAction`**, junto com `Organization`/`HealthUnit`/`User` —
o nome do banco (`sync_hosp_u{public_id da unidade}`) já é conhecido nesse momento, porque
deriva do `public_id`, gerado no `HealthUnit::create()` que acabou de rodar na mesma
transação.

Isso substitui a necessidade de um outbox literal (tabela de mensagens pendentes): a
própria linha de `tenant_databases`, em estado `LEGACY` com `schema_status = pending`,
**é** o registro durável de intenção — nasce garantidamente junto com a unidade (mesma
transação Core, mesmo commit), antes de qualquer chamada a `CREATE DATABASE` ou de
qualquer job ser despachado. Um `CREATE DATABASE` que falhe, ou um job que nunca rode,
deixam a unidade visível em `tenant:status` como "registrada, schema pendente" — nunca
invisível.

## Retomada de provisionamento parcial (resolve o achado de recuperação)

Com o registro nascendo antes de tudo, a pergunta "o que acontece se o job falhar no
meio?" já tem resposta pelo mesmo mecanismo que a Fase 3 usa para o piloto manual: cada
etapa (`TenantDatabaseProvisioner::provision`, `TenantPilotDataSynchronizer::synchronize`,
`TenantDatabaseReconciler::reconcile`, `TenantDatabaseLifecycle::transition`) já é
idempotente e já é chamável independentemente, porque a Fase 3 foi desenhada para ser
operada manualmente passo a passo. O `TenantDatabaseAutoProvisioner` (job) só automatiza a
sequência — não substitui a possibilidade de rodar os passos manualmente.

Isso resolve diretamente o cenário levantado na auditoria ("nova tentativa encontra CNES
já utilizado"): depois da correção de atomicidade, `ProvisionTenantAction` só cria
`Organization`/`HealthUnit`/`tenant_databases` juntos, ou nenhum dos três. Não existe mais
"CNES já usado, mas unidade incompleta" — existe só "unidade criada, banco físico ainda
não pronto", que é um estado visível e retomável, não um estado quebrado.

Novo comando explícito, `tenant:resume-provisioning {unit}`: lê o `state` atual do
`TenantDatabase` da unidade e chama o próximo passo aplicável (mesma lógica que um operador
seguiria manualmente pelos comandos `tenant:pilot-*` já existentes — este comando só
automatiza "qual é o próximo passo", sem inventar mecanismo novo). Roda sob demanda (um
admin aciona depois de ver `tenant:status` mostrar uma unidade parada) ou, futuramente, via
um agendamento que varre unidades paradas há mais que um limite de tempo — este segundo
modo fica registrado como ideia, não é escopo desta fase.

## `TenantDatabaseAutoProvisioner` (novo serviço, sequência revisada)

1. `ProvisionTenantAction` (já corrigida + os 3 itens pendentes desta rodada): cria
   `Organization`, `HealthUnit` (`is_active = false`), bootstrap Core, `User`, **e**
   `TenantDatabaseLifecycle::register()` — tudo na mesma transação Core. Despacha
   `ProvisionTenantDatabaseJob::dispatch(...)->afterCommit()` carregando
   `health_unit_public_id` e `actor_user_public_id`.
2. Job (ou `tenant:resume-provisioning`, mesma lógica): `tenant_provisioning` executa
   `CREATE DATABASE` + `CREATE USER` + `GRANT` (schema exato, sem curinga) para a
   credencial da unidade; grava `runtime_username`/`encrypted_runtime_password` no
   `TenantDatabase`.
3. `TenantDatabaseProvisioner::provision()` — migrations **só de `database/migrations/tenant/`**
   (Fase 6), agora resolvendo a conexão pela credencial recém-gerada → `SHADOW`.
4. `TenantPilotDataSynchronizer::synchronize()` — carga inicial (o pouco que já existe no
   legado para essa unidade nesse momento).
5. `TenantDatabaseReconciler::reconcile()` → `VALIDATING` → `recordContinuityEvidence()` →
   nova reconciliação → `CUTOVER` → `TENANT` → `HealthUnit.is_active = true`.

Sem atalho na máquina de estados — decisão mantida da revisão anterior, não revisitada
aqui.

## O que fica em aberto para quando esta fase for detalhada de verdade

1. **Versão exata do MySQL/configuração de `partial_revokes`** — mesma pendência,
   escopo reduzido (só afeta o grant amplo de `tenant_provisioning` sobre si mesma).
2. **Host de origem das credenciais** (`'%'` é só placeholder de exemplo, não é a
   configuração recomendada) — restringir por rede/CIDR real, exigir TLS.
3. **Rotação da credencial `tenant_provisioning`** — a credencial por unidade não tem essa
   pergunta com a mesma urgência (blast radius de rotação já é uma unidade só).
4. **Evidência de continuidade (backup/restore) por unidade nova** — mesma pendência da
   revisão anterior, sem resposta nova.
5. **Ação de "adicionar unidade a uma organização existente"** — ainda não existe no
   código; quando existir, precisa passar pela mesma sequência (register-antes-de-criar,
   ator explícito, mesmo job).

## Fora de escopo

- Qualquer implementação de código de auto-provisionamento nesta rodada.
- Alterar `TenantDatabaseState::canTransitionTo()` ou qualquer guarda de `CUTOVER` já
  validado na Fase 3.
- Criar a ação de "adicionar unidade a organização existente" — não existe hoje.
- Resolver `partial_revokes`/versão do MySQL/host de origem das credenciais — dependem de
  confirmação de ambiente, não de decisão de arquitetura.
- Decidir a política de evidência de continuidade por unidade nova (item 4 acima).
- Implementar o agendamento automático de `tenant:resume-provisioning` — fica como ideia
  registrada, não como requisito desta fase.

## Critério para começar esta fase de verdade

1. Os 3 itens pendentes de `ProvisionTenantAction` (ator explícito, `is_active` inicial
   `false`, `register()` dentro da transação Core) implementados, commitados, com testes.
2. Fase 6 concluída (`database/migrations/tenant/` existe e o provisionador usa `--path`).
3. Pelo menos uma unidade real ter completado `LEGACY→...→TENANT` pela Fase 3/4 sem
   intervenção manual fora do runbook documentado.
4. Versão do MySQL em produção confirmada, modelo de `GRANT` validado contra ela, e host
   de origem das credenciais restrito (não `'%'`).

Só então este documento deve virar um `docs/CODEX_CORE_UNIT_DB_FASE_7_IMPL.md` com o mesmo
nível de detalhe (arquivos exatos, migration das colunas novas em `tenant_databases`,
testes, prompt pronto pro Codex) usado nas fases anteriores.
