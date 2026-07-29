# Estado da implementação

Atualizado em 24/07/2026.

## Fase 1 — Fundação e autenticação

- [x] Bootstrap Laravel 13 e PHP 8.5
- [x] MySQL 8.4, Nginx e PHP-FPM
- [x] Blade, Alpine.js, Tailwind CSS e Vite
- [x] Docker Compose com `nginx`, `app`, `mysql`, `queue-worker`, `scheduler` e `backup`
- [x] Organização, unidade, usuários e vínculo por unidade
- [x] Papéis e permissões iniciais
- [x] Login local e logout
- [x] Rate limit de login
- [x] Usuário inativo
- [x] Troca obrigatória de senha inicial
- [x] Sessões e cache em banco
- [x] Unidade ativa validada no backend
- [x] Auditoria de eventos de identidade
- [x] Layout principal e telas de autenticação
- [x] Armazenamento privado e health checks
- [x] PHPUnit, Pint, Larastan, ESLint e Prettier configurados
- [x] Migração limpa e seed validados
- [x] PHPUnit: 20 testes e 84 asserções
- [x] Pint executado sem falhas
- [x] Larastan nível 6 executado sem falhas
- [x] ESLint e Prettier executados sem falhas
- [x] Build frontend executado
- [x] `docker compose config` validado
- [ ] Build da imagem Docker não executado: daemon do Docker Desktop indisponível no ambiente

## Fases funcionais

- [x] Fase 2 — Pacientes e recepção
- [x] Fase 3 — Filas e painel de chamadas
- [x] Fase 4 — Triagem
- [x] Fase 5 — Atendimento médico
- [x] Fase 6 — Documentos e relatórios
- [x] Fase 7 — Revisão integrada e segurança

## Fase 6 — Documentos e relatórios

- [x] Contrato interno de renderização e Dompdf 3.1.6
- [x] Nove tipos de documento clínico
- [x] PDF e HTML privados, hash SHA-256 e código de verificação
- [x] Download autorizado sem cache público
- [x] Histórico imutável de versões e anulação preservando arquivos
- [x] Dashboard operacional com polling e mascaramento por papel
- [x] Relatórios com filtros, tempos médios e limite de volume
- [x] Exportação CSV/PDF com mesma consulta e auditoria
- [x] Isolamento por unidade e período máximo validados
- [x] Testes da fase: 2 testes e 68 asserções

## Fase 7 — Revisão integrada e segurança

- [x] Fluxo automatizado recepção → triagem → médico → alta → documento
- [x] Matriz exata de permissões sem privilégios implícitos
- [x] Isolamento por unidade em auditoria, relatórios e registros clínicos
- [x] Auditoria pesquisável e acessos ao prontuário
- [x] Sanitização central de segredos no contexto auditável
- [x] HTTPS obrigatório, hosts/proxies confiáveis, HSTS, CSP e rate limits
- [x] Limite configurável de sessões simultâneas
- [x] Backup registrado, retenção, criptografia configurável e verificação de hashes
- [x] Tela administrativa de continuidade e observabilidade básica
- [x] Índices adicionais para relatórios e auditoria
- [x] Containers sem privilégio e aplicação com filesystem raiz somente leitura
- [x] Planos de contingência, homologação, treinamento e piloto
- [x] PHPUnit: 51 testes e 536 asserções
- [x] Pint e Larastan nível 6 sem falhas
- [x] ESLint, Prettier, build frontend e configuração Docker validados

## Revisão Clean Code

- [x] Casos de uso de escrita estão em Actions
- [x] Controllers apenas recebem, delegam e respondem
- [x] Validação HTTP está em Form Requests
- [x] Não há parâmetros booleanos ambíguos nos casos de uso
- [x] Não há captura silenciosa de exceção
- [x] Não há lógica de negócio em Blade ou JavaScript
- [x] Decisões não óbvias estão documentadas
- [x] Seeders contêm apenas dados fictícios
- [x] Ferramentas automáticas executadas sem falhas
