# Decisões arquiteturais

## ADR-001 — Laravel 13 e PHP 8.5

Adotados por serem versões estáveis compatíveis com o ambiente em 23/07/2026. O projeto usa
restrições semânticas no Composer para receber correções compatíveis.

## ADR-002 — Monólito modular

O domínio é dividido em módulos dentro de uma única aplicação e um único banco. Isso preserva
transações entre atendimento, fila e auditoria e reduz a complexidade da implantação local.

## ADR-003 — Unidade ativa na sessão, sempre revalidada

O identificador da unidade ativa fica na sessão, mas cada requisição revalida o vínculo do usuário,
a ativação da unidade e a ativação da organização. Remover um vínculo passa a valer imediatamente.

## ADR-004 — Auditoria síncrona para identidade

Eventos de login, falha, logout, senha e troca de unidade são persistidos de forma síncrona. A
identidade é uma fronteira crítica e não deve confirmar a ação contando apenas com entrega futura
por fila.

## ADR-005 — Recuperação de senha offline por administrador

Não há fluxo de e-mail no MVP. A redefinição será uma ação administrativa auditada. Não existe
cadastro público nem recuperação baseada em serviço externo.

## ADR-006 — Dompdf na Fase 6

Em julho de 2026, o adaptador Laravel pesquisado ainda não declara compatibilidade com Laravel 13.
O renderer só é necessário na fase de documentos. A Fase 6 deverá escolher uma versão compatível
ou encapsular `dompdf/dompdf` diretamente atrás do contrato interno, sem reduzir a versão do
framework nem introduzir dependência incompatível.

## ADR-007 — SQLite apenas para testes

MySQL 8.4 é o banco principal. PHPUnit usa SQLite em memória para testes de comportamento rápidos e
isolados; validações específicas de concorrência e SQL serão executadas também contra MySQL.

## ADR-008 — Classificação de risco exclusivamente profissional

Faixas configuráveis de sinais vitais bloqueiam valores tecnicamente impossíveis e exigem
confirmação explícita para valores incomuns. Elas não selecionam nem sugerem automaticamente um
nível de risco. Protocolo, fluxograma, discriminador, justificativa e risco são confirmados pelo
profissional responsável na finalização.

## ADR-009 — Registro final de triagem imutável

Enquanto a triagem está em rascunho, alterações usam versão otimista e pertencem ao profissional que
a iniciou. Após a finalização, avaliação, aferições e classificação não são sobrescritas; informações
posteriores são registradas como adendos com autor, motivo e data.

## ADR-010 — Agregado médico versionado e registros filhos imutáveis

Anamnese, exame físico e conduta permanecem editáveis apenas no rascunho da consulta e usam versão
otimista. Diagnósticos, prescrições finalizadas, resultados, evoluções e encaminhamentos são
registros cronológicos sem rotas de sobrescrita. A finalização calcula um hash do conteúdo clínico
estruturado; complementos posteriores usam adendos.

## ADR-011 — Destinação explícita em um agregado único

Alta, observação, internação solicitada, transferência, evasão e óbito compartilham autoria,
horário, vínculo com episódio e consulta, mas possuem validações condicionais próprias. Um registro
único de destinação por episódio evita fechamentos concorrentes e conduz a máquina de estados do
atendimento. Observação e solicitação de internação permanecem estados intermediários válidos.

## ADR-012 — Renderização local e versões documentais append-only

O contrato interno de PDF usa diretamente `dompdf/dompdf` 3.1.6, sem adaptador acoplado à versão do
Laravel. Recursos remotos, PHP e JavaScript ficam desativados. Cada emissão preserva HTML e PDF no
disco privado, registra SHA-256 e cria uma versão imutável; correções apontam para uma nova versão e
anulações mantêm todos os arquivos para rastreabilidade.

## ADR-013 — Uma consulta central para tela e exportações

Tela, CSV e PDF de atendimentos usam a mesma consulta e os mesmos filtros, limite de mil linhas e
regra de mascaramento. Isso evita divergência entre o que o usuário visualiza e exporta. Toda
consulta parte da unidade ativa, e cada exportação registra formato, período e quantidade na
auditoria sem copiar conteúdo clínico para o log.

## ADR-014 — Auditoria consultável permanece append-only e unit-scoped

A interface de auditoria expõe somente leitura e sempre inicia pela unidade ativa. Eventos gerais e
acessos ao prontuário permanecem em tabelas separadas, mas compartilham filtros de período, usuário,
paciente e atendimento. O contexto passa por sanitização central de credenciais e segredos antes da
persistência.

## ADR-015 — HTTPS obrigatório é validado também pela aplicação

O terminador TLS pode ficar no proxy institucional, mas a aplicação não confia apenas na topologia.
Em produção, métodos seguros recebidos por HTTP são redirecionados e métodos de escrita são
rejeitados. Hosts e proxies confiáveis são explícitos; HSTS só é enviado quando a conexão é
reconhecida como segura.

## ADR-016 — Backup possui evidência independente de integridade

Cada execução registra resultado no banco e produz manifesto SHA-256. A criptografia AES-256 é
ativada por chave montada fora do repositório. Uma verificação posterior valida confinamento do
caminho, manifesto, hashes e legibilidade, registrando resultado próprio. Essa verificação não
substitui o teste periódico de restauração em ambiente isolado.
