# Backup e restauração

## Conteúdo do backup

O serviço `backup` cria diariamente:

- dump transacional do MySQL comprimido;
- arquivo comprimido do volume privado;
- `SHA256SUMS` para ambos;
- diretório UTC no formato `YYYYMMDDTHHMMSSZ`;
- remoção de diretórios além da retenção configurada.
- registro de sucesso ou falha em `backup_logs`, sem senha ou conteúdo clínico.

O volume Docker `backups` deve ser replicado para outro equipamento. Um volume no mesmo servidor não
protege contra falha física.

## Criptografia

Em produção, monte uma chave fora do repositório e exija criptografia:

```yaml
# compose.override.yml, armazenado somente no servidor
services:
  backup:
    volumes:
      - /srv/sync-sus/secrets/backup.key:/run/secrets/sync_sus_backup_key:ro
```

```dotenv
SYNC_SUS_BACKUP_REQUIRE_ENCRYPTION=true
SYNC_SUS_BACKUP_ENCRYPTION_KEY_FILE=/run/secrets/sync_sus_backup_key
```

Gere a chave com fonte criptográfica, limite a leitura ao administrador do servidor e mantenha uma
cópia lacrada em local diferente. Sem a chave, arquivos `.enc` não podem ser restaurados.

## Backup manual

```bash
docker compose run --rm -e BACKUP_RUN_ONCE=true backup
```

O container normal executa em ciclo diário. `BACKUP_RUN_ONCE=true` encerra o processo depois de um
único conjunto.

## Restauração

1. Pare `nginx`, `app`, `queue-worker` e `scheduler`.
2. Copie o conjunto escolhido para área de restauração.
3. Valide:

   ```bash
   sha256sum -c SHA256SUMS
   docker compose exec app php artisan sync-sus:backup-verify \
     /backups/20260724T120000Z --actor=administrador@instituicao.local
   ```

   A verificação automatizada restringe o caminho a `SYNC_SUS_BACKUP_PATH`, valida o manifesto, os
   dois hashes e a leitura integral dos arquivos compactados não criptografados. Em conjuntos
   criptografados, o hash é verificado antes da descriptografia.

4. Se o conjunto estiver criptografado, descriptografe em diretório temporário protegido:

   ```bash
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
     -in database.sql.gz.enc -out database.sql.gz \
     -pass file:/run/secrets/sync_sus_backup_key
   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
     -in private-files.tar.gz.enc -out private-files.tar.gz \
     -pass file:/run/secrets/sync_sus_backup_key
   ```

5. Recrie o banco vazio e restaure:

   ```bash
   gunzip -c database.sql.gz | docker compose exec -T mysql \
     mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"
   ```

6. Restaure `private-files.tar.gz` no volume privado, preservando permissões.
7. Suba a aplicação e execute:

   ```bash
   docker compose up -d
   docker compose exec app php artisan migrate --force
   docker compose exec app php artisan optimize
   ```

8. Valide `/health/ready`, autenticação, unidade ativa, filas, auditoria e um download privado
   autorizado.

## Teste periódico

Execute restauração trimestral em ambiente isolado. Registre conjunto, hashes, duração, responsável,
RPO observado, RTO observado e resultado. O comando de verificação registra a evidência preliminar
em `backup_verifications`; a restauração completa ainda deve ser registrada no roteiro de
homologação. Nunca teste restauração sobre a base de produção.
