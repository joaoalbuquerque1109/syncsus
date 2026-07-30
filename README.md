# SYNC SUS

Sistema web hospitalar para a porta de entrada de urgência e emergência, projetado para operação em
servidor local e acesso pela rede interna.

## Estado atual

As sete fases planejadas estão executáveis. A fundação inclui:

- Laravel 13, PHP 8.5, Blade, Alpine.js e Tailwind CSS;
- autenticação local sem cadastro público;
- sessão em banco e cache Redis com fallback automático para o banco;
- troca obrigatória de senha inicial;
- bloqueio temporário de tentativas de login;
- usuários ativos/inativos;
- papéis e permissões granulares;
- vínculo e seleção de unidade ativa;
- auditoria de login, falha, logout, senha e troca de unidade;
- health checks e armazenamento privado;
- Docker Compose com Nginx, aplicação, MySQL, Redis, worker, scheduler e backup;
- PHPUnit, Pint, Larastan, ESLint e Prettier.

Pacientes e recepção incluem:

- catálogos de setores, salas, pontos de atendimento, especialidades, formas de chegada, entradas
  e níveis de risco;
- cadastro completo e provisório de paciente, RG, passaporte, códigos IBGE, responsáveis legal e
  financeiro, múltiplos contatos e endereço;
- resumo clínico longitudinal com alergias, condições, medicamentos contínuos e histórico social;
- busca unificada por nome, prontuário, CPF ou CNS com documentos mascarados;
- normalização, validação e prevenção de duplicidade de CPF/CNS;
- registro auditável de acesso ao prontuário;
- wizard de recepção em três etapas, acompanhante e destino;
- geração atômica do prontuário, atendimento, histórico, senha e entrada em fila;
- idempotência de envio e bloqueio de atendimento ativo duplicado;
- comprovante imprimível e autorização por papel e unidade.

Filas e painel incluem:

- ordenação no backend, filtros e atualização automática da fila;
- chamada, rechamada, início, ausência, retorno e transferência transacionais;
- versão otimista e bloqueio de linha para impedir início concorrente;
- histórico imutável de chamadas, transições e transferências;
- configuração de filas, pontos permitidos e quantidade mínima de chamadas;
- painel por código técnico não sequencial, heartbeat e polling incremental;
- últimas chamadas, indicador de desconexão e áudio local por senha;
- modo de identificação configurável e payload público sem documentos ou dados clínicos.

Triagem inclui:

- início transacional a partir da senha chamada, com bloqueio e versão otimista;
- avaliação inicial em rascunho, alergias, medicamentos, riscos e alertas;
- histórico de aferições, unidades normalizadas e IMC calculado no backend;
- faixas técnicas configuráveis que validam a digitação sem classificar o paciente;
- protocolo, fluxograma, discriminador e risco confirmados pelo profissional;
- encaminhamento automático para a fila de destino e atualização do atendimento;
- finalização imutável, adendos append-only e auditoria dos eventos clínicos.

Atendimento médico inclui:

- início concorrente pela fila médica e vínculo exclusivo com o médico responsável;
- anamnese geral, exame físico, conduta e controle otimista de versão;
- diagnósticos principais e secundários com catálogo CID local ou hipótese descritiva;
- prescrição hospitalar e receita domiciliar finalizadas de forma imutável;
- solicitações de exames, mantendo resultados para a futura integração laboratorial;
- evoluções clínicas cronológicas e encaminhamentos internos ou externos;
- alta, observação, internação solicitada, transferência, evasão e óbito;
- finalização com hash do conteúdo, transição do episódio e adendos auditados.

Documentos e relatórios incluem:

- nove tipos de documentos clínicos, com conteúdo estruturado e PDF gerado localmente;
- armazenamento privado, download autorizado, SHA-256 e código público de verificação;
- novas versões append-only e anulação sem remoção do histórico;
- dashboard do plantão com indicadores e atendimentos ativos atualizados por polling;
- relatório de atendimentos por período, status, risco, especialidade, profissional e destinação;
- tempos médios e distribuições operacionais;
- exportações CSV e PDF auditadas, limitadas e mascaradas conforme a permissão do papel.

Revisão integrada e segurança incluem:

- teste automatizado do fluxo completo da recepção ao documento de alta;
- matriz completa de permissões e isolamento entre unidades;
- auditoria pesquisável e histórico de acessos ao prontuário;
- HTTPS obrigatório configurável, hosts confiáveis, CSP, HSTS, rate limits e limite de sessões;
- backup registrado, criptografia configurável, retenção e verificação de integridade;
- painel administrativo de continuidade, jobs, painéis e espaço livre;
- hardening de containers e procedimentos de contingência, homologação, treinamento e piloto.

Administração cadastral inclui usuários e seus acessos por unidade, profissionais de saúde com
conselho, registro, especialidade, RQE e CNES, além da manutenção de unidades, especialidades,
formas de chegada e tipos de entrada. A identificação profissional é exibida no atendimento e nos
documentos clínicos emitidos.

O planejamento das sete fases está em [docs/PLANO_IMPLEMENTACAO.md](docs/PLANO_IMPLEMENTACAO.md).

## Requisitos

- Docker 29+ com Docker Compose, ou
- PHP 8.5, Composer 2.9, Node.js 24 e MySQL 8.4.

## Execução com Docker

1. Copie o ambiente:

   ```bash
   cp .env.example .env
   ```

2. Defina senhas fortes para `DB_PASSWORD`, `DB_ROOT_PASSWORD` e as variáveis
   `SYNC_SUS_ADMIN_*`.

3. Construa a imagem:

   ```bash
   docker compose build
   ```

4. Gere uma chave e copie a saída completa para `APP_KEY` no `.env`:

   ```bash
   docker compose run --rm app php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
   ```

5. Suba os serviços e prepare o banco:

   ```bash
   docker compose up -d
   docker compose exec app php artisan migrate --seed
   ```

6. Acesse `http://localhost:8080`.

O administrador criado pelo seeder deve trocar a senha no primeiro acesso. Em produção, o seeder
recusa a execução se as variáveis do administrador estiverem ausentes ou a senha for fraca.

## Demonstração local com SQLite

Para avaliar o sistema sem MySQL, ative `SYNC_SUS_SEED_DEMO=true` em um ambiente `local` com
`DB_CONNECTION=sqlite` e execute:

```bash
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

O seeder demonstrativo é recusado em produção e cria somente dados sintéticos: usuários por perfil,
profissionais com CRM/COREN, pacientes com histórico clínico, filas, triagens, consultas,
destinações, auditoria e um documento PDF. As credenciais e a
composição dos cenários estão em [docs/DEMO_SQLITE.md](docs/DEMO_SQLITE.md).

## Desenvolvimento local

O banco principal continua sendo MySQL. SQLite em memória é usado apenas pelo PHPUnit.

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan serve
```

## Qualidade

```bash
composer quality
npm run lint
npm run format:check
npm run build
```

## Operação

- Health de processo: `GET /health/live`
- Health de prontidão: `GET /health/ready`
- Operação: [docs/OPERATIONS.md](docs/OPERATIONS.md)
- Backup e restauração: [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md)
- Decisões arquiteturais: [docs/DECISIONS.md](docs/DECISIONS.md)
- Cadastros assistenciais: [docs/CADASTROS_ASSISTENCIAIS.md](docs/CADASTROS_ASSISTENCIAIS.md)
- Segurança e LGPD: [docs/SEGURANCA_E_LGPD.md](docs/SEGURANCA_E_LGPD.md)
- Contingência: [docs/CONTINGENCIA.md](docs/CONTINGENCIA.md)
- Homologação e piloto: [docs/HOMOLOGACAO_E_PILOTO.md](docs/HOMOLOGACAO_E_PILOTO.md)
