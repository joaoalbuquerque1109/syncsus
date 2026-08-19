# Checklist de ativação do provisionamento nativo

Use esta sequência para ativar o provisionamento de bancos por unidade em produção. Não avance enquanto a validação do passo atual falhar.

## Antes do deploy

- [ ] Criar no MySQL uma conta administrativa exclusiva para provisionamento, com apenas os privilégios necessários para criar os bancos `tenant_*`, criar as contas de runtime e conceder privilégios nesses bancos.
- [ ] Confirmar que essa conta não é a conta usada pela aplicação web e registrar o segredo no gerenciador de variáveis do serviço isolado.
- [ ] Habilitar e validar TLS no MySQL do Railway.
- [ ] Disponibilizar o arquivo da CA dentro do container e anotar seu caminho absoluto para `TENANT_PROVISIONING_SSL_CA` e, quando aplicável, `TENANT_RUNTIME_SSL_CA`.
- [ ] Confirmar na topologia privada do Railway o host de origem das conexões de runtime e escolher um valor explícito e restrito para `TENANT_RUNTIME_ACCOUNT_HOST`; não usar `%`.

## Serviço isolado no Railway

- [ ] Criar um serviço a partir da mesma imagem da aplicação, sem endpoint público.
- [ ] Definir `SYNC_SUS_RAILWAY_MODE=tenant-worker`. Esse modo inicia somente o `queue-worker` da fila `tenant-provisioning`; o valor padrão `web` continua iniciando PHP-FPM, nginx, o worker geral e o scheduler.
- [ ] Configurar nesse serviço `TENANT_PROVISIONING_HOST`, `TENANT_PROVISIONING_PORT`, `TENANT_PROVISIONING_DATABASE`, `TENANT_PROVISIONING_USERNAME` e `TENANT_PROVISIONING_PASSWORD`.
- [ ] Configurar `TENANT_PROVISIONING_SSL_CA`, `TENANT_RUNTIME_SSL_CA` quando necessário e `TENANT_RUNTIME_ACCOUNT_HOST` com os valores validados anteriormente.
- [ ] Configurar a mesma conexão de fila usada pelo serviço web.
- [ ] Definir `TENANT_PROVISIONING_EXPECTED_PARTIAL_REVOKES=OFF`, correspondente ao valor previamente medido no servidor.
- [ ] Manter `TENANT_PROVISIONING_WORKER=false` e não disponibilizar nenhuma variável administrativa no serviço web.

## Deploy e ativação

- [ ] Fazer o deploy do serviço isolado e confirmar nos logs que apenas `queue:work --queue=tenant-provisioning --sleep=2 --tries=1 --timeout=120` foi iniciado.
- [ ] Definir `TENANT_PROVISIONING_WORKER=true` somente no serviço isolado e reiniciá-lo.
- [ ] Criar uma unidade de validação pelo fluxo administrativo e acompanhar o job até `infrastructure_status=grants_applied`.
- [ ] No MySQL, confirmar que o banco `tenant_*` e a conta `tu_*` foram criados, que a conta exige TLS e que seus privilégios se limitam ao banco da unidade.
- [ ] Confirmar novamente que o serviço web permanece sem credenciais administrativas, com `TENANT_PROVISIONING_WORKER=false`.

O corte automático permanece desabilitado; não altere `TENANT_AUTOMATIC_CUTOVER` durante esta ativação.
