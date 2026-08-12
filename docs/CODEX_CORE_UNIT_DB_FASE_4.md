# Fase 4 — Rollout das unidades e split definitivo de auditoria

## Estado

Concluída no ambiente local em 2026-08-10. A única unidade cadastrada, `URGENCIA-CENTRAL`,
percorreu o ciclo completo `LEGACY → SHADOW → VALIDATING → CUTOVER → TENANT`.

## Rollout executado

- Perfil dedicado: `emergencia-central` (SQLite local; configuração mantida apenas no
  `.env`, sem credenciais versionadas).
- Carga inicial final: 701 registros.
- Migração de prontuário anterior ao cutover: 14 `unit_patients`, 12 atendimentos e cinco
  conflitos históricos resolvidos de forma auditada para a única unidade da organização.
- Double-write comprovado por escrita controlada durante `VALIDATING`.
- Reconciliações antes do cutover: `matched`.
- Backup restaurado em arquivo separado e validado com `PRAGMA integrity_check = ok`.
- Smoke pós-cutover: prontuário, recepção, filas, triagem, atendimento, exames, documentos
  e auditoria lidos pela conexão dedicada.

Backups locais recuperáveis:

- `database/database.pre-tenant-pilot-20260810.sqlite`
- `database/database.pre-audit-split-20260810.sqlite`
- `database/urgencia-central.pre-audit-split-20260810.sqlite`
- `database/urgencia-central.backup-20260810.sqlite`
- `database/urgencia-central.restore-test-20260810.sqlite`

## Split definitivo de auditoria

Foi implementada a decisão ADR-016 do plano mestre:

- `security_audit_logs` é Core e recebe eventos `user.*`, `professional.*`,
  `organization.*`, `tenant.*` e `security.*`;
- `audit_logs` permanece Tenant e recebe eventos clínicos e operacionais;
- não há duplicação automática do mesmo evento;
- `correlation_id` permite relacionar eventos Core e Tenant da mesma operação;
- a tela de auditoria consulta e apresenta as duas trilhas separadamente.

Na migração local, 49 eventos de identidade foram movidos para o Core e 144 eventos
clínicos permaneceram no banco da unidade. Nenhum evento classificado como segurança
permaneceu no Tenant e nenhum registro ficou sem `correlation_id`.

## Correção de pré-requisito encontrada durante o rollout

A migration de referências públicas assumia que `specialties.public_id` já existia, mas a
tabela histórica não possuía a coluna. O upgrade com dados reais falhava antes do control
plane. A própria migration agora adiciona e preenche o ULID, e o model `Specialty` gera o
identificador em novas inclusões.

## Recuperação

O estado `TENANT` não possui rollback automático. Em uma emergência local, os arquivos
pré-cutover acima preservam os estados anteriores, mas a restauração exige decisão
operacional explícita para não descartar escritas feitas depois do cutover.

