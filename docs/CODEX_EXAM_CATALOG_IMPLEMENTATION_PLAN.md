# CODEX EXAM CATALOG IMPLEMENTATION PLAN

> Documento de planejamento. Nenhum código, migration ou alteração funcional foi criada ao produzir este arquivo. Todas as afirmações sobre o estado atual foram verificadas diretamente no código-fonte (caminhos citados) — nada foi assumido.

## 0. Achado crítico que muda a natureza deste plano

`C:\tortoise_dir` **não é um sistema hipotético a integrar no futuro — é o código-fonte (ou uma versão dele) do backend real do "Synclab Web"** (`synclabweb.unisync.com.br`), que o SyncHosp **já integra em produção hoje**, de forma parcial e unidirecional (envio de pedidos). A evidência:

- `config/sync_sus.php:44`: `'base_url' => env('SYNC_SUS_SYNCLAB_BASE_URL', 'https://synclabweb.unisync.com.br')`
- `tortoise_dir/app/Lib/PerfilAcesso/TPerfilAcessoUnisync.php` — marca "Unisync", a mesma raiz do domínio `unisync.com.br`.
- `docs/SYNCLAB_INTEGRATION.md` já documenta o endpoint real: `POST https://synclabweb.unisync.com.br/app/addrequisicao/{cnes}`.

Isso significa que este plano **não desenha um contrato do zero** — ele formaliza, corrige e estende um contrato que já está em produção com um fornecedor externo real. Mudanças no formato hoje trocado (payload de `/app/addrequisicao/{cnes}`) não podem ser feitas unilateralmente; exigem coordenação com quem opera o Synclab Web, versionamento explícito e um período de compatibilidade. Todo o plano abaixo foi desenhado com essa restrição em mente.

---

## 1. Arquitetura

```text
SyncHosp
   ↕  HTTP/JSON, Basic Auth por unidade, hoje só outbound (pedido)
Integration Layer (App\Modules\Laboratory)
   - SynclabClient (Infrastructure/Synclab)
   - SynclabOrderPayloadBuilder (Application/Services)
   - LaboratoryIntegration / LaboratoryExam / LaboratoryOrderTransmission / LaboratoryTransmissionAttempt (Infrastructure/Eloquent)
   ↕
Synlab Web — synclabweb.unisync.com.br (código-fonte em C:\tortoise_dir)
```

Nenhum dos dois sistemas acessa o banco do outro. O SyncHosp fala com o Synclab Web exclusivamente via HTTP (`Illuminate\Http\Client\Factory`, `SynclabClient.php:37-44`), autenticado por Basic Auth com credenciais próprias por unidade (`laboratory_integrations.username/password`, criptografadas via cast `encrypted`).

---

## 2. Fonte de verdade

| Informação | Fonte de verdade | SyncHosp | Synlab Web | Sincronização |
|---|---|---|---|---|
| Identidade do exame (canônico) | **SyncHosp** (a ser criado — ver §6) | Cria e mantém `Exam` canônico por organização | Não tem noção de organização/unidade do SyncHosp | N/A — Synlab não conhece o conceito |
| Nome do exame | **Synlab Web** (fornecedor define o exame de fato ofertado) | Espelha via importação (hoje CSV) | Definição original em `exames_tipos.nome` | Importação periódica/manual (hoje: CSV versionado, `database/data/synclab_exams.csv`) |
| Código SUS/TUSS | **Tabela SUS oficial** (nenhum dos dois é fonte primária) | `laboratory_exams.sus_procedure_code`, pareado manualmente (`SynclabExamCatalogSeeder::procedureCodes()`) | `exames_tipos.idtabela_procedimentos_sus` (FK própria) | Nunca inferido automaticamente por nome — pareamento curado, um lado não sobrescreve o outro |
| Código externo laboratorial | **Synlab Web** | `laboratory_exams.external_code` (cópia) | `exames_tipos.codigo` (original) | Importação; SyncHosp nunca gera nem edita esse código |
| Grupo de exames | **Ambos, separadamente** (ver §8/§9) | `ExamGroup` do SyncHosp (a criar) — pode nascer de importação, mas depois de criado é do SyncHosp | `grupos_exames` do Synlab | Importação inicial opcional; após criado, cada lado é dono da sua cópia — nunca sobrescrita automática (regra explícita, ver §9) |
| Composição do grupo | Mesmo dono do grupo em cada lado | `exam_group_items` | `grupos_exames_itens` | Mesma regra acima |
| Disponibilidade por unidade | **SyncHosp, sempre** | Responsabilidade exclusiva (hoje via `laboratory_exams` escopado por `laboratory_integration_id`→`health_unit_id`) | Synlab Web não segmenta por unidade — não participa dessa decisão | N/A — nunca delegado ao Synlab |
| Pedido médico (`ExamOrder`) | **SyncHosp** | Dono absoluto — nasce e vive no banco da unidade | Recebe uma cópia via `/app/addrequisicao/{cnes}`, processa internamente | Envio único por pedido (outbound), idempotente (`idempotency_key`) |
| Status laboratorial (aceito/rejeitado/em processamento) | **Synlab Web informa, SyncHosp registra** | `laboratory_order_transmissions.status` reflete o que o Synlab respondeu | Synlab é quem decide o status real | Hoje: apenas resposta HTTP síncrona ao POST (200 = aceito). Não há callback assíncrono de mudança de status — gap identificado em §13 |
| Resultado | **Synlab Web produz, SyncHosp persiste** | `exam_results` aceita origem manual ou Synclab | Fonte do resultado clínico | Implementado na Fase 4 sob flag e token por integração — ver §12/§14 |

Regra geral: **nenhuma informação tem dois donos simultâneos**. Onde os dois sistemas mantêm cópias (grupo, catálogo), a sincronização é sempre unidirecional em um dado momento e nunca há sobrescrita automática silenciosa — qualquer divergência vira um estado revisável, nunca um merge automático.

---

## 3. Estado atual real (baseline — não é greenfield)

Já implementado e em produção (não recriar, apenas estender/corrigir):

| Capacidade | Onde | Observação |
|---|---|---|
| Envio de pedido | `SynclabOrderPayloadBuilder.php`, `SynclabClient.php:18-57` | `POST /app/addrequisicao/{cnes}`, Basic Auth, só HTTP 200 é aceito (`synclab_contract.php:10`) |
| Confiabilidade de envio | `laboratory_order_transmissions` + `laboratory_transmission_attempts` | `idempotency_key` único, lease/`worker_token`, 9 estados (`LaboratoryTransmissionStatus` enum) |
| Snapshot imutável do pedido | `RecordLaboratoryTransmissionAuditAction`, colunas `request_hash`/`response_hash` | Primeira tentativa é congelada e reenviada tal qual em retries |
| Catálogo por unidade | `laboratory_integrations` (1 por `health_unit_id`+provider) → `laboratory_exams` | Cada unidade tem sua **própria cópia** do catálogo — não há catálogo canônico compartilhado entre unidades da mesma organização |
| Importação de catálogo | `SynclabExamCatalogSeeder.php` | **Manual, via CSV versionado** (`database/data/synclab_exams.csv`, 123 linhas com `itemexame=0`), não é uma chamada de API ao Synlab. Tem asserção rígida de contagem (`count($rows) !== 123` lança exceção) — frágil para operação contínua |
| Pareamento SUS | `SynclabExamCatalogSeeder::procedureCodes()` | Curado manualmente, nunca inferido por nome |
| Resultado de exame | **Implementado na Fase 4, desabilitado por padrão** — `SYNC_SUS_SYNCLAB_RESULTS_ENABLED=false` | Webhook autenticado, ingestão idempotente em fila e `exam_results.source=synclab`; ativação depende da configuração do fornecedor |
| Grupos de exames | **Não existe** no SyncHosp | Existe no Synlab (`grupos_exames`/`grupos_exames_itens`), sem equivalente aqui |

**Desvio já existente que precisa de correção coordenada (não silenciosa):** o payload hoje envia **IDs internos sequenciais como identidade externa**:
- `SynclabOrderPayloadBuilder.php:104-105`: `ordem_servico`/`codigo_pedido` = `$externalOrderNumber`, que é `exam_orders.id` (BIGINT interno), não `exam_orders.public_id` (ULID já existente no schema, `2026_07_24_050000_create_medical_care_tables.php:132`).
- `SynclabOrderPayloadBuilder.php:95`: `paciente.codigo` = `$patient->getKey()` (BIGINT interno), não `patients.public_id` (ULID já existente, `2026_07_24_020100_create_patients_tables.php:15`).

Isso viola diretamente o invariante **INV-SYN-04** exigido para este plano. Como o contrato já está em produção com um fornecedor real, a correção **não pode ser um `find-and-replace`** — está desenhada como uma fase própria e compatível em §17/§Fase 3, não uma mudança imediata.

---

## 4. Identificadores entre sistemas

| Entidade | Identificador estável no SyncHosp (já existe) | Como aparece hoje no contrato Synclab | Estratégia alvo |
|---|---|---|---|
| `Patient` | `public_id` (ULID) | `paciente.codigo` = `id` interno (desvio) | Fase de transição: continuar enviando `codigo` (compat.) + adicionar campo adicional não obrigatório com o ULID assim que o Synclab aceitar um novo campo (negociação externa necessária) |
| `ExamOrder` | `public_id` (ULID) | `ordem_servico`/`codigo_pedido` = `id` interno (desvio) | Mesma estratégia — manter `id` numérico enquanto for a chave que o Synclab usa para responder/consultar (é o "external_order_number" documentado), evoluir apenas com acordo do fornecedor |
| `LaboratoryExam` | **Não tem `public_id` hoje** — gap a corrigir (ver Fase 1) | `external_code` = código do Synclab (correto, é o identificador deles, não nosso) | Adicionar `public_id` ao `LaboratoryExam` para servir de âncora do `ExamMapping` (§6) |
| `ExamGroup` (a criar) | `public_id` (ULID), seguindo o padrão do projeto | N/A — Synlab não expõe API de grupos hoje | Identidade nasce no SyncHosp |
| `ExamOrderItem` | Não tem `public_id` hoje; usa `internal_code` (string livre) | Vai dentro do array `exames[]` do pedido, por `codigo` (= `external_code` do exame) | Suficiente para o escopo atual (envio); avaliar `public_id` apenas se/quando existir canal de status por item |

Regra permanente daqui para frente (**aplica-se a tudo que for novo**, não ao que já está em produção): nenhuma tabela nova troca IDs sequenciais como referência externa. Sempre `public_id` (ULID), seguindo o padrão já usado em 100% das tabelas do projeto.

---

## 5. Exam Mapping (novo conceito — hoje inexistente como entidade própria)

Hoje, `laboratory_exams` acumula **duas responsabilidades diferentes** na mesma tabela:
1. "Este exame existe e está disponível nesta unidade" (disponibilidade)
2. "Este é o código que o Synclab usa para esse exame" (mapeamento externo)

Isso funciona no cenário atual (uma unidade = uma integração = um catálogo próprio), mas **não escala** para: (a) uma organização com várias unidades querendo o mesmo exame canônico, hoje cada unidade tem uma cópia desconectada da outra; (b) múltiplos providers laboratoriais além do Synclab (exigido pelo critério de aceite, §24).

### Proposta

```text
Exam (canônico, escopo organização)
  id, public_id, organization_id, name, sus_procedure_code, is_active

ExamMapping (novo)
  id, public_id, exam_id → Exam,
  laboratory_integration_id → LaboratoryIntegration (ou provider_id genérico no futuro),
  external_code, external_name_snapshot,
  match_type: exact | probable | manual | unmatched,
  match_confidence (nullable),
  mapped_by (user), mapped_at,
  is_active
```

- `Exam` é **sempre** SyncHosp-owned; nunca importado automaticamente sem revisão (ver matching em §6).
- `ExamMapping` é o único lugar onde o "código do Synclab" aparece — se amanhã entrar um segundo provider, ele ganha suas próprias linhas em `ExamMapping`, sem tocar em `Exam`.
- `laboratory_exams` (tabela atual) passa a representar o **catálogo bruto importado do provider** (o que ele oferece), não mais a fonte de disponibilidade — a disponibilidade real por unidade passa a ser decidida por `HealthUnitExam` (§10), que só pode apontar para um `Exam` que tenha um `ExamMapping` ativo para a integração daquela unidade.

Esse desenho é uma extensão, não uma reescrita: `laboratory_exams`/`laboratory_integrations`/toda a cadeia de transmissão continuam existindo exatamente como estão.

---

## 6. Importação do catálogo do Synlab — critérios de matching

Fluxo:

```text
Synlab Web (CSV hoje / API no futuro)
     ↓
laboratory_exams (catálogo bruto importado — já existe, mantém-se)
     ↓
processo de matching (novo)
     ↓
ExamMapping (proposto)
     ↓
Exam canônico do SyncHosp
```

Critérios de match, em ordem de prioridade (nunca por nome isolado):

| Critério | Resultado |
|---|---|
| `external_code` já mapeado antes para este `Exam` | **MATCH EXATO** — reaplica automaticamente |
| `sus_procedure_code` idêntico E nome normalizado com alta similaridade | **MATCH PROVÁVEL** — sugerido, exige confirmação humana |
| Nome normalizado (case/acento/plural) idêntico, sem código SUS em nenhum dos dois | **MATCH PROVÁVEL** — mesma exigência |
| Nenhum critério bate | **SEM CORRESPONDÊNCIA** — vira candidato a novo `Exam`, não é descartado |
| Mesmo `external_code` já mapeado para um `Exam` **diferente** do sugerido agora | **CONFLITO** — bloqueia importação automática daquela linha, fica pendente de decisão humana |

Nunca consolidar dois exames apenas por nome igual (exigência explícita do requisito) — nome é apenas um dos sinais, nunca o único, e nunca decide sozinho um MATCH EXATO.

---

## 7. Grupos de exames (`ExamGroup`) — novo módulo

```text
ExamGroup (proposto, escopo organização)
  id, public_id, organization_id, name, is_active

ExamGroupItem (proposto)
  id, exam_group_id → ExamGroup, exam_id → Exam, display_order
```

Importação de grupos do Synlab (`grupos_exames`/`grupos_exames_itens`, confirmados em `tortoise_dir/app/Http/Controllers/GruposExamesTiposController.php`):
- Como o Synlab Web **não expõe hoje nenhuma API para grupos** (só recebe pedidos), a importação de grupos não pode ser automática/contínua — é um processo assistido (exportação manual/CSV, à semelhança do catálogo de exames), executado por um administrador, nunca em tempo real.
- Um grupo importado só é criado no SyncHosp se **todos os seus itens já tiverem `ExamMapping` ativo** (regra explícita do requisito) — grupo com item sem mapping não é criado, fica pendente.
- Depois de criado no SyncHosp, o `ExamGroup` é propriedade do SyncHosp. Não há sincronização contínua nos dois sentidos.

### Divergência entre cópias do grupo (§9 do requisito)

Se o grupo "Pré-operatório" existir nos dois lados com composição diferente (ex.: Synlab tem A,B,C; SyncHosp tem A,B,C,D):

- **Fonte de verdade após a criação inicial: o lado que criou por último decide a sua própria cópia.** Não existe sobrescrita automática em nenhuma direção.
- Uma nova importação do Synlab que encontre um grupo já existente no SyncHosp com o mesmo nome **não sobrescreve** — gera um registro de **conflito de importação** (tabela `exam_group_import_conflicts`, proposta) com o diff (itens a mais/a menos), à espera de decisão manual de um administrador.
- Toda decisão de conflito (aceitar diff, ignorar, mesclar manualmente) é auditada via `AuditLog` (mecanismo já existente no projeto) — actor, ação, estado anterior/novo.
- **Nunca**: merge automático, atualização silenciosa de pedidos históricos que já usaram a composição antiga do grupo (INV-SYN-08 garante que o grupo é expandido no momento do pedido — a composição fica congelada no snapshot do `ExamOrder`, então uma mudança futura no grupo nunca altera pedidos já enviados).

---

## 8. Disponibilidade por unidade (`HealthUnitExam`)

O requisito pede explicitamente esse modelo; hoje a disponibilidade por unidade já é garantida (de forma diferente — por integração própria por unidade), então isso é uma **formalização**, não uma criação de regra nova:

```text
Exam (canônico, organização)
      ↓
HealthUnitExam (proposto)
      ↓
HealthUnit
```

```text
HealthUnitExam (proposto)
  id, exam_id → Exam, health_unit_id → HealthUnit,
  is_enabled, enabled_at, enabled_by,
  requires_mapping: só pode ser is_enabled=true se existir ExamMapping ativo
      para (exam_id, laboratory_integration_id da unidade)
```

Isso preserva o exemplo do requisito: se o Synlab tem A, B, C, D, mas a Unidade A só habilitou A, B, C, o médico da Unidade A não pode solicitar D — a tela de solicitação de exame (já existente, `LaboratoryExamSearchController` em `Medical`) passa a filtrar por `HealthUnitExam.is_enabled=true` daquela unidade, não mais apenas por "existe em `laboratory_exams` desta integração".

---

## 9. Pedido médico → Synlab (fluxo completo)

```text
Médico (MedicalConsultationController)
   ↓
ExamOrder + ExamOrderItems (já existente — sem mudança de schema aqui)
   ↓
resolver ExamMapping por item (novo — hoje resolve laboratory_exam_id direto)
   ↓
SynclabOrderPayloadBuilder (existente, ajustado para ler via ExamMapping)
   ↓
LaboratoryOrderTransmission (existente — idempotency_key, lease, retry)
   ↓
SynclabClient::submitOrder() (existente)
   ↓
Synlab Web
```

Dados enviados (já implementado, mantém-se igual — nenhuma alteração no payload nesta fase): identificação do pedido (`ordem_servico`/`codigo_pedido`), unidade+CNES, paciente (nome + CPF ou CNS obrigatório), profissional solicitante com registro, exames (código externo), prioridade/data/origem/observação. **Nada além disso é enviado** — sem diagnóstico, sem histórico clínico, sem dados de outros exames do mesmo paciente.

Regra explícita a preservar: **exame sem mapping ativo não é enviado silenciosamente**. Hoje `SynclabOrderPayloadBuilder.php:59-62` já lança `InvalidLaboratoryOrder` se um item não tiver `external_exam_code`/`laboratoryExam` — esse comportamento é mantido e vira também um dos testes obrigatórios (§ Testes).

---

## 10. Grupo → Synlab

Grupo nunca é enviado como grupo. No momento da solicitação médica, se o médico escolher um `ExamGroup`, o sistema expande para os `Exam` individuais **antes** de criar os `ExamOrderItem`:

```text
ExamGroup "Pré-operatório"
   ↓ (expande no momento da criação do pedido)
ExamOrderItem (Hemograma), ExamOrderItem (Glicemia), ExamOrderItem (Creatinina), ExamOrderItem (Coagulograma)
   ↓ (cada item resolve seu próprio ExamMapping)
Synclab recebe 4 códigos individuais, nunca "grupo"
```

Isso é o que já acontece implicitamente hoje sem grupos (cada `ExamOrderItem` já vira uma entrada individual em `exames[]`, `SynclabOrderPayloadBuilder.php:58-73`) — a introdução de grupos não muda nada no envio, só automatiza a seleção no lado do médico. Se houver valor de negócio em rastrear "este pedido veio do grupo X", isso pode ser guardado como metadado interno (`exam_order_items.exam_group_id` nullable, proposto) — nunca enviado ao Synclab, que não precisa e não tem onde recebê-lo.

---

## 11. Resposta do Synclab — estados e transições

Já implementado (`LaboratoryTransmissionStatus`, `app/Modules/Laboratory/Domain/Enums/LaboratoryTransmissionStatus.php`):

```text
AwaitingConfiguration → (unidade configura integração) → Pending
Pending → Sending → [HTTP 200] → Accepted
                  → [HTTP 429/5xx] → Retrying → Sending (novo attempt)
                  → [outro HTTP, ou lease expirada durante Sending] → ManualReview
                  → [validação local falhou antes de enviar] → Rejected
(qualquer estado não-terminal) → Cancelled (ação humana explícita)
```

Isso já cobre pedido aceito/rejeitado/erro temporário/status pendente. **O que falta** (porque o Synclab hoje não tem mecanismo de confirmação separado, `synclab_contract.php:9`): não há como o SyncHosp saber, depois do 200 inicial, se o pedido foi "processado" ou "cancelado" do lado do Synlab — isso é uma limitação do fornecedor, não do SyncHosp, e deve ficar registrada como tal (não fingir um estado que a integração não pode de fato observar).

"Exame desconhecido" e "paciente inválido" hoje **são validados no lado do SyncHosp antes de enviar** (`SynclabOrderPayloadBuilder` lança `InvalidLaboratoryOrder`) — o pedido nunca sai do SyncHosp nesses casos, então não existe uma resposta do Synclab para esse cenário especificamente; se o Synclab um dia expuser esses erros na resposta HTTP, eles cairiam em `Rejected` com o `error_code`/`last_error` já suportados pela tabela.

---

## 12. Resultados — implementado na Fase 4 sob feature flag

Como não há API documentada do Synclab para push de resultado, e o SyncHosp não expõe hoje nenhum endpoint de recepção, há duas estratégias possíveis — o plano recomenda a primeira como padrão, mantendo a segunda como fallback:

**A) Webhook (Synclab → SyncHosp), recomendado.** Requer que o Synclab consiga chamar um endpoint do SyncHosp. Mais próximo de tempo real.
**B) Polling (SyncHosp → Synclab).** Usado apenas se o Synclab não conseguir originar chamadas HTTP para o SyncHosp (ex.: restrição de rede do provedor). Mais simples de autorizar (SyncHosp sempre inicia), mas com latência maior e necessidade de um endpoint de consulta no Synclab que hoje não existe/não está documentado.

Fluxo (modelo A):

```text
Synlab Web
   ↓ POST /api/v1/laboratory/synclab/results (novo, autenticado)
SyncHosp — Integration Layer
   ↓ resolve pedido (ver resolução abaixo — nunca por ID sequencial isolado)
Tenant Resolver (organization_id + health_unit_id vêm de laboratory_order_transmissions, não do payload recebido)
   ↓
ExamOrderItem (via laboratory_exam_id/external_code do item)
   ↓
ExamResult (novo registro, source='synclab', não 'manual')
```

### Resolução segura (sem confiar em ID sequencial externo)

O requisito exige explicitamente não confiar apenas em IDs sequenciais enviados de fora. Estratégia:

1. O Synclab deve devolver o **mesmo `codigo_pedido`** que o SyncHosp enviou no pedido original (`ordem_servico`) — hoje é um `id` numérico (desvio conhecido, §3), mas mesmo assim é **verificado contra `laboratory_order_transmissions.external_order_number`**, não usado como PK direto de nenhuma tabela sensível.
2. A partir de `external_order_number` + `laboratory_integration_id` (a integração que originou aquele pedido é conhecida — está gravada na transmissão), o sistema resolve `exam_order_id` → `organization_id`/`health_unit_id` **pelos dados já persistidos no SyncHosp**, nunca por um `organization_id`/`unit_id` que viesse dentro do payload de resultado (o payload de resultado, se algum dia enviar esses campos, é ignorado/apenas para log — a resolução de tenant nunca confia em valor externo).
3. O item específico é resolvido pelo `codigo` do exame (mesmo `external_code` já usado no envio) cruzado com os itens daquele `exam_order_id`.
4. Se qualquer uma dessas resoluções falhar (pedido desconhecido, integração não bate, item não encontrado), o resultado recebido vai para uma fila de `manual_review` — nunca é descartado silenciosamente nem gravado num registro "adivinhado".

---

## 13. Idempotência

**Pedidos (já implementado):** `laboratory_order_transmissions.idempotency_key` (único) + `unique(laboratory_integration_id, exam_order_id)` — reenvio do mesmo pedido nunca cria uma segunda transmissão.

**Resultados (implementado na Fase 4):**

```text
ExamResultIngestion (proposto)
  id, public_id, laboratory_order_transmission_id → LaboratoryOrderTransmission,
  external_result_reference (o que o Synclab mandar como identificador do resultado, se houver)
     OU content_hash do payload recebido quando não houver referência própria,
  unique(laboratory_order_transmission_id, external_result_reference)
  status: received | applied | duplicate | rejected
```

Se o Synclab reenviar o mesmo resultado duas vezes (com ou sem um identificador próprio), a segunda tentativa é reconhecida como `duplicate` pela constraint única e **não gera um segundo `ExamResult`** — a resposta ao Synclab continua sendo "sucesso" (idempotência correta: reenviar não deve ser tratado como erro pelo lado que reenvia).

---

## 14. Falhas de comunicação

| Cenário | Já coberto hoje | Ação para resultados (novo) |
|---|---|---|
| SyncHosp disponível, Synclab indisponível (envio de pedido) | Sim — `Retrying`, backoff `[60,300,900]`, `retryUntil` 6h (`SubmitLaboratoryOrderJob`) | N/A (fluxo de envio, não muda) |
| Synclab disponível, SyncHosp indisponível (recepção de resultado) | N/A hoje | Webhook precisa responder rápido e enfileirar processamento (job `ShouldQueue`), nunca processar de forma síncrona e bloqueante — se o SyncHosp estiver fora do ar, o Synclab deve poder tentar de novo mais tarde (retorno HTTP 5xx enquanto indisponível é aceitável e esperado) |
| Lease expira no meio do envio | Sim — vai para `ManualReview`, não reenvia sozinho (evita duplicidade quando não se sabe se o Synclab já recebeu) | Durante as tentativas, o resultado permanece `received` e pode ser retomado; quando as tentativas se esgotam, vai para `manual_review` e deixa de ser redespachado automaticamente |

Em nenhum cenário uma falha de comunicação apaga ou reverte o `ExamOrder` local — ele é sempre criado antes de qualquer tentativa de envio (INV-SYN-09).

---

## 15. Compatibilidade futura e versionamento

- O contrato de **envio de pedido já é versionado** por convenção de arquivo: `config/synclab_contract.php:6` (`'version' => 'outbound-orders-2026-08-03'`). Este plano adota a mesma prática para tudo que for novo: cada mudança de contrato (novo campo, nova operação) bump nessa string + changelog em `docs/SYNCLAB_INTEGRATION.md`.
- Nenhuma integração nova deve depender de nome de tabela/model interno do Synlab Web (`exames_tipos`, `grupos_exames`, etc.) — tudo que cruza a fronteira é JSON de payload documentado neste arquivo, nunca um nome de coluna do `tortoise_dir`.
- Como o Synclab Web não expõe versionamento de URL (`/app/addrequisicao/{cnes}` é fixo, sem `/v1/`), o versionamento do lado do SyncHosp é interno (arquivo de contrato + testes de regressão de payload), nãoye um parâmetro trocado com o fornecedor. Se o SyncHosp expuser endpoints próprios (ex.: webhook de resultado, §12), esses sim devem nascer com prefixo `/api/v1/...` desde o início.

---

## 16. Segurança entre sistemas

| Direção | Mecanismo atual | Gap / recomendação |
|---|---|---|
| SyncHosp → Synclab (pedido) | Basic Auth por unidade, credenciais `encrypted` no banco, HTTPS obrigatório validado antes do envio (`SynclabClient.php:30-31`), timeout configurado | Nenhum gap crítico — já adequado |
| Synclab → SyncHosp (resultado) | Token exclusivo por integração no header `X-Synclab-Result-Token`, rotacionável na tela administrativa e armazenado somente como hash | Permanece desabilitado até a coordenação externa e a ativação explícita global/por unidade |
| Replay / duplicação de chamada | Mitigado por idempotência (não por assinatura de replay) | Suficiente para o risco atual (não há indício de necessidade de proteção contra replay criptográfico nesta integração) |
| Segredos | Já fora do código (`.env`/config), credenciais de integração criptografadas em banco | Manter — nenhum segredo deve ir para este documento nem para `docs/SYNCLAB_INTEGRATION.md` em texto claro |

---

## 17. Observabilidade

Já respondível hoje (pedido): "foi enviado?", "quando?", "foi aceito?", "qual erro?", "quantas tentativas?" — tudo via `laboratory_order_transmissions` + `laboratory_transmission_attempts`, com payload sanitizado (`RecordLaboratoryTransmissionAuditAction`).

**Ainda não respondível, a cobrir:**
- "Qual mapping foi utilizado?" — só existirá quando `ExamMapping` (§6) existir, com `mapped_by`/`mapped_at` auditados.
- Resultados agora possuem ingestão, estado, duplicidades, erro operacional e auditoria; o conteúdo bruto permanece criptografado e nunca é escrito em log de arquivo.

---

## 18. Testes de integração obrigatórios

| Cenário exigido | Onde teria que viver | Situação |
|---|---|---|
| Exam do SyncHosp mapeia corretamente para Synlab | `tests/Feature/Laboratory/ExamMappingTest.php` (novo) | A criar junto com `ExamMapping` |
| Exam sem mapping não é enviado silenciosamente | Já existe padrão equivalente em `SynclabOrderSubmissionTest.php` (item sem `external_exam_code` falha) | Estender para o novo modelo de mapping |
| Unit A utiliza mapping da Unit A / Unit B não utiliza mapping da Unit A | `tests/Feature/Laboratory/HealthUnitExamScopeTest.php` (novo) | A criar — mesmo padrão de isolamento já usado em `HealthUnitScopeTest.php` |
| Organization A não acessa mapping da B | Mesmo arquivo acima | A criar |
| Grupo expande antes do envio | `tests/Feature/Laboratory/ExamGroupExpansionTest.php` (novo) | A criar junto com `ExamGroup` |
| Itens duplicados são eliminados | Mesmo arquivo acima | A criar |
| Pedido duplicado não é criado no Synclab | Já coberto — `SynclabOrderSubmissionTest.php` (idempotency_key) | Manter, sem mudança |
| Resultado duplicado não é criado no SyncHosp | `tests/Feature/ExamResultIngestionTest.php` | ✅ Implementado na Fase 4 |
| Falha temporária do Synclab não perde o pedido | Já coberto — retry/backoff testado implicitamente pelo estado `Retrying` | Confirmar teste explícito existe; se não, adicionar |
| Resultado entra no banco correto da unidade | `tests/Feature/ExamResultIngestionTest.php` | ✅ Implementado na Fase 4 |
| Alteração interna do grupo não altera pedidos históricos | `tests/Feature/Laboratory/ExamGroupExpansionTest.php` | A criar — validar que `ExamOrderItem` já criado não muda se o `ExamGroup` for editado depois |

---

## 19. Critério arquitetural de aceite — checagem

| Exigência (§24 do requisito) | Atendida pelo desenho acima? |
|---|---|
| Atualizar SyncHosp sem atualizar Synlab imediatamente | Sim — `ExamMapping`/`ExamGroup`/`HealthUnitExam` são inteiramente internos ao SyncHosp; o payload trocado com o Synclab não muda nesta fase |
| Atualizar Synlab sem alterar banco do SyncHosp | Sim — nenhuma FK cruza bancos; toda dependência é via HTTP |
| Mudar implementação interna de exames no Synlab | Sim — SyncHosp só depende do JSON trocado (`codigo`, `nome` no payload), nunca de `exames_tipos`/schema interno |
| Mover bancos de unidades do SyncHosp | Sim — a integração já é resolvida via `health_unit_id`/`laboratory_integration_id`, não depende de topologia física de banco |
| Adicionar outro provider laboratorial além do Synclab | Sim — é exatamente o motivo de `ExamMapping` referenciar uma integração genérica em vez de embutir "synclab" no modelo de disponibilidade |

---

## 20. Invariantes (consolidado)

| ID | Invariante | Status hoje |
|---|---|---|
| INV-SYN-01 | SyncHosp nunca consulta diretamente tabelas internas do Synlab Web | ✅ Já verdadeiro — integração é 100% HTTP |
| INV-SYN-02 | Synlab Web nunca consulta diretamente bancos internos do SyncHosp | ✅ Já verdadeiro |
| INV-SYN-03 | Comunicação ocorre por contrato versionado | ⚠️ Parcial — envio de pedido tem versão de contrato (`synclab_contract.php`); resultado ainda não existe, nasce versionado |
| INV-SYN-04 | IDs internos sequenciais não são identidade externa permanente | ❌ **Violado hoje** (`exam_orders.id`, `patients.id` no payload) — tratado como dívida conhecida, correção faseada (§3, §17) |
| INV-SYN-05 | Disponibilidade de exame por unidade é do SyncHosp | ✅ Já verdadeiro (via integração por unidade); passa a ser explícito via `HealthUnitExam` |
| INV-SYN-06 | Pedidos clínicos pertencem ao banco da unidade | ✅ Já verdadeiro |
| INV-SYN-07 | Resultados são persistidos no banco correspondente à unidade | ✅ Implementado — tenant resolvido somente pela integração/transmissão persistidas |
| INV-SYN-08 | Grupo é expandido para exames antes do processamento laboratorial | A implementar — desenho em §10 garante isso por construção |
| INV-SYN-09 | Falha do Synclab não causa perda do pedido local | ✅ Já verdadeiro |
| INV-SYN-10 | Toda operação remota sujeita a retry é idempotente | ✅ Verdadeiro para pedidos e resultados |

---

## 21. Contrato de API

### Operação: Envio de pedido (existente, formalizado aqui)
```text
Direção: SyncHosp → Synlab Web
Endpoint: POST /app/addrequisicao/{cnes}
Autenticação: HTTP Basic Auth (credenciais por unidade, criptografadas em repouso)
Request: ver SynclabOrderPayloadBuilder.php — pedido, paciente, exames[]
Response: JSON; apenas HTTP 200 é "aceito"; 429/5xx = retryable; outros = rejeitado
Idempotência: idempotency_key único por (integração, exam_order_id); reenvio reusa o snapshot da 1ª tentativa
Responsabilidade: SyncHosp (dono do pedido)
```

### Operação: Consulta de status (avaliada — não recomendada nesta fase)
```text
Não implementar agora. O Synclab não documenta hoje um endpoint de consulta de status,
e o requisito não deve inventar um contrato que o fornecedor não suporta. Se o
Synclab expuser um endpoint de consulta no futuro, encaixa no mesmo padrão do
webhook de resultado (autenticado, idempotente, resolvendo tenant pelos dados
já persistidos, nunca pelo payload recebido).
```

### Operação: Importação/sincronização de catálogo (existente, formalizado)
```text
Direção: Synlab Web → SyncHosp (hoje: processo manual, não uma chamada de API)
Mecanismo atual: arquivo CSV versionado (database/data/synclab_exams.csv), aplicado via seeder
Autenticação: N/A (arquivo local, não uma chamada de rede)
Idempotência: upsert por (laboratory_integration_id, external_code); códigos ausentes na
   nova versão são marcados is_active=false, nunca removidos (preserva histórico)
Responsabilidade: administrador do SyncHosp, mediante arquivo fornecido pelo Synclab
Observação: se no futuro o Synclab expuser uma API de catálogo, o mesmo processo de
   matching (§6) se aplica — o mecanismo de transporte muda, a regra de negócio não.
```

### Operação: Recepção de resultado (implementada, desabilitada por padrão)
```text
Direção: Synlab Web → SyncHosp
Endpoint: POST /api/v1/laboratory/synclab/results
Autenticação: token de API por laboratory_integration_id (novo mecanismo, distinto da
   credencial de saída), enviado em header
Request: identificador do pedido (o mesmo codigo_pedido enviado por nós), código do
   exame, conteúdo do resultado, identificador do resultado no Synclab se existir
Response: 200 = recebido e processado; 202 = recebido, processamento assíncrono
   enfileirado ou replay idêntico reconhecido como sucesso; 409 = mesma referência
   externa com conteúdo divergente; 422 = pedido/item não
   resolvido (cai em manual_review internamente, resposta ainda é 200/202 para não
   forçar o Synclab a re-tentar algo que já está sendo tratado)
Erros: pedido desconhecido, item não encontrado, payload malformado — todos revisáveis,
   nunca descartados silenciosamente
Idempotência: unique(laboratory_order_transmission_id, external_result_reference OU hash)
Responsabilidade: compartilhada — Synclab envia, SyncHosp resolve e persiste com segurança
```

### Operação: Grupos de exames (avaliada — sem API, importação assistida)
```text
Não há operação de API nesta fase. Grupos são importados por processo manual/assistido
(mesmo mecanismo do catálogo), nunca por chamada de sistema a sistema, porque o
Synclab Web não expõe hoje nenhum endpoint para grupos (confirmado: apenas
GruposExamesTiposController, uma tela administrativa interna do Synlab, sem rota de API).
```

---

## 22. Fases e impacto no Synlab

```text
FASE 1 — Fundação interna (ExamMapping, ExamGroup, HealthUnitExam)
  IMPACTO NO SYNLAB: nenhum
  - Cria Exam, ExamMapping, ExamGroup, ExamGroupItem, HealthUnitExam
  - Popula ExamMapping a partir do laboratory_exams já existente (match automático
    por external_code idêntico = MATCH EXATO, sem intervenção)
  - O escopo funcional da Fase 1 não exige mudança no payload trocado com o Synclab
  - NOTA DE IMPLEMENTAÇÃO: o código feature-gated da Fase 3 foi entregue no mesmo
    changeset das Fases 1/2. Portanto, o código não ficou isolado por fase como este
    plano previa originalmente; o impacto em produção continua sendo nenhum enquanto
    SYNC_SUS_SYNCLAB_PUBLIC_IDENTIFIERS_ENABLED=false (valor padrão)

FASE 2 — Matching e importação assistida de catálogo/grupos
  IMPACTO NO SYNLAB: catálogo
  - Substitui a asserção rígida de 123 linhas do seeder por um fluxo de matching
    revisável (MATCH EXATO/PROVÁVEL/SEM CORRESPONDÊNCIA/CONFLITO)
  - Introduz importação assistida de grupos
  - Ainda não muda nada no envio de pedido

FASE 3 — Correção faseada do INV-SYN-04 (identificadores)
  IMPACTO NO SYNLAB: contrato
  STATUS: iniciada no código, ainda inerte em produção
  - Implementada de forma aditiva sob a flag
    SYNC_SUS_SYNCLAB_PUBLIC_IDENTIFIERS_ENABLED=false por padrão
  - A versão transitória outbound-orders-2026-08-08-public-identifiers acrescenta
    pedido.identificador_externo = exam_orders.public_id e
    paciente.identificador_externo = patients.public_id
  - exam_orders.id/patients.id e todos os campos legados permanecem no payload
  - A ativação real continua condicionada à aprovação explícita de quem opera o
    Synclab; até essa aprovação, a flag deve permanecer false e o payload efetivo
    continua sendo o contrato legado outbound-orders-2026-08-03

FASE 4 — Recepção de resultado
  IMPACTO NO SYNLAB: resultado
  STATUS: implementada no SyncHosp, aguardando configuração externa para ativação
  - Novo endpoint /api/v1/laboratory/synclab/results
  - Token exclusivo por integração, payload criptografado, fila, idempotência,
    manual_review e recuperação agendada implementados
  - SYNC_SUS_SYNCLAB_RESULTS_ENABLED=false por padrão
  - Requer que o Synclab seja configurado para chamar o SyncHosp (coordenação externa)
  - Sem mudança no fluxo de envio de pedido já existente

FASE 5 — Sincronização incremental (se/quando o Synclab expuser API de catálogo)
  IMPACTO NO SYNLAB: sincronização
  STATUS: bloqueada — condição externa ainda não satisfeita em 2026-08-09
  - Só se inicia se o fornecedor disponibilizar um endpoint — não é assumido como certo
  - Reaproveita o motor de matching da Fase 2, trocando apenas a fonte (CSV → API)
  - Verificação no código disponível do Synclab confirmou apenas rotas administrativas
    autenticadas em routes/web.php (/examestipos e operações auxiliares); elas não são
    contrato de integração e não serão consumidas pelo SyncHosp
  - O contrato local registra incremental_catalog_sync como
    blocked_provider_api_unavailable; o CSV versionado permanece como fonte operacional
  - Para desbloquear: o fornecedor deve publicar endpoint suportado, autenticação,
    schema versionado de request/response, paginação ou cursor, semântica de exclusão e
    política de limites/retries. Só então a fonte API será ligada ao motor da Fase 2
```

---

## 23. PROMPT PARA O CODEX — FASE 1

```text
Contexto: SyncHosp (Laravel 13/PHP 8.3) tem uma integração de laboratório já em
produção com o Synclab Web (App\Modules\Laboratory). Esta fase NÃO altera o
contrato de envio de pedido já existente (SynclabClient, SynclabOrderPayloadBuilder,
laboratory_order_transmissions permanecem intocados). O objetivo é introduzir uma
camada de catálogo canônico e mapeamento, hoje inexistente, sem quebrar nada do
que já funciona.

Criar (dentro de App\Modules\Laboratory, seguindo Application/Domain/Infrastructure/
Presentation já usado no módulo):

1. Migration + Model `Exam` (organization_id, public_id ulid, name,
   sus_procedure_code nullable, is_active) — canônico, escopado por organização.
2. Migration + Model `ExamMapping` (exam_id, laboratory_integration_id,
   external_code, external_name_snapshot, match_type enum [exact,probable,manual,
   unmatched], match_confidence nullable, mapped_by, mapped_at, is_active).
   unique(laboratory_integration_id, external_code).
3. Migration + Model `HealthUnitExam` (exam_id, health_unit_id, is_enabled,
   enabled_at, enabled_by). unique(exam_id, health_unit_id).
4. Adicionar public_id (ulid, unique) a laboratory_exams via migration nova
   (não editar a migration antiga).
5. Comando artisan (não seeder) que faça o backfill inicial: para cada
   laboratory_exams ativo hoje, criar/associar um Exam canônico (1:1 nesta
   primeira fase, já que hoje cada unidade tem catálogo isolado) e um
   ExamMapping com match_type=exact.
6. Testes Feature cobrindo: isolamento por organização/unidade do novo
   ExamMapping/HealthUnitExam (nenhuma unidade enxerga mapping de outra
   unidade/organização); exam sem HealthUnitExam habilitado não aparece na
   busca de exames do médico.

Não alterar: SynclabClient, SynclabOrderPayloadBuilder, laboratory_order_transmissions,
laboratory_transmission_attempts, o endpoint /app/addrequisicao, ou qualquer
campo hoje enviado ao Synclab. Esta fase é aditiva e não tem impacto no contrato
externo (IMPACTO NO SYNLAB: nenhum).

Seguir os invariantes INV-SYN-01 a INV-SYN-10 descritos em
docs/CODEX_EXAM_CATALOG_IMPLEMENTATION_PLAN.md, especialmente INV-SYN-05
(disponibilidade por unidade continua sendo decisão do SyncHosp).
```

---

## 24. Resumo para decisão

- A integração com o Synclab Web **já existe e funciona** para envio de pedidos — este plano não a substitui, formaliza e estende.
- Recepção de resultado e grupos de exames foram implementados no SyncHosp; a recepção permanece feature-gated até a configuração coordenada do Synclab.
- Existe uma **dívida arquitetural já em produção** (INV-SYN-04 violado: IDs internos usados como identidade externa) que não pode ser corrigida sem coordenação com o fornecedor — tratada como Fase 3, isolada e opcional até haver alinhamento externo.
- Nenhuma fase proposta exige acesso direto ao banco do Synlab Web nem expõe schema interno do SyncHosp — o critério de aceite do §24 do requisito é satisfeito pelo desenho.
