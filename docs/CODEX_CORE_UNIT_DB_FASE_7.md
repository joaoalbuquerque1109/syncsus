# Fase 7 — Provisionamento nativo de unidade nova

> Documento de planejamento. Nenhum código foi alterado ao produzi-lo. **Não deve ser
> implementado nesta rodada nem antes da Fase 3/4 estarem validadas em produção com pelo
> menos uma unidade real** — é a última fase do roteiro (`docs/SYNC_HOSP_CORE_UNIT_DB_MASTER_PLAN.md`,
> §21) por decisão deliberada: automatizar a criação física "do zero" antes de o ciclo de
> vida `LEGACY→...→TENANT` ter rodado manualmente pelo menos uma vez em produção é assumir
> risco na etapa mais nova do sistema sem necessidade.

## Objetivo

Quando um administrador de plataforma cria uma organização/unidade nova
(`ProvisionTenantAction`, hoje o único ponto do código que insere `HealthUnit`), o sistema
cria automaticamente o banco físico dedicado dessa unidade, roda as migrations e leva a
unidade direto ao estado `TENANT` — sem passo manual de DBA. Isso fecha o roteiro de 8
fases: as unidades migradas do legado passam pelo ciclo completo (Fase 3/4); as unidades
que nascem depois da arquitetura madura nunca tocam o banco legado.

## Por que o "salto de privilégio" não é resolvido só pela autorização de admin

Confirmado em `ProvisionTenantRequest::authorize()`: hoje só `isPlatformAdministrator()`
pode criar unidade. Isso restringe **quem aciona a rota** — é a camada de autorização da
aplicação. Não restringe, sozinho, **o que a credencial de banco pode fazer**: se a
conexão MySQL usada para `CREATE DATABASE` for a mesma que a aplicação usa para todo o
resto (`core`, `tenant_template`), qualquer requisição da aplicação — inclusive as que não
têm nada a ver com criar unidade — passa a rodar numa conexão com esse privilégio. Uma
injeção de SQL numa tela qualquer, uma dependência vulnerável, um `.env` vazado: nenhum
desses caminhos passa pela autorização de admin, mas herdaria o privilégio de banco se a
credencial for compartilhada.

A solução não é abrir mão da automação — é usar uma credencial dedicada, nunca
reaproveitada, e ainda assim restrita ao mínimo que o MySQL permite expressar.

## Mecanismo

### Credencial de provisionamento, isolada da credencial de runtime

Nova conexão nomeada em `config/database.php` (ex. `tenant_provisioning`), com variáveis
de ambiente próprias (`TENANT_PROVISIONING_DB_HOST`/`USERNAME`/`PASSWORD`/...) — nunca
`DB_USERNAME`/`DB_PASSWORD` nem as credenciais dos perfis em `TENANT_DATABASE_PROFILES`.
Essa conexão só é aberta dentro do serviço de auto-provisionamento (abaixo), nunca mantida
viva nem reutilizada em outro lugar do app.

No lado do MySQL (ação de infraestrutura, pré-requisito documentado aqui, fora do código
Laravel — mesmo espírito do que a Fase 3 já assume para o perfil do piloto):

```sql
CREATE USER 'tenant_provisioner'@'%' IDENTIFIED BY '...';
GRANT CREATE, ALTER, DROP ON `sync_hosp_u%`.* TO 'tenant_provisioner'@'%';
```

O `GRANT` usa o padrão de nome do banco (prefixo `sync_hosp_u`) — mesmo que essa
credencial vaze, ela não alcança `sync_hosp_core` nem qualquer schema fora do padrão. Não
é privilégio de superusuário/root; é o menor privilégio que o MySQL consegue expressar
para "criar bancos que seguem esta convenção de nome".

### Nome do banco

Derivado do `public_id` da unidade (ex. `sync_hosp_u{ulid em minúsculo}`), nunca do `id`
autoincremento — consistente com o motivo de o projeto já usar `HasPublicId` em todo
lugar: não expor identificador interno sequencial.

### `TenantDatabaseAutoProvisioner` (novo serviço)

Reaproveita, sem reescrever, tudo que a Fase 3 já validou:

1. Abre a conexão `tenant_provisioning`, executa `CREATE DATABASE IF NOT EXISTS <nome>`.
2. Chama `TenantDatabaseLifecycle::register()` (já existe) para criar o `TenantDatabase`
   em `LEGACY`.
3. Chama `TenantDatabaseProvisioner::provision()` (já existe) — roda as migrations no
   banco novo e remove FKs cross-database via `TenantSchemaHardener` — unidade entra em
   `SHADOW`.
4. Chama `TenantPilotDataSynchronizer::synchronize()` (já existe) — como a unidade acabou
   de nascer, o "legado" dela é só o que `ProvisionTenantAction` já escreveu na mesma
   transação (catálogo inicial via `OrganizationCatalogBootstrapper`, se algum), não dado
   clínico de verdade. A carga é trivial, mas passa pelo mesmo caminho testado.
5. Chama `TenantDatabaseReconciler::reconcile()` (já existe) — como o volume é mínimo,
   tende a `matched` imediatamente.
6. Transição para `VALIDATING`, `recordContinuityEvidence()`, nova reconciliação,
   transição para `CUTOVER`, depois `TENANT` — **a mesma sequência de comandos que a Fase
   3 já expõe manualmente via `tenant:pilot-*`**, só que executada programaticamente em
   vez de digitada por um operador.

**Nenhuma mudança na máquina de estados (`TenantDatabaseState::canTransitionTo()`)** — a
tentação seria adicionar um atalho `LEGACY→TENANT` direto "porque é unidade nova, não tem
dado a arriscar". Decidi não propor isso: usar o ciclo completo já testado (Fase 3 tem 169
testes em cima dele) é mais seguro do que abrir uma exceção nova na máquina de estados só
para o caso "parece simples". O custo de rodar os passos extras numa unidade vazia é
segundos, não é um problema real a resolver.

### Disparo: job em fila, não bloqueando a requisição HTTP

`ProvisionTenantAction::execute()` não deve rodar isso inline — `CREATE DATABASE` +
migrations pode levar alguns segundos, e a Fase 0 já estabeleceu o padrão de que
providenciamento é uma operação assíncrona/observável, não uma chamada síncrona (mesmo
raciocínio do `SubmitLaboratoryOrderJob`). Um `ProvisionTenantDatabaseJob` é despachado
`afterCommit()` da transação de `ProvisionTenantAction`, carrega o `public_id` da unidade
recém-criada, e executa a sequência do `TenantDatabaseAutoProvisioner`. Falha em qualquer
etapa fica visível em `tenant:status` e nos `tenant_database_events` (já existentes) — um
administrador consegue ver exatamente em qual estado a unidade travou e decidir manualmente
(inclusive rodando os comandos `tenant:pilot-*` manuais como plano B, já que continuam
existindo).

## O que fica em aberto para quando esta fase for detalhada de verdade

Estas são decisões que só fazem sentido responder depois que a Fase 3/4 tiver rodado com
dado real — registrar aqui a pergunta, não a resposta:

1. **Evidência de continuidade (backup/restore) por unidade nova**: exigir um teste de
   restore específico para cada banco recém-criado (caro, repetido a cada unidade) ou
   aceitar uma evidência "o pipeline de backup/restore genérico deste ambiente já foi
   verificado" registrada uma vez? A guarda de `CUTOVER` em `TenantDatabaseLifecycle`
   não muda de qualquer forma — só a fonte da evidência que ela exige.
2. **O que fazer se o job falhar no meio** (ex. banco criado, migrations não terminaram):
   a unidade fica visível em `tenant:status` num estado intermediário; falta decidir se
   isso bloqueia o admin de convidar usuários pra ela até resolver, ou se o app permite
   uso normal via a conexão legada enquanto o provisionamento não termina (o que
   recriaria, para unidade nova, o mesmo cenário de double-write que a Fase 3 já trata).
3. **Rotação da credencial `tenant_provisioning`** — mesma pergunta já registrada como
   risco residual para a chave HMAC de CPF/CNS (seção 23 do plano mestre), agora aplicada
   a mais uma credencial.
4. **Ação de "adicionar unidade a uma organização existente"** — hoje não existe no
   código (confirmado: `ProvisionTenantAction` é o único ponto de criação de
   `HealthUnit`, e há teste específico garantindo que uma organização não ganha segunda
   unidade por esse fluxo). Quando esse fluxo existir, precisa disparar o mesmo job.

## Fora de escopo

- Qualquer implementação de código nesta rodada.
- Alterar `TenantDatabaseState::canTransitionTo()` ou qualquer guarda de `CUTOVER` já
  validado na Fase 3.
- Criar a ação de "adicionar unidade a organização existente" — não existe hoje e não é
  pré-requisito para esta fase (o gatilho inicial é só `ProvisionTenantAction`).
- Decidir a política de evidência de continuidade por unidade nova (item 1 acima) — fica
  para quando a fase for detalhada com informação real de produção.

## Critério para começar esta fase de verdade

Pelo menos uma unidade real ter completado `LEGACY→...→TENANT` pela Fase 3/4 sem
intervenção manual fora do runbook documentado, com a reconciliação e as evidências de
continuidade funcionando como desenhado. Só então este documento deve virar um
`docs/CODEX_CORE_UNIT_DB_FASE_7_IMPL.md` com o mesmo nível de detalhe (arquivos exatos,
migration da nova conexão, testes, prompt pronto pro Codex) usado nas fases anteriores.
