# Sync Hosp — Claude Code Project Instructions

## Purpose

Sync Hosp is a multi-hospital healthcare system. Work must prioritize correctness, tenant isolation, traceability, data integrity, maintainability, and low-context execution.

## Core architecture rules

- Prefer a modular monolith unless the existing codebase clearly uses another structure.
- Keep Controllers thin.
- Place business rules in Actions, Services, Domain Services, Policies, Value Objects, or dedicated domain classes.
- Avoid duplicating business rules across Controllers, Jobs, Commands, Views, and JavaScript.
- Keep persistence concerns separate from domain decisions when practical.
- Prefer explicit, testable workflows over hidden side effects.
- Reuse existing conventions before introducing new abstractions.
- Make the smallest correct change that solves the task.
- Do not refactor unrelated code during a scoped task.

## Multi-hospital / tenant rules

- Every sensitive domain operation must be scoped to the authenticated hospital/tenant context.
- Never trust `hospital_id`, `tenant_id`, `unit_id`, or equivalent identifiers supplied by the client as authorization.
- Resolve tenant context from the authenticated session, token, membership, hostname, middleware, or existing trusted tenant resolver.
- Prevent cross-tenant reads, writes, updates, deletes, exports, searches, and file access.
- When adding new tenant-owned entities, ensure tenant ownership is explicit and indexed when appropriate.
- Prefer centralized tenant scopes/policies over scattered manual `where hospital_id = ...` checks.

## Sensitive healthcare data

Treat the following as sensitive:
- patient identity and demographics;
- clinical records;
- triage;
- medical attendance;
- prescriptions;
- certificates/medical documents;
- exams and results;
- attachments;
- audit events;
- staff access to patient information.

Never expose sensitive data in logs unnecessarily.

## Auditability

Important clinical and administrative state transitions should be traceable.

When applicable, audit:
- actor;
- hospital/tenant;
- entity;
- previous state;
- new state;
- timestamp;
- reason/metadata;
- source/IP/device when the project already supports it.

Do not silently overwrite historical clinical information when an append-only or revision model is more appropriate.

## Concurrency

For workflows that can be claimed or completed by multiple users:
- identify race conditions;
- use database transactions;
- use row locks, unique constraints, optimistic locking, or atomic updates where appropriate;
- do not rely only on UI disabling;
- add a concurrency-oriented test when the risk is material.

Examples:
- patient queue claiming;
- starting triage;
- starting medical attendance;
- generating sequential identifiers;
- consuming scarce resources;
- closing an attendance.

## Validation

- Validate on the server.
- Use existing Form Requests / validators when present.
- Authorization and validation are separate concerns.
- Never assume frontend validation is sufficient.

## Database

- Inspect existing schema and conventions before adding tables/columns.
- Add indexes for tenant filters, high-frequency lookups, foreign keys, queue status, timestamps, and other demonstrated access patterns.
- Avoid N+1 queries.
- Avoid loading large patient datasets into memory.
- Use pagination for large collections.
- Migrations must be reversible when practical.
- Do not alter production data destructively without an explicit migration strategy.

## Laravel conventions

When the project uses Laravel:
- prefer Form Requests for non-trivial validation;
- prefer Policies/Gates for authorization;
- prefer Jobs for slow or asynchronous work;
- prefer queues for PDF generation, notifications, exports, integrations, and heavy processing;
- use transactions for multi-step state transitions;
- keep Blade/Alpine/Vue/JS presentation logic free from authoritative clinical/business rules;
- do not use `env()` outside configuration files unless the codebase explicitly requires it.

## PDFs and documents

- PDF generation must not become the source of truth.
- Build documents from persisted/validated domain data.
- For expensive PDFs, prefer queued generation.
- Do not store permanent documents only on local ephemeral disk.
- Preserve authorization on document download.
- Avoid recalculating business values independently inside templates.

## Background jobs

Jobs must be:
- idempotent when practical;
- safe to retry;
- tenant-aware;
- observable;
- explicit about failures.

Do not enqueue sensitive payloads unnecessarily when an entity ID is enough.

## Testing

Before changing code:
1. locate tests for the target module;
2. understand the current behavior;
3. add or modify the smallest relevant tests.

Test order:
1. exact test;
2. module tests;
3. related integration tests;
4. full suite only when justified or before final delivery.

For critical flows, test:
- authorization;
- tenant isolation;
- invalid transitions;
- concurrency where relevant;
- successful state transition;
- audit creation when applicable.

## Context-efficiency rules

Do not scan or read the whole repository by default.

Use this sequence:
1. identify the module;
2. search for symbols/routes/classes;
3. list candidate files;
4. read only the minimum necessary files;
5. expand scope only when evidence requires it.

Do not read entire:
- `vendor/`;
- `node_modules/`;
- compiled/build output;
- large SQL dumps;
- full log files;
- generated assets;
- large exports;
- backup files.

For logs, use targeted grep/tail/filtering.
For test output, prefer concise/filtered output.
For large files, search first and read only relevant ranges.

## Task discipline

For every non-trivial task, keep a compact working model:

- objective;
- observed problem;
- affected module;
- files actually relevant;
- invariants;
- acceptance criteria;
- tests;
- unresolved risk.

Do not repeatedly re-read stable project documentation if the required facts are already known in the current task.

## Scope control

If the user asks to modify one module:
- do not change unrelated modules;
- do not rename global concepts;
- do not perform broad dependency upgrades;
- do not rewrite architecture unless required.

If a cross-module dependency is genuinely necessary, explain it before expanding the change.

## Before editing

1. Identify the module.
2. Locate the execution path.
3. Identify authorization and tenant scope.
4. Identify persistence/state transitions.
5. Identify tests.
6. State the smallest implementation plan.
7. Edit only after the relevant path is understood.

## Completion checklist

A task is complete only when:
- requested behavior works;
- authorization is preserved;
- tenant isolation is preserved;
- tests relevant to the change pass;
- no known critical regression remains;
- no unrelated files were changed without justification;
- final response lists files changed and validation performed.
