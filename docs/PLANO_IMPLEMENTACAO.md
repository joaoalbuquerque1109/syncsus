# Plano de implementação do SYNC SUS

## Estratégia

O produto será entregue como monólito modular, em sete incrementos utilizáveis. Cada fase só é
considerada pronta após migração limpa, seed idempotente, testes de comportamento, autorização,
auditoria, análise estática, lint e build dos assets.

## Fase 1 — Fundação e autenticação

**Status:** concluída.

**Objetivo:** estabelecer uma base segura e operacional para todos os módulos.

Entregas:

- Laravel, MySQL, Nginx, Blade, Alpine, Tailwind e Vite;
- Docker Compose com aplicação, banco, worker, scheduler e backup;
- organização e unidade mínimas;
- usuários, papéis, permissões e vínculo por unidade;
- login local, logout, usuário inativo e troca obrigatória de senha;
- rate limit, sessões em banco e cookies seguros configuráveis;
- unidade ativa validada no backend;
- auditoria dos eventos de identidade;
- layout institucional responsivo e acessível;
- armazenamento privado, health checks e ferramentas de qualidade.

Aceite:

- usuário válido entra e acessa somente unidade vinculada;
- usuário inativo e credencial inválida não entram;
- primeiro acesso exige nova senha forte;
- excesso de tentativas é bloqueado temporariamente;
- login, falha, logout, senha e troca de unidade ficam auditados;
- `/health/live` e `/health/ready` respondem conforme as dependências.

## Fase 2 — Pacientes e recepção

**Status:** concluída.

**Objetivo:** concluir o fluxo administrativo desde a chegada até a entrada na primeira fila.

Entregas:

- catálogos administrativos operacionais: setores, salas, pontos, especialidades, entradas,
  chegadas e níveis de risco;
- cadastro completo, busca unificada e edição de paciente;
- CPF/CNS normalizados, mascaramento e detecção de duplicidade;
- paciente provisório e responsáveis;
- wizard de recepção, acompanhante e revisão;
- número de prontuário, atendimento e senha gerados atomicamente;
- idempotência, prevenção de atendimento ativo duplicado e comprovante;
- auditoria de acesso ao paciente e abertura.

Aceite:

- recepcionista localiza ou cria paciente, finaliza uma única abertura e o atendimento chega à
  fila correta com número e senha únicos.

## Fase 3 — Filas e painel de chamadas

**Status:** concluída.

**Objetivo:** organizar espera, chamada e direcionamento sem expor dados indevidos.

Entregas:

- filas configuráveis e ordenação no backend;
- chamada, rechamada, retorno, ausência e transferência;
- concorrência com bloqueio de linha e versão;
- pontos de atendimento e histórico completo de chamadas;
- painel por código público, heartbeat e polling incremental;
- identificação configurável, payload mínimo e áudio offline por senha;
- indicadores de desconexão e prevenção de repetição após recarga.

Aceite:

- dois profissionais não iniciam a mesma entrada;
- o painel recebe chamada nova rapidamente e nunca recebe CPF, CNS ou dados clínicos.

## Fase 4 — Triagem

**Status:** concluída.

**Objetivo:** registrar avaliação inicial e classificação de risco com segurança clínica.

Entregas:

- início concorrente a partir da fila;
- queixa, história, protocolo, discriminador, alergias e alertas;
- sinais vitais históricos, unidades e IMC calculado no backend;
- limites técnicos configuráveis sem decisão clínica automática;
- classificação de risco confirmada pelo profissional;
- encaminhamento pós-triagem, finalização imutável e adendo;
- testes de permissão, conflito e transição.

Aceite:

- profissional autorizado chama, registra, classifica e envia o atendimento ao destino correto;
- registro final não é silenciosamente alterado.

## Fase 5 — Atendimento médico

**Status:** concluída.

**Objetivo:** cobrir o registro clínico e a destinação do episódio.

Entregas:

- fila médica, chamada e início concorrente;
- resumo do episódio, anamnese e exame físico;
- diagnósticos, conduta, prescrição hospitalar e receita;
- pedidos/resultados manuais de exames, evoluções e encaminhamentos;
- rascunho, versão, conflito, finalização e adendo;
- alta, observação, internação solicitada, transferência, evasão e óbito;
- máquina de estados e imutabilidade clínica.

Aceite:

- médico conclui atendimento com requisitos mínimos e destinação explícita;
- conteúdo finalizado preserva versão, autor e histórico.

## Fase 6 — Documentos e relatórios

**Status:** concluída.

**Objetivo:** produzir saídas clínicas e gerenciais autorizadas.

Entregas:

- contrato interno de renderização e adaptador Dompdf compatível;
- receita, atestado, declaração, orientação de alta e resumo;
- PDF privado, versão, SHA-256, código de verificação e download autorizado;
- dashboard operacional com polling;
- relatórios por período, status, risco, especialidade, profissional e destinação;
- tempos médios, chamadas e ausências;
- exportação CSV/PDF auditada e mascarada.

Aceite:

- documento não é acessível por URL de storage;
- relatórios respeitam unidade, papel, período e volume.

## Fase 7 — Revisão integrada e segurança

**Status:** concluída.

**Objetivo:** homologar o fluxo ponta a ponta e preparar operação real.

Entregas:

- testes integrados do caminho recepção → triagem → médico → destinação;
- matriz completa de autorização e testes de isolamento por unidade;
- revisão LGPD, logs, uploads, CSRF, headers, cookies e segredos;
- auditoria pesquisável e acessos ao prontuário;
- backup, restauração verificada e política de retenção;
- testes básicos de carga e consultas críticas;
- hardening de containers, HTTPS interno e documentação de contingência;
- roteiro de homologação, treinamento e piloto.

Aceite:

- fluxo completo funciona apenas com dados fictícios;
- restauração é testada;
- nenhuma pendência crítica de segurança, concorrência ou integridade clínica permanece.

## Dependências entre fases

```text
Fundação/autenticação
    → Pacientes/recepção
        → Filas/painel
            → Triagem
                → Atendimento médico
                    → Documentos/relatórios
                        → Revisão integrada/segurança
```
