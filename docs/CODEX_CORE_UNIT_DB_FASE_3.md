# Fase 3 — Banco físico piloto por unidade

## Estado

Control plane implementado em 2026-08-09. O código está pronto para executar o piloto,
mas nenhum banco externo, unidade real ou cutover de produção foi escolhido ou alterado
automaticamente.

## Topologia adotada

A implementação é provider-neutral. O provedor cria previamente o database vazio e
injeta suas credenciais pelo gerenciador de segredos em `TENANT_DATABASE_PROFILES`.
O Core persiste apenas o nome do perfil e do database, nunca senha ou token.

Exemplo de perfil (valor JSON em uma única linha):

```dotenv
TENANT_LEGACY_CONNECTION=mysql
TENANT_DATABASE_PROFILES={"pilot":{"driver":"mysql","host":"host","port":3306,"database":"sync_hosp_u0001","username":"user","password":"secret","charset":"utf8mb4","collation":"utf8mb4_unicode_ci","prefix":"","strict":true}}
```

## Ciclo de vida

```text
LEGACY → SHADOW → VALIDATING → CUTOVER → TENANT
             ↘          ↘          ↘
                        ROLLBACK → LEGACY
```

- `LEGACY`: leitura e escrita permanecem no banco legado.
- `SHADOW`: leitura permanece no legado; models Tenant e pivôs operacionais recebem
  double-write idempotente no banco dedicado.
- `VALIDATING`: continua lendo do legado e exige nova reconciliação sem divergências.
- `CUTOVER`: a resolução da conexão passa a usar o banco dedicado.
- `TENANT`: estado estável e sem transição automática de retorno.
- `ROLLBACK`: volta imediatamente à conexão legada; o banco dedicado fica preservado.

Cada transição é validada sob lock no Core e gera um `tenant_database_event` imutável.
`CUTOVER` só é permitido depois de:

1. schema dedicado pronto;
2. reconciliação `matched` executada em `VALIDATING`;
3. evidência auditada de backup verificado;
4. evidência auditada de restore testado.

## Ordem operacional do piloto

Substitua os identificadores pelos `public_id` reais. Todos os comandos mutáveis exigem
`--apply`; sem a flag, apenas informam que nenhuma mudança foi feita.

```bash
php artisan migrate --database=core --force
php artisan tenant:pilot-register UNIT_PUBLIC_ID pilot ACTOR_PUBLIC_ID --apply
php artisan tenant:pilot-provision UNIT_PUBLIC_ID ACTOR_PUBLIC_ID --apply
php artisan tenant:pilot-sync UNIT_PUBLIC_ID ACTOR_PUBLIC_ID --apply
php artisan tenant:pilot-reconcile UNIT_PUBLIC_ID ACTOR_PUBLIC_ID --apply
php artisan tenant:pilot-transition UNIT_PUBLIC_ID VALIDATING ACTOR_PUBLIC_ID --apply
```

Depois de uma janela real de double-write, execute novamente a reconciliação.
Durante `SHADOW`/`VALIDATING`, monitore o log por `tenant.shadow_write_failed`. Se o
evento aparecer, execute `tenant:pilot-reconcile` antes do horário programado para
identificar a divergência sem interromper a escrita primária da unidade:

```bash
php artisan tenant:pilot-continuity UNIT_PUBLIC_ID ACTOR_PUBLIC_ID \
  --backup-reference=BACKUP_VERIFICADO \
  --restore-reference=RESTORE_TESTADO \
  --apply
php artisan tenant:pilot-reconcile UNIT_PUBLIC_ID ACTOR_PUBLIC_ID --apply
php artisan tenant:pilot-transition UNIT_PUBLIC_ID CUTOVER ACTOR_PUBLIC_ID --apply
```

Após smoke tests de prontuário, recepção, fila, triagem, atendimento, exames, documentos,
auditoria, scheduler e jobs:

```bash
php artisan tenant:pilot-transition UNIT_PUBLIC_ID TENANT ACTOR_PUBLIC_ID --apply
```

Rollback durante o piloto:

```bash
php artisan tenant:pilot-transition UNIT_PUBLIC_ID ROLLBACK ACTOR_PUBLIC_ID --apply
php artisan tenant:pilot-transition UNIT_PUBLIC_ID LEGACY ACTOR_PUBLIC_ID --apply
```

Visibilidade operacional:

```bash
php artisan tenant:status
```

## Migração e reconciliação

- O provisionamento executa as migrations no database dedicado e remove somente as FKs
  que atravessariam Tenant→Core. FKs internas do Tenant permanecem.
- A carga inicial copia apenas o conjunto da unidade/organização, preservando IDs e
  podendo ser repetida com segurança (`upsert`).
- A reconciliação cobre todo o manifesto Tenant com contagem e SHA-256 canônico por
  tabela. Ela apenas reporta divergências; não corrige dado clínico automaticamente.
- A chave de idempotência da recepção passou a carregar `health_unit_public_id`, evitando
  transportar uma chave de outra unidade durante a separação física.
- Auditorias com contexto de unidade acompanham a conexão Tenant; eventos sem contexto
  continuam no legado até o split definitivo da Fase 4.

## Limites operacionais

- A aplicação não cria recursos no provedor (instância/database/usuário); isso continua
  sendo uma ação de infraestrutura anterior a `tenant:pilot-register`.
- Nenhum piloto deve chegar a `CUTOVER` somente com testes automatizados. Backup, restore
  e smoke tests precisam ser realizados no ambiente real e referenciados no evento.
- `TENANT` não possui rollback automático, conforme ADR-012: depois desse estado pode
  haver escrita exclusiva no banco dedicado.
