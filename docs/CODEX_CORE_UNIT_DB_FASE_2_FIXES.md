# Correções pós-auditoria da Fase 2 — Prontuário local por unidade

> Documento de planejamento. Nenhum código foi alterado ao produzi-lo. Escrito depois de
> auditar a implementação de `docs/CODEX_CORE_UNIT_DB_FASE_1.md` e
> `docs/CODEX_CORE_UNIT_DB_FASE_2.md` (162 testes passando, phpstan sem erros) — a
> implementação em si é sólida; este documento cobre dois achados encontrados na auditoria,
> um deles com risco real de integridade de dado.

## Contexto

A Fase 2 moveu `PatientContact`/`PatientAddress`/`PatientGuardian` (entre outros) para
conexão Tenant enquanto `Patient`/`PatientIdentifier` permanecem Core. Isso quebrou a
premissa que `SavePatientAction::execute()` tinha antes — tudo dentro de uma única
`DB::transaction()` — porque não é possível abrir uma transação atômica cobrindo duas
conexões diferentes (Princípio 2 do plano mestre: "nenhuma transação distribuída... toda
operação que escreve nas duas conexões precisa ser desenhada como idempotente/retentável").

A implementação atual já separa corretamente em duas transações (Core, depois Tenant), mas
não fechou o caso de retry: no caminho de **criação** de paciente novo, se a transação
Tenant falhar depois que a Core já commitou, um reenvio do formulário cria um **segundo**
`Patient`. Nenhum teste cobre esse cenário.

## 1. Correção principal — idempotência de `SavePatientAction` na criação

Reaproveita o padrão que já existe em `IdempotencyKey`/`OpenEncounterAction`
(`app/Modules/Reception/...`) — mesmo formato de chave, mesma lógica de replay — só que do
lado **Core**, porque é o `Patient` que precisa ser protegido contra duplicação.

### 1.1 Migration

Nova migration criando `patient_operation_keys` (Core):

```php
Schema::create('patient_operation_keys', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('route_name', 64);
    $table->string('idempotency_key', 64);
    $table->char('request_hash', 64);
    $table->ulid('patient_public_id')->nullable();
    $table->string('status', 24)->default('pending'); // pending|completed
    $table->timestamps();

    $table->unique(['user_id', 'route_name', 'idempotency_key'], 'patient_operation_key_unique');
});
```

`down()` faz `Schema::dropIfExists('patient_operation_keys')` — aditiva, reversível, sem
tocar em nenhuma tabela existente.

### 1.2 Model `PatientOperationKey` (Core)

`app/Modules/Patients/Infrastructure/Eloquent/PatientOperationKey.php`, espelhando a forma
de `App\Modules\Reception\Infrastructure\Eloquent\IdempotencyKey`, mas `extends CoreModel`:

```php
final class PatientOperationKey extends CoreModel
{
    protected $guarded = [];
}
```

### 1.3 `SavePatientRequest`

Adicionar regra condicional (só exigida na criação, seguindo a mesma técnica já usada no
arquivo para diferenciar criação/edição via `$this->route('patient')`):

```php
'idempotency_key' => [
    Rule::requiredIf($this->route('patient') === null),
    'nullable', 'string', 'max:64',
],
```

Não se aplica à edição — nesse caminho `$patient` já é conhecido pelo chamador, então um
reenvio já é idempotente por natureza (reaproveita a mesma linha).

### 1.4 `SavePatientAction::execute()`

Reestrutura o método (mantém a assinatura pública inalterada):

1. Se `$patient` já foi passado (edição): comportamento atual, sem mudança.
2. Se `$patient` é `null` (criação):
   - Calcula `request_hash` igual a `OpenEncounterAction::execute()` (hash do payload
     sem `idempotency_key`, chaves ordenadas).
   - Dentro da transação Core (`DB::connection('core')->transaction(...)`), antes de criar
     o `Patient`:
     ```php
     $existingKey = PatientOperationKey::query()
         ->where('user_id', $user->getKey())
         ->where('route_name', 'patients.store')
         ->where('idempotency_key', $data['idempotency_key'])
         ->lockForUpdate()
         ->first();

     if ($existingKey !== null) {
         if (! hash_equals($existingKey->request_hash, $requestHash)) {
             throw ValidationException::withMessages([
                 'idempotency_key' => 'Este formulário já foi usado com dados diferentes. Recarregue a página.',
             ]);
         }
         if ($existingKey->status === 'completed') {
             return Patient::query()->where('public_id', $existingKey->patient_public_id)->firstOrFail();
         }
         // status === 'pending': reaproveita o Patient já criado numa tentativa anterior
         // em vez de criar um novo — é isto que elimina a duplicata.
         $patient = Patient::query()->where('public_id', $existingKey->patient_public_id)->firstOrFail();
     }
     ```
   - Se `$existingKey` continua `null`: cria o `Patient` normalmente e, **na mesma
     transação Core**, grava `PatientOperationKey` com `status = 'pending'` e
     `patient_public_id` = public_id do paciente recém-criado.
3. Fora da transação Core (como já é hoje): roda a transação Tenant (contatos, endereço,
   responsável, `UnitPatientRegistry::ensure()`).
4. Ao final, se `$existingKey` foi usado (caminho de criação), atualiza
   `PatientOperationKey` para `status = 'completed'`. Se essa atualização falhar, o pior
   caso é reprocessar a etapa Tenant (idempotente) no próximo retry — nunca duplica o
   `Patient`.

### 1.5 `PatientController::create()` + view

`create()` passa a gerar `'idempotencyKey' => (string) Str::ulid()` para a view, igual a
`ReceptionController::create()`. A view `patients/create.blade.php` ganha:

```blade
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
```

### 1.6 Teste de regressão

Novo teste em `tests/Feature/PatientManagementTest.php` (ou arquivo dedicado), reproduzindo
exatamente o cenário do achado: criar paciente com uma `idempotency_key`, forçar/simular
falha na etapa Tenant (ex.: uma segunda chamada de `SavePatientAction::execute()` com a
mesma `idempotency_key` e os mesmos dados, sem passar por uma falha real — o que importa é
provar que a segunda chamada **não cria um segundo `Patient`**), e então:

```php
$this->assertDatabaseCount('patients', 1); // na conexão core
```

Mais um teste confirmando que uma segunda chamada com `idempotency_key` igual mas **payload
diferente** lança `ValidationException` (replay de formulário alterado).

## 2. Correção menor — auditoria ausente em `ResolveUnitPatientMigrationConflict`

`ResolveUnitPatientMigrationConflict::execute()` já registra `resolved_by_public_id` e
`resolved_at` no próprio registro de conflito, mas não emite um evento formal via
`RecordAuditEventAction`, ao contrário do padrão usado em módulos como Documents e
Laboratory para transições administrativas equivalentes.

Adicionar, no fim do método (antes do `return $conflict->refresh();`):

```php
$this->audit->execute(
    'patient.unit_migration_conflict_resolved',
    request(), // ou receber Request como parâmetro, se preferível para testabilidade
    $actor,
    [
        'conflict' => $conflict->public_id,
        'source_table' => $conflict->source_table,
        'source_id' => $conflict->source_id,
        'resolved_unit' => $unit->public_id,
    ],
    (int) $unit->getKey(),
);
```

Requer injetar `RecordAuditEventAction` no construtor de `ResolveUnitPatientMigrationConflict`
(hoje a classe não tem construtor com dependências). Prefira receber `Request` como
parâmetro explícito de `execute()` em vez de usar o helper `request()`, para manter o
mesmo padrão de injeção explícita usado no resto do módulo (`RecordAuditEventAction::execute`
já é chamado assim em `IssueClinicalDocumentAction`, por exemplo) — ajustar a assinatura do
comando artisan `patients:resolve-unit-conflict` de acordo.

Teste: estender `test_backfill_migrates_only_unambiguous_records_and_is_idempotent` (ou
criar um teste dedicado) verificando que resolver um conflito grava um `AuditLog`/evento
correspondente.

## Fora de escopo

- Mudar o comportamento de busca exata de CPF/CNS (achado 3 da auditoria) — é uma decisão
  de produto já implementada corretamente, não um bug. Ação recomendada é só de texto de
  apoio na tela, não código estrutural, e fica a critério de quem opera a recepção.
- Remover `tests/Feature/ScratchPatientRelationBoundaryTest.php` — arquivo de verificação
  descartável desta auditoria, não rastreado no git; remover ou manter é decisão do usuário,
  não faz parte desta correção.
- Qualquer mudança em `MigrateLegacyUnitPatientRecords`/`ResolveUnitPatientMigrationConflict`
  além da chamada de auditoria da seção 2 — o algoritmo de conflito em si já foi validado e
  não precisa mudar.
- Qualquer mudança em `UnitPatientRegistry`, `PatientIdentifierProtector`,
  `BackfillPatientIdentifierProtection` — auditados e corretos, sem achados.

## Critérios de aceite

- Novo teste de regressão prova que duas chamadas de `SavePatientAction::execute()` com a
  mesma `idempotency_key` e o mesmo payload de criação resultam em **um único** `Patient`
  na conexão `core`.
- Teste prova que a mesma `idempotency_key` com payload diferente lança `ValidationException`
  em vez de criar/reaproveitar silenciosamente.
- Caminho de edição (`$patient` não nulo) permanece sem exigir `idempotency_key` e sem
  regressão de comportamento.
- `ResolveUnitPatientMigrationConflict::execute()` grava um evento de auditoria consultável
  junto com a resolução do conflito.
- Suíte completa (`php artisan test`) e `phpstan analyse` sem novos erros.
- Nenhuma mudança em `PatientContact`/`PatientAddress`/`PatientGuardian`/`UnitPatient`/
  migrations já existentes da Fase 2.

## Prompt para o Codex

```
Contexto: a auditoria da Fase 2 (docs/CODEX_CORE_UNIT_DB_FASE_2.md) encontrou que
SavePatientAction::execute() não é mais atômico entre a escrita Core (Patient/
PatientIdentifier) e a escrita Tenant (contatos/endereço/responsável) — um retry após
falha parcial na criação de paciente novo pode duplicar o Patient. Também encontrou que
ResolveUnitPatientMigrationConflict::execute() não emite evento de auditoria formal.

Leia docs/CODEX_CORE_UNIT_DB_FASE_2_FIXES.md por completo antes de começar. Implemente
exatamente as seções 1 e 2 desse documento, nesta ordem:
1. Migration + model PatientOperationKey (Core).
2. Regra condicional de idempotency_key em SavePatientRequest (só na criação).
3. Reestruturação de SavePatientAction::execute() para o caminho de criação, seguindo o
   mesmo padrão de IdempotencyKey/OpenEncounterAction já usado em Reception.
4. PatientController::create() + view gerando e propagando o idempotency_key.
5. Teste de regressão provando que retry com a mesma chave não duplica o Patient, e que
   payload divergente é rejeitado.
6. RecordAuditEventAction em ResolveUnitPatientMigrationConflict::execute() + ajuste do
   comando artisan patients:resolve-unit-conflict e teste correspondente.

Não toque em nada listado em "Fora de escopo". Não altere o comportamento do caminho de
edição de paciente. Rode a suíte completa e phpstan antes de considerar concluído.
```
