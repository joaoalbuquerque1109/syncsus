# Operação

## Serviços

- `nginx`: única porta HTTP exposta;
- `app`: PHP-FPM e aplicação;
- `mysql`: rede interna, sem porta publicada;
- `redis`: cache descartável; a aplicação volta ao cache MySQL em caso de falha;
- `queue-worker`: jobs persistidos em banco;
- `scheduler`: agendador do Laravel;
- `backup`: dump diário, arquivos privados, hash e retenção.

## Verificações

```bash
docker compose ps
curl http://localhost:8080/health/live
curl http://localhost:8080/health/ready
docker compose logs --tail=100 app queue-worker scheduler
docker compose exec redis redis-cli ping
```

`live` confirma o processo HTTP. `ready` verifica banco e leitura/escrita no armazenamento privado.
Uma falha no Redis degrada o desempenho, mas não deve interromper o atendimento.

## Atualização

1. Confirme espaço em disco e último backup.
2. Ative o modo de manutenção.
3. Construa as novas imagens.
4. Execute migrations.
5. Limpe e recrie caches.
6. Execute health checks e testes de fumaça.
7. Desative o modo de manutenção.

```bash
docker compose exec app php artisan down
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize
docker compose exec app php artisan up
```

## Segurança de produção

- publicar somente HTTPS no proxy interno;
- configurar `SYNC_SUS_REQUIRE_HTTPS=true`, `SYNC_SUS_TRUSTED_HOSTS` e apenas os IPs reais do proxy
  em `SYNC_SUS_TRUSTED_PROXIES`;
- definir `SESSION_SECURE_COOKIE=true`;
- manter `APP_DEBUG=false`;
- restringir servidor por firewall/VPN;
- não compartilhar `.env`, dumps ou arquivos privados;
- revisar usuários inativos e vínculos por unidade;
- monitorar falhas de login e jobs falhos.

O middleware rejeita operações de escrita recebidas por HTTP e redireciona apenas métodos seguros.
HSTS é enviado somente em conexão reconhecida como HTTPS. O proxy deve sobrescrever
`X-Forwarded-Proto`; nunca aceite esse cabeçalho diretamente de uma rede não confiável.

### TLS interno

O certificado deve conter o nome definido em `APP_URL`, por exemplo `syncsus.local`. Use certificado
emitido pela autoridade interna da instituição e distribua a cadeia confiável aos computadores. O
Nginx da aplicação pode permanecer na rede privada atrás do terminador TLS; firewall deve impedir
acesso direto à porta HTTP do container.

### Containers

- aplicação, worker e scheduler usam filesystem raiz somente leitura, `tmpfs` para temporários e
  nenhuma capability Linux;
- banco permanece apenas na rede interna `backend`;
- todos os serviços usam `no-new-privileges`;
- não execute containers com `privileged: true`;
- atualize imagens de forma controlada, sempre com backup e teste em homologação.

## Auditoria e continuidade

- **Auditoria** mostra eventos e acessos ao prontuário somente da unidade ativa;
- **Administração → Operação** mostra último backup, última verificação, jobs, painéis e espaço livre;
- contexto de auditoria é sanitizado centralmente para senha, token, cookie, sessão e segredo;
- falhas de backup são registradas com mensagem genérica, sem credenciais;
- nunca copie exceções de jobs ou dumps para chamados externos sem revisão e anonimização.

## Recuperação de senha offline

Um administrador ativo com `administration.manage` pode redefinir uma senha no próprio servidor:

```bash
docker compose exec app php artisan sync-sus:user-reset-password usuario@instituicao.local \
  --actor=administrador@instituicao.local
```

As senhas são digitadas de forma oculta. A operação encerra as sessões do usuário, exige troca no
próximo login e registra auditoria sem gravar a senha.

## Filas e painéis

- A tela autenticada de filas atualiza os dados a cada cinco segundos.
- O painel público usa polling incremental a cada dois segundos e envia heartbeat a cada quinze.
- O código público do painel é uma credencial técnica de alta entropia. Não publique esse endereço
  fora da rede interna.
- Após alterar `APP_KEY`, execute novamente o seeder e reprovisione o endereço dos painéis.
- O navegador exige interação para liberar áudio. Na abertura do painel, use **Ativar áudio**.

O endpoint público retorna somente evento, senha, identificação pública configurada, destino,
horário e indicação de rechamada. Prontuário, CPF, CNS, telefone, endereço, diagnóstico e risco não
fazem parte do contrato.

### Índices críticos

A consulta operacional usa o índice composto
`queue_entries_queue_id_status_priority_weight_entered_at_index` para restringir fila e situação
antes de ordenar prioridade e entrada. O polling do painel usa
`queue_calls_queue_id_id_index`, permitindo buscar apenas eventos posteriores ao cursor recebido.
Também existem índices únicos para `panels.public_code` e `queue_calls.public_id`, além do índice de
`panels.last_heartbeat_at`.

Verificação recomendada no MySQL:

```sql
EXPLAIN
SELECT id, ticket_number, priority_weight, entered_at
FROM queue_entries
WHERE queue_id = 1 AND status = 'waiting'
ORDER BY priority_weight DESC, entered_at ASC
LIMIT 100;

EXPLAIN
SELECT id, public_id, ticket_snapshot, called_at
FROM queue_calls
WHERE queue_id IN (1) AND id > 0
ORDER BY id ASC
LIMIT 20;
```

O plano deve iniciar pelos índices compostos acima. Se o volume local crescer, acompanhe o
`filesort` da ordenação mista de prioridade decrescente e entrada crescente antes de alterar a
estratégia.

Relatórios por período usam `encounters_health_unit_id_arrival_at_index`. Auditoria e acessos usam
índices compostos de unidade, usuário, paciente e horário. O dashboard limita a lista ativa a 20
episódios; exportações são limitadas a 1.000 linhas, 366 dias e 20 solicitações por minuto por
usuário.

Os indicadores do dashboard usam o índice
`encounters_unit_status_closed_at_index`, uma consulta agregada por unidade e
cache curto de três segundos. As chaves incluem organização e unidade para não
misturar dados entre tenants.
