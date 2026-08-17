# Fase 1 — Harness Core/Tenant separado

## Estado

Implementada em 2026-08-09.

## Entrega

- Os testes de integração usam dois bancos SQLite `:memory:` fisicamente independentes:
  `core` e `tenant_test`.
- `CoreModel` usa sempre `core`; não existe mais o desvio que reutilizava o PDO padrão.
- `TenantConnectionManager` resolve `tenant_test` durante testes e preserva o banco legado
  padrão fora do ambiente de teste, até a Fase 3.
- O trait `RefreshCoreAndTenantDatabase` migra e transaciona as duas conexões.
- Roles e permissions usam models Core explícitos.
- Validações e seeders de entidades Core declaram a conexão correta.
- Vínculos operacionais profissional→fila/ponto de atendimento são gravados no Tenant.

## Invariante de teste

Uma consulta acidental do Tenant a uma tabela Core vazia (ou o inverso) deve falhar no
teste. Ter o esquema completo nos dois bancos é apenas uma ponte de migrations; os dados e
models continuam separados pela conexão.

## Validação

```bash
php artisan test --filter=CoreTenantBoundaryTest
php artisan test
```
