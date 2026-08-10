# Correção pós-auditoria da Fase 3 — isolamento de falha no double-write

> Documento de planejamento. Nenhum código foi alterado ao produzi-lo. Escrito depois de
> auditar a implementação da Fase 3 (`docs/CODEX_CORE_UNIT_DB_FASE_3.md`) — 168 testes
> passando, phpstan sem erros, máquina de estados e guardas de `CUTOVER` corretas. Este
> documento cobre o único achado da auditoria, com risco real para a unidade piloto.

## Contexto

`AppServiceProvider::boot()` registra os listeners globais que fazem o double-write de
`SHADOW`/`VALIDATING` (`eloquent.saved: *`, `eloquent.deleted: *`, `DB::listen(...)`),
chamando `TenantShadowWriter::mirrorSaved()`/`mirrorDeleted()`/`mirrorPivotStatement()`
sem nenhum try/catch. Como esses listeners rodam de forma síncrona, uma falha ao escrever
no banco piloto (rede, pool esgotado, credencial rotacionada) propaga como exceção e
derruba a transação **primária** — a do banco legado —, porque `DB::transaction()` faz
rollback em qualquer `Throwable` que escape do closure, independentemente de qual conexão
o originou.

Concretamente: enquanto a unidade estiver em `SHADOW`/`VALIDATING`, uma instabilidade no
banco piloto (a peça mais nova e menos testada do sistema) pode impedir o salvamento de
triagem, consulta, prescrição etc. na unidade real — mesmo com o banco legado saudável.
Isso é o oposto do objetivo da Fase 3 (reduzir blast radius, não aumentá-lo).

## Correção

`TenantShadowWriter` passa a ter contrato "nunca lança" — a responsabilidade de nunca
derrubar a escrita primária fica encapsulada na própria classe, não depende de quem a
chama lembrar de envolver em try/catch. Cada um dos três métodos públicos captura
`Throwable`, reporta via `report()` (mesma convenção já usada em
`app/Http/Controllers/HealthController.php`) e registra um log estruturado sem dado
clínico, sem interromper o fluxo.

### `TenantShadowWriter`

```php
public function mirrorSaved(Model $model): void
{
    $shadow = $this->shadowConnectionFor($model->getConnectionName());
    if ($shadow === null || ! $model->exists) {
        return;
    }

    $this->safely($shadow, $model->getTable(), function () use ($shadow, $model): void {
        DB::connection($shadow)->table($model->getTable())->updateOrInsert(
            [$model->getKeyName() => $model->getKey()],
            $model->getAttributes(),
        );
    });
}

public function mirrorDeleted(Model $model): void
{
    $shadow = $this->shadowConnectionFor($model->getConnectionName());
    if ($shadow === null) {
        return;
    }

    $this->safely($shadow, $model->getTable(), function () use ($shadow, $model): void {
        DB::connection($shadow)->table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->delete();
    });
}

public function mirrorPivotStatement(QueryExecuted $query): void
{
    if (! $this->context->isResolved() || $query->connectionName !== $this->connections->legacyConnectionName()) {
        return;
    }
    $table = $this->pivotTable($query->sql);
    if ($table === null) {
        return;
    }
    $shadow = $this->connections->shadowConnectionName($this->context->healthUnit());
    if ($shadow === null) {
        return;
    }

    $this->safely($shadow, $table, function () use ($shadow, $query): void {
        DB::connection($shadow)->statement($query->sql, $query->bindings);
    });
}

private function safely(string $shadowConnection, string $table, \Closure $write): void
{
    try {
        $write();
    } catch (\Throwable $exception) {
        report($exception);
        Log::warning('tenant.shadow_write_failed', [
            'health_unit_public_id' => $this->context->isResolved() ? $this->context->healthUnit()->public_id : null,
            'shadow_connection' => $shadowConnection,
            'table' => $table,
            'exception' => $exception::class,
        ]);
    }
}
```

Nenhuma outra mudança de comportamento: quando a escrita no espelho funciona, o
resultado é idêntico ao que existe hoje. A rede de segurança contra divergência causada
por uma falha silenciosa continua sendo a reconciliação (`tenant:pilot-reconcile`), que já
compara contagem e hash por tabela — este ajuste só garante que uma falha de espelho vire
um log operacional em vez de um 500 para quem está atendendo paciente.

### Visibilidade operacional (opcional, mas recomendado)

Adicionar ao runbook (`docs/CODEX_CORE_UNIT_DB_FASE_3.md`, seção "Ordem operacional do
piloto") uma linha explícita: durante a janela de `SHADOW`/`VALIDATING`, monitorar o log
por `tenant.shadow_write_failed` e, se aparecer, rodar `tenant:pilot-reconcile` antes do
horário programado, em vez de esperar a reconciliação seguinte. Isso é documentação, não
código novo — não cria um mecanismo de alerta automático nesta rodada.

## Teste de regressão

Novo teste em `TenantDatabasePilotTest.php` (ou arquivo dedicado), reproduzindo o cenário
do achado: colocar uma unidade em `SHADOW`, apontar a conexão dedicada para um perfil
inválido (ex.: banco sqlite inexistente/inacessível, trocando `config('database.connections.<nome>')`
depois do provisionamento) e então:

1. Salvar um registro Tenant (ex. `Panel::update(...)`) enquanto a unidade está em
   `SHADOW`.
2. Assertar que a operação **não lança** e que o dado foi persistido corretamente no banco
   **legado** (`tenant_test`), provando que a falha do espelho não derrubou a escrita
   primária.
3. Assertar (via `Log::shouldReceive('warning')->once()->with('tenant.shadow_write_failed', ...)`,
   ou `Log::spy()` + `Log::shouldHaveReceived(...)`) que a falha foi registrada.

## Fora de escopo

- Qualquer mecanismo de alerta automático (e-mail, Slack, PagerDuty) para
  `tenant.shadow_write_failed` — fica para quando houver decisão de qual canal usar.
- Reconciliação automática disparada pela própria falha de espelho — a reconciliação
  continua sendo um comando explícito (`tenant:pilot-reconcile`), não reativa.
- Qualquer mudança na máquina de estados (`TenantDatabaseLifecycle`), nos guardas de
  `CUTOVER`, no provisionamento (`TenantDatabaseProvisioner`/`TenantSchemaHardener`) ou na
  sincronização inicial (`TenantPilotDataSynchronizer`) — auditados e corretos, sem
  achados.
- Rodar `tenant:pilot-register`/`tenant:pilot-provision` contra uma unidade real de
  produção — isso continua sendo uma decisão operacional de vocês, não parte desta
  correção.

## Critérios de aceite

- `TenantShadowWriter::mirrorSaved()`/`mirrorDeleted()`/`mirrorPivotStatement()` nunca
  propagam exceção do lado do espelho — comportamento coberto pelo teste de regressão.
- Uma falha no espelho gera exatamente um log `tenant.shadow_write_failed` com contexto
  suficiente para localizar a unidade/tabela, sem incluir atributos de dado clínico.
- Comportamento de sucesso (espelho funcionando) permanece idêntico ao atual —
  `test_pilot_runs_through_shadow_validation_cutover_and_rollback` continua passando sem
  alteração.
- Suíte completa (`php artisan test`) e `phpstan analyse` sem novos erros.

## Prompt para o Codex

```
Contexto: a auditoria da Fase 3 (docs/CODEX_CORE_UNIT_DB_FASE_3.md) encontrou que
TenantShadowWriter::mirrorSaved/mirrorDeleted/mirrorPivotStatement não capturam exceção.
Como os listeners em AppServiceProvider::boot() rodam sincronamente dentro da mesma
requisição, uma falha ao escrever no banco piloto (SHADOW/VALIDATING) propaga e derruba a
transação da escrita legada correspondente — ou seja, uma instabilidade no banco piloto
pode impedir que a unidade real salve dado clínico, mesmo com o banco legado saudável.

Leia docs/CODEX_CORE_UNIT_DB_FASE_3_FIXES.md por completo antes de começar. Implemente
exatamente a seção "Correção" desse documento:
1. TenantShadowWriter ganha um método privado `safely()` e os três métodos públicos
   passam a nunca propagar exceção do lado do espelho, reportando via report() e
   registrando Log::warning('tenant.shadow_write_failed', ...) sem dado clínico no
   contexto do log.
2. Teste de regressão provando que uma falha no espelho não derruba a escrita primária e
   que o log é emitido.
3. Atualizar o runbook em docs/CODEX_CORE_UNIT_DB_FASE_3.md com a nota de monitoramento
   descrita na seção "Visibilidade operacional".

Não toque na máquina de estados, nos guardas de CUTOVER, no provisionamento nem na
sincronização inicial — auditados e corretos, fora de escopo desta correção. Não crie
mecanismo de alerta automático. Rode a suíte completa e phpstan antes de considerar
concluído.
```
