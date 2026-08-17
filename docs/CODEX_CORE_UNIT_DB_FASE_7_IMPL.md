# Fase 7 - Registro de implementacao

Implementada em 2026-08-11. Esta fase automatiza o provisionamento nativo de uma unidade
nova sem pular a maquina de estados e sem ativar a unidade antes do cutover concluido.

## Fluxo implementado

1. `ProvisionTenantAction` grava organizacao, unidade inativa, usuario gestor, catalogos e
   intencao nativa na mesma transacao Core; o job e publicado depois do commit.
2. O worker isolado valida MySQL 8.0+, valor esperado de `partial_revokes` e TLS da sessao
   antes de executar DDL. Depois cria banco, credencial exclusiva e grants exatos.
3. `TenantDatabaseAutoProvisioner` aplica apenas migrations Tenant e converge
   `LEGACY -> SHADOW -> VALIDATING` com sincronizacao e reconciliacao.
4. Divergencia interrompe o fluxo no estado seguro. Falhas e pausas ficam registradas em
   `tenant_database_events`, sem expor segredos.
5. Em `VALIDATING`, uma verificacao de backup concluida, compativel e pertencente ao banco
   exato da unidade e obrigatoria. O restore tambem precisa de referencia explicita.
6. Uma reconciliacao final ocorre antes de `CUTOVER`. O gate
   `TENANT_AUTOMATIC_CUTOVER` e `false` por padrao. Somente depois de aprovado o fluxo
   alcanca `TENANT` e ativa a unidade.

O job e idempotente e retomavel. `tenant:resume-provisioning` usa a mesma orquestracao,
e seu modo padrao e somente leitura. `tenant:status` apresenta o proximo passo.

## Arquivos centrais

- `app/Support/Tenancy/TenantDatabaseAutoProvisioner.php`
- `app/Modules/Administration/Application/Jobs/ProvisionTenantDatabaseJob.php`
- `app/Support/Tenancy/TenantInfrastructureProvisioner.php`
- `app/Support/Tenancy/TenantDatabaseLifecycle.php`
- `routes/console.php`
- `config/tenancy.php`
- `tests/Feature/CoreUnitDatabasePhase7Test.php`
- `tests/Feature/TenantProvisioningTest.php`

## Variaveis obrigatorias no worker

Além das credenciais administrativas e runtime descritas em
`docs/TENANT_PROVISIONING_WORKER.md`:

```dotenv
TENANT_PROVISIONING_EXPECTED_PARTIAL_REVOKES=ON_OU_OFF_CONFIRMADO_NO_SERVIDOR
TENANT_PROVISIONING_REQUIRE_TLS=true
TENANT_AUTOMATIC_CUTOVER=false
```

O processo web nao recebe a credencial administrativa. O host de qualquer conta runtime
ou administrativa nunca pode ser `%`.

## Operacao

```bash
php artisan tenant:status
php artisan tenant:resume-provisioning ULID_DA_UNIDADE
php artisan tenant:resume-provisioning ULID_DA_UNIDADE --apply
php artisan tenant:record-continuity ULID_DA_UNIDADE ID_DA_VERIFICACAO REFERENCIA_DO_RESTORE ATOR --apply
```

Antes de habilitar cutover, confirme que o status esta em `VALIDATING`, que a evidencia de
continuidade pertence ao banco exato e que a reconciliacao esta limpa. A variavel de
cutover deve existir somente no worker autorizado durante a janela operacional.

## Validacao

Os testes da fase cobrem pausa por continuidade, rejeicao de evidencia arbitraria,
retomada, gate de cutover, ativacao apenas em `TENANT`, divergencia em `SHADOW`, isolamento
da credencial administrativa e recusa de DDL sem `partial_revokes` explicitamente
confirmado. A validacao completa deve permanecer verde antes de uso operacional.

## Limite operacional atual

O codigo esta pronto, mas nao presume configuracao externa. Em cada ambiente real ainda
e obrigatorio confirmar a versao do MySQL, o valor global de `partial_revokes`, o grant da
conta provisionadora, o host/CIDR de origem e a cadeia CA usada pelo TLS. Sem isso o worker
falha antes do primeiro DDL, intencionalmente.
