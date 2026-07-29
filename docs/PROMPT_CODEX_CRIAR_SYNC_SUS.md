# Prompt para o Codex — Criar o SYNC SUS

Copie todo o conteúdo abaixo e forneça ao Codex dentro da raiz do repositório que deverá receber o projeto.

---

## PROMPT

Você é o engenheiro de software principal responsável por construir o **SYNC SUS**, um sistema web hospitalar para urgência e emergência, instalado em servidor local e acessado pela rede interna.

Sua tarefa não é apenas gerar um exemplo, protótipo descartável ou conjunto de telas estáticas. Você deve criar uma aplicação Laravel funcional, organizada, testada, executável por Docker Compose e aderente à especificação e às regras de Clean Code presentes neste repositório.

## 1. Fontes obrigatórias

Antes de escrever código, leia integralmente:

```text
docs/SYNC_SUS_ESPECIFICACAO.md
docs/REGRAS_CLEAN_CODE.md
```

Use também as referências visuais:

```text
design/01_dashboard.png
design/02_recepcao_abertura.png
design/03_recepcao_busca_paciente.png
design/04_recepcao_dados_paciente.png
design/05_recepcao_encaminhamento.png
design/06_fila_triagem.png
design/07_triagem_classificacao_risco.png
design/08_triagem_sinais_vitais.png
design/09_atendimento_medico.png
```

A especificação funcional é a fonte de verdade do escopo. O arquivo de Clean Code é normativo. As imagens definem direção visual e hierarquia, não substituem os requisitos.

Quando houver conflito:

1. segurança e integridade clínica;
2. especificação funcional;
3. regras de Clean Code;
4. referência visual;
5. conveniência de implementação.

Não invente um módulo fora do escopo para “completar” o produto.

## 2. Comportamento esperado de execução

1. Inspecione o repositório atual antes de alterar qualquer arquivo.
2. Se o repositório estiver vazio, inicialize o projeto Laravel na raiz.
3. Se já houver código, preserve o que for válido e compatível; não reescreva sem necessidade.
4. Crie um arquivo `IMPLEMENTATION_STATUS.md` com checklist das fases.
5. Trabalhe de forma incremental e atualize esse checklist após cada fase.
6. Não afirme que uma fase está concluída sem executar os testes e verificações correspondentes.
7. Não faça perguntas quando houver uma decisão razoável já definida na especificação.
8. Quando uma ambiguidade realmente bloquear a implementação, documente a suposição em `docs/DECISIONS.md` e escolha a alternativa mais segura e simples.
9. Continue até entregar o maior número possível de fases funcionais dentro da execução disponível.
10. Ao final, apresente resumo do que foi criado, pendências reais e comandos exatos para execução.

## 3. Stack obrigatória

Use:

- PHP em versão estável compatível com o Laravel escolhido;
- Laravel em versão estável suportada pelo ambiente;
- MySQL 8 ou superior;
- Blade;
- Alpine.js;
- Tailwind CSS;
- Vite;
- Nginx;
- PHP-FPM;
- Docker Compose;
- Laravel Queue com driver `database`;
- Laravel Scheduler;
- sessões em banco;
- cache em banco no MVP;
- PHPUnit;
- Laravel Pint;
- Larastan/PHPStan;
- ESLint e Prettier para JavaScript;
- `spatie/laravel-permission`, em versão compatível;
- Dompdf, encapsulado atrás de contrato interno, para geração inicial de PDFs.

Não use:

- React;
- Vue;
- Livewire;
- Inertia;
- uma SPA;
- microserviços;
- Redis no MVP;
- CDN para arquivos essenciais;
- APIs externas obrigatórias;
- banco SQLite como banco principal;
- dependência de internet em runtime.

## 4. Convenções obrigatórias

- Código, classes, tabelas, colunas e enums em inglês.
- Interface, validações e documentos em Português do Brasil.
- O termo oficial para pessoa atendida é `Patient`.
- O termo oficial para episódio hospitalar é `Encounter`.
- Use ULID público e bigint interno nos agregados expostos em URL.
- Use route model binding por `public_id` ou código público.
- Não exponha IDs sequenciais nas URLs.
- Use strict types nos arquivos PHP próprios quando compatível.
- Use enums PHP para estados estáveis.
- Use DTOs quando um caso de uso tiver contrato com muitos campos.
- Use Form Requests para validação HTTP.
- Use Policies e Gates para autorização contextual.
- Use Actions para casos de uso com escrita.
- Use Query Services para consultas complexas e relatórios.
- Use transação para operações que alteram estado, fila, triagem, consulta, documento ou destinação.
- Não crie repositório genérico para todos os Models.
- Não coloque lógica de negócio em Controller, Blade ou JavaScript.
- Não use comentários que apenas descrevam o código.
- Não capture exceções silenciosamente.

## 5. Arquitetura de pastas

Implemente um monólito modular pragmático baseado na estrutura:

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

Estrutura interna esperada:

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

Aplique essa estrutura com pragmatismo. Não crie arquivos vazios apenas para simular arquitetura. Cada arquivo deve ter responsabilidade real.

## 6. Entregáveis obrigatórios de infraestrutura

Crie:

```text
Dockerfile
docker-compose.yml
docker/nginx/default.conf
docker/php/php.ini
.env.example
Makefile ou scripts equivalentes
README.md
IMPLEMENTATION_STATUS.md
docs/DECISIONS.md
docs/OPERATIONS.md
docs/BACKUP_RESTORE.md
```

O Docker Compose deve conter:

```text
nginx
app
mysql
queue-worker
scheduler
backup
```

Requisitos:

- healthcheck do MySQL;
- dependências condicionadas ao healthcheck;
- volume persistente do banco;
- volume de arquivos privados;
- volume de backups;
- nenhum segredo real versionado;
- rede interna para banco;
- Nginx expõe apenas HTTP/HTTPS da aplicação;
- usuário não-root quando viável;
- `APP_DEBUG=false` no exemplo de produção;
- comandos documentados para desenvolvimento e produção local.

## 7. Bootstrap e ferramentas de qualidade

Configure:

- Laravel Pint;
- Larastan/PHPStan;
- PHPUnit;
- ESLint;
- Prettier;
- scripts Composer e NPM para lint, teste e build.

Scripts desejados:

```text
composer test
composer lint
composer analyse
composer quality
npm run lint
npm run format:check
npm run build
```

`composer quality` deve executar, no mínimo, Pint em modo de verificação, análise estática e testes.

Não reduza regras da análise estática apenas para fazer o comando passar. Corrija o código ou documente exceção específica e justificada.

## 8. Banco de dados

Implemente migrations para o modelo definido na especificação, priorizando as tabelas necessárias ao fluxo MVP.

Mínimo obrigatório:

### Administração e identidade

```text
organizations
health_units
departments
rooms
service_points
specialties
entry_types
arrival_methods
risk_levels
users
professionals
professional_health_unit
professional_specialty
professional_department
roles e permissions
```

### Pacientes

```text
patients
patient_identifiers
patient_contacts
patient_addresses
patient_guardians
patient_allergies
patient_conditions
```

### Atendimento e recepção

```text
encounters
reception_records
encounter_companions
encounter_status_history
number_sequences
idempotency_keys
```

### Filas e painel

```text
queues
queue_entries
queue_calls
display_panels
display_panel_queue
queue_sequences
```

### Triagem

```text
triages
vital_signs
triage_alerts
triage_procedures
```

### Atendimento médico

```text
medical_consultations
physical_exams
diagnoses
clinical_notes
prescriptions
prescription_items
exam_orders
exam_order_items
exam_results
referrals
```

### Destinação

```text
discharges
observations
admission_requests
transfers
patient_absences
death_records
```

### Documentos e auditoria

```text
documents
document_versions
attachments
audit_logs
patient_access_logs
backup_logs
```

Use:

- FKs explícitas;
- índices compostos para filas e relatórios;
- uniques em números públicos;
- strings estáveis para enums persistidos;
- `lock_version` onde houver edição concorrente;
- `voided_at` e `void_reason` para anulação clínica;
- soft delete apenas em catálogos administrativos quando fizer sentido.

Não use JSON para esconder modelagem central. JSON pode ser usado apenas para metadata ou payload estruturado de documento.

## 9. Enums e máquina de estados

Crie enums para, no mínimo:

```text
EncounterStatus
QueueEntryStatus
RiskLevelCode
ClinicalRecordStatus
PrescriptionType
PrescriptionStatus
DocumentStatus
DiagnosisType
DestinationType
```

Implemente serviços ou Actions explícitos para transições.

Exemplos:

```text
OpenEncounterAction
MoveEncounterToTriageQueueAction
CallQueueEntryAction
StartTriageAction
CompleteTriageAction
MoveEncounterToMedicalQueueAction
StartMedicalConsultationAction
CompleteMedicalConsultationAction
DischargeEncounterAction
TransferEncounterAction
```

Não faça:

```php
$encounter->update(['current_status' => 'waiting_medical']);
```

fora do serviço de transição apropriado.

Toda transição deve:

1. validar estado atual;
2. validar permissão;
3. validar pré-condições;
4. usar `DB::transaction`;
5. bloquear linha quando necessário;
6. atualizar o agregado;
7. gravar histórico;
8. gravar auditoria;
9. retornar resultado tipado ou entidade atualizada.

## 10. Autenticação, papéis e permissões

Implemente autenticação Blade local.

Papéis iniciais:

```text
administrator
receptionist
triage_professional
doctor
manager
auditor
```

Crie permissions granulares, por exemplo:

```text
patients.view
patients.create
patients.update
patients.merge
encounters.open
encounters.cancel
queues.view
queues.call
queues.transfer
triage.view
triage.start
triage.complete
triage.addendum
medical.view
medical.start
medical.complete
medical.prescribe
medical.issue_documents
reports.view
audit.view
administration.manage
```

Implemente escopo de unidade. Um usuário não deve acessar outra unidade sem vínculo ou permissão global.

Configure:

- session driver database;
- troca obrigatória de senha inicial;
- login rate limit;
- desativação de usuário;
- logout;
- middleware de unidade ativa;
- último login;
- auditoria de login e falha.

Recuperação de senha no MVP pode ser feita por administrador, sem depender de e-mail.

## 11. Seeders e factories

Crie seeders idempotentes para:

- organização demonstrativa;
- unidade `Urgência Central`;
- setores;
- salas;
- pontos de atendimento;
- especialidades;
- tipos de entrada;
- formas de chegada;
- níveis de risco;
- filas;
- painel;
- papéis e permissões;
- profissionais fictícios;
- usuários de desenvolvimento;
- pacientes fictícios;
- atendimentos demonstrativos.

Nunca use dados reais mostrados nas referências.

O admin de desenvolvimento deve ser criado por variáveis de ambiente:

```text
SYNC_SUS_ADMIN_NAME
SYNC_SUS_ADMIN_EMAIL
SYNC_SUS_ADMIN_PASSWORD
```

Em produção, o seeder deve recusar senha ausente ou insegura.

## 12. Componentes visuais

Crie layout próprio baseado nas imagens:

- sidebar azul-marinho;
- logo SYNC SUS;
- item ativo em azul;
- topbar clara;
- cards brancos;
- tabelas limpas;
- badges textuais de risco;
- formulários em etapas;
- ações primárias em azul;
- confirmações em verde;
- layout adequado para 1366×768.

Crie componentes Blade reutilizáveis:

```text
layout.app
layout.sidebar
layout.topbar
page.header
card
stat-card
form.input
form.select
form.textarea
form.checkbox
form.radio-group
button.primary
button.secondary
button.danger
badge.status
badge.risk
table
modal
confirm-dialog
empty-state
alert
patient-summary
encounter-summary
timeline
```

Requisitos de acessibilidade:

- labels ligados aos inputs;
- foco visível;
- navegação por teclado;
- `aria-*` quando necessário;
- ícones com texto acessível;
- risco não comunicado apenas por cor;
- mensagens de validação associadas ao campo;
- contraste adequado.

## 13. Alpine.js

Use Alpine apenas para interação de interface:

```text
patientSearch
receptionWizard
queueMonitor
callPanel
triageForm
prescriptionEditor
medicalCare
confirmDialog
```

Regras:

- regras de negócio permanecem no Laravel;
- endpoints retornam JSON consistente;
- usar CSRF;
- exibir loading, sucesso e erro;
- abortar request anterior em buscas rápidas quando possível;
- debounce na busca;
- evitar estado global mutável desnecessário;
- limpar listeners ao destruir componente;
- nenhum CDN;
- código modular em `resources/js/alpine`.

## 14. Implementação das telas

### 14.1 Tela 1 — Dashboard

Implemente:

- cartões de métricas;
- tabela de atendimentos em andamento;
- badges de risco;
- alertas operacionais;
- filtros;
- polling a cada 15 segundos;
- última atualização;
- endpoints otimizados;
- autorização por perfil.

### 14.2 Tela 2 — Abertura do atendimento

Wizard de dados da recepção com:

- data/hora;
- unidade;
- operador;
- tipo de entrada;
- forma de chegada;
- origem;
- prioridade administrativa;
- motivo;
- observações;
- campos condicionais.

### 14.3 Tela 3 — Busca de paciente

Implemente:

- busca unificada;
- normalização de CPF/CNS;
- paginação;
- resultados mascarados;
- possível duplicidade;
- seleção;
- paciente não identificado.

### 14.4 Tela 4 — Dados do paciente

Abas:

- dados pessoais;
- documentos;
- endereço;
- contatos;
- responsáveis;
- complementares.

Implemente validação backend e persistência transacional.

### 14.5 Tela 5 — Encaminhamento

Implemente:

- destino inicial;
- especialidade;
- profissional filtrado;
- setor;
- sala;
- fila;
- observações;
- resumo;
- finalização idempotente.

### 14.6 Tela 6 — Fila da triagem

Implemente:

- filtros;
- polling;
- ordenação no backend;
- chamar;
- rechamar;
- iniciar;
- ausência;
- transferência;
- concorrência;
- mensagens de conflito.

### 14.7 Tela 7 — Classificação de risco

Implemente:

- queixa;
- história resumida;
- fluxo e discriminador;
- alergias;
- medicamentos;
- condições;
- exame inicial;
- nível de risco;
- justificativa;
- destino;
- finalização.

Não implemente decisão automática de risco.

### 14.8 Tela 8 — Sinais vitais

Implemente:

- todos os campos da especificação;
- unidades;
- IMC no backend;
- limites técnicos configuráveis;
- confirmação para valores fora da faixa de sanidade;
- histórico de aferições;
- nenhum valor vazio convertido para zero.

### 14.9 Tela 9 — Atendimento médico

Implemente:

- resumo lateral;
- abas;
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
- destinação;
- rascunho;
- conflito de versão;
- finalização.

### 14.10 Painel de chamadas

Embora não esteja nas nove imagens, é obrigatório.

Implemente:

- rota pública por código não sequencial;
- token ou mecanismo técnico seguro;
- chamada atual;
- últimas chamadas;
- modo de identificação;
- polling a cada 2 segundos;
- heartbeat;
- aviso de desconexão;
- áudio local por senha;
- fallback sem síntese de nome;
- prevenção de repetição após recarregar;
- payload sem dados clínicos.

## 15. Paciente provisório e duplicidade

Implemente paciente provisório com:

- nome gerado;
- faixa etária estimada;
- sexo aparente ou não informado;
- descrição;
- `is_provisional`;
- atendimento completo permitido.

Implemente serviço de busca de possíveis duplicidades.

Implemente preview de fusão e ação de fusão somente se houver tempo após o fluxo principal. Se a fusão completa não for entregue, entregue a detecção e documente a pendência; não faça fusão insegura.

## 16. Numeração e concorrência

Implemente geração atômica para:

- prontuário;
- número de atendimento;
- senha de fila;
- versão de documento.

Use tabelas de sequência e `lockForUpdate`.

Use `lock_version` ou estratégia equivalente para detectar edição concorrente em:

- encounter;
- queue_entry;
- triage;
- medical_consultation.

Crie teste que simule dois profissionais tentando iniciar o mesmo atendimento.

## 17. Auditoria

Crie serviço central de auditoria.

Registre os eventos definidos na especificação.

Não registre:

- senha;
- token;
- conteúdo completo de anamnese em log comum;
- receita completa em log comum;
- CPF/CNS completos em log técnico.

Crie `patient_access_logs` ao abrir histórico clínico ou prontuário.

Crie tela de auditoria para administrador/auditor com filtros:

- período;
- usuário;
- ação;
- paciente;
- atendimento;
- unidade.

## 18. Documentos e arquivos

Implemente armazenamento privado.

Crie contrato:

```php
interface DocumentRenderer
{
    public function render(DocumentRenderData $data): RenderedDocument;
}
```

Implemente adaptador Dompdf.

Crie, no mínimo:

- receita simples;
- atestado;
- declaração de comparecimento;
- orientação de alta;
- resumo de atendimento.

Cada documento deve possuir:

- `public_id`;
- versão;
- hash SHA-256;
- PDF privado;
- rota autorizada de download;
- auditoria de emissão e download.

Não permita acesso direto pelo caminho do storage.

## 19. Relatórios

Implemente inicialmente:

- atendimentos por período;
- por status;
- por classificação;
- por especialidade;
- por profissional;
- por destinação;
- tempos médios principais;
- chamadas e ausências.

Use Query Services testáveis.

Permita CSV e PDF apenas conforme permissão.

## 20. Backup e operação

Crie script ou container de backup que:

- execute `mysqldump` consistente;
- compacte;
- calcule hash;
- copie arquivos privados;
- aplique retenção;
- registre em `backup_logs` quando chamado pela aplicação ou script integrado;
- retorne código de saída correto.

Documente restauração em `docs/BACKUP_RESTORE.md`.

Crie health endpoints:

```text
/health/live
/health/ready
```

`ready` verifica banco e storage.

## 21. Testes obrigatórios

Não teste detalhes de implementação. Teste comportamento.

Crie testes para:

### Autorização

- recepcionista não acessa conteúdo clínico;
- triagem não prescreve;
- médico não gerencia usuários;
- gestor não altera prontuário;
- usuário não acessa unidade sem vínculo.

### Pacientes

- criação completa;
- provisório;
- CPF normalizado;
- duplicidade;
- atualização;
- acesso auditado.

### Recepção

- abertura completa;
- sequência única;
- idempotência;
- atendimento ativo;
- campos condicionais;
- fila inicial.

### Filas

- ordenação;
- chamada;
- rechamada;
- ausência;
- transferência;
- conflito concorrente;
- payload seguro do painel.

### Triagem

- início;
- sinais vitais;
- IMC;
- classificação;
- encaminhamento;
- finalização;
- bloqueio posterior;
- adendo.

### Atendimento médico

- início;
- rascunho;
- diagnóstico;
- prescrição;
- exame;
- documento;
- validação de finalização;
- destinação;
- imutabilidade;
- conflito de versão.

### Documentos

- geração;
- versão;
- hash;
- autorização de download;
- auditoria.

### Segurança

- rate limit de login;
- CSRF;
- arquivo privado;
- mascaramento;
- painel sem dados sensíveis.

Use relógio controlado, factories e banco isolado. Nenhum teste pode depender de internet ou ordem de execução.

## 22. Performance

Considere computadores modestos e rede local.

- eager loading explícito;
- evitar N+1;
- paginação;
- índices adequados;
- endpoints de polling incrementais;
- respostas pequenas;
- scripts e CSS compilados;
- nenhuma biblioteca frontend pesada;
- cache somente onde a invalidação for clara;
- não otimizar por suposição sem medir.

Crie pelo menos uma verificação de consulta da fila e documente os índices usados.

## 23. Segurança

Implemente:

- escaping do Blade;
- CSRF;
- Policies;
- rate limiting;
- cookies seguros em produção;
- headers de segurança no Nginx;
- upload validado;
- downloads autorizados;
- logs sem dados excessivos;
- nenhuma credencial no Git;
- `.env.example` sem segredos;
- banco não exposto publicamente;
- `APP_DEBUG=false` em produção.

Não use dados de pacientes reais em seeders, testes ou screenshots.

## 24. Ordem de execução

Execute na ordem abaixo.

### Fase A — Inspeção e planejamento

- ler arquivos;
- inspecionar repositório;
- criar status;
- registrar decisões;
- definir versão efetiva das dependências.

### Fase B — Fundação

- Laravel;
- Docker;
- MySQL;
- Nginx;
- Blade/Alpine/Tailwind;
- autenticação;
- qualidade;
- layout;
- health checks.

### Fase C — Administração e permissões

- migrations;
- seeders;
- CRUDs essenciais;
- escopo de unidade.

### Fase D — Pacientes

- cadastro;
- busca;
- provisório;
- auditoria.

### Fase E — Recepção

- wizard;
- abertura transacional;
- numeração;
- idempotência.

### Fase F — Filas e painel

- fila;
- chamada;
- painel;
- áudio;
- concorrência.

### Fase G — Triagem

- avaliação;
- sinais;
- risco;
- encaminhamento.

### Fase H — Atendimento médico

- consulta;
- diagnóstico;
- prescrição;
- exames;
- evolução;
- documentos;
- destinação.

### Fase I — Dashboard, relatórios e operação

- métricas;
- relatórios;
- backup;
- documentação.

Após cada fase:

1. execute migrations do zero;
2. execute seeders;
3. execute testes;
4. execute Pint;
5. execute análise estática;
6. execute build frontend;
7. atualize `IMPLEMENTATION_STATUS.md`;
8. corrija falhas antes de avançar.

## 25. Critérios mínimos para considerar o produto executável

O fluxo abaixo deve funcionar com dados fictícios:

1. recepcionista entra no sistema;
2. localiza ou cadastra paciente;
3. abre atendimento;
4. gera número e senha;
5. paciente aparece na fila de triagem;
6. profissional chama no painel;
7. inicia triagem;
8. registra sinais e risco;
9. envia para fila médica;
10. médico chama;
11. inicia atendimento;
12. registra anamnese, exame, diagnóstico e conduta;
13. cria prescrição ou receita;
14. emite documento;
15. dá alta ou registra outra destinação;
16. atendimento fica encerrado e imutável;
17. auditor consegue verificar as ações.

Não considere a aplicação concluída se as telas existirem, mas o fluxo não persistir corretamente.

## 26. Checklist de Clean Code antes da entrega

Antes de finalizar, confirme por escrito em `IMPLEMENTATION_STATUS.md`:

1. Pint executado.
2. Larastan/PHPStan executado.
3. PHPUnit executado.
4. ESLint/Prettier executados.
5. Cada função possui responsabilidade coesa.
6. Não há parâmetros booleanos ambíguos.
7. Não há captura silenciosa de exceção.
8. Erros de domínio possuem tipos específicos.
9. Não há lógica de negócio em Controllers ou Blade.
10. Não há mutação invisível de argumentos.
11. Testes validam comportamento e erros.
12. Não há TODO sem contexto.
13. Não há código morto.
14. Decisões não óbvias estão em `docs/DECISIONS.md`.
15. Não há dados reais.

## 27. Saída final esperada

Ao terminar, responda com:

### Implementado

Lista objetiva de módulos e fluxos realmente funcionando.

### Testes e qualidade

Comandos executados e resultados.

### Como executar

Comandos exatos, por exemplo:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
npm install
npm run build
```

Ajuste os comandos à estrutura realmente criada.

### Usuários de demonstração

Informe como criá-los ou quais variáveis usar. Não publique senha fixa de produção.

### Pendências

Liste apenas pendências reais e específicas. Não declare concluído o que não foi testado.

### Decisões

Resuma decisões arquiteturais adicionadas em `docs/DECISIONS.md`.

Comece agora pela inspeção do repositório, leitura integral dos documentos e criação do `IMPLEMENTATION_STATUS.md`. Em seguida, implemente as fases na ordem definida.

---

## FIM DO PROMPT
