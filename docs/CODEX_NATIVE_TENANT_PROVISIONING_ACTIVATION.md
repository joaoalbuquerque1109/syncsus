# Ativação do provisionamento nativo de banco por unidade

> Documento de planejamento. Nenhum código foi alterado ao produzi-lo. Todo achado sobre
> o estado atual foi confirmado no código-fonte e, quando indicado, medido diretamente em
> produção (consultas somente leitura, sem persistir nem alterar dado nenhum).

## 1. Contexto

Hoje, criar uma unidade pelo formulário administrativo (`ProvisionTenantAction` →
`TenantDatabaseLifecycle::registerNative()`) falha com
`LogicException: O host da credencial de runtime deve ser explícito e restrito.`, porque
o provisionamento nativo de banco dedicado por unidade nunca foi ativado — está
deliberadamente desligado (`TENANT_PROVISIONING_WORKER=false`) desde que essa
funcionalidade foi construída. A unidade "Super Unidade" existente hoje em produção usa a
conexão legada/compartilhada (`core`), criada manualmente, não por este caminho.

Este documento cobre o que falta para ativar o caminho real de provisionamento nativo —
**não** o modelo arquitetural de bancos-por-unidade como um todo (isso é a iniciativa
maior descrita em `docs/CODEX_CORE_UNIT_DB_ROADMAP.md`; aqui a arquitetura de conexão já
existe e está implementada, só nunca foi ligada).

## 2. Estado atual confirmado

| Peça | Estado | Evidência |
|---|---|---|
| Versão do MySQL | OK — 9.4.0, exige ≥ 8.0.0 | `SELECT VERSION()` em produção |
| `partial_revokes` do servidor | `OFF` (0) | `SELECT @@GLOBAL.partial_revokes` em produção |
| TLS entre app e MySQL | **Ausente.** Nenhuma conexão do sistema usa TLS hoje, incluindo a conexão normal do app | `SHOW SESSION STATUS LIKE 'Ssl_cipher'` retornou vazio na conexão `core` em produção |
| Credencial administrativa (`TENANT_PROVISIONING_*`) | **Não configurada.** Nenhuma variável de ambiente definida | `config('tenancy.native_provisioning.administrative_connection')` — todos os campos vêm de `env()` sem valor |
| `TENANT_RUNTIME_ACCOUNT_HOST` | **Não configurada** (vazio) | Causa direta do `LogicException` observado |
| Worker isolado da fila `tenant-provisioning` | **Não existe.** O único `queue-worker` do sistema escuta `integrations,default` | `docker/railway/supervisord.conf:33` |

`TenantInfrastructureProvisioner::provision()` (`app/Support/Tenancy/TenantInfrastructureProvisioner.php:24-26`)
já recusa rodar a menos que `tenancy.native_provisioning.worker_enabled` esteja `true` —
essa é uma trava de segurança deliberada no próprio código, não um bug: a credencial
administrativa (que pode `CREATE DATABASE`/`CREATE USER`) só deve existir dentro de um
processo isolado do processo que serve requisições web, comentário explícito no código-fonte
("Essas credenciais pertencem exclusivamente ao worker de provisionamento isolado").

## 3. Decisões e ações que ficam **fora do alcance do Codex**

O Codex trabalha no repositório de código. As ações abaixo são operacionais/infra —
executadas por um operador humano via Railway CLI/dashboard e MySQL diretamente, **antes**
ou **depois** do código ser aplicado, nunca pelo próprio Codex:

1. **Criar a credencial MySQL administrativa** — um usuário novo, dedicado, com
   `CREATE`, `GRANT OPTION` restrito ao necessário (nunca reaproveitar a credencial atual
   do app). Definir `TENANT_PROVISIONING_HOST/_PORT/_DATABASE/_USERNAME/_PASSWORD`.
2. **Obter/configurar TLS no MySQL do Railway** e definir `TENANT_PROVISIONING_SSL_CA`
   (e, se aplicável, `TENANT_RUNTIME_SSL_CA`) com o certificado correto. Sem isso,
   `assertServerEnvironment()` continua recusando a conexão administrativa
   (`TENANT_PROVISIONING_REQUIRE_TLS=true` por padrão).
3. **Determinar o valor de `TENANT_RUNTIME_ACCOUNT_HOST`** — precisa refletir de onde as
   credenciais de runtime de cada unidade efetivamente conectam na rede privada do
   Railway. Não é uma decisão de código; exige checar a topologia de rede real do projeto
   no Railway antes de restringir o host.
4. **Criar o serviço Railway isolado** que roda o worker da fila `tenant-provisioning`
   (mesma imagem, comando diferente) e só nele definir as variáveis administrativas do
   item 1 — o serviço web principal nunca deve ter essas variáveis.
5. **Definir `TENANT_PROVISIONING_EXPECTED_PARTIAL_REVOKES=OFF`** — já confirmado pela
   medição da seção 2, mas é uma variável de ambiente, não código.
6. Após o código deste documento estar em produção: `TENANT_PROVISIONING_WORKER=true`
   **somente no serviço isolado**, nunca no serviço web.

O prompt da seção 6 não pede ao Codex para tocar em nenhuma dessas seis ações.

## 4. Escopo do Codex (somente código)

1. **Definição do serviço/worker isolado como código versionado**: um novo
   `docker/railway/supervisord.tenant-worker.conf` (um único `[program]`, comando
   `php artisan queue:work --queue=tenant-provisioning --sleep=2 --tries=1 --timeout=120`
   — `--tries=1` porque `TenantInfrastructureProvisioner::provision()` já marca `failed`
   e propaga a exceção; reexecutar automaticameante a mesma DDL não é seguro) e o ajuste
   mínimo necessário no `Dockerfile`/`docker/railway/start.sh` para que a mesma imagem
   saiba iniciar como worker isolado (variável de ambiente ou argumento de
   `startCommand`, decisão do Codex, documentada no PR) em vez do supervisord completo do
   serviço web. Não duplicar `nginx`/`php-fpm`/`scheduler` nesse processo — o worker
   isolado só precisa do `queue-worker` dedicado.
2. **Confirmar/ajustar a leitura de `TENANT_PROVISIONING_SSL_CA` e `TENANT_RUNTIME_SSL_CA`**
   em `config/tenancy.php` — hoje já existe (`array_filter` sobre `Mysql::ATTR_SSL_CA`),
   validar que funciona corretamente quando a variável aponta para um caminho de arquivo
   real dentro do container (o Codex não gera o certificado, só garante que o código lê e
   usa o caminho configurado).
3. **Teste de integração do caminho feliz completo**, usando o padrão já existente em
   `tests/Feature/TenantProvisioningTest.php` (`test_native_worker_refuses_ddl_without_explicit_partial_revokes_expectation`
   já cobre o caminho de recusa) — adicionar o caminho de sucesso: com
   `tenancy.native_provisioning.worker_enabled=true` e uma conexão administrativa MySQL
   real via `TENANT_TEST_DB_CONNECTION=mysql` (mesmo mecanismo que os testes de CI já usam
   para pegar bugs de cross-connection), provisionar de ponta a ponta e assertar
   `infrastructure_status` chegando em `grants_applied`, `CREATE DATABASE`/`CREATE USER`
   efetivos, e que a credencial fica restrita ao banco criado (não `*.*`).
4. **Documentação operacional** (`docs/`, sem tocar nos arquivos `CODEX_CORE_UNIT_DB_*`
   já existentes): um novo documento curto listando, em ordem, os passos manuais da seção
   3 deste arquivo, para o operador seguir ao ativar em produção.

## 5. Fora de escopo

- Qualquer mudança em `TenantInfrastructureProvisioner`, `TenantDatabaseLifecycle` ou
  `ProvisionTenantDatabaseJob` além do necessário para o teste do item 3 — a lógica de
  provisionamento já existe e já está correta; isto é ativação, não reescrita.
- Corte automático (`TENANT_AUTOMATIC_CUTOVER`) — permanece desligado.
- Qualquer alteração em `TENANT_LEGACY_CONNECTION` ou no fluxo de unidades que continuam
  na conexão compartilhada — nada muda para elas.
- Criar, rodar ou testar contra credenciais MySQL reais de produção — os testes do item 3
  rodam contra o MySQL de CI/local, nunca contra produção.

## 6. Prompt para o Codex (a ser entregue somente após autorização explícita)

```
Contexto: o SyncHosp tem provisionamento nativo de banco dedicado por unidade totalmente
implementado (TenantInfrastructureProvisioner, TenantDatabaseLifecycle,
ProvisionTenantDatabaseJob), mas nunca foi ativado em produção — está desligado por
TENANT_PROVISIONING_WORKER=false. Isso está documentado em detalhe em
docs/CODEX_NATIVE_TENANT_PROVISIONING_ACTIVATION.md. Leia esse documento inteiro antes de
começar.

Seu escopo é SOMENTE a seção 4 desse documento (código), nesta ordem:
1. Criar docker/railway/supervisord.tenant-worker.conf com um único [program:queue-worker]
   rodando `php artisan queue:work --queue=tenant-provisioning --sleep=2 --tries=1
   --timeout=120`, e o ajuste mínimo no Dockerfile/docker/railway/start.sh para a mesma
   imagem poder iniciar como esse worker isolado em vez do supervisord completo do
   serviço web. Documente no PR exatamente qual variável de ambiente ou argumento decide
   qual modo iniciar.
2. Confirme que TENANT_PROVISIONING_SSL_CA e TENANT_RUNTIME_SSL_CA em config/tenancy.php
   funcionam corretamente quando apontam para um arquivo de certificado real dentro do
   container — ajuste só se encontrar um bug real, não refatore o que já funciona.
3. Adicione o teste de caminho feliz descrito na seção 4, item 3, em
   tests/Feature/TenantProvisioningTest.php, seguindo o padrão já usado no arquivo (rodar
   com TENANT_TEST_DB_CONNECTION=mysql real, não SQLite) — sem apagar ou modificar os
   testes de recusa já existentes.
4. Escreva o documento operacional descrito na seção 4, item 4 — passos manuais, em
   ordem, sem repetir prosa que já existe na seção 3 deste documento, só transformá-la em
   checklist executável.

NÃO toque em TenantInfrastructureProvisioner.php, TenantDatabaseLifecycle.php,
ProvisionTenantDatabaseJob.php, TENANT_LEGACY_CONNECTION, TENANT_AUTOMATIC_CUTOVER, nem em
nenhum arquivo docs/CODEX_CORE_UNIT_DB_*.md existente. Não crie, rode nem tente conectar a
nenhuma credencial MySQL real de produção — os testes novos rodam exclusivamente contra o
MySQL de CI/local. Rode a suíte completa e phpstan antes de considerar qualquer item
concluído.
```
