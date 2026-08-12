# Fase 6 — Migrations Tenant e detecção de drift

> Implementada em 2026-08-11 e aplicada na **URGENCIA-CENTRAL**.

## Objetivo

Impedir que o provisionamento de uma unidade execute migrations Core no banco Tenant e
detectar unidades cujo histórico de schema não corresponde ao conjunto esperado.

## Contrato de migrations

- `database/migrations/` permanece como baseline histórico/Core. Os arquivos anteriores
  ao corte da Fase 6 não são movidos ou renomeados, preservando o histórico já registrado
  em produção.
- `database/migrations/tenant/` é a fonte exclusiva de migrations para bancos de unidade.
  Toda evolução futura de tabela Tenant deve ser criada nessa pasta.
- `2026_08_11_000000_create_tenant_schema_baseline` reproduz o histórico somente durante
  a criação de um banco vazio, remove FKs cross-database e elimina as cópias Core vazias.
- Em banco já existente, a baseline não recria tabelas: valida, endurece e remove apenas
  cópias Core comprovadamente vazias. Se qualquer cópia contiver dados, a operação falha
  antes de excluir qualquer tabela.
- A tabela `migrations` permanece no Tenant como repositório do Laravel.

O baseline histórico é uma ponte de compatibilidade. Novas alterações não devem editar
os arquivos antigos nem ampliar seu cutoff; devem ser migrations incrementais Tenant.

## Provisionamento

`TenantDatabaseProvisioner` agora usa `TenantSchemaMigrator`, que executa:

```text
php artisan migrate --database=<conexão-da-unidade> \
  --path=database/migrations/tenant --force
```

Ele não executa a pasta Core e não depende mais de criar tabelas Core permanentes para
depois ignorá-las. Isso satisfaz o pré-requisito técnico da Fase 7 para provisionamento
nativo repetível.

## Drift

O comando operacional é:

```bash
# Audita todas as unidades CUTOVER/TENANT sem aplicar DDL
php artisan tenant:schema

# Audita uma unidade
php artisan tenant:schema <health-unit-public-id>

# Aplica somente migrations Tenant; ator administrativo é obrigatório
php artisan tenant:schema <health-unit-public-id> \
  --apply --actor=<administrator-public-id>
```

Cada unidade é processada isoladamente. Falha em uma conexão é registrada como
`schema_migration_failed` e não impede a próxima unidade. O Core recebe eventos
`schema_checked`/`schema_migrated`, número de migrations históricas, pendências e uma
assinatura SHA-256 versão 2.

A assinatura cobre a baseline Tenant e todos os arquivos históricos dos quais ela
depende. Se um arquivo já aplicado for alterado em vez de uma nova migration ser criada,
a unidade fica `drifted`; executar `migrate` não mascara essa divergência.

Uma auditoria sem DDL roda diariamente às 02:15. `tenant:status` continua sendo a visão
central do estado de schema.

## Infraestrutura compartilhada

Sessões, cache, jobs, batches e failed jobs são Core. Os defaults de `session.php`,
`cache.php` e `queue.php` agora apontam explicitamente para `core`; ações de identidade e
o painel operacional também usam essa conexão. As variáveis documentadas são:

```dotenv
SESSION_CONNECTION=core
DB_QUEUE_CONNECTION=core
DB_CACHE_CONNECTION=core
DB_CACHE_LOCK_CONNECTION=core
```

## Aplicação na URGENCIA-CENTRAL

Antes da alteração foram criadas as cópias:

- `database/database.pre-phase6-20260811.sqlite`
- `database/urgencia-central.pre-phase6-20260811.sqlite`

Resultado observado:

- schema: `ready`;
- migrations pendentes: `0`;
- tabelas no Tenant: `105 → 67`;
- tabelas Core restantes no Tenant: `0`;
- dados de controle conferidos após a alteração: 12 atendimentos, 145 eventos clínicos
  de auditoria e 1 integração laboratorial.

## Critérios de aceite

- banco Tenant novo nasce sem tabelas Core;
- provisionador usa somente `database/migrations/tenant/`;
- cópia Core com qualquer dado falha fechado e é preservada;
- drift é detectado e auditado por unidade;
- falha de uma unidade não interrompe as demais;
- infraestrutura compartilhada usa Core independentemente da unidade ativa;
- harness de teste recria conexões Core e Tenant fisicamente separadas.
