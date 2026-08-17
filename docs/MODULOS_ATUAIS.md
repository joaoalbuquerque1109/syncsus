# Módulos atuais do SYNC HOSP

**Versão do documento:** 1.0  
**Data de referência:** 29/07/2026  
**Escopo:** funcionalidades existentes no código e disponíveis na versão atual do sistema.

## 1. Visão geral

O SYNC HOSP é um sistema de apoio ao fluxo assistencial de unidades de saúde. Atualmente, ele cobre o percurso do paciente desde a identificação e abertura da recepção até a triagem, atendimento médico, emissão de documentos, relatórios e auditoria.

O fluxo principal implementado é:

```text
Login e seleção da unidade
        ↓
Cadastro ou identificação do paciente
        ↓
Abertura do atendimento na recepção
        ↓
Entrada na fila definida pelo tipo de atendimento
        ↓
Triagem, quando exigida
        ↓
Fila médica por unidade e especialidade
        ↓
Atendimento médico
        ↓
Destino do atendimento e documentos
        ↓
Relatórios, auditoria e acompanhamento operacional
```

O sistema utiliza isolamento multitenant por organização. No desenho atual, a organização é a fronteira de isolamento dos dados e representa a unidade gestora. Dentro dela podem existir um ou mais estabelecimentos ou locais operacionais cadastrados em `health_units`.

## 2. Perfis de acesso

O acesso às funcionalidades é controlado por papéis e permissões.

| Perfil | Responsabilidades principais |
|---|---|
| Administrador global | Acesso total à plataforma e possibilidade de selecionar qualquer organização e local ativo. É um acesso único e não possui vínculo organizacional. |
| Gestor | Administra usuários, profissionais, cadastros operacionais e fluxos da própria organização. Também acessa filas, relatórios e auditoria. |
| Recepcionista | Consulta e cadastra pacientes, abre e cancela atendimentos administrativos e acompanha filas. |
| Profissional de triagem | Consulta dados assistenciais, opera filas de triagem, registra sinais vitais, classifica risco e encaminha o paciente. |
| Médico | Opera filas médicas compatíveis com suas unidades e especialidades, realiza atendimento e emite documentos clínicos. |
| Auditor | Consulta relatórios e trilhas de auditoria, sem autorização para alterar o atendimento. |

Regras de governança existentes:

- existe um único administrador global;
- o administrador global não pertence a nenhuma organização;
- todo usuário comum deve estar obrigatoriamente vinculado a uma organização;
- uma mesma pessoa pode ter cadastros separados para atuar em organizações diferentes;
- cada organização deve manter pelo menos um gestor ativo;
- o último gestor ativo não pode ser desativado nem perder o papel de gestor;
- o papel de administrador não pode ser concedido pela tela de gestão de usuários;
- os dados exibidos e alterados são limitados à organização e ao local de saúde ativos.

## 3. Fundação, autenticação e contexto da unidade

Este módulo controla a entrada do usuário no sistema e estabelece o contexto usado pelos demais módulos.

### Funcionalidades

- login com código da organização ou código administrativo;
- autenticação por e-mail e senha;
- limitação de tentativas de login;
- bloqueio de usuários inativos;
- troca obrigatória da senha inicial;
- alteração e redefinição administrativa de senha;
- limite configurável de sessões simultâneas;
- logout e encerramento da sessão;
- seleção e troca do local de saúde ativo;
- validação do vínculo do usuário com o local selecionado;
- acesso do administrador global a todos os locais ativos;
- registro de eventos de autenticação e troca de contexto;
- endpoints de verificação de disponibilidade e prontidão da aplicação.

O código informado no login define a organização do usuário comum. O código administrativo identifica o acesso global. Após a autenticação, o local ativo é aplicado a todas as consultas operacionais.

## 4. Administração e multitenancy

O módulo administrativo mantém os cadastros que estruturam a organização e o funcionamento de cada local.

### Gestão de usuários

- cadastro e atualização de usuários;
- definição dos papéis de acesso;
- ativação e desativação;
- vínculo com um ou mais locais da mesma organização;
- definição do local padrão;
- redefinição de senha;
- obrigatoriedade de ao menos um gestor ativo por organização.

### Gestão de profissionais

- cadastro de médicos, enfermeiros, técnicos, fisioterapeutas, psicólogos, assistentes sociais e outros profissionais;
- vínculo opcional entre o cadastro profissional e um usuário de acesso;
- dados pessoais, profissionais, contatos e endereço;
- código institucional e CNES;
- conselhos profissionais, número de registro, estado, emissão, validade e indicação do registro principal;
- vínculo com locais de atuação;
- vínculo com especialidades;
- vínculo explícito com as filas que o profissional pode visualizar;
- vínculo explícito com as salas, consultórios ou pontos em que pode chamar pacientes;
- definição da especialidade principal;
- RQE e data de registro por especialidade;
- ativação e desativação do profissional.

### Catálogos organizacionais

- especialidades;
- formas de chegada;
- tipos de entrada;
- locais de saúde;
- código, nome, ordem de exibição e estado ativo dos registros;
- regras do tipo de entrada, como exigência de triagem, aceitação de paciente provisório e fila padrão;
- exigência de dados do veículo em formas de chegada configuradas para isso.

### Configuração do fluxo

- setores administrativos, de triagem, médicos, de observação, diagnóstico e procedimentos;
- salas;
- pontos de atendimento;
- filas;
- vínculo de filas com setores, especialidades e pontos de atendimento;
- painéis públicos;
- vínculo de painéis com filas;
- modo de identificação do paciente exibido no painel;
- criação automática de uma estrutura inicial ao cadastrar um novo local.

### Isolamento atual

- pacientes, atendimentos e catálogos assistenciais pertencem a uma organização;
- filas, salas, setores e painéis pertencem a um local de saúde;
- usuários comuns só acessam dados da organização à qual pertencem;
- médicos só veem as filas médicas explicitamente vinculadas ao seu cadastro, validadas contra suas unidades e especialidades;
- profissionais de triagem só veem as filas de triagem explicitamente vinculadas ao seu cadastro;
- dentro de uma fila, o profissional só pode operar os pontos de atendimento que lhe foram atribuídos;
- recepcionistas e gestores possuem a visão operacional permitida por seus papéis;
- o administrador global pode alternar entre organizações e locais sem possuir vínculo.

## 5. Pacientes

O módulo de pacientes mantém a identificação, os dados cadastrais e parte do histórico assistencial.

### Cadastro definitivo

- nome completo e nome social;
- data de nascimento e sexo;
- CPF, CNS, RG e passaporte;
- emissor, estado e data de emissão dos documentos aplicáveis;
- filiação e indicação de filiação desconhecida;
- identidade de gênero, raça/cor, nacionalidade e etnia;
- naturalidade e código IBGE;
- estado civil, filhos, deficiência, tipo sanguíneo, escolaridade e ocupação;
- telefones e e-mail;
- endereço completo, área urbana ou rural e endereço desconhecido;
- responsável legal e responsável financeiro;
- observações administrativas.

### Regras de identificação

- CPF válido quando informado;
- CPF não pode ser repetido no banco;
- CNS, RG e passaporte também possuem proteção contra duplicidade;
- não existe fluxo de unificação de pacientes;
- os documentos são normalizados antes de serem armazenados e comparados;
- informações sensíveis são mascaradas nas telas em que a identificação completa não é necessária.

### Paciente provisório

É possível criar um cadastro provisório quando a pessoa ainda não pode ser identificada completamente. O uso desse paciente em um atendimento depende da configuração do tipo de entrada.

### Histórico assistencial complementar

Usuários com permissão clínica podem registrar:

- alergias;
- condições e antecedentes;
- medicamentos em uso;
- histórico social.

O sistema registra os acessos ao prontuário para fins de auditoria.

## 6. Recepção e atendimentos

O módulo de recepção abre o episódio assistencial e encaminha o paciente ao fluxo correto.

### Abertura do atendimento

- localização ou seleção do paciente;
- tipo de entrada;
- forma e horário de chegada;
- origem e motivo da entrada;
- prioridade administrativa;
- observações da recepção;
- informações do veículo quando exigidas;
- setor, especialidade e fila inicial;
- acompanhante, contato, parentesco e indicação de responsável.

### Regras do fluxo de entrada

- o paciente deve pertencer à organização ativa;
- o tipo de entrada deve estar ativo na organização;
- paciente provisório só pode ser usado quando o tipo de entrada permitir;
- entradas que exigem triagem devem começar em uma fila de triagem;
- entradas que dispensam triagem devem começar em uma fila médica;
- fila, setor e especialidade precisam ser compatíveis;
- a abertura utiliza uma chave de idempotência para evitar duplicação causada por reenvio do formulário;
- são gerados número do atendimento e senha de fila.

### Cancelamento

- exige motivo, confirmação e versão atual do atendimento;
- atendimentos finalizados não podem ser cancelados;
- o cancelamento encerra as entradas de fila ainda abertas;
- rascunhos de triagem ou consulta médica relacionados são cancelados;
- cancelamentos antes do início clínico podem ser feitos por perfis administrativos autorizados;
- durante a triagem ou consulta, o cancelamento clínico é limitado ao profissional responsável;
- todo cancelamento gera histórico e auditoria.

## 7. Filas e painel de chamadas

Este módulo organiza a movimentação dos pacientes entre os pontos de atendimento.

### Operação das filas

- página operacional única **Filas e chamadas** para médicos e profissionais de triagem;
- visualização das filas permitidas ao perfil;
- atualização periódica das entradas;
- geração sequencial de senha por fila;
- chamada e rechamada;
- início do atendimento;
- botão **Editar** para reabrir triagem ou consulta com estado `Em atendimento`;
- registro de ausência;
- retorno do paciente à fila;
- transferência entre filas;
- conclusão ou cancelamento da passagem pela fila;
- histórico completo das mudanças de estado;
- proteção contra atualização concorrente por controle de versão.

Os estados suportados incluem aguardando, chamado, em atendimento, ausente, transferido, concluído e cancelado.

Uma entrada em atendimento só apresenta a ação de edição ao profissional que iniciou o registro ou ao administrador global. A mesma regra é validada no servidor ao acessar a URL diretamente e ao salvar alterações.

### Separação por papel e especialidade

- profissionais de triagem acessam somente as filas e os pontos de triagem atribuídos no cadastro profissional;
- médicos acessam somente as filas e os consultórios atribuídos no cadastro profissional, dentro de seus locais e especialidades;
- um médico ou profissional de triagem sem vínculo operacional não recebe acesso a nenhuma fila;
- filas diferentes permitem separar destinos como **Triagem 1**, **Triagem 2**, **Clínica geral — Consultório 1** e **Clínica geral — Consultório 2**;
- recepcionistas, gestores e administrador visualizam as filas de acordo com suas permissões administrativas.

### Painel público

- endereço público próprio para cada painel;
- atualização automática do estado;
- exibição do nome completo do paciente nas chamadas atuais e recentes;
- locução do nome do paciente e do ponto de atendimento;
- sinalização de chamada e rechamada;
- heartbeat para monitorar se o painel está conectado;
- limitação de requisições públicas;
- a senha continua sendo gerada, armazenada e incluída no fluxo técnico, mas sua apresentação visual e locução estão temporariamente comentadas para reativação futura.

## 8. Triagem

O módulo de triagem atende pacientes encaminhados a filas de classificação.

### Fluxo

- início da triagem a partir de uma entrada válida da fila;
- associação do profissional e ponto de atendimento;
- criação e salvamento de rascunho;
- registro de sinais vitais;
- escolha de protocolo, fluxograma e discriminador;
- definição do nível de risco;
- justificativa obrigatória da classificação;
- encaminhamento à fila de destino;
- finalização com confirmação profissional;
- inclusão posterior de adendos sem alterar o registro finalizado.

### Dados clínicos

- queixa principal e início dos sintomas;
- história breve;
- escala de dor;
- alergias relatadas;
- medicamentos em uso;
- condições conhecidas;
- situação gestacional;
- risco de queda;
- necessidade de isolamento;
- sinais de violência;
- exame inicial e observações.

### Sinais vitais

- pressão arterial;
- frequência cardíaca e respiratória;
- temperatura;
- saturação de oxigênio;
- glicemia;
- peso e altura;
- escala de dor;
- Glasgow;
- tipo sanguíneo;
- circunferência;
- alertas clínicos padronizados.

Os valores passam por validação técnica. Valores fora das faixas configuradas exigem confirmação explícita do profissional.

## 9. Atendimento médico

O módulo médico recebe o paciente da fila compatível e mantém o registro da consulta.

### Requisitos para iniciar

- usuário com papel de médico;
- cadastro profissional médico ativo;
- vínculo com o local atual;
- especialidade compatível com a fila;
- ausência de outra consulta em rascunho atribuída ao mesmo médico.

### Registro da consulta

- queixa principal;
- história da doença atual;
- antecedentes pessoais, familiares e cirúrgicos;
- medicamentos e alergias;
- hábitos;
- histórico ginecológico;
- revisão de sistemas;
- exame físico estruturado e campo livre;
- conduta, procedimentos, orientações e necessidade de reavaliação;
- diagnósticos;
- prescrições com itens;
- solicitações de exames;
- evoluções e notas clínicas;
- encaminhamentos.

### Finalização e destino

O médico pode concluir o atendimento com:

- alta;
- observação;
- solicitação de internação;
- transferência;
- evasão;
- óbito.

Cada destino possui validações próprias, como orientações de alta, instituição de transferência, tipo de leito, tentativas de contato ou causa do óbito.

### Correções clínicas

- registros em rascunho são protegidos por versão e só podem ser alterados pelo médico responsável ou pelo administrador global;
- após a finalização, o conteúdo original não é sobrescrito;
- informações complementares são registradas por adendo;
- diagnóstico e nota clínica podem ser anulados com justificativa e preservação do histórico;
- prescrição pode ser cancelada com justificativa;
- todos os documentos vinculados ao atendimento, inclusive os emitidos antes da disponibilização desta função, aparecem na aba Correções e podem ser invalidados pelo médico responsável ou pelo administrador global;
- a opção de apagar documento exige justificativa e confirmação, altera sua verificação para anulado e preserva o PDF e todas as versões para auditoria;
- as ações geram auditoria.

### Catálogo CID-10

- 1.835 categorias importadas da planilha institucional fornecida, de `A00` a `Z99`;
- busca assíncrona por código ou descrição, limitada aos registros ativos;
- seleção canônica do catálogo para impedir alteração manual do código ou da descrição;
- suporte adicional aos códigos detalhados já cadastrados no sistema;
- a fonte fornecida contém categorias de três caracteres, e não todos os subcódigos decimais.

### Limite atual relativo a exames

O sistema registra somente a solicitação do exame. Resultados de exames não são digitados, armazenados ou anulados manualmente nesta versão. A recepção futura de resultados deverá ocorrer por integração com o laboratório.

## 10. Disponibilidade médica na unidade

Os médicos aptos a operar na unidade são determinados pelo cadastro profissional e pelos vínculos permanentes.

### Funcionalidades atuais

- médico com usuário e cadastro profissional ativos;
- vínculo do usuário e do cadastro profissional com a unidade;
- papel de médico;
- ao menos uma especialidade cadastrada;
- filtro por especialidade quando o fluxo exigir;
- operação imediata das filas médicas, sem check-in ou check-out.

### Limite atual

Não existe escala de plantões, confirmação de presença, planejamento antecipado, aprovação, troca de plantão ou controle de jornada.

## 11. Documentos clínicos

O módulo de documentos gera arquivos clínicos vinculados ao paciente e ao atendimento.

### Organização dos tipos

- **Atestados:** possuem aba e tabela próprias, com período de afastamento, declaração clínica, informações adicionais e CID autorizado.
- **Receitas, solicitações de exames e encaminhamentos:** são registrados nas tabelas clínicas próprias e o PDF é gerado a partir da respectiva aba, sem redigitação na aba Documentos.
- **Documentos gerais:** declaração de comparecimento, declaração de acompanhante, relatório médico, orientações de alta e resumo do atendimento permanecem na aba Documentos com título definido automaticamente pelo sistema.

Cada registro clínico estruturado pode originar somente um documento PDF. Uma nova solicitação de geração reutiliza o documento já existente, evitando duplicidade. Prescrições canceladas não podem gerar PDF e o cancelamento invalida o documento que já tenha sido gerado.

### Segurança e versionamento

- geração de HTML e PDF;
- armazenamento privado;
- código público de verificação;
- hash SHA-256 do conteúdo;
- download autorizado e sem cache público;
- histórico imutável de versões dos documentos gerais;
- criação de nova versão de documento geral sem sobrescrever a anterior;
- anulação do documento com motivo e preservação dos arquivos;
- verificação pública sujeita a limite de requisições;
- auditoria da emissão, download, nova versão e anulação.

Nos atestados e relatórios médicos, o CID é pesquisado no catálogo ativo e só é incluído após confirmação da autorização expressa do paciente. Código e descrição são resolvidos novamente no servidor antes da geração do PDF; texto livre enviado pelo navegador não é aceito como CID.

A anulação de documentos clínicos não significa anulação de resultado de exame. Resultados laboratoriais não fazem parte do fluxo atual.

## 12. Dashboard e relatórios

### Dashboard operacional

- visão geral do plantão;
- métricas atualizadas periodicamente;
- atendimentos ativos;
- quantitativos por etapa e estado;
- tempos operacionais;
- mascaramento de dados conforme o perfil;
- contexto limitado ao local ativo.

### Relatório de atendimentos

- filtro por período;
- período máximo de 366 dias;
- estado do atendimento;
- nível de risco;
- especialidade;
- profissional;
- destino clínico;
- totais e tempos médios;
- exportação CSV;
- exportação PDF;
- mesma consulta e critérios aplicados à tela e às exportações;
- limite de volume e de frequência para exportações.

## 13. Auditoria e segurança

O módulo de auditoria permite rastrear ações administrativas, operacionais e clínicas.

### Informações auditadas

- usuário e local;
- data e hora;
- tipo da ação;
- paciente e atendimento relacionados, quando aplicável;
- contexto técnico sanitizado;
- acessos ao prontuário;
- alterações de identidade e senha;
- abertura, movimentação e cancelamento de atendimento;
- eventos de triagem e atendimento médico;
- emissão e alteração de documentos;
- exportações e ações administrativas.

### Consulta

- pesquisa por período;
- filtros por ação, usuário, paciente e atendimento;
- resumo dos eventos;
- separação entre eventos gerais e acessos a pacientes;
- isolamento pelo local ativo.

### Controles de segurança

- permissões explícitas por papel;
- proteção CSRF;
- rate limits para login, painéis, verificações e exportações;
- sanitização de senhas, tokens e outros segredos antes da auditoria;
- sessões e cache persistidos em banco;
- limitação de sessões simultâneas;
- armazenamento privado de documentos;
- configuração para HTTPS, HSTS, CSP, hosts e proxies confiáveis;
- controle otimista de concorrência em registros clínicos e operacionais.

## 14. Operações, continuidade e backup

Esta área é exclusiva do administrador global.

### Funcionalidades

- estado dos trabalhos em fila;
- quantidade de trabalhos com falha;
- painéis conectados recentemente;
- espaço livre no armazenamento privado;
- histórico de execuções de backup;
- histórico de verificações de backup;
- verificação de integridade por hashes;
- registro do usuário responsável pela verificação;
- endpoints de disponibilidade e prontidão.

O projeto também possui documentação de contingência, backup, restauração, homologação e operação.

## 15. Integrações externas

O sistema está preparado para receber integrações, mas atualmente os fluxos funcionais utilizam os dados armazenados no próprio banco.

### Previstas

- API do SUS para dados e identificação dos pacientes;
- API do Synclab para comunicação laboratorial;
- recebimento de informações e resultados laboratoriais sem digitação manual no SYNC HOSP.

### Estado atual

- não há sincronização automática de pacientes com o SUS;
- não há comunicação ativa com o Synclab;
- não há recebimento ou armazenamento operacional de resultados de exames;
- as solicitações de exames são mantidas no atendimento para futura integração.

## 16. Tecnologia e execução

### Backend

- PHP 8.5;
- Laravel 13;
- Eloquent ORM;
- filas, sessões e cache com suporte a banco de dados;
- Dompdf para documentos PDF;
- Spatie Laravel Permission para papéis e permissões.

### Frontend

- Blade;
- Alpine.js;
- Tailwind CSS;
- Vite.

### Bancos de dados

- SQLite para demonstração e desenvolvimento local;
- estrutura preparada e testável para MySQL 8.4 no ambiente de produção.

### Infraestrutura

- execução local com Artisan;
- build de produção do frontend;
- Docker com Nginx, PHP-FPM, worker, scheduler, banco e rotina de backup;
- arquivos de configuração para publicação no Railway.

## 17. Limitações conhecidas da versão atual

- não existe unificação de pacientes;
- CPF duplicado é bloqueado;
- não existe importação automática de pacientes pelo SUS;
- não existe integração ativa com o Synclab;
- resultados de exames não são registrados no sistema;
- não existe controle de escala ou presença diária dos médicos;
- não há agendamento assistencial ou agenda de consultas;
- não há faturamento, estoque, farmácia ou gestão de leitos completa;
- a criação de uma organização inteiramente nova ainda não possui um fluxo administrativo completo na interface;
- um usuário comum pertence a uma única organização; para atuar em outra organização, precisa de outro cadastro;
- o SQLite é destinado à visualização local, enquanto a publicação deve utilizar um banco persistente de produção.

## 18. Resumo do estado atual

Os módulos essenciais solicitados estão implementados:

1. fundação e autenticação;
2. pacientes e recepção;
3. filas e painel de chamadas;
4. triagem;
5. atendimento médico;
6. documentos e relatórios;
7. revisão integrada e segurança.

Além deles, o sistema já inclui administração multitenant, gestão de profissionais e especialidades, auditoria, continuidade operacional e backup. As próximas evoluções mais relevantes são a integração com SUS e Synclab, a configuração definitiva do banco de produção e o fluxo administrativo para provisionar novas organizações.
