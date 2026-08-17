# SYNC HOSP

## Especificação funcional, técnica e de implementação

**Versão:** 1.0  
**Status:** especificação-base para desenvolvimento do MVP  
**Produto:** sistema web hospitalar para urgência e emergência  
**Modo de implantação:** servidor local da instituição, acessado pela rede interna  
**Idioma da interface:** Português do Brasil  
**Fuso horário padrão:** `America/Fortaleza`, configurável por instalação  

---

## 1. Resumo executivo

O **SYNC HOSP** será um sistema web hospitalar destinado à porta de entrada de unidades de urgência e emergência. Ele controlará o fluxo operacional e clínico do paciente desde a chegada à recepção até a destinação definida pelo médico, com organização por filas, chamadas em painel de TV, classificação de risco, atendimento médico, documentos e auditoria.

O sistema será instalado em um servidor físico ou virtual dentro da instituição e funcionará sem depender da internet para as operações principais. Os computadores da recepção, triagem, consultórios e setores administrativos acessarão o sistema por navegador, dentro da rede local.

O MVP terá como fluxo principal:

```text
Chegada do paciente
        ↓
Recepção e abertura do atendimento
        ↓
Fila de classificação de risco
        ↓
Chamada visual e sonora
        ↓
Triagem e classificação de risco
        ↓
Fila médica
        ↓
Chamada para consultório
        ↓
Atendimento médico
        ↓
Alta, observação, solicitação de internação, transferência,
evasão ou óbito
```

O produto não será uma cópia visual ou técnica de outro software. As referências levantadas servem apenas para compreender o domínio hospitalar, os fluxos e os campos necessários. A implementação deverá possuir identidade própria, arquitetura própria e regras explicitamente documentadas.

---

## 2. Objetivos do produto

### 2.1 Objetivo principal

Disponibilizar um sistema hospitalar leve, confiável, auditável e simples de operar para controlar o atendimento de demanda espontânea em urgência e emergência.

### 2.2 Objetivos específicos

1. Reduzir registros manuais e informações duplicadas.
2. Organizar as filas de triagem e atendimento médico.
3. Exibir chamadas em painéis de TV com áudio local.
4. Preservar o histórico completo do atendimento hospitalar.
5. Separar claramente responsabilidades de recepção, enfermagem, médico, gestão e auditoria.
6. Impedir alterações silenciosas em registros clínicos finalizados.
7. Permitir funcionamento integral em rede local mesmo sem internet.
8. Gerar documentos médicos e relatórios em PDF.
9. Produzir indicadores de tempo de espera e volume de atendimentos.
10. Manter trilha de auditoria das ações relevantes.

### 2.3 Princípios do produto

- **Segurança clínica:** o sistema auxilia o registro e o fluxo, mas não substitui o julgamento profissional.
- **Rastreabilidade:** toda mudança importante deve ter autor, data, hora e contexto.
- **Imutabilidade clínica:** registros finalizados não são sobrescritos; correções ocorrem por adendo ou anulação justificada.
- **Baixo acoplamento:** módulos separados por responsabilidade em um monólito modular.
- **Operação local:** nenhuma função essencial depende de serviços externos.
- **Interface objetiva:** telas com poucos cliques, ações explícitas e boa leitura em computadores modestos.
- **Privacidade por padrão:** cada perfil vê apenas o necessário para executar seu trabalho.
- **Configuração institucional:** tipos de entrada, setores, salas, filas, especialidades e níveis de risco não devem ficar fixos no código.

---

## 3. Escopo do MVP

### 3.1 Funcionalidades incluídas

1. Autenticação local.
2. Gestão de usuários, perfis e permissões.
3. Cadastro da instituição, unidades, setores, salas e pontos de atendimento.
4. Dashboard hospitalar.
5. Cadastro e busca de pacientes.
6. Identificação provisória de paciente não identificado.
7. Detecção de possíveis duplicidades cadastrais.
8. Recepção e abertura de atendimento hospitalar.
9. Registro de acompanhante e responsável.
10. Geração de número de atendimento e senha.
11. Filas configuráveis.
12. Chamada, rechamada, ausência e transferência de fila.
13. Painel de TV visual e sonoro.
14. Triagem e classificação de risco.
15. Registro de sinais vitais.
16. Registro de alertas clínicos.
17. Encaminhamento pós-triagem.
18. Fila médica.
19. Atendimento médico.
20. Diagnósticos e CID.
21. Prescrição hospitalar.
22. Receita domiciliar.
23. Solicitação e registro manual de exames.
24. Evolução clínica.
25. Encaminhamentos.
26. Atestados, declarações, relatórios e orientações de alta.
27. Alta, observação, solicitação de internação, transferência, evasão e óbito.
28. Auditoria e histórico de acesso ao prontuário.
29. Relatórios operacionais.
30. Rotina de backup local.

### 3.2 Fora do escopo inicial

- Agendamento de consultas.
- Agenda médica eletiva.
- Gestão completa de leitos e internação.
- Prescrição e checagem de enfermagem durante internação.
- Farmácia e estoque.
- Laboratório integrado e interfaceamento com equipamentos.
- Integração com PACS ou sistemas de imagem.
- Faturamento SUS completo, BPA, APAC e AIH.
- Regulação municipal ou estadual completa.
- Centro cirúrgico completo.
- Telemedicina.
- Aplicativo móvel nativo.
- Integração obrigatória com serviços externos.
- Decisão automática de classificação de risco.

### 3.3 Evoluções previstas

A arquitetura deverá permitir, sem exigir reescrita do núcleo:

- gestão de leitos;
- internação;
- evolução multiprofissional;
- farmácia;
- estoque;
- laboratório;
- faturamento;
- regulação;
- integrações nacionais e estaduais;
- assinatura digital;
- sincronização entre unidades.

---

## 4. Terminologia do domínio

| Termo | Definição no SYNC HOSP |
|---|---|
| Paciente | Pessoa atendida pela instituição. |
| Prontuário | Identificador longitudinal do paciente dentro da instalação. |
| Atendimento | Episódio hospitalar iniciado na recepção e encerrado com uma destinação. |
| Recepção | Etapa administrativa de identificação e abertura do atendimento. |
| Triagem | Avaliação inicial e classificação de risco. |
| Classificação de risco | Nível de prioridade confirmado por profissional autorizado. |
| Fila | Conjunto ordenado de pacientes aguardando um setor ou profissional. |
| Entrada de fila | Participação de um atendimento em uma fila específica. |
| Chamada | Evento que direciona um paciente ou senha para um ponto de atendimento. |
| Ponto de atendimento | Guichê, sala, consultório ou setor que recebe uma chamada. |
| Atendimento médico | Registro clínico realizado pelo médico dentro do episódio hospitalar. |
| Evolução | Registro clínico adicional, cronológico e imutável. |
| Destinação | Resultado operacional do atendimento: alta, observação, internação, transferência etc. |
| Adendo | Complemento a um registro clínico finalizado, sem apagar o conteúdo original. |
| Paciente provisório | Pessoa ainda não identificada ou com dados insuficientes. |

---

## 5. Arquitetura tecnológica

### 5.1 Stack principal

- **Linguagem:** PHP, versão estável compatível com o Laravel adotado.
- **Framework backend:** Laravel, versão estável suportada no início do projeto.
- **Renderização web:** Blade.
- **Interatividade:** Alpine.js.
- **CSS:** Tailwind CSS compilado localmente.
- **Build frontend:** Vite.
- **Banco de dados:** MySQL 8 ou superior.
- **Servidor web:** Nginx.
- **Execução PHP:** PHP-FPM.
- **Filas assíncronas:** Laravel Queue com driver `database` no MVP.
- **Agendador:** Laravel Scheduler em processo próprio.
- **PDF:** Dompdf ou adaptador equivalente encapsulado por interface interna.
- **Autorização:** Policies, Gates e pacote `spatie/laravel-permission` na versão compatível.
- **Testes:** PHPUnit com recursos de teste do Laravel.
- **Qualidade PHP:** Laravel Pint e Larastan/PHPStan.
- **Qualidade frontend:** ESLint e Prettier para arquivos JavaScript.
- **Contêineres:** Docker Compose.
- **Logs:** logs estruturados do Laravel, com rotação.
- **Armazenamento:** filesystem local privado, fora do diretório público.

### 5.2 Restrições arquiteturais

1. Não usar SPA completa.
2. Não usar React, Vue ou Livewire no MVP.
3. Não usar CDN para assets essenciais.
4. Não depender de internet para login, filas, triagem, atendimento, documentos ou relatórios.
5. Não expor o MySQL diretamente às estações de trabalho.
6. Não colocar regra clínica ou transição de estado apenas no JavaScript.
7. Não acessar modelos diretamente a partir de views.
8. Não criar microserviços no MVP.
9. Não adotar repositório genérico para todos os modelos.
10. Não armazenar documentos clínicos sensíveis no diretório público.

### 5.3 Topologia de execução

```text
Estações de trabalho
Chrome / Edge
        │
        │ HTTPS na rede local
        ▼
Nginx
        │
        ▼
PHP-FPM + Laravel
        ├── Blade
        ├── Alpine.js
        ├── Queue Worker
        ├── Scheduler
        ├── Geração de PDF
        └── Auditoria
        │
        ▼
MySQL + armazenamento privado
```

### 5.4 Serviços do Docker Compose

```text
nginx
app
mysql
queue-worker
scheduler
backup
```

Serviços opcionais apenas em desenvolvimento:

```text
mailpit
phpmyadmin ou adminer
```

Ferramentas administrativas de banco não devem existir em produção sem autenticação, restrição de rede e justificativa operacional.

---

## 6. Organização do código

### 6.1 Estratégia

O sistema será um **monólito modular pragmático**. Cada módulo terá responsabilidade própria, mas compartilhará o mesmo processo e banco de dados.

Estrutura sugerida:

```text
app/
└── Modules/
    ├── Identity/
    ├── Administration/
    ├── Patients/
    ├── Reception/
    ├── Queues/
    ├── CallPanel/
    ├── Triage/
    ├── MedicalCare/
    ├── Documents/
    ├── Reports/
    └── Audit/
```

Estrutura interna de um módulo:

```text
ModuleName/
├── Domain/
│   ├── Enums/
│   ├── Exceptions/
│   ├── Services/
│   └── ValueObjects/
├── Application/
│   ├── Actions/
│   ├── DTOs/
│   ├── Queries/
│   └── Contracts/
├── Infrastructure/
│   ├── Eloquent/
│   ├── Persistence/
│   └── Adapters/
├── Presentation/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Requests/
│   │   └── Resources/
│   └── Policies/
└── Providers/
```

Views:

```text
resources/views/
├── layouts/
├── components/
├── dashboard/
└── modules/
    ├── patients/
    ├── reception/
    ├── queues/
    ├── panels/
    ├── triage/
    ├── medical-care/
    ├── reports/
    └── administration/
```

JavaScript:

```text
resources/js/
├── app.js
├── alpine/
│   ├── patient-search.js
│   ├── reception-wizard.js
│   ├── queue-monitor.js
│   ├── call-panel.js
│   ├── triage-form.js
│   ├── prescription-editor.js
│   └── medical-care.js
└── support/
```

### 6.2 Dependências entre camadas

- Presentation depende de Application.
- Application depende de Domain.
- Infrastructure implementa contratos definidos em Application ou Domain.
- Domain não depende de Laravel, banco, HTTP ou Blade quando isso for tecnicamente viável.
- Controllers apenas recebem a requisição, delegam a ação e retornam resposta.
- Form Requests validam forma e autorização inicial.
- Actions executam casos de uso.
- Policies validam autorização contextual.
- Serviços de domínio protegem invariantes.

### 6.3 Convenções de idioma

- Código, nomes de classes, tabelas, colunas e enums: **inglês consistente**.
- Interface, mensagens, documentos e textos exibidos: **Português do Brasil**.
- Não misturar termos equivalentes como `citizen`, `client` e `patient`. O termo oficial será `patient`.
- O episódio hospitalar será chamado de `encounter` no código.

---

## 7. Regras obrigatórias de Clean Code

O arquivo `docs/REGRAS_CLEAN_CODE.md` é normativo e deve ser lido antes de qualquer implementação. Em especial:

1. Nomes descritivos, pronunciáveis e consistentes.
2. Booleanos afirmativos, como `isActive`, `canEdit` e `requiresTriage`.
3. Funções coesas, sem fragmentação artificial baseada apenas em quantidade de linhas.
4. Guard clauses para reduzir aninhamento.
5. Separação entre orquestração, regra de negócio e infraestrutura.
6. Comentários explicam decisões e trade-offs, nunca o óbvio.
7. Duplicação essencial deve ser removida; duplicação acidental não deve gerar abstração prematura.
8. Nenhuma exceção pode ser capturada silenciosamente.
9. Erros esperados usam exceções específicas ou objetos de resultado explícitos.
10. Estado imutável por padrão; mutações devem ser intencionais.
11. Testes validam comportamento observável e caminhos de erro.
12. Pint, PHPStan/Larastan, ESLint e Prettier devem ser executados antes da entrega.
13. Não deixar código morto, imports sem uso ou TODO sem contexto.

### 7.1 Regras adicionais do projeto

- Controllers não podem conter regra de negócio.
- Models Eloquent não devem se tornar objetos oniscientes.
- Transições de estado devem passar por Actions ou Services específicos.
- Operações que alteram fila ou estado clínico devem usar transação de banco.
- Valores clínicos relevantes devem usar objetos ou DTOs tipados quando isso reduzir ambiguidade.
- Não usar arrays associativos extensos como contrato interno quando um DTO nomeado for mais claro.
- Não usar `try/catch` genérico apenas para retornar mensagem amigável.
- Não registrar conteúdo clínico completo em logs de aplicação.
- Não expor IDs sequenciais em URLs públicas; usar `public_id` em ULID.

---

## 8. Perfis, papéis e permissões

### 8.1 Papéis iniciais

#### Administrador

- gerenciar usuários, papéis e permissões;
- cadastrar unidades, setores, salas, filas e painéis;
- cadastrar tipos de entrada, formas de chegada, especialidades e níveis de risco;
- ativar e inativar catálogos;
- consultar relatórios;
- consultar auditoria;
- executar rotinas administrativas autorizadas.

#### Recepcionista

- buscar paciente;
- cadastrar paciente;
- atualizar dados administrativos;
- criar paciente provisório;
- abrir atendimento;
- registrar acompanhante;
- direcionar para fila inicial;
- visualizar andamento operacional;
- cancelar abertura com justificativa e permissão.

Não pode visualizar evolução, diagnóstico, prescrição ou conteúdo clínico completo.

#### Profissional de triagem

- visualizar fila de triagem;
- chamar e rechamar paciente;
- iniciar triagem;
- registrar queixa, sinais vitais e alertas;
- confirmar classificação de risco;
- encaminhar para setor ou especialidade;
- finalizar triagem;
- criar adendo quando autorizado.

#### Médico

- visualizar filas médicas autorizadas;
- chamar paciente;
- iniciar atendimento;
- consultar triagem e histórico clínico permitido;
- registrar anamnese, exame físico, diagnóstico e conduta;
- prescrever;
- solicitar exames;
- registrar evolução;
- emitir documentos;
- definir destinação;
- finalizar atendimento;
- criar adendo.

#### Gestor

- visualizar dashboard e relatórios;
- consultar atendimentos conforme escopo institucional;
- acompanhar indicadores de fila;
- não alterar registros clínicos.

#### Auditor

- consultar trilha de auditoria;
- consultar acessos ao prontuário;
- visualizar versões e adendos;
- não alterar registros operacionais ou clínicos.

#### Painel de TV

- autenticação técnica por token do painel;
- leitura apenas das chamadas associadas;
- nenhum acesso ao prontuário;
- nenhum acesso a CPF, CNS, telefone, endereço ou conteúdo clínico.

### 8.2 Matriz resumida

| Recurso | Admin | Recepção | Triagem | Médico | Gestor | Auditor |
|---|---:|---:|---:|---:|---:|---:|
| Cadastro administrativo do paciente | Sim | Sim | Consulta limitada | Consulta | Consulta | Consulta |
| Conteúdo clínico | Configurável | Não | Parcial | Sim | Leitura restrita | Leitura auditada |
| Abrir atendimento | Sim | Sim | Não | Não | Não | Não |
| Classificar risco | Não por padrão | Não | Sim | Configurável | Não | Não |
| Prescrever | Não | Não | Não | Sim | Não | Não |
| Chamar paciente | Sim | Configurável | Sim | Sim | Não | Não |
| Encerrar atendimento médico | Não | Não | Não | Sim | Não | Não |
| Relatórios | Sim | Limitado | Limitado | Limitado | Sim | Sim |
| Auditoria | Sim | Não | Não | Próprias ações | Limitado | Sim |

Toda autorização deve ser validada no backend. Ocultar um botão na interface não substitui Policy ou Gate.

---

## 9. Máquina de estados do atendimento

### 9.1 Status principais

```text
opened
waiting_triage
called_to_triage
in_triage
waiting_medical
called_to_medical
in_medical_care
waiting_exam
waiting_procedure
under_observation
awaiting_admission
admitted
awaiting_transfer
discharged
transferred
left_without_notice
deceased
cancelled
```

### 9.2 Transições permitidas

```text
opened
  → waiting_triage
  → cancelled

waiting_triage
  → called_to_triage
  → cancelled

called_to_triage
  → in_triage
  → waiting_triage
  → left_without_notice

in_triage
  → waiting_medical
  → in_medical_care, somente fluxo emergencial autorizado
  → discharged, somente com permissão institucional
  → awaiting_transfer

waiting_medical
  → called_to_medical
  → left_without_notice

called_to_medical
  → in_medical_care
  → waiting_medical
  → left_without_notice

in_medical_care
  → waiting_exam
  → waiting_procedure
  → under_observation
  → awaiting_admission
  → awaiting_transfer
  → discharged
  → deceased

waiting_exam
  → in_medical_care
  → under_observation
  → discharged

under_observation
  → in_medical_care
  → awaiting_admission
  → awaiting_transfer
  → discharged
  → deceased

awaiting_admission
  → admitted
  → under_observation
  → awaiting_transfer
  → discharged

awaiting_transfer
  → transferred
  → under_observation
  → in_medical_care
```

### 9.3 Regras de transição

1. Nenhuma tela pode alterar `current_status` diretamente.
2. Cada transição deve ser representada por uma Action nomeada.
3. A Action valida origem, destino, papel do usuário e pré-condições.
4. A alteração ocorre dentro de transação.
5. O histórico é gravado na mesma transação.
6. Transições inválidas lançam exceção de domínio específica.
7. O sistema não deve corrigir transição inválida silenciosamente.
8. Toda destinação final exige autor, data e motivo ou dados próprios.
9. Um atendimento encerrado não volta ao estado ativo; deve ser criado novo episódio ou adendo conforme o caso.

---

## 10. Design system e experiência de uso

### 10.1 Identidade visual

O SYNC HOSP utilizará interface clara, com contraste alto e navegação lateral escura. A identidade visual será própria.

- Cor primária: azul institucional.
- Fundo principal: cinza muito claro ou branco.
- Navegação lateral: azul-marinho.
- Verde: confirmação e sucesso.
- Amarelo, laranja, vermelho, verde e azul: classificação de risco, sempre acompanhados de texto.
- Vermelho de interface: erro ou ação destrutiva, sem confundir com nível clínico.

### 10.2 Layout global

```text
┌────────────────┬───────────────────────────────────────────────┐
│ Logo SYNC HOSP │ Cabeçalho: unidade, notificações e usuário    │
├────────────────┼───────────────────────────────────────────────┤
│ Início         │                                               │
│ Recepção       │ Conteúdo da tela                              │
│ Triagem        │                                               │
│ Atendimento    │                                               │
│ Filas          │                                               │
│ Pacientes      │                                               │
│ Relatórios     │                                               │
│ Administração  │                                               │
└────────────────┴───────────────────────────────────────────────┘
```

### 10.3 Regras de interface

1. A ação principal deve ser visualmente evidente.
2. Ações destrutivas exigem confirmação e justificativa quando aplicável.
3. Não usar ícone sem `title`, rótulo acessível ou texto quando a ação não for óbvia.
4. Tabelas devem ter cabeçalho fixo quando houver rolagem longa.
5. Filtros ativos devem aparecer de forma visível e removível.
6. Campos obrigatórios devem ter marcador e mensagem específica.
7. Erros devem ser exibidos ao lado do campo e em resumo no topo quando o formulário for extenso.
8. Formulários longos devem ser divididos em etapas ou abas.
9. A interface deve ser utilizável por teclado.
10. O foco deve ser movido para o primeiro erro após falha de validação.
11. Não depender apenas de cor para comunicar risco ou status.
12. O sistema deve indicar quando está salvando, salvo ou com erro.
13. O sistema não deve apagar rascunho sem confirmação.
14. Dados somente leitura devem parecer diferentes de campos editáveis.
15. O menu deve respeitar as permissões do usuário.
16. Assets devem ser locais.
17. Resolução-alvo primária: 1366×768 e superiores.
18. Layout deve continuar utilizável em 1280×720.
19. Tablet pode ser suportado, mas desktop é o foco do MVP.

### 10.4 Componentes Blade compartilhados

```text
<x-layout.app>
<x-layout.sidebar>
<x-layout.topbar>
<x-page.header>
<x-card>
<x-stat-card>
<x-form.input>
<x-form.select>
<x-form.textarea>
<x-form.checkbox>
<x-form.radio-group>
<x-form.date-time>
<x-button.primary>
<x-button.secondary>
<x-button.danger>
<x-badge.status>
<x-badge.risk>
<x-table>
<x-modal>
<x-confirm-dialog>
<x-empty-state>
<x-alert>
<x-patient-summary>
<x-encounter-summary>
<x-timeline>
```

Componentes devem aceitar apenas propriedades necessárias e não conter regra de negócio.

---

## 11. Tela 1 — Dashboard hospitalar

### 11.1 Objetivo

Apresentar visão operacional do hospital em tempo quase real, sem expor conteúdo clínico desnecessário.

### 11.2 Cabeçalho

- Saudação com nome do usuário.
- Unidade ativa.
- Perfil ativo.
- Data e hora local.
- Indicador de conectividade com o servidor.
- Menu de usuário.

### 11.3 Cartões principais

- Aguardando triagem.
- Em triagem.
- Aguardando médico.
- Em atendimento.
- Em observação.
- Aguardando internação.
- Transferências no dia.
- Altas no dia.

Cada cartão deve conter:

- quantidade;
- comparação opcional com período anterior;
- link para lista filtrada, se o usuário tiver permissão;
- aviso quando houver paciente acima do tempo de referência.

### 11.4 Tabela de atendimentos em andamento

Campos:

- senha;
- paciente em formato permitido pelo perfil;
- etapa atual;
- classificação de risco;
- tempo de espera da etapa;
- tempo total no hospital;
- local ou fila;
- profissional responsável, quando permitido;
- indicador de atraso;
- ação de abrir visão operacional.

### 11.5 Alertas

- paciente vermelho aguardando;
- paciente laranja acima do tempo de referência;
- fila sem profissional ativo;
- painel de TV desconectado;
- atendimento aberto sem fila;
- atendimento sem destinação após consulta;
- backup diário não concluído;
- número incomum de erros no período.

### 11.6 Filtros

- unidade;
- setor;
- período;
- classificação;
- status;
- profissional.

### 11.7 Atualização

- polling padrão a cada 15 segundos;
- botão de atualizar;
- mostrar horário da última atualização;
- evitar requisições quando a aba estiver oculta por longo período.

---

## 12. Tela 2 — Recepção: abertura do atendimento

### 12.1 Objetivo

Registrar a chegada e os dados administrativos iniciais antes da identificação do paciente.

### 12.2 Etapas do wizard

1. Dados da recepção.
2. Identificação do paciente.
3. Dados do paciente.
4. Encaminhamento.
5. Revisão e finalização.

### 12.3 Campos de dados da recepção

| Campo | Tipo | Obrigatório | Regra |
|---|---|---:|---|
| Data e hora de chegada | datetime-local | Sim | Padrão: agora; não permitir futuro acima da tolerância configurada. |
| Unidade | select | Sim | Unidade ativa e permitida para o usuário. |
| Operador | somente leitura | Sim | Usuário autenticado. |
| Tipo de entrada | select pesquisável | Sim | Catálogo ativo. |
| Forma de chegada | select | Sim | Caminhando, cadeira, maca, ambulância etc. |
| Origem do paciente | select/texto | Não | Catálogo configurável. |
| Prioridade administrativa | select | Sim | Não, idoso, gestante, PCD etc.; não altera risco clínico. |
| Motivo da entrada | select pesquisável | Sim | Catálogo configurável. |
| Observações da recepção | textarea | Não | Máximo configurável; sem conteúdo clínico detalhado. |
| Veículo/ambulância | texto | Condicional | Exigido para formas de chegada configuradas. |
| Instituição de origem | texto/select | Condicional | Exigido em transferências. |
| Número de regulação | texto | Condicional | Quando aplicável. |

### 12.4 Regras

1. O horário registrado deve ser preservado separadamente do horário de criação no banco.
2. Prioridade administrativa não pode sobrescrever classificação de risco.
3. Campos condicionais devem ser validados no backend.
4. O usuário só pode selecionar unidades autorizadas.
5. O wizard pode manter rascunho por sessão durante período limitado.
6. Nenhum atendimento é criado antes da confirmação final, salvo opção institucional de pré-registro.

---

## 13. Tela 3 — Recepção: busca e identificação do paciente

### 13.1 Busca unificada

Campo único com suporte a:

- prontuário;
- nome;
- CPF;
- CNS;
- data de nascimento;
- nome da mãe;
- telefone.

### 13.2 Comportamento da busca

- iniciar com três caracteres para nome;
- CPF e CNS podem buscar com ou sem máscara;
- debounce de 300 a 500 ms;
- paginação no backend;
- máximo inicial de 20 resultados;
- destacar correspondência;
- não retornar dados além do necessário;
- registrar busca sensível somente quando definida pela política institucional.

### 13.3 Resultado

Cada resultado mostra:

- nome completo;
- nome social, quando houver;
- prontuário;
- data de nascimento e idade;
- sexo;
- CPF parcialmente mascarado;
- CNS parcialmente mascarado;
- nome da mãe, quando necessário para desambiguação;
- cidade;
- indicador de cadastro incompleto;
- ação `Selecionar`.

### 13.4 Possível duplicidade

O sistema deve sinalizar:

- CPF idêntico;
- CNS idêntico;
- nome e data de nascimento muito semelhantes;
- nome, data de nascimento e nome da mãe semelhantes;
- telefone compartilhado, apenas como pista, nunca como prova.

A decisão de unir registros não ocorre nesta tela. Deve existir processo administrativo específico e auditado.

### 13.5 Paciente não identificado

Ação explícita: `Cadastrar paciente não identificado`.

Campos mínimos:

- sexo aparente ou `não informado`;
- faixa etária estimada;
- descrição física resumida;
- forma de chegada;
- local onde foi encontrado, quando aplicável;
- observações administrativas;
- nome provisório gerado automaticamente.

Formato sugerido:

```text
NÃO IDENTIFICADO 20260723-001
```

### 13.6 Regras do cadastro provisório

- `is_provisional = true`;
- CPF e CNS nulos;
- prontuário próprio;
- pode receber atendimento completo;
- identificação posterior não apaga o registro original;
- fusão exige permissão, confirmação e auditoria;
- anexos e documentos devem permanecer vinculados ao atendimento.

---

## 14. Tela 4 — Recepção: dados do paciente

### 14.1 Abas

- Dados pessoais.
- Documentos.
- Endereço.
- Contatos.
- Responsáveis.
- Informações complementares.

### 14.2 Dados pessoais

| Campo | Tipo | Obrigatório |
|---|---|---:|
| Nome completo | texto | Sim, exceto provisório |
| Nome social | texto | Não |
| Data de nascimento | data | Sim, exceto provisório |
| Sexo | select | Sim |
| Identidade de gênero | select | Não |
| Raça/cor | select | Configurável |
| Etnia | select/texto | Não |
| Nacionalidade | select | Não |
| Município de nascimento | select/texto | Não |
| Estado civil | select | Não |
| PCD | boolean/select | Não |
| Tipo sanguíneo | select | Não |
| Nome da mãe | texto | Configurável |
| Mãe desconhecida | checkbox | Não |
| Nome do pai | texto | Não |
| Pai desconhecido | checkbox | Não |
| Escolaridade | select | Não |
| Ocupação | select/texto | Não |
| Unidade de referência | select | Não |

### 14.3 Documentos

- CPF.
- CNS.
- RG ou identidade.
- Órgão emissor.
- UF emissora.
- Data de emissão.
- Certidão, quando aplicável.
- Documento estrangeiro.

Regras:

- CPF normalizado em coluna sem máscara;
- CNS normalizado;
- índices únicos condicionais conforme política de dados;
- documentos de paciente provisório podem ser nulos;
- divergências devem gerar alerta, não correção automática.

### 14.4 Contatos

- telefone principal;
- telefone alternativo;
- WhatsApp, apenas indicador;
- e-mail;
- contato de emergência;
- preferência de contato, para uso futuro.

### 14.5 Endereço

- CEP;
- país;
- estado;
- município;
- bairro;
- tipo de logradouro;
- logradouro;
- número;
- complemento;
- ponto de referência;
- zona urbana/rural;
- endereço desconhecido.

O sistema não deve depender de API de CEP. Consulta externa pode ser opcional e nunca bloquear o cadastro.

### 14.6 Responsável legal

- nome;
- CPF;
- data de nascimento;
- parentesco;
- telefone;
- endereço, quando diferente;
- motivo da responsabilidade;
- documento comprobatório, opcional;
- início e fim da responsabilidade.

### 14.7 Validações

- nome sem caracteres inválidos evidentes;
- data de nascimento não futura;
- CPF validado por algoritmo quando preenchido;
- CNS validado quando a regra institucional estiver habilitada;
- telefone normalizado;
- não impedir emergência por ausência de informação não essencial;
- campos obrigatórios podem variar por unidade, perfil e tipo de paciente.

---

## 15. Tela 5 — Recepção: encaminhamento

### 15.1 Objetivo

Definir a fila inicial e os dados operacionais de destino após identificação.

### 15.2 Campos

| Campo | Tipo | Obrigatório | Observação |
|---|---|---:|---|
| Destino inicial | select | Sim | Normalmente classificação de risco. |
| Especialidade | select pesquisável | Condicional | Apenas especialidades ativas na unidade. |
| Profissional | select pesquisável | Não | Filtrado por vínculo e especialidade. |
| Setor | select | Sim | Filtrado pela unidade. |
| Sala/ponto de atendimento | select | Não | Usado quando houver direcionamento definido. |
| Fila | select | Sim | Filas compatíveis com o destino. |
| Observações | textarea | Não | Conteúdo administrativo. |

### 15.3 Filtro de profissionais

O backend deve retornar apenas profissionais:

- ativos;
- vinculados à unidade;
- vinculados à especialidade selecionada;
- autorizados para o setor;
- com cadastro profissional ativo;
- com ocupação compatível.

### 15.4 Revisão final

Antes de finalizar, exibir:

- paciente;
- prontuário;
- tipo de entrada;
- forma de chegada;
- unidade;
- fila inicial;
- destino;
- prioridade administrativa;
- operador;
- horário de chegada.

### 15.5 Finalização da abertura

Dentro de uma única transação:

1. gerar número do atendimento;
2. criar `encounter`;
3. criar registro de recepção;
4. vincular acompanhante, se houver;
5. gerar senha da fila;
6. criar entrada na fila;
7. registrar status `waiting_triage` ou equivalente configurado;
8. gravar histórico;
9. gravar auditoria;
10. retornar comprovante e próxima ação.

A operação deve ser idempotente para impedir atendimento duplicado em duplo clique ou repetição de requisição.

---

## 16. Tela 6 — Fila de espera da triagem

### 16.1 Cabeçalho e filtros

- unidade;
- setor;
- sala de triagem;
- período;
- classificação, quando já conhecida;
- forma de entrada;
- somente meus pacientes;
- busca por senha, prontuário, nome, CPF ou CNS;
- botão atualizar;
- horário da última atualização.

### 16.2 Colunas

- senha;
- paciente;
- idade;
- forma de chegada;
- prioridade administrativa;
- chegada;
- espera;
- número de chamadas;
- situação;
- ações.

### 16.3 Ações

- Chamar.
- Rechamar.
- Iniciar triagem.
- Marcar como não localizado.
- Devolver à fila.
- Transferir fila.
- Abrir resumo administrativo.

### 16.4 Ordenação

A ordenação deve ser feita no servidor com estratégia configurável, considerando:

1. emergência sinalizada;
2. classificação já existente;
3. prioridade administrativa;
4. tempo de espera;
5. horário de entrada na fila.

Prioridade administrativa nunca deve ser tratada como classificação clínica.

### 16.5 Concorrência

Ao iniciar triagem:

- bloquear a entrada de fila em transação;
- verificar se outro profissional já iniciou;
- atribuir profissional e sala;
- atualizar status;
- registrar horário;
- remover ou marcar entrada da fila como em atendimento.

Se houver conflito, exibir mensagem clara com nome do profissional e horário, quando permitido.

---

## 17. Tela 7 — Triagem: avaliação e classificação de risco

### 17.1 Cabeçalho permanente

- senha;
- paciente;
- idade;
- sexo;
- forma de chegada;
- horário de chegada;
- tempo total;
- alergias conhecidas;
- alertas cadastrais;
- profissional de triagem;
- sala.

### 17.2 Abas

- Avaliação.
- Sinais vitais.
- Histórico resumido.
- Observações.

### 17.3 Campos da avaliação

| Campo | Tipo | Obrigatório |
|---|---|---:|
| Queixa principal | textarea | Sim |
| Início dos sintomas | datetime/texto estruturado | Não |
| História resumida | textarea | Sim |
| Fluxograma | select pesquisável | Configurável |
| Discriminador | select pesquisável | Configurável |
| Escala de dor | número 0–10 | Não |
| Alergias relatadas | boolean + texto | Sim |
| Medicamentos em uso | boolean + texto | Não |
| Condições conhecidas | múltipla seleção + texto | Não |
| Gestação/suspeita | select | Condicional |
| Risco de queda | select | Não |
| Necessidade de isolamento | select + motivo | Não |
| Sinais de violência | campo protegido | Não |
| Exame inicial | textarea | Não |
| Observações | textarea | Não |

### 17.4 Classificação de risco

Campos:

- protocolo utilizado;
- nível de risco;
- cor;
- descrição;
- tempo de referência;
- justificativa;
- profissional responsável;
- data e hora.

Níveis padrão configuráveis:

- Vermelho — emergência.
- Laranja — muito urgente.
- Amarelo — urgente.
- Verde — pouco urgente.
- Azul — não urgente.

### 17.5 Regras clínicas e técnicas

1. O sistema não deve escolher automaticamente a classificação final.
2. Pode exibir alertas de consistência, mas o profissional confirma a decisão.
3. O protocolo e os catálogos devem ser configuráveis.
4. Alterar classificação após finalização exige permissão, motivo e adendo.
5. Cor deve sempre aparecer com nome textual.
6. O tempo de referência é informativo e não deve ocultar pacientes atrasados.
7. O registro deve guardar a versão do protocolo usada.

### 17.6 Encaminhamento pós-triagem

- fila de destino;
- setor;
- especialidade;
- profissional, opcional;
- sala, opcional;
- prioridade operacional;
- observação de encaminhamento.

Possíveis destinos:

- sala de emergência;
- clínica médica;
- pediatria;
- ortopedia;
- obstetrícia;
- cirurgia;
- medicação;
- procedimento;
- transferência;
- alta após triagem, somente se permitido.

---

## 18. Tela 8 — Triagem: sinais vitais

### 18.1 Campos

| Campo | Unidade | Tipo |
|---|---|---|
| Pressão sistólica | mmHg | inteiro |
| Pressão diastólica | mmHg | inteiro |
| Frequência cardíaca | bpm | inteiro |
| Frequência respiratória | irpm | inteiro |
| Temperatura | °C | decimal |
| Saturação de oxigênio | % | inteiro/decimal |
| Glicemia | mg/dL | inteiro |
| Peso | kg | decimal |
| Altura | m ou cm | decimal |
| IMC | kg/m² | calculado |
| Escala de dor | 0–10 | inteiro |
| Glasgow | 3–15 | inteiro |
| Tipo sanguíneo | catálogo | select |
| Circunferência, quando aplicável | cm | decimal |

### 18.2 Alertas adicionais

- hipertensão conhecida;
- diabetes;
- sepse suspeita;
- dispneia;
- sangramento ativo;
- arritmia/taquicardia;
- alergia medicamentosa;
- medicação externa já administrada;
- oxigênio em uso;
- dispositivo invasivo, quando aplicável.

### 18.3 Validação técnica

Devem existir limites de sanidade configuráveis para reduzir erro de digitação. Esses limites não são critérios clínicos e não substituem avaliação profissional.

Exemplos de comportamento:

- valor fora da faixa técnica gera confirmação explícita;
- valor impossível bloqueia salvamento;
- campo vazio permanece vazio, não vira zero;
- unidade é exibida junto ao campo;
- IMC é calculado no backend e mostrado na interface;
- altura e peso devem ser normalizados antes do cálculo.

### 18.4 Histórico

Cada conjunto de sinais vitais deve ser um registro próprio com:

- data e hora da aferição;
- profissional;
- origem: triagem, consulta, observação etc.;
- valores;
- observações;
- indicador de correção por adendo.

Não sobrescrever aferição anterior.

---

## 19. Tela 9 — Atendimento médico

### 19.1 Estrutura

A tela será dividida em:

- coluna esquerda com resumo permanente do paciente;
- área principal com abas clínicas;
- cabeçalho com status, risco e ações;
- rodapé ou barra fixa com salvar rascunho e finalizar.

### 19.2 Resumo permanente

- iniciais/avatar;
- nome completo e nome social;
- idade e sexo;
- prontuário;
- CPF e CNS mascarados;
- número do atendimento;
- chegada;
- fim da triagem;
- classificação de risco;
- alergias;
- alertas;
- profissional e especialidade;
- fila e consultório;
- tempo total no hospital;
- botão chamar/rechamar;
- botão consultar histórico, conforme permissão.

### 19.3 Abas principais

1. Resumo.
2. Anamnese.
3. Exame físico.
4. Diagnóstico.
5. Prescrição.
6. Exames.
7. Evolução.
8. Encaminhamentos.
9. Documentos.
10. Destinação.

### 19.4 Resumo

- dados da triagem;
- sinais vitais mais recentes;
- queixa principal;
- classificação;
- alergias;
- condições conhecidas;
- medicamentos em uso;
- últimos atendimentos;
- últimas prescrições;
- exames pendentes;
- alertas cadastrais;
- linha do tempo do episódio atual.

### 19.5 Anamnese

- queixa principal;
- história da doença atual;
- antecedentes pessoais;
- antecedentes familiares;
- histórico cirúrgico;
- medicamentos em uso;
- alergias;
- hábitos;
- histórico gineco-obstétrico, quando aplicável;
- revisão de sistemas;
- observações adicionais.

Modelos por especialidade podem ser adicionados futuramente. O MVP deve ter formulário geral e permitir texto livre estruturado.

### 19.6 Exame físico

- estado geral;
- nível de consciência;
- pele e mucosas;
- cabeça e pescoço;
- aparelho respiratório;
- aparelho cardiovascular;
- abdome;
- sistema neurológico;
- sistema musculoesquelético;
- extremidades;
- achados específicos;
- texto livre.

### 19.7 Diagnóstico

Para cada diagnóstico:

- tipo: hipótese, confirmado ou descartado;
- CID;
- descrição;
- principal ou secundário;
- data e hora;
- profissional;
- observação;
- situação ativa ou encerrada no episódio.

Regras:

- um diagnóstico principal por fechamento, salvo configuração;
- CID pesquisável por código e descrição;
- não aceitar código inexistente no catálogo ativo;
- permitir diagnóstico descritivo provisório quando a política autorizar.
- carregar o catálogo sob demanda, sem enviar todos os códigos na abertura da tela;
- utilizar como base inicial as 1.835 categorias CID-10 de três caracteres da fonte institucional fornecida.

### 19.8 Conduta

- resumo da conduta;
- medicação administrada ou prescrita;
- exames solicitados;
- procedimentos;
- orientação;
- necessidade de reavaliação;
- destino provável;
- observações.

### 19.9 Salvamento

- rascunho manual;
- autosave opcional apenas para campos de texto, com indicação visual;
- rascunho não é registro final;
- finalização exige validações;
- conflito de edição deve ser detectado;
- conteúdo final recebe hash ou versão para rastreabilidade.

---

## 20. Prescrição hospitalar

### 20.1 Objetivo

Registrar itens destinados ao uso dentro da instituição, separados da receita para uso domiciliar.

### 20.2 Cabeçalho

- atendimento;
- paciente;
- médico prescritor;
- data e hora;
- setor;
- tipo de prescrição;
- status: rascunho, finalizada, cancelada;
- observações gerais.

### 20.3 Item da prescrição

| Campo | Obrigatório | Observação |
|---|---:|---|
| Medicamento/produto | Sim | Catálogo local pesquisável. |
| Apresentação | Sim | Ex.: comprimido, ampola. |
| Concentração | Condicional | Texto estruturado. |
| Dose | Sim | Decimal e unidade. |
| Unidade da dose | Sim | mg, mL, UI etc. |
| Via | Sim | Oral, IV, IM etc. |
| Frequência | Sim | Catálogo e texto complementar. |
| Intervalo | Condicional | Ex.: a cada 8 horas. |
| Duração | Não | Número e unidade. |
| Data/hora inicial | Não | Para uso futuro. |
| Administração imediata | boolean | Não |
| Se necessário | boolean | Não |
| Condição para uso | Condicional | Obrigatório se `se necessário`. |
| Diluição | Não | Texto. |
| Velocidade de infusão | Não | Texto/valor. |
| Observações | Não | Texto. |

### 20.4 Regras

- parâmetros clínicos não devem ser inferidos automaticamente;
- não aceitar item sem dose e via quando exigidos;
- cancelamento exige motivo;
- prescrição finalizada é imutável;
- alteração gera nova versão ou substituição explícita;
- o MVP não inclui checagem de enfermagem;
- catálogo de medicamentos pode iniciar simples e local.

---

## 21. Receita domiciliar

### 21.1 Tipos

- receituário simples;
- receituário especial, apenas como modelo institucional aprovado;
- laudo ou orientação vinculada;
- receita de alta.

### 21.2 Campos por item

- medicamento;
- concentração;
- forma farmacêutica;
- quantidade;
- dose;
- via;
- frequência;
- duração;
- posologia por extenso;
- orientações;
- uso contínuo;
- observações.

### 21.3 Documento

O PDF deve conter:

- instituição e unidade;
- paciente;
- data;
- conteúdo;
- médico e registro profissional;
- identificador público do documento;
- código de verificação local;
- rodapé institucional.

A validade jurídica e o formato final devem ser homologados pela instituição e assessoria responsável. O MVP deve separar geração técnica de documento de regras jurídicas específicas.

---

## 22. Solicitação e resultado de exames

### 22.1 Solicitação

Cabeçalho:

- atendimento;
- solicitante;
- data e hora;
- prioridade;
- indicação clínica;
- observações.

Itens:

- código interno;
- exame;
- grupo: laboratório, imagem, cardiologia ou outro;
- prioridade;
- lateralidade, quando aplicável;
- preparo;
- justificativa;
- status.

### 22.2 Status

```text
requested
collected
in_progress
result_available
reviewed
cancelled
```

No MVP, os estados podem ser atualizados manualmente por usuários autorizados.

### 22.3 Resultado manual

- texto do resultado;
- conclusão;
- data e hora;
- profissional responsável;
- arquivo anexado;
- observações;
- status revisado.

### 22.4 Regras

- anexos armazenados em diretório privado;
- download exige autorização;
- hash do arquivo armazenado;
- tipos e tamanhos permitidos configuráveis;
- resultado finalizado não é sobrescrito;
- correção usa nova versão.

---

## 23. Evolução clínica

### 23.1 Campos

- atendimento;
- data e hora do registro;
- data e hora clínica, quando diferente;
- tipo de evolução;
- texto;
- profissional;
- especialidade;
- status: rascunho ou finalizada;
- referência a registro anterior, quando adendo;
- motivo do adendo.

### 23.2 Regras

1. Evolução finalizada não pode ser editada.
2. Rascunho pode ser editado somente pelo autor ou papel autorizado.
3. Adendo preserva o texto anterior.
4. Exclusão física é proibida.
5. Anulação exige motivo e mantém o registro.
6. Ordenação sempre cronológica.
7. A interface deve diferenciar evolução, adendo e anulação.

---

## 24. Encaminhamentos

### 24.1 Campos

- tipo: interno ou externo;
- especialidade;
- setor ou instituição de destino;
- profissional destinatário, opcional;
- motivo;
- resumo clínico;
- prioridade;
- hipótese diagnóstica;
- documentos anexos;
- orientações;
- data e hora;
- solicitante;
- status.

### 24.2 Status

```text
draft
issued
accepted
completed
cancelled
```

O MVP pode implementar apenas `draft`, `issued` e `cancelled`.

---

## 25. Documentos clínicos

### 25.1 Tipos iniciais

- atestado médico;
- declaração de comparecimento;
- declaração de acompanhante;
- receita;
- solicitação de exames;
- encaminhamento;
- relatório médico;
- orientações de alta;
- resumo do atendimento;
- termo institucional configurável.

### 25.2 Atestado

Campos:

- modelo;
- paciente ou acompanhante;
- data e hora inicial;
- quantidade de dias ou horas;
- CID, somente conforme regra institucional e autorização;
- texto complementar;
- observações internas;
- médico emissor.

O CID deve ser selecionado no catálogo ativo. O servidor persiste a identificação, o código e a descrição canônicos do registro selecionado e ignora texto livre apresentado como CID.

### 25.3 Versionamento

```text
documents
  └── document_versions
```

Cada versão contém:

- conteúdo estruturado;
- HTML renderizado;
- caminho do PDF;
- hash;
- autor;
- data e hora;
- motivo da nova versão;
- status.

### 25.4 Verificação local

O documento pode conter QR Code ou código que aponte para rota interna ou externa configurável. Em ambiente exclusivamente local, a validação funciona apenas na rede interna. A implantação deve deixar isso claro.

---

## 26. Destinação do atendimento

Todo atendimento médico ativo deve terminar com destinação explícita ou permanecer em estado intermediário válido.

### 26.1 Alta

Campos:

- diagnóstico principal;
- diagnósticos secundários;
- condição na alta;
- resumo clínico;
- conduta;
- orientações;
- sinais de alerta;
- receita vinculada;
- documentos vinculados;
- retorno recomendado;
- acompanhante responsável, quando aplicável;
- data e hora;
- médico.

### 26.2 Observação

- motivo;
- setor;
- posição ou leito provisório;
- médico responsável;
- data e hora inicial;
- previsão de reavaliação;
- prescrição vinculada;
- exames pendentes;
- cuidados gerais;
- status.

O MVP registra observação, mas não implementa mapa completo de leitos.

### 26.3 Solicitação de internação

- especialidade;
- diagnóstico;
- justificativa;
- tipo de leito;
- prioridade;
- isolamento necessário;
- data e hora;
- solicitante;
- situação.

### 26.4 Transferência

- instituição de destino;
- cidade;
- setor de destino;
- motivo;
- diagnóstico;
- condição clínica;
- profissional de contato;
- meio de transporte;
- acompanhante;
- data e hora da solicitação;
- data e hora da saída;
- documentos enviados;
- observações.

### 26.5 Evasão

- data e hora constatada;
- última localização;
- tentativas de chamada;
- tentativas de contato;
- condição conhecida;
- profissional responsável;
- observação obrigatória.

### 26.6 Óbito

- data e hora;
- local;
- profissional responsável;
- diagnóstico ou causa informada conforme competência;
- observações;
- documentos vinculados;
- registro restrito;
- auditoria reforçada.

O detalhamento legal e documental deve ser homologado pela instituição antes de uso produtivo.

---

## 27. Filas e painel de chamadas

### 27.1 Tipos de fila

- triagem;
- clínica médica;
- pediatria;
- ortopedia;
- obstetrícia;
- cirurgia geral;
- emergência;
- medicação;
- coleta;
- exames;
- procedimentos;
- serviço social;
- outras configuráveis.

### 27.2 Configuração da fila

- nome;
- código;
- prefixo;
- unidade;
- setor;
- especialidade;
- estratégia de prioridade;
- sequência reinicia diariamente ou não;
- tamanho da senha;
- pontos de atendimento permitidos;
- papéis autorizados;
- ativa;
- ordem de exibição.

### 27.3 Entrada de fila

Campos principais:

- atendimento;
- fila;
- senha;
- prioridade;
- status;
- entrada;
- primeira chamada;
- última chamada;
- início do atendimento;
- saída;
- número de chamadas;
- ponto de atendimento atual;
- profissional atribuído;
- motivo de saída.

### 27.4 Status da entrada de fila

```text
waiting
called
in_service
absent
transferred
completed
cancelled
```

### 27.5 Chamada

Ao chamar:

1. validar permissão;
2. bloquear a entrada em transação;
3. verificar status atual;
4. validar ponto de atendimento;
5. criar registro de chamada;
6. atualizar entrada da fila;
7. registrar auditoria;
8. disponibilizar chamada ao painel.

### 27.6 Rechamada

- cria novo registro;
- incrementa contador;
- não sobrescreve chamada anterior;
- pode manter o mesmo ponto de atendimento;
- registra usuário e horário.

### 27.7 Ausência

- confirmação obrigatória;
- quantidade mínima de chamadas pode ser configurada;
- motivo;
- retorno à fila ou encerramento;
- registro de horário.

### 27.8 Transferência

- fila de origem;
- fila de destino;
- motivo;
- prioridade preservada ou recalculada conforme regra explícita;
- usuário;
- horário;
- histórico completo.

### 27.9 Painel de TV

Rota:

```text
/panels/{publicCode}
```

Configuração:

- unidade;
- nome;
- código público;
- filas associadas;
- modo de identificação;
- quantidade de chamadas anteriores;
- som ativo;
- volume sugerido;
- tema;
- mensagem institucional;
- ativo.

### 27.10 Privacidade no painel

Modos:

- apenas senha;
- primeiro nome e inicial;
- nome social e inicial;
- primeiro nome e último sobrenome;
- nome completo, somente se autorizado.

O payload do painel não contém prontuário, CPF, CNS, telefone, endereço, diagnóstico ou risco clínico, salvo informação visual estritamente necessária e autorizada.

### 27.11 Áudio offline

Modo padrão recomendado:

- chamada por senha;
- arquivos de áudio locais para frases e números;
- nenhuma API externa.

Modo opcional:

- síntese de voz do navegador para nome;
- habilitada apenas quando uma voz local compatível estiver disponível;
- fallback automático para senha.

### 27.12 Atualização

- polling a cada 2 segundos no MVP;
- endpoint retorna apenas chamadas após o último identificador recebido;
- cabeçalhos para evitar cache incorreto;
- backoff quando servidor estiver indisponível;
- indicador visual de desconexão;
- retomada sem repetir áudio de chamadas antigas.

---

## 28. Pacientes e prontuário longitudinal

### 28.1 Identificador

- `id`: bigint interno.
- `public_id`: ULID público.
- `medical_record_number`: número de prontuário.

### 28.2 Geração de prontuário

- sequência atômica;
- não reutilizável;
- única por instalação;
- configurável para prefixo;
- uso de bloqueio de linha para concorrência.

### 28.3 Duplicidade e fusão

A fusão de pacientes será módulo administrativo restrito.

Fluxo:

1. selecionar registro principal;
2. selecionar registro duplicado;
3. comparar documentos e atendimentos;
4. confirmar justificativa;
5. transferir referências de forma transacional;
6. marcar duplicado como mesclado;
7. preservar identificadores anteriores;
8. registrar auditoria detalhada.

Não excluir fisicamente o paciente duplicado.

### 28.4 Histórico

O prontuário longitudinal deve apresentar:

- atendimentos anteriores;
- classificações;
- diagnósticos;
- prescrições;
- exames;
- documentos;
- evoluções;
- destinações;
- anexos.

O acesso deve respeitar perfil, unidade, relação assistencial e política institucional.

---

## 29. Regras de negócio críticas

### 29.1 Atendimento duplicado

Antes de abrir novo atendimento, alertar se existir atendimento ativo do mesmo paciente na mesma unidade.

Opções autorizadas:

- abrir o atendimento existente;
- continuar mesmo assim com justificativa e permissão;
- cancelar a nova abertura.

### 29.2 Idempotência

Operações críticas devem aceitar token idempotente:

- finalizar abertura;
- chamar paciente;
- iniciar triagem;
- finalizar triagem;
- iniciar consulta;
- finalizar consulta;
- emitir documento;
- registrar destinação.

### 29.3 Registro de horário

Separar:

- horário clínico ou operacional informado;
- `created_at` do banco;
- `updated_at`;
- horário do servidor.

### 29.4 Bloqueio de edição concorrente

- campos de formulário incluem versão do registro;
- o backend compara versão ou `updated_at`;
- conflito retorna erro específico;
- mostrar quem alterou e quando, quando permitido;
- nunca sobrescrever silenciosamente.

### 29.5 Finalização clínica

Antes de finalizar atendimento médico, validar:

- atendimento em estado compatível;
- médico responsável;
- anamnese ou resumo mínimo;
- exame físico ou justificativa;
- diagnóstico ou hipótese, conforme configuração;
- conduta;
- destinação;
- itens incompletos;
- documentos pendentes, se obrigatórios.

### 29.6 Anulação

Registros clínicos e operacionais não são apagados. A anulação contém:

- estado anulado;
- motivo;
- autor;
- data e hora;
- referência ao substituto, quando houver.

### 29.7 Soft delete

Soft delete pode ser usado em catálogos administrativos. Não usar como mecanismo principal para apagar registros clínicos.

### 29.8 Segurança de dados em logs

Não registrar em logs comuns:

- texto completo de anamnese;
- diagnóstico completo;
- CPF completo;
- CNS completo;
- endereço;
- receita completa;
- resultado de exame.

Logs técnicos podem usar IDs internos, public IDs, códigos de erro e contexto mínimo.

---

## 30. Modelo de dados

### 30.1 Padrões gerais

- PK interna `BIGINT UNSIGNED`.
- `public_id` ULID único nos agregados expostos em URL.
- timestamps em todas as tabelas relevantes.
- timezone da aplicação configurado; banco preferencialmente em UTC ou política documentada.
- chaves estrangeiras explícitas.
- índices compostos para filas e relatórios.
- `JSON` apenas para metadados flexíveis, não para substituir modelagem central.
- colunas sensíveis normalizadas e mascaradas na apresentação.
- enums de domínio representados em PHP e persistidos por string estável.

### 30.2 Administração

#### `organizations`

- id;
- public_id;
- legal_name;
- trade_name;
- document_number;
- cnes_code, opcional;
- timezone;
- locale;
- logo_path;
- is_active;
- created_at;
- updated_at.

#### `health_units`

- id;
- public_id;
- organization_id;
- code;
- name;
- cnes_code;
- address fields;
- phone;
- is_active;
- created_at;
- updated_at.

#### `departments`

- id;
- health_unit_id;
- code;
- name;
- type;
- is_clinical;
- is_active;
- display_order.

#### `rooms`

- id;
- department_id;
- code;
- name;
- room_type;
- is_active.

#### `service_points`

- id;
- public_id;
- room_id;
- code;
- name;
- type;
- is_active.

#### `specialties`

- id;
- code;
- name;
- is_active;
- display_order.

#### `entry_types`

- id;
- code;
- name;
- requires_triage;
- allows_provisional_patient;
- default_queue_id, nullable;
- is_active;
- display_order.

#### `arrival_methods`

- id;
- code;
- name;
- requires_vehicle_data;
- is_active.

#### `risk_levels`

- id;
- code;
- name;
- color_key;
- reference_minutes;
- priority_weight;
- protocol_version;
- is_active;
- display_order.

### 30.3 Identidade e acesso

#### `users`

- id;
- public_id;
- name;
- email ou username;
- password;
- professional_id, nullable;
- default_health_unit_id;
- is_active;
- must_change_password;
- last_login_at;
- password_changed_at;
- timestamps.

#### `professionals`

- id;
- public_id;
- full_name;
- social_name;
- professional_type;
- council_type;
- council_number;
- council_state;
- occupation_code;
- is_active;
- timestamps.

#### vínculos

- professional_health_unit;
- professional_specialty;
- professional_department;
- professional_shift, opcional.

Papéis e permissões seguem tabelas do pacote adotado.

### 30.4 Pacientes

#### `patients`

- id;
- public_id;
- medical_record_number;
- full_name;
- social_name;
- birth_date;
- estimated_age;
- sex;
- gender_identity;
- race_color;
- ethnicity;
- nationality;
- birth_city;
- marital_status;
- is_disabled;
- blood_type;
- mother_name;
- mother_unknown;
- father_name;
- father_unknown;
- education_level;
- occupation;
- reference_health_unit_id;
- is_provisional;
- provisional_description;
- merged_into_patient_id;
- status;
- timestamps.

#### `patient_identifiers`

- id;
- patient_id;
- type: cpf, cns, rg, passport etc.;
- normalized_value;
- display_value;
- issuer;
- issuer_state;
- issued_at;
- is_primary;
- verified_at;
- timestamps.

#### `patient_contacts`

- id;
- patient_id;
- type;
- value;
- normalized_value;
- is_primary;
- notes;
- timestamps.

#### `patient_addresses`

- id;
- patient_id;
- postal_code;
- country;
- state;
- city;
- district;
- street_type;
- street;
- number;
- complement;
- reference;
- area_type;
- is_primary;
- is_unknown;
- timestamps.

#### `patient_guardians`

- id;
- patient_id;
- full_name;
- cpf;
- relationship;
- phone;
- responsibility_reason;
- starts_at;
- ends_at;
- timestamps.

#### `patient_allergies`

- id;
- patient_id;
- substance;
- reaction;
- severity;
- status;
- source;
- recorded_by;
- recorded_at;
- timestamps.

#### `patient_conditions`

- id;
- patient_id;
- code;
- description;
- status;
- onset_date;
- notes;
- recorded_by;
- timestamps.

### 30.5 Atendimento

#### `encounters`

- id;
- public_id;
- encounter_number;
- patient_id;
- health_unit_id;
- entry_type_id;
- arrival_method_id;
- current_status;
- risk_level_id, nullable;
- administrative_priority;
- arrival_at;
- registration_at;
- triage_started_at;
- triage_finished_at;
- medical_started_at;
- medical_finished_at;
- observation_started_at;
- closed_at;
- assigned_professional_id, nullable;
- assigned_specialty_id, nullable;
- current_department_id, nullable;
- current_room_id, nullable;
- lock_version;
- created_by;
- closed_by, nullable;
- cancellation_reason, nullable;
- timestamps.

Índices:

- unique encounter_number;
- patient_id + current_status;
- health_unit_id + current_status + arrival_at;
- risk_level_id + current_status;
- assigned_professional_id + current_status.

#### `reception_records`

- id;
- encounter_id;
- operator_id;
- origin;
- entry_reason_id ou text;
- reception_notes;
- vehicle_information;
- origin_institution;
- regulation_number;
- timestamps.

#### `encounter_companions`

- id;
- encounter_id;
- full_name;
- cpf;
- phone;
- relationship;
- is_legal_guardian;
- authorized_at;
- timestamps.

#### `encounter_status_history`

- id;
- encounter_id;
- from_status;
- to_status;
- reason;
- metadata;
- changed_by;
- changed_at.

### 30.6 Filas

#### `queues`

- id;
- public_id;
- health_unit_id;
- department_id;
- specialty_id, nullable;
- code;
- name;
- prefix;
- sequence_reset_policy;
- priority_strategy;
- is_active;
- display_order;
- timestamps.

#### `queue_entries`

- id;
- public_id;
- encounter_id;
- queue_id;
- ticket_number;
- priority_weight;
- status;
- entered_at;
- first_called_at;
- last_called_at;
- service_started_at;
- exited_at;
- call_count;
- assigned_professional_id;
- service_point_id;
- exit_reason;
- lock_version;
- timestamps.

Índices:

- queue_id + status + priority_weight + entered_at;
- encounter_id + status;
- ticket_number + queue_id + entered_at date.

#### `queue_calls`

- id;
- public_id;
- queue_entry_id;
- service_point_id;
- called_by;
- call_sequence;
- display_name;
- called_at;
- acknowledged_at;
- result;
- metadata;
- timestamps.

#### `display_panels`

- id;
- public_id;
- health_unit_id;
- code;
- name;
- identification_mode;
- sound_mode;
- previous_calls_count;
- theme;
- is_active;
- last_seen_at;
- timestamps.

#### `display_panel_queue`

- display_panel_id;
- queue_id.

### 30.7 Triagem

#### `triages`

- id;
- public_id;
- encounter_id;
- professional_id;
- room_id;
- chief_complaint;
- symptom_started_at;
- brief_history;
- protocol_name;
- protocol_version;
- flowchart_code;
- discriminator_code;
- pain_scale;
- initial_exam;
- notes;
- risk_level_id;
- risk_justification;
- started_at;
- completed_at;
- status;
- finalized_at;
- lock_version;
- timestamps.

#### `vital_signs`

- id;
- encounter_id;
- triage_id, nullable;
- source;
- measured_at;
- systolic_pressure;
- diastolic_pressure;
- heart_rate;
- respiratory_rate;
- temperature;
- oxygen_saturation;
- blood_glucose;
- weight_kg;
- height_cm;
- bmi;
- pain_scale;
- glasgow_score;
- blood_type;
- oxygen_in_use;
- notes;
- recorded_by;
- voided_at;
- void_reason;
- timestamps.

#### `triage_alerts`

- id;
- triage_id;
- type;
- is_present;
- details;
- recorded_by;
- timestamps.

#### `triage_procedures`

- id;
- triage_id;
- procedure_id;
- quantity;
- diagnosis_code;
- notes;
- performed_by;
- performed_at;
- timestamps.

### 30.8 Atendimento médico

#### `medical_consultations`

- id;
- public_id;
- encounter_id;
- professional_id;
- specialty_id;
- room_id;
- chief_complaint;
- present_illness_history;
- personal_history;
- family_history;
- surgical_history;
- current_medications;
- allergies_summary;
- habits;
- review_of_systems;
- conduct_summary;
- status;
- started_at;
- finalized_at;
- lock_version;
- timestamps.

#### `physical_exams`

- id;
- medical_consultation_id;
- general_state;
- consciousness;
- skin_mucosa;
- head_neck;
- respiratory;
- cardiovascular;
- abdomen;
- neurological;
- musculoskeletal;
- extremities;
- specific_findings;
- free_text;
- timestamps.

#### `diagnoses`

- id;
- encounter_id;
- medical_consultation_id;
- code;
- description;
- diagnosis_type;
- is_primary;
- status;
- notes;
- diagnosed_by;
- diagnosed_at;
- timestamps.

#### `clinical_notes`

- id;
- public_id;
- encounter_id;
- author_id;
- note_type;
- content;
- clinical_at;
- status;
- finalized_at;
- parent_note_id;
- addendum_reason;
- voided_at;
- void_reason;
- timestamps.

#### `prescriptions`

- id;
- public_id;
- encounter_id;
- professional_id;
- prescription_type;
- status;
- general_instructions;
- finalized_at;
- cancelled_at;
- cancellation_reason;
- version;
- timestamps.

#### `prescription_items`

- id;
- prescription_id;
- medication_name;
- presentation;
- concentration;
- dose;
- dose_unit;
- route;
- frequency;
- interval_text;
- duration_value;
- duration_unit;
- quantity;
- instructions;
- is_immediate;
- is_as_needed;
- as_needed_condition;
- dilution;
- infusion_rate;
- display_order;
- timestamps.

#### `exam_orders`, `exam_order_items`, `exam_results`

Campos conforme seções anteriores, sempre vinculados ao atendimento e autor.

#### `referrals`

Campos conforme seção 24.

### 30.9 Destinação

Tabelas:

- `discharges`;
- `observations`;
- `admission_requests`;
- `transfers`;
- `patient_absences`;
- `death_records`.

Cada tabela contém `encounter_id`, autor, timestamps clínicos, campos próprios e status.

### 30.10 Documentos e anexos

#### `documents`

- id;
- public_id;
- encounter_id;
- patient_id;
- document_type;
- current_version_id;
- status;
- created_by;
- timestamps.

#### `document_versions`

- id;
- document_id;
- version_number;
- structured_content JSON;
- rendered_html_path;
- pdf_path;
- file_hash;
- created_by;
- created_at;
- reason.

#### `attachments`

- id;
- public_id;
- attachable_type;
- attachable_id;
- original_name;
- stored_name;
- disk;
- path;
- mime_type;
- size_bytes;
- sha256;
- uploaded_by;
- timestamps.

### 30.11 Auditoria

#### `audit_logs`

- id;
- public_id;
- user_id;
- action;
- auditable_type;
- auditable_id;
- encounter_id, nullable;
- patient_id, nullable;
- changed_fields;
- context;
- ip_address;
- user_agent;
- occurred_at.

#### `patient_access_logs`

- id;
- user_id;
- patient_id;
- encounter_id, nullable;
- access_type;
- purpose;
- route_name;
- occurred_at.

---

## 31. Rotas e endpoints principais

As rotas devem usar nomes, middleware e autorização explícitos.

### 31.1 Autenticação

```text
GET    /login
POST   /login
POST   /logout
GET    /password/change
PUT    /password/change
```

### 31.2 Dashboard

```text
GET    /dashboard
GET    /dashboard/metrics
GET    /dashboard/active-encounters
```

### 31.3 Pacientes

```text
GET    /patients
GET    /patients/search
GET    /patients/create
POST   /patients
GET    /patients/{patient:public_id}
GET    /patients/{patient:public_id}/edit
PUT    /patients/{patient:public_id}
GET    /patients/{patient:public_id}/history
POST   /patients/provisional
POST   /patients/merge-preview
POST   /patients/merge
```

### 31.4 Recepção

```text
GET    /reception
GET    /reception/encounters/create
POST   /reception/drafts
PUT    /reception/drafts/{draft}
POST   /reception/encounters
GET    /reception/encounters/{encounter:public_id}/receipt
POST   /reception/encounters/{encounter:public_id}/cancel
```

### 31.5 Filas

```text
GET    /queues/{queue:public_id}
GET    /queues/{queue:public_id}/entries
POST   /queue-entries/{entry:public_id}/call
POST   /queue-entries/{entry:public_id}/recall
POST   /queue-entries/{entry:public_id}/start-service
POST   /queue-entries/{entry:public_id}/return
POST   /queue-entries/{entry:public_id}/mark-absent
POST   /queue-entries/{entry:public_id}/transfer
```

### 31.6 Painel

```text
GET    /panels/{panel:code}
GET    /panels/{panel:code}/state
POST   /panels/{panel:code}/heartbeat
```

### 31.7 Triagem

```text
GET    /triage/queue
POST   /triage/encounters/{encounter:public_id}/start
GET    /triage/{triage:public_id}
PUT    /triage/{triage:public_id}/draft
POST   /triage/{triage:public_id}/vital-signs
POST   /triage/{triage:public_id}/complete
POST   /triage/{triage:public_id}/addendum
```

### 31.8 Atendimento médico

```text
GET    /medical/queue
POST   /medical/encounters/{encounter:public_id}/start
GET    /medical/consultations/{consultation:public_id}
PUT    /medical/consultations/{consultation:public_id}/draft
POST   /medical/consultations/{consultation:public_id}/diagnoses
POST   /medical/consultations/{consultation:public_id}/prescriptions
POST   /medical/consultations/{consultation:public_id}/exam-orders
POST   /medical/consultations/{consultation:public_id}/clinical-notes
POST   /medical/consultations/{consultation:public_id}/referrals
POST   /medical/consultations/{consultation:public_id}/documents
POST   /medical/consultations/{consultation:public_id}/complete
POST   /medical/consultations/{consultation:public_id}/addendum
```

### 31.9 Destinação

```text
POST   /encounters/{encounter:public_id}/discharge
POST   /encounters/{encounter:public_id}/observation
POST   /encounters/{encounter:public_id}/admission-request
POST   /encounters/{encounter:public_id}/transfer
POST   /encounters/{encounter:public_id}/absence
POST   /encounters/{encounter:public_id}/death
```

### 31.10 Documentos

```text
GET    /documents/{document:public_id}
GET    /documents/{document:public_id}/pdf
POST   /documents/{document:public_id}/new-version
POST   /documents/{document:public_id}/void
GET    /document-verification/{publicCode}
```

### 31.11 Administração

CRUDs para:

- usuários;
- profissionais;
- unidades;
- setores;
- salas;
- pontos de atendimento;
- especialidades;
- tipos de entrada;
- formas de chegada;
- filas;
- painéis;
- níveis de risco;
- catálogos clínicos;
- configurações.

Rotas de escrita devem usar métodos HTTP corretos e proteção CSRF.

---

## 32. Validação e tratamento de erros

### 32.1 Categorias de erro

- erro de validação de formulário;
- regra de negócio violada;
- transição de estado inválida;
- conflito de concorrência;
- recurso não encontrado;
- acesso negado;
- falha de infraestrutura;
- invariante quebrada.

### 32.2 Exceções de domínio sugeridas

```text
ActiveEncounterAlreadyExists
EncounterStateTransitionNotAllowed
QueueEntryAlreadyInService
QueueEntryCannotBeCalled
TriageAlreadyStarted
TriageAlreadyFinalized
MedicalConsultationAlreadyStarted
MedicalConsultationAlreadyFinalized
ClinicalRecordIsImmutable
PatientMergeNotAllowed
ConcurrentUpdateDetected
DocumentGenerationFailed
UnauthorizedPatientAccess
```

### 32.3 Mensagens

Mensagens devem informar:

- o que não pôde ser realizado;
- por que, quando seguro;
- qual registro foi afetado;
- o que o usuário pode fazer.

Exemplo bom:

```text
Não foi possível iniciar a triagem. Este paciente já está em atendimento por
Ana Souza desde 10:42. Atualize a fila antes de tentar novamente.
```

Evitar:

```text
Erro inesperado.
```

Para falhas internas, mostrar código de referência ao usuário e registrar detalhes técnicos sem dados clínicos excessivos.

### 32.4 Respostas JSON

Para endpoints Alpine/fetch:

```json
{
  "success": false,
  "error": {
    "code": "QUEUE_ENTRY_ALREADY_IN_SERVICE",
    "message": "Este paciente já está em atendimento.",
    "fields": {}
  }
}
```

### 32.5 Idempotency key

Operações críticas devem aceitar cabeçalho ou campo:

```text
Idempotency-Key
```

A chave, usuário, rota, payload hash e resposta devem ser armazenados por período configurável.

---

## 33. Segurança, privacidade e LGPD

### 33.1 Princípios

- mínimo privilégio;
- necessidade de acesso;
- finalidade definida;
- rastreabilidade;
- retenção controlada;
- segurança em profundidade;
- separação entre ambiente real e desenvolvimento.

### 33.2 Autenticação

- login por usuário ou e-mail institucional;
- senha armazenada por hash forte suportado pelo Laravel;
- expiração de sessão configurável;
- invalidação de sessões ao desativar usuário;
- troca obrigatória de senha inicial;
- bloqueio temporário após tentativas excessivas;
- registro de último login;
- recuperação de senha por administrador no MVP offline;
- 2FA preparado para fase futura.

### 33.3 Autorização

- Roles e Permissions;
- Policies por recurso;
- escopo por unidade;
- escopo por setor;
- escopo por relação assistencial;
- acesso excepcional exige justificativa, se adotado pela instituição.

### 33.4 Sessões

- armazenamento em banco;
- cookie `HttpOnly`;
- cookie `SameSite=Lax` ou política mais restritiva compatível;
- cookie `Secure` em HTTPS;
- regeneração após login;
- logout remove sessão;
- limite configurável de sessões simultâneas.

### 33.5 Criptografia

- HTTPS interno obrigatório em produção;
- backups criptografados;
- segredos apenas em `.env` ou mecanismo de segredo da infraestrutura;
- campos extremamente sensíveis podem usar criptografia de aplicação após avaliação de impacto em busca e índices;
- nunca versionar `.env`.

### 33.6 Arquivos

- armazenamento privado;
- nomes aleatórios;
- validação de MIME e extensão;
- limite de tamanho;
- varredura antivírus quando disponível;
- download por controller autorizado;
- cabeçalhos seguros;
- hash SHA-256.

### 33.7 Auditoria

Registrar:

- login, logout e falhas;
- busca e abertura de prontuário quando exigido;
- criação e alteração de paciente;
- abertura e cancelamento de atendimento;
- mudança de status;
- chamadas;
- triagem;
- classificação;
- atendimento médico;
- diagnóstico;
- prescrição;
- documentos;
- impressão e download;
- destinação;
- fusão de pacientes;
- alteração de permissões;
- exportação de relatório.

### 33.8 Dados em desenvolvimento

- proibido usar dados reais;
- seeders devem usar nomes fictícios;
- documentos anexos devem ser sintéticos;
- dumps reais não podem ser enviados a serviços externos;
- homologação deve usar base anonimizada quando necessário.

### 33.9 Hardening

- MySQL acessível apenas pela rede interna de containers ou host autorizado;
- firewall no servidor;
- acesso remoto apenas por VPN;
- usuário do banco com privilégio mínimo;
- containers sem execução privilegiada;
- imagens fixadas por versão;
- atualizações controladas;
- cabeçalhos HTTP de segurança;
- proteção contra clickjacking;
- rate limit em login e endpoints sensíveis;
- validação CSRF;
- escaping padrão do Blade;
- evitar HTML não sanitizado vindo do usuário.

---

## 34. Auditoria funcional

### 34.1 Tipos de evento

```text
user.logged_in
user.login_failed
patient.created
patient.updated
patient.viewed
patient.merged
encounter.opened
encounter.status_changed
encounter.cancelled
queue.entry_created
queue.patient_called
queue.patient_recalled
queue.patient_absent
queue.entry_transferred
triage.started
triage.vital_signs_recorded
triage.risk_classified
triage.completed
medical.started
medical.diagnosis_added
medical.prescription_finalized
medical.document_issued
medical.completed
encounter.discharged
encounter.transferred
encounter.deceased
document.downloaded
report.exported
```

### 34.2 Contexto mínimo

- usuário;
- papel;
- unidade;
- ação;
- recurso;
- IDs internos e públicos;
- data/hora;
- IP;
- user-agent;
- campos alterados;
- motivo, quando aplicável.

### 34.3 Restrições

- auditoria não pode ser editada pela interface comum;
- limpeza somente por política institucional e rotina restrita;
- falha ao gravar auditoria em operação crítica deve impedir confirmação ou ser tratada por estratégia explicitamente documentada;
- nenhuma auditoria pode conter senha, token ou segredo.

---

## 35. Relatórios e indicadores

### 35.1 Relatórios operacionais

- atendimentos por período;
- por tipo de entrada;
- por forma de chegada;
- por unidade;
- por setor;
- por especialidade;
- por profissional;
- por classificação de risco;
- por destinação;
- por faixa etária;
- por sexo, quando autorizado;
- pacientes provisórios;
- atendimentos cancelados;
- evasões;
- transferências;
- observações;
- solicitações de internação.

### 35.2 Tempos

- chegada até recepção concluída;
- recepção até primeira chamada da triagem;
- chegada até início da triagem;
- duração da triagem;
- fim da triagem até chamada médica;
- fim da triagem até início médico;
- duração da consulta;
- tempo total no hospital;
- tempo por classificação;
- tempo por fila;
- tempo por setor.

### 35.3 Painel de chamadas

- total de chamadas;
- rechamadas;
- ausências;
- chamadas por painel;
- chamadas por ponto de atendimento;
- chamadas por profissional;
- tempo entre chamada e início.

### 35.4 Exportação

- PDF para relatórios resumidos;
- CSV para dados tabulares autorizados;
- exportação auditada;
- limite de período e volume;
- mascaramento de dados conforme perfil.

### 35.5 Definição dos cálculos

Os cálculos devem ser centralizados em Query Services ou classes de relatório, com testes. Não duplicar fórmulas em controllers, views e exportadores.

---

## 36. Observabilidade e operação

### 36.1 Logs

Canais separados:

- aplicação;
- segurança;
- filas;
- documentos;
- backup;
- auditoria em banco.

### 36.2 Health checks

```text
GET /health/live
GET /health/ready
```

`live` verifica processo.  
`ready` verifica banco, storage e dependências essenciais.

### 36.3 Métricas operacionais mínimas

- erros HTTP por período;
- jobs falhos;
- tempo de resposta;
- espaço em disco;
- último backup;
- tamanho do banco;
- painéis conectados;
- worker ativo;
- scheduler ativo.

### 36.4 Jobs falhos

- usar tabela `failed_jobs`;
- tela administrativa simples;
- retry apenas por usuário autorizado;
- erro com contexto e sem dados clínicos excessivos.

---

## 37. Backup e recuperação

### 37.1 Política mínima

- backup lógico completo do MySQL diariamente;
- cópia incremental ou adicional durante o dia conforme volume;
- backup dos arquivos privados;
- retenção configurável;
- cópia para equipamento diferente;
- cópia externa criptografada quando possível;
- teste de restauração periódico.

### 37.2 Serviço de backup

O container ou script deve:

1. gerar dump consistente;
2. compactar;
3. criptografar quando configurado;
4. calcular hash;
5. copiar arquivos necessários;
6. registrar resultado em `backup_logs`;
7. aplicar retenção;
8. emitir alerta em falha.

### 37.3 Restauração

Documentar:

- pré-requisitos;
- restauração do banco;
- restauração dos arquivos;
- validação de hashes;
- execução de migrations pendentes;
- verificação do sistema;
- plano de retorno.

---

## 38. Implantação local

### 38.1 Endereço

Exemplo:

```text
https://syncsus.local
```

O DNS interno ou arquivo de hosts deve ser configurado pela infraestrutura.

### 38.2 Variáveis de ambiente

```text
APP_NAME="SYNC HOSP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://syncsus.local
APP_TIMEZONE=America/Fortaleza
APP_LOCALE=pt_BR

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=sync_sus
DB_USERNAME=sync_sus
DB_PASSWORD=...

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
FILESYSTEM_DISK=local_private

SYNC_SUS_PANEL_POLL_SECONDS=2
SYNC_SUS_DASHBOARD_POLL_SECONDS=15
SYNC_SUS_REQUIRE_HTTPS=true
SYNC_SUS_BACKUP_PATH=/backups
```

### 38.3 Atualização

1. verificar espaço;
2. executar backup;
3. ativar manutenção;
4. atualizar imagens ou código;
5. executar migrations;
6. limpar e recriar caches;
7. executar smoke tests;
8. remover manutenção;
9. registrar versão instalada.

### 38.4 Rollback

- imagem anterior preservada;
- backup antes da migration;
- migrations destrutivas exigem estratégia de expansão/contração;
- não depender de rollback de migration em produção como única proteção.

---

## 39. Testes

### 39.1 Estratégia

Testar comportamento observável, incluindo caminhos de erro. Não perseguir percentual de cobertura como objetivo isolado.

### 39.2 Tipos

- unitários para enums, value objects e regras puras;
- feature para casos de uso HTTP;
- integração para banco, filas e documentos;
- autorização para cada papel;
- concorrência para ações críticas;
- smoke tests de implantação;
- testes de interface essenciais quando viável.

### 39.3 Cenários obrigatórios

#### Pacientes

- criar paciente completo;
- criar provisório;
- impedir CPF duplicado conforme regra;
- alertar possível duplicidade;
- fundir pacientes com auditoria;
- negar fusão sem permissão.

#### Recepção

- abrir atendimento;
- gerar número e senha;
- detectar atendimento ativo;
- impedir duplo envio por idempotência;
- validar campos condicionais;
- cancelar com justificativa.

#### Filas

- ordenar corretamente;
- chamar;
- rechamar;
- marcar ausência;
- transferir;
- impedir dois profissionais iniciarem o mesmo paciente;
- painel receber somente dados permitidos.

#### Triagem

- iniciar;
- registrar sinais;
- finalizar classificação;
- impedir nível inválido;
- impedir edição após finalização;
- criar adendo;
- encaminhar para fila correta.

#### Atendimento médico

- iniciar consulta;
- salvar rascunho;
- registrar diagnóstico;
- finalizar prescrição;
- emitir documento;
- impedir finalização sem requisitos;
- detectar conflito;
- finalizar com cada destinação.

#### Segurança

- negar rota sem permissão;
- limitar unidade;
- registrar acesso ao prontuário;
- mascarar dados no painel;
- proteger download de arquivo;
- bloquear login excessivo.

### 39.4 Determinismo

- usar `Carbon::setTestNow` ou relógio injetável;
- factories independentes;
- sem internet;
- sem dependência de ordem;
- banco isolado por teste;
- arquivos temporários controlados.

---

## 40. Dados de demonstração

Seeders devem criar somente dados fictícios.

### 40.1 Estrutura inicial

- organização `Hospital Municipal Demonstrativo`;
- unidade `Urgência Central`;
- setores: Recepção, Triagem, Clínica Médica, Ortopedia;
- salas: Triagem 01, Triagem 02, Consultório 01 a 04;
- filas correspondentes;
- painel principal;
- níveis de risco;
- tipos de entrada;
- formas de chegada;
- especialidades;
- papéis e permissões.

### 40.2 Usuários de desenvolvimento

Criados a partir de variáveis de ambiente ou comando explícito. Não manter senha padrão fixa em produção.

### 40.3 Pacientes de demonstração

Usar nomes evidentemente fictícios e documentos válidos apenas para teste, nunca pertencentes a pessoas reais conhecidas.

---

## 41. Backlog por fase

### Fase 0 — Fundação técnica

- bootstrap Laravel;
- Docker Compose;
- Nginx;
- MySQL;
- Blade, Alpine e Tailwind;
- autenticação;
- sessão em banco;
- Pint;
- Larastan;
- PHPUnit;
- layout principal;
- tratamento de erros;
- auditoria base;
- health checks.

### Fase 1 — Administração

- organização;
- unidades;
- setores;
- salas;
- pontos de atendimento;
- especialidades;
- tipos de entrada;
- formas de chegada;
- níveis de risco;
- usuários;
- profissionais;
- papéis e permissões.

### Fase 2 — Pacientes

- busca;
- cadastro;
- documentos;
- contatos;
- endereço;
- responsável;
- provisório;
- duplicidade;
- histórico;
- auditoria de acesso.

### Fase 3 — Recepção

- wizard;
- rascunho;
- abertura;
- acompanhante;
- número de atendimento;
- senha;
- atendimento ativo;
- idempotência;
- comprovante.

### Fase 4 — Filas e painel

- filas;
- entradas;
- ordenação;
- chamadas;
- rechamadas;
- ausência;
- transferência;
- painel;
- áudio offline;
- heartbeat.

### Fase 5 — Triagem

- fila;
- início concorrente;
- avaliação;
- sinais vitais;
- alertas;
- classificação;
- procedimentos;
- encaminhamento;
- adendo.

### Fase 6 — Atendimento médico

- fila médica;
- resumo;
- anamnese;
- exame físico;
- diagnóstico;
- conduta;
- prescrição;
- receita;
- exames;
- evolução;
- encaminhamento;
- documentos;
- destinação.

### Fase 7 — Dashboard e relatórios

- métricas;
- tempos;
- relatórios;
- exportação;
- filtros;
- permissões.

### Fase 8 — Operação e homologação

- backup;
- restauração;
- hardening;
- testes de carga básicos;
- treinamento;
- piloto;
- correções;
- documentação de operação.

---

## 42. Critérios de aceite por fluxo

### 42.1 Recepção completa

Dado um paciente existente ou novo, a recepcionista consegue:

1. informar dados da chegada;
2. localizar ou cadastrar o paciente;
3. revisar dados;
4. escolher fila inicial;
5. finalizar uma única vez;
6. receber número de atendimento e senha;
7. visualizar o paciente na fila correta;
8. consultar a auditoria da ação.

### 42.2 Triagem completa

Dado paciente aguardando triagem, profissional autorizado consegue:

1. chamar no painel;
2. iniciar sem conflito;
3. registrar avaliação;
4. registrar sinais vitais;
5. confirmar classificação;
6. escolher destino;
7. finalizar;
8. ver o paciente na fila médica ou destino definido;
9. impedir edição silenciosa posterior.

### 42.3 Atendimento médico completo

Dado paciente aguardando médico, o médico consegue:

1. chamar;
2. iniciar consulta;
3. consultar triagem;
4. registrar anamnese, exame, diagnóstico e conduta;
5. prescrever ou solicitar exames;
6. emitir documentos;
7. definir destinação;
8. finalizar;
9. consultar registro finalizado;
10. criar adendo sem apagar conteúdo anterior.

### 42.4 Painel

- exibe chamada nova em até poucos segundos;
- reproduz áudio local;
- não mostra dados proibidos;
- mantém últimas chamadas;
- recupera conexão;
- não repete áudio antigo após recarregar.

### 42.5 Segurança

- recepção não vê conteúdo clínico;
- usuário sem unidade não acessa atendimento da unidade;
- download exige permissão;
- ações críticas geram auditoria;
- sessão expira;
- paciente finalizado não é editado silenciosamente.

---

## 43. Definição de pronto

Uma história só está pronta quando:

1. comportamento e casos de erro foram implementados;
2. autorização foi testada;
3. validação backend existe;
4. auditoria foi considerada;
5. interface possui estados de carregamento, vazio e erro;
6. testes relevantes passam;
7. Pint passa;
8. Larastan/PHPStan passa no nível definido;
9. ESLint e Prettier passam quando houver JS alterado;
10. migrations e seeders funcionam do zero;
11. não há dados reais;
12. documentação foi atualizada;
13. não há TODO sem contexto;
14. não há código morto;
15. critérios do arquivo de Clean Code foram revisados.

---

## 44. Decisões arquiteturais explícitas

### 44.1 Por que monólito modular

O domínio é amplo, mas o MVP será instalado localmente e operado por equipe pequena. Um monólito modular reduz custo de implantação, autenticação distribuída, observabilidade e consistência transacional. Microserviços só devem ser considerados quando houver necessidade operacional comprovada.

### 44.2 Por que Blade + Alpine.js

O sistema é orientado a formulários, tabelas e fluxos internos. Blade entrega HTML no servidor e Alpine adiciona interações pequenas sem o custo de uma SPA. Isso favorece desempenho, simplicidade e manutenção.

### 44.3 Por que MySQL

É conhecido pela equipe, adequado ao volume inicial e possui suporte sólido no Laravel. O modelo deve usar transações, índices e constraints corretamente.

### 44.4 Por que polling no painel

Polling de dois segundos em rede local é suficiente para o MVP e reduz complexidade de WebSocket. O endpoint será incremental. WebSocket poderá ser introduzido depois sem alterar a regra de chamada.

### 44.5 Por que fila em banco

Jobs do MVP são poucos e locais. O driver de banco reduz dependências. Redis pode ser adicionado quando métricas demonstrarem necessidade.

### 44.6 Por que registros clínicos imutáveis

A sobrescrita destrói rastreabilidade. Rascunhos podem mudar; registros finalizados só recebem adendo, nova versão ou anulação justificada.

---

## 45. Arquivos de referência visual

A pasta `design/` contém as nove telas de referência do SYNC HOSP:

```text
01_dashboard.png
02_recepcao_abertura.png
03_recepcao_busca_paciente.png
04_recepcao_dados_paciente.png
05_recepcao_encaminhamento.png
06_fila_triagem.png
07_triagem_classificacao_risco.png
08_triagem_sinais_vitais.png
09_atendimento_medico.png
```

Essas imagens definem direção de layout, hierarquia e identidade visual. O código deve reconstruir os componentes de forma responsiva e acessível, sem transformar imagens em telas estáticas.

---

## 46. Resultado esperado do MVP

O MVP estará funcional quando a unidade conseguir executar, de ponta a ponta, o seguinte cenário:

```text
Recepcionista identifica paciente
→ abre atendimento
→ gera senha
→ paciente aparece na fila
→ enfermeira chama pelo painel
→ registra triagem e risco
→ envia à fila médica
→ médico chama
→ registra consulta
→ emite receita ou pedido de exame
→ define alta ou outra destinação
→ sistema preserva histórico e auditoria
```

Esse é o limite funcional que deve orientar a primeira entrega. Funcionalidades fora desse fluxo só entram quando não comprometerem a conclusão, estabilidade e homologação do núcleo.
