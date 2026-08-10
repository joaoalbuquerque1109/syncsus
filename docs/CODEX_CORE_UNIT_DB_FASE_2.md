# Fase 2 — Prontuário local por unidade

## Estado

Implementada de forma aditiva em 2026-08-09. Nenhum dado legado é apagado e nenhum
conflito é resolvido automaticamente.

## Modelo

- Core: `Patient`, `PatientIdentifier`, `PatientUnitParticipation` e
  `PatientUnitMigrationConflict`.
- Tenant: `UnitPatient`, contatos, endereços, responsáveis, alergias, condições,
  medicamentos e histórico social.
- Os registros locais carregam `unit_patient_id`, `patient_public_id` e
  `health_unit_public_id`. As colunas legadas permanecem durante a reconciliação.
- O escopo da unidade ativa é aplicado automaticamente aos sete tipos de registro local.

## Migração histórica

O critério é deliberadamente conservador:

1. paciente com atendimento em exatamente uma unidade: o registro é vinculado
   automaticamente;
2. paciente com atendimentos em mais de uma unidade: conflito `multiple_units`;
3. paciente sem atendimento: conflito `no_unit`;
4. referência sem paciente Core: conflito `missing_core_patient`.

Os comandos de mudança exigem `--apply`; sem essa opção, apenas simulam/contam.

```bash
php artisan patients:protect-identifiers
php artisan patients:migrate-unit-records --connection=tenant_template

php artisan patients:protect-identifiers --apply
php artisan patients:migrate-unit-records --connection=tenant_template --apply
php artisan patients:list-unit-conflicts
```

Resolução manual, depois da conferência clínica/administrativa:

```bash
php artisan patients:resolve-unit-conflict CONFLICT_PUBLIC_ID UNIT_PUBLIC_ID ACTOR_PUBLIC_ID
```

A rotina é idempotente e preserva o registro original. Não executar remoção das
colunas antigas enquanto existir conflito pendente.

## Identificadores

- `encrypted_value`: criptografia autenticada do Laravel.
- `fingerprint`: `HMAC-SHA256(chave, tipo:valor_normalizado)` para busca exata.
- `fingerprint_key_version`: versão persistida para rotação futura.
- `PATIENT_IDENTIFIER_HMAC_KEY` é obrigatória, dedicada e não reutiliza `APP_KEY`.
- O valor aberto legado é mantido temporariamente para rollout e reconciliação.

Busca por CPF/CNS passa a ser exata; busca parcial de identificador não é compatível
com fingerprint HMAC sem criar um índice adicional de prefixos.

## Ordem de implantação

1. gerar e armazenar a chave HMAC no gerenciador de segredos;
2. criar backup;
3. executar migrations;
4. executar os dois comandos sem `--apply` e registrar as contagens;
5. proteger identificadores com `--apply`;
6. migrar registros locais com `--apply`;
7. auditar e resolver conflitos manualmente;
8. reconciliar contagens antes de qualquer cutover da Fase 3.
