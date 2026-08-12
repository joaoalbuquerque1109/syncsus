# Fase 5 — Descomissionamento de suposições de banco único

> Implementada em 2026-08-10 e ativada na unidade piloto **URGENCIA-CENTRAL**.

## Objetivo

Retirar três dependências operacionais do banco único sem introduzir chamadas HTTP
síncronas em cascata entre unidades:

1. consolidar indicadores cross-unidade no Core por processamento assíncrono;
2. vincular backup/restore ao banco e à unidade corretos, com continuidade temporal;
3. permitir diagnóstico centralizado do estado de cada Tenant.

## Implementação

### Reporting assíncrono

- `RefreshUnitReportSnapshotJob` resolve uma unidade de cada vez, consulta somente seu
  banco Tenant e grava o agregado em `unit_report_snapshots`, no Core.
- O job é único por unidade por 240 segundos, sempre limpa o `TenantContext` e emite os
  eventos estruturados `tenant.report_snapshot_started`,
  `tenant.report_snapshot_completed` e `tenant.report_snapshot_failed`.
- `reports:refresh-unit-snapshots` despacha os jobs para unidades em `CUTOVER`/`TENANT`.
  A opção `--sync` existe para operação e diagnóstico supervisionados.
- O scheduler despacha a atualização a cada cinco minutos.
- A visão consolidada de Operações lê exclusivamente os snapshots do Core. Uma request
  HTTP não abre conexões sequenciais com todos os bancos Tenant. Snapshot com mais de
  dez minutos é marcado como desatualizado.

### Backup e restore por unidade

- `backup_logs` e `backup_verifications` agora pertencem explicitamente ao Core e podem
  registrar `tenant_database_id`, `health_unit_id`, escopo e referência temporal.
- `sync-sus:backup-verify <diretório> --unit=<public_id>` exige, para backup Tenant,
  `TENANT_BACKUP.json` com:

```json
{
  "tenant_database_public_id": "...",
  "health_unit_public_id": "...",
  "core_reference_at": "2026-08-10T18:00:00-03:00",
  "restore_point_at": "2026-08-10T18:01:00-03:00"
}
```

- O verificador rejeita conjunto de outra unidade/banco, timestamps ausentes ou
  inválidos e ponto de restore anterior à referência Core. Também preserva as validações
  de confinamento de diretório, manifesto SHA-256, integridade e leitura dos arquivos
  compactados.
- O campo `restore_compatible` representa compatibilidade temporal verificada; ele não
  substitui um ensaio periódico de restauração em infraestrutura isolada.

### Observabilidade operacional

`php artisan tenant:status` apresenta, numa única consulta operacional, unidade, estado,
schema, infraestrutura, perfil, última reconciliação, último snapshot e último backup
Tenant verificado. O caractere `-` é evidência ausente, não sucesso implícito.

## Ativação da unidade piloto

- Core e Tenant receberam a versão de migration
  `2026_08_10_090000_create_phase_five_operational_foundation`.
- Antes da aplicação foram preservadas cópias físicas:
  - `database/database.pre-phase5-20260810.sqlite`
  - `database/urgencia-central.pre-phase5-20260810.sqlite`
- Foi gerado e lido pelo Core um snapshot real da `URGENCIA-CENTRAL`.
- O status de backup permanece `-` até que um conjunto real do provedor seja produzido,
  restaurado e verificado. Não foi criada evidência artificial para satisfazer o painel.

## Operação

```bash
php artisan reports:refresh-unit-snapshots
php artisan reports:refresh-unit-snapshots <health-unit-public-id> --sync
php artisan tenant:status
php artisan sync-sus:backup-verify <backup-set> --unit=<health-unit-public-id> --actor=<email>
```

O worker de filas e o scheduler devem estar ativos. Alertar quando houver
`tenant.report_snapshot_failed`, snapshot com idade superior a dez minutos ou ausência de
backup verificado além da política operacional definida pela instituição.

## Critérios de aceite cobertos

- nenhum fan-out cross-Tenant ocorre durante a renderização da página de Operações;
- falha de uma unidade não contamina o `TenantContext` do próximo job;
- o agregado identifica unidade e conexão de origem;
- backup Tenant errado ou temporalmente incompatível falha fechado;
- Core centraliza snapshot, verificação de backup e estado do control plane;
- testes de regressão, análise estática e formatação fazem parte da validação da fase.
