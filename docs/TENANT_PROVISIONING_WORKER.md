# Worker isolado de provisionamento de tenants

O processo web nunca recebe `TENANT_PROVISIONING_USERNAME` ou
`TENANT_PROVISIONING_PASSWORD`. Ele apenas grava a intenção no Core e publica um job que
contém o `health_unit_public_id` na fila `tenant-provisioning`.

O worker deve ser um serviço/processo separado, sem tráfego HTTP público, com saída de
rede restrita ao MySQL e estas variáveis exclusivas no seu secret manager:

```dotenv
TENANT_PROVISIONING_WORKER=true
TENANT_PROVISIONING_QUEUE=tenant-provisioning
TENANT_PROVISIONING_HOST=mysql.internal
TENANT_PROVISIONING_PORT=3306
TENANT_PROVISIONING_DATABASE=mysql
TENANT_PROVISIONING_USERNAME=tenant_provisioner
TENANT_PROVISIONING_PASSWORD=secret-manager-reference
TENANT_PROVISIONING_SSL_CA=/run/secrets/mysql-ca.pem
TENANT_PROVISIONING_EXPECTED_PARTIAL_REVOKES=OFF
TENANT_PROVISIONING_REQUIRE_TLS=true

TENANT_RUNTIME_ACCOUNT_HOST=IP_OU_HOST_DA_APLICACAO
TENANT_RUNTIME_DB_HOST=mysql.internal
TENANT_RUNTIME_DB_PORT=3306
TENANT_RUNTIME_SSL_CA=/run/secrets/mysql-ca.pem
```

Executar exclusivamente a fila privilegiada:

```bash
php artisan queue:work --queue=tenant-provisioning --tries=5 --timeout=1800
```

A conta administrativa deve usar o host real desse worker e `REQUIRE SSL`; nunca `%`.
Ela possui alcance potencial sobre todos os schemas de unidade porque precisa repassar
privilégios com `GRANT OPTION`. Sua contenção é operacional: serviço separado, rede,
TLS, secret manager, fila exclusiva e auditoria por `tenant_database_events`.

Antes de qualquer DDL, o worker recusa a execucao se o servidor nao for MySQL 8.0 ou
superior, se `@@GLOBAL.partial_revokes` divergir do valor explicitamente confirmado em
`TENANT_PROVISIONING_EXPECTED_PARTIAL_REVOKES`, ou se a sessao administrativa estiver sem
TLS. O valor `ON`/`OFF` deve refletir o ambiente real e o grant previamente revisado; nao
use um valor presumido apenas para liberar o job.

Uma falha pode ser retomada sem gerar nova senha:

```bash
php artisan tenant:resume-provisioning ULID_DA_UNIDADE --apply
```

O comando mostra o proximo passo em dry-run e, com `--apply`, republica somente o
identificador publico. O worker retoma a mesma orquestracao convergente do estado atual.

Em `VALIDATING`, registre evidencia real de backup/restore antes de retomar:

```bash
php artisan tenant:record-continuity ULID_DA_UNIDADE ID_DA_VERIFICACAO REFERENCIA_DO_RESTORE ATOR --apply
```

O identificador de backup precisa apontar para um `backup_verifications` concluido,
compativel com restore e pertencente ao banco exato da unidade. O cutover permanece
fechado por padrao (`TENANT_AUTOMATIC_CUTOVER=false`).

O comando apenas republica o identificador público. O worker reaplica `CREATE DATABASE IF
NOT EXISTS`, `CREATE USER IF NOT EXISTS`, `ALTER USER` e `GRANT` usando a credencial já
cifrada no Core.
