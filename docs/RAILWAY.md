# Publicacao no Railway

O repositorio esta preparado para o Railway usar o `Dockerfile` e o `railway.json`.
Publique somente com um MySQL 8.4. Para persistir documentos e backups, conecte
um volume ao servico web e monte-o em `/var/www/html/storage/app`.

## Servico web

Conecte o repositorio e adicione um MySQL ao mesmo projeto. Gere o dominio
publico antes de importar as variaveis. No editor RAW do servico web, configure:

```text
APP_NAME="SYNC SUS"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}
APP_TIMEZONE=America/Fortaleza
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
APP_FAKER_LOCALE=pt_BR
DB_CONNECTION=mysql
DB_URL=${{MySQL.MYSQL_URL}}
LOG_CHANNEL=stderr
LOG_LEVEL=warning
LOG_STDERR_FORMATTER=\Monolog\Formatter\JsonFormatter
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local_private
MAIL_MAILER=log
SYNC_SUS_REQUIRE_HTTPS=true
SYNC_SUS_ADMIN_NAME=Administrador
SYNC_SUS_ADMIN_EMAIL=EMAIL_DO_ADMINISTRADOR
SYNC_SUS_ADMIN_PASSWORD=SENHA_TEMPORARIA_FORTE
SYNC_SUS_ADMIN_ACCESS_CODE=ADMIN
SYNC_SUS_SEED_DEMO=false
SYNC_SUS_BACKUP_PATH=/var/www/html/storage/app/backups
SYNC_SUS_BACKUP_RETENTION_DAYS=14
SYNC_SUS_BACKUP_REQUIRE_ENCRYPTION=false
SYNC_SUS_PANEL_POLL_SECONDS=2
SYNC_SUS_DASHBOARD_POLL_SECONDS=15
SYNC_SUS_MAX_CONCURRENT_SESSIONS=1
```

Gere `APP_KEY` localmente com `php artisan key:generate --show`. Use uma senha
administrativa com pelo menos 12 caracteres, maiuscula, minuscula, numero e
simbolo.

Nao defina manualmente `PORT`, `RAILWAY_PUBLIC_DOMAIN`, `RAILWAY_SERVICE_ID`,
`SYNC_SUS_TRUSTED_HOSTS` ou `SYNC_SUS_TRUSTED_PROXIES`. O Railway fornece os
tres primeiros e a aplicacao configura automaticamente o dominio publico, o
host de healthcheck e os proxies.

O pre-deploy executa migrations e seeders idempotentes. O healthcheck de
ativacao usa `/health/live`; depois da publicacao, `/health/ready` valida MySQL
e o volume privado.

## Volume privado

Conecte um volume ao servico web com o caminho:

```text
/var/www/html/storage/app
```

O processo de inicializacao prepara as permissoes do volume como `root` e
executa PHP-FPM como `www-data`. Nao configure `RAILWAY_RUN_UID=0`: o container
ja faz a transicao de privilegios internamente.

## Worker e agendador

Crie um segundo servico a partir do mesmo repositorio e sobrescreva o comando:

```text
php artisan queue:work --sleep=3 --tries=3 --timeout=120
```

Para o agendador, crie um servico cron com:

```text
php artisan schedule:run
```

e periodicidade de um minuto. Os tres servicos devem compartilhar as mesmas
variaveis de aplicacao, banco e volume privado quando houver acesso a documentos.

## Antes de liberar acesso

Confirme `/health/live` e `/health/ready`, entre com o administrador, troque a
senha temporaria, cadastre a primeira unidade e valide recepcao, painel,
triagem, atendimento, documento e cancelamento em um ambiente de homologacao.
