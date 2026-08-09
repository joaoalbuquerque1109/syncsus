# Integração SYNC HOSP e Synclab

## Escopo ativo

O SYNC HOSP envia requisições de exames criadas na recepção ou no consultório médico para o Synclab. O envio é assíncrono, isolado por unidade e direcionado ao endpoint:

```text
POST https://synclabweb.unisync.com.br/app/addrequisicao/{cnes}
```

Para São Caetano/PE, o CNES de teste é `6612547`. O campo `ordem_servico` enviado ao Synclab continua sendo o `id` numérico de `exam_orders`, que também aparece como ID no grid do SYNC HOSP. Essa compatibilidade não deve ser removida durante a transição de identificadores.

Somente uma resposta HTTP 200 confirma a transmissão. Respostas 429 e 5xx entram em nova tentativa; outros códigos HTTP ficam rejeitados para revisão operacional.

O JSON da primeira tentativa fica armazenado de forma criptografada e imutável.
As tentativas posteriores reutilizam exatamente esse snapshot, mesmo que o
cadastro do paciente ou do profissional seja alterado depois da emissão.
Respostas do provedor também ficam criptografadas no histórico de tentativas.

Um envio em estado `sending` recebe uma concessão temporária (lease). Se o
worker for interrompido e a concessão expirar, o pedido vai para
`manual_review`; ele não é reenviado automaticamente, pois o Synclab pode ter
recebido a chamada antes da queda. Confirme primeiro no sistema de destino.

## Catálogo

O arquivo versionado `database/data/synclab_exams.csv` é importado para
`laboratory_exams`. O seeder aceita exclusivamente linhas com `itemexame = 0`;
itens/componentes não podem ser selecionados diretamente. A quantidade de
exames-pai não é fixa: inclusões e remoções do fornecedor são aceitas e geram
uma nova rodada de matching revisável. Um arquivo alternativo pode ser indicado
por `SYNC_SUS_SYNCLAB_CATALOG_PATH`.

O matching nunca consolida exames automaticamente apenas pelo nome:

- código externo já mapeado: `exact`, reaplicado automaticamente;
- mesmo código SUS e nome com alta similaridade: `probable`, exige confirmação;
- mesmo nome normalizado e ausência de código SUS nos dois lados: `probable`,
  também exige confirmação;
- sem candidato: `unmatched`, pode originar um novo exame canônico;
- mapping anterior incompatível ou sugestão ambígua: `conflict`, sempre manual.

Fluxo operacional após importar o catálogo:

```text
php artisan laboratory:match-exam-catalog [integration_public_id]
php artisan laboratory:review-exam-catalog <integration_public_id>
php artisan laboratory:resolve-exam-match <candidate_public_id> <decision> <actor_public_id> [--exam=<exam_public_id>] [--enable]
```

### Sincronização incremental (Fase 5)

A Fase 5 permanece bloqueada porque o Synclab ainda não fornece uma API de
catálogo suportada para integração sistema-a-sistema. As rotas `/examestipos`
encontradas no sistema do fornecedor pertencem à interface administrativa web;
elas não constituem um contrato público e não são consumidas pelo SYNC HOSP.

O estado `blocked_provider_api_unavailable` fica registrado em
`synclab_contract.transitions.incremental_catalog_sync`. Enquanto faltarem o
endpoint, o método de autenticação, o schema versionado, a semântica de
paginação/cursor e a regra de remoção, o CSV versionado continua sendo a fonte
operacional. Quando o fornecedor formalizar esses itens, a implementação deverá
trocar somente a fonte de entrada e reutilizar o motor de matching revisável já
existente; mappings confirmados e disponibilidade por unidade não poderão ser
sobrescritos automaticamente.

As decisões aceitas são `confirm`, `create`, `keep_existing`, `remap` e
`ignore`, conforme o estado do candidato. Toda decisão humana registra usuário,
estado anterior/novo e unidade em `audit_logs`.

### Grupos de exames

Grupos são importados de forma assistida; não existe sincronização contínua com
o Synclab. O CSV usa `;` e as colunas abaixo (os equivalentes em português
`codigo_grupo`, `nome_grupo`, `codigo_exame` e `ordem` também são aceitos):

```csv
group_code;group_name;exam_external_code;display_order
PRE;Pré-operatório;127;1
PRE;Pré-operatório;128;2
```

```text
php artisan laboratory:import-exam-groups <integration_public_id> <arquivo.csv>
php artisan laboratory:review-exam-group-conflicts <integration_public_id>
php artisan laboratory:resolve-exam-group-conflict <conflict_public_id> <accept|ignore|merge> <actor_public_id>
```

Um grupo novo só é criado quando todos os códigos possuem mapping ativo. Se um
grupo local já existir com composição diferente, a importação registra o diff e
não altera seus itens. Apenas uma decisão explícita `accept` ou `merge` muda a
composição; `ignore` preserva a cópia local. A decisão é auditada e não altera
pedidos históricos.

Cada exame é transmitido com as coleções obrigatórias `amostras` e `itens` vazias. Isso atende ao parser do Synclab sem antecipar coleta, código de barras, componentes ou resultados; essas informações continuam fora do escopo e serão identificadas posteriormente no laboratório.

O campo `sus_procedure_code` possui até 10 caracteres. Códigos SIGTAP só são preenchidos quando o pareamento com `DadosPadraoTabelaProcedimentoSUS.php` é inequívoco. Exames sem pareamento continuam utilizáveis porque o contrato de envio usa `external_code`, o código próprio do Synclab.

## Dados enviados

- Identificador da requisição e ordem de serviço.
- Unidade e CNES.
- Médico solicitante em `pedido.profissional`, com UF, conselho e número do registro profissional quando cadastrados.
- Usuário que registrou a requisição em `usuario_web_id`: a recepcionista nos pedidos da recepção e o próprio médico nos pedidos do consultório.
- ID numérico do paciente no campo `paciente.codigo`, nome e pelo menos CPF ou CNS.
- Exames selecionados, usando o código externo do Synclab.
- Prioridade, data, origem e observações da requisição.

## Transição de identificadores públicos

O contrato legado `outbound-orders-2026-08-03` permanece ativo por padrão. A
versão aditiva `outbound-orders-2026-08-08-public-identifiers` está implementada,
mas só pode ser habilitada depois da aprovação explícita do operador do Synclab.

Quando habilitada, ela acrescenta:

- `pedido.identificador_externo`: `exam_orders.public_id` (ULID);
- `paciente.identificador_externo`: `patients.public_id` (ULID).

Os campos legados `ordem_servico`, `codigo_pedido`, `pedido.codigo` e
`paciente.codigo` continuam presentes e com seus valores numéricos atuais. A
flag não altera snapshots de tentativas já iniciadas; retries continuam usando
o JSON imutável da primeira tentativa.

Após a confirmação formal do fornecedor, habilite:

```dotenv
SYNC_SUS_SYNCLAB_PUBLIC_IDENTIFIERS_ENABLED=true
```

Enquanto a confirmação não existir, mantenha `false`. A ativação deve ser
validada primeiro com um pedido fictício autorizado e conferida no sistema de
destino antes de uso clínico.

## Recepção de resultados

A recepção usa o contrato versionado `inbound-results-2026-08-08` e nasce
desabilitada. O operador do Synclab deve enviar resultados para:

```text
POST /api/v1/laboratory/synclab/results
X-Synclab-Result-Token: <token exclusivo da unidade>
Content-Type: application/json
```

```json
{
  "codigo_pedido": "123",
  "codigo_exame": "127",
  "resultado": "Hemoglobina 13,2 g/dL",
  "conclusao": "Dentro dos valores de referência.",
  "observacoes": "Resultado final.",
  "data_resultado": "2026-08-08T14:30:00-03:00",
  "referencia_resultado": "SYNCLAB-RESULT-0001"
}
```

`conclusao`, `observacoes` e `referencia_resultado` são opcionais. O endpoint
responde `202` quando registra e enfileira ou reconhece um replay idêntico,
`409` somente para conflito da mesma referência com conteúdo divergente, `422`
para payload inválido, `401` para token ausente/inválido e `403` quando a
recepção está desabilitada. Payloads inválidos são preservados criptografados
com estado `rejected`; pedidos ou itens que não possam ser resolvidos entram em
`manual_review` e nunca são associados por aproximação.

O tenant não vem do JSON. O sistema localiza a transmissão por
`laboratory_integration_id + external_order_number`, valida organização e unidade
já persistidas e só então resolve o item por `external_exam_code`. Campos externos
como `organization_id` ou `health_unit_id`, se enviados, não participam dessa
decisão. Um resultado existente nunca é sobrescrito silenciosamente.

O gestor gera ou rotaciona o token na tela **Administração > Integração
Synclab**. O valor é exibido uma única vez; apenas seu hash é armazenado. Depois
de entregar o token ao fornecedor por canal seguro, habilite a recepção da
unidade na mesma tela e, somente após a configuração externa, libere globalmente:

```dotenv
SYNC_SUS_SYNCLAB_RESULTS_ENABLED=true
```

O processamento ocorre na fila `integrations`. Ingestões que permanecerem em
`received` após interrupção do worker são reencaminhadas pelo agendador a cada
cinco minutos. O conteúdo clínico não é escrito em logs; eventos de recebimento,
aplicação, rejeição e revisão manual ficam em `audit_logs` sem o resultado bruto.

## Fora do escopo atual

Amostras, códigos de barras, componentes analíticos, resultados parciais,
retificações automáticas de resultados e polling do Synclab permanecem fora do
escopo. Esses campos não são enviados no JSON do pedido.

## Configuração de produção

```dotenv
SYNC_SUS_SYNCLAB_ENABLED=true
SYNC_SUS_SYNCLAB_PUBLIC_IDENTIFIERS_ENABLED=false
SYNC_SUS_SYNCLAB_RESULTS_ENABLED=false
SYNC_SUS_SYNCLAB_BASE_URL=https://synclabweb.unisync.com.br
SYNC_SUS_SYNCLAB_CNES=6612547
SYNC_SUS_SYNCLAB_USERNAME=<usuario-fornecido-pelo-synclab>
SYNC_SUS_SYNCLAB_PASSWORD=<senha-fornecida-pelo-synclab>
SYNC_SUS_SYNCLAB_QUEUE=integrations
SYNC_SUS_SYNCLAB_CONNECT_TIMEOUT=5
SYNC_SUS_SYNCLAB_TIMEOUT=30
```

Depois de configurar as variáveis, execute o deploy normalmente. A inicialização aplica migrations e seeders; o mesmo container do Railway executa a aplicação web, o worker da fila e o agendador. Não é necessário criar outro serviço neste momento.

O CNES de sete dígitos é a identidade pública e única do tenant. Ele deve
ser igual na organização, na unidade principal e na configuração Synclab; a
tela administrativa sincroniza esses valores e rejeita duplicidades.

### Ambiente local no Windows

O PHP precisa confiar na cadeia HTTPS. O bundle pode ser obtido em `https://curl.se/ca/cacert.pem`. Configure no `C:\php\php.ini`:

```ini
curl.cainfo="C:\caminho\seguro\cacert.pem"
openssl.cafile="C:\caminho\seguro\cacert.pem"
```

Use uma fila assíncrona (`QUEUE_CONNECTION=database` ou `redis`) e inicie todo o ambiente com `composer run dev`. Esse comando sobe servidor, worker das filas `integrations,default`, agendador e Vite. Assim uma indisponibilidade do Synclab não bloqueia a tela clínica. O Laravel Pail não faz parte desse comando porque depende de `pcntl`, extensão indisponível no PHP para Windows; quando necessário, acompanhe `storage/logs/laravel.log` em outro terminal.

Mantenha apenas uma execução de `composer run dev`. O Vite usa obrigatoriamente a porta `5173`, que é a origem local autorizada pela política de segurança do sistema. O comando remove automaticamente referências `public/hot` obsoletas antes de iniciar.

Pedidos criados enquanto a integração estava desativada permanecem em `awaiting_configuration`. Eles não são enviados em lote ao habilitar a unidade; um gestor deve revisar e usar **Tentar novamente** em cada pedido.

Credenciais e dados reais de pacientes nunca devem ser versionados. Antes do primeiro envio em produção, use uma requisição de paciente fictício autorizada pelo Synclab e confira o registro correspondente no grid do sistema de destino.
