# Teste de carga local

Stack isolada (mysql/redis/app/nginx/queue-worker/scheduler) para testar a
imagem de produção sob carga real, sem tocar no `.env` real nem no
`docker-compose.yml` do repo. `.env.loadtest` traz apenas segredos
descartáveis, válidos só para os containers efêmeros deste diretório.

## Passo a passo

```bash
# 1. Build das imagens (a partir da raiz do repo)
docker build --target app -t sync-sus-app:local .
docker build --target web -t sync-sus-web:local .

# 2. Subir a stack
docker compose -f loadtest/docker-compose.yml -p syncsus-loadtest up -d

# 3. Migrar e semear dados de demonstração
docker exec syncsus-loadtest-app-1 php artisan migrate:fresh --force
docker exec syncsus-loadtest-app-1 php artisan db:seed --force

# 4. Pegar o código do painel semeado
docker exec syncsus-loadtest-mysql-1 mysql -uroot -pLoadTestRoot123! \
  --default-character-set=utf8mb4 -e "USE sync_sus; SELECT public_code FROM panels;"

# 5. Rodar o k6 (mesma rede docker do nginx)
docker run --rm --network syncsus-loadtest_frontend \
  -e BASE_URL=http://nginx:80 \
  -e PANEL_CODE=<public_code do passo 4> \
  -v "$(pwd)/loadtest/loadtest.js:/loadtest.js" \
  grafana/k6 run /loadtest.js

# 6. Derrubar tudo
docker compose -f loadtest/docker-compose.yml -p syncsus-loadtest down -v
```

## Antes de rodar com volume alto

O endpoint público de painel (`/panels/{code}/state`) tem rate limit de
180 req/min por IP (`app/Providers/AppServiceProvider.php`, regra
`public-panels`). Como o k6 bate tudo de um único IP, isso derruba o teste
artificialmente. Suba temporariamente esse limite antes do build, rode o
teste, e reverta antes de commitar — nunca suba esse bypass para produção.

## Login usado no cenário autenticado

Login de recepcionista semeado pelo `DemoDataSeeder`:
`recepcao@syncsus.local` / `Demo#SyncSUS2026` (sobrescreva via
`STAFF_EMAIL`/`STAFF_PASSWORD` no `docker run` do k6 se necessário).
