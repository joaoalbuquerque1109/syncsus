# Publicacao no Railway

O repositorio esta preparado para o Railway usar o `Dockerfile` e o `railway.json`.
Publique somente com um MySQL 8.4 e um volume persistente montado em
`/var/www/html/storage/app/private`.

## Servico web

Conecte o repositorio, adicione MySQL e configure:

```text
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://DOMINIO_PUBLICO
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_SECURE_COOKIE=true
SYNC_SUS_REQUIRE_HTTPS=true
SYNC_SUS_TRUSTED_PROXIES=*
SYNC_SUS_ADMIN_NAME=Administrador
SYNC_SUS_ADMIN_EMAIL=EMAIL_DO_ADMINISTRADOR
SYNC_SUS_ADMIN_PASSWORD=SENHA_TEMPORARIA_FORTE
SYNC_SUS_ADMIN_ACCESS_CODE=ADMIN
SYNC_SUS_SEED_DEMO=false
```

`RAILWAY_PUBLIC_DOMAIN` e `PORT` sao fornecidos pelo Railway. O host publico e
incluido automaticamente na lista de hosts confiaveis.

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

Confirme `/health/ready`, entre com o administrador, troque a senha temporaria,
cadastre a primeira unidade e valide recepcao, painel, triagem, atendimento,
documento e cancelamento em um ambiente de homologacao.
