<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;

final class PublicLookupService
{
    /** @var array{HealthUnit, string}|null */
    private ?array $previousContext = null;

    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionManager $connectionManager,
    ) {}

    public function registerPanel(Panel $panel): void
    {
        $this->register(
            (string) $panel->public_code,
            'panel',
            $panel->resolveHealthUnit() ?? throw new \LogicException('Panel sem unidade Core válida.'),
        );
    }

    public function registerClinicalDocument(ClinicalDocument $document): void
    {
        $this->register(
            (string) $document->verification_code,
            'clinical_document',
            $document->resolveHealthUnit() ?? throw new \LogicException('Documento sem unidade Core válida.'),
        );
    }

    public function resolvePanel(string $publicCode): Panel
    {
        $lookup = $this->lookup($publicCode, 'panel');
        $panel = Panel::on($lookup->tenant_connection)
            ->where('public_code', $publicCode)
            ->firstOrFail();
        $this->activate(
            $panel->resolveHealthUnit() ?? throw new \LogicException('Panel sem unidade Core válida.'),
            $lookup->tenant_connection,
        );

        return $panel;
    }

    public function resolveClinicalDocument(string $verificationCode): ClinicalDocument
    {
        $lookup = $this->lookup($verificationCode, 'clinical_document');
        $document = ClinicalDocument::on($lookup->tenant_connection)
            ->where('verification_code', $verificationCode)
            ->firstOrFail();
        $this->activate(
            $document->resolveHealthUnit() ?? throw new \LogicException('Documento sem unidade Core válida.'),
            $lookup->tenant_connection,
        );

        return $document;
    }

    public function reset(): void
    {
        $this->tenantContext->reset();
        if ($this->previousContext !== null) {
            $this->tenantContext->resolve($this->previousContext[0], $this->previousContext[1]);
            $this->previousContext = null;
        }
    }

    private function register(string $identifier, string $entityType, HealthUnit $unit): void
    {
        PublicLookupIndex::query()->updateOrCreate(
            ['public_id_or_code' => $identifier],
            [
                'entity_type' => $entityType,
                'tenant_connection' => $this->connectionManager->connectionName($unit),
            ],
        );
    }

    private function lookup(string $identifier, string $entityType): PublicLookupIndex
    {
        return PublicLookupIndex::query()
            ->where('public_id_or_code', $identifier)
            ->where('entity_type', $entityType)
            ->firstOrFail();
    }

    private function activate(HealthUnit $unit, string $connectionName): void
    {
        if ($this->previousContext === null && $this->tenantContext->isResolved()) {
            $this->previousContext = [
                $this->tenantContext->healthUnit(),
                $this->tenantContext->connectionName(),
            ];
        }
        $this->tenantContext->reset();
        $this->tenantContext->resolve($unit, $connectionName);
    }
}
