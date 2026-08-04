<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

final class SynclabExamCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalogPath = database_path('data/synclab_exams.csv');
        if (! is_file($catalogPath)) {
            throw new RuntimeException('Catalogo de exames Synclab nao encontrado.');
        }

        $rows = $this->parentExamRows($catalogPath);
        $version = hash_file('sha256', $catalogPath) ?: null;
        foreach (HealthUnit::query()->get() as $unit) {
            $integration = $this->integration($unit);
            $activeCodes = [];

            foreach ($rows as $row) {
                $externalCode = preg_replace('/\.0$/', '', trim($row['codigo'])) ?: trim($row['codigo']);
                $activeCodes[] = $externalCode;
                $exam = LaboratoryExam::query()->firstOrNew([
                    'laboratory_integration_id' => $integration->getKey(),
                    'external_code' => $externalCode,
                ]);
                if ($exam->exists && $exam->source_version === null) {
                    continue;
                }
                $exam->fill([
                    'acronym' => $this->nullable($row['mnemonico']),
                    'integration_acronym' => $this->nullable($row['mnemonico']),
                    'name' => trim($row['nome']),
                    'short_name' => Str::limit(trim($row['nome']), 255, ''),
                    'sus_procedure_code' => $this->procedureCodes()[(int) $externalCode]
                        ?? $exam->sus_procedure_code,
                    'group_name' => $this->nullable($row['tipo']),
                    'synonyms' => array_filter([$this->nullable($row['descricao'])]),
                    'is_active' => true,
                    'source_version' => $version,
                    'content_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
                ])->save();
            }

            LaboratoryExam::query()
                ->where('laboratory_integration_id', $integration->getKey())
                ->whereNotNull('source_version')
                ->whereNotIn('external_code', $activeCodes)
                ->update(['is_active' => false]);
        }
    }

    private function integration(HealthUnit $unit): LaboratoryIntegration
    {
        $targetUnitCode = trim((string) config('sync_sus.synclab.unit_code'));
        $isTarget = $targetUnitCode !== '' && hash_equals($targetUnitCode, (string) $unit->code);
        $configuredCnes = preg_replace('/\D/', '', (string) config('sync_sus.synclab.cnes'));
        if ($isTarget && strlen($configuredCnes) === 7 && $unit->cnes_code !== $configuredCnes) {
            $unit->forceFill(['cnes_code' => $configuredCnes])->save();
        }

        $integration = LaboratoryIntegration::query()->firstOrNew([
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
        ]);
        $username = trim((string) config('sync_sus.synclab.username')) ?: (string) $integration->username;
        $password = (string) config('sync_sus.synclab.password') ?: (string) $integration->password;
        $ready = $isTarget
            && (bool) config('sync_sus.synclab.enabled')
            && strlen((string) $unit->fresh()?->cnes_code) === 7
            && $username !== ''
            && $password !== '';

        $integration->fill([
            'organization_id' => $unit->organization_id,
            'base_url' => rtrim((string) config('sync_sus.synclab.base_url'), '/'),
            'external_tenant_code' => $unit->fresh()?->cnes_code,
            'username' => $username !== '' ? $username : null,
            'password' => $password !== '' ? $password : null,
            'is_active' => true,
            'transmission_enabled' => $ready,
            'result_sync_enabled' => false,
            'connection_status' => $ready ? 'configured' : 'not_configured',
        ])->save();

        return $integration;
    }

    /** @return list<array{codigo: string, nome: string, descricao: string, mnemonico: string, tipo: string, itemexame: string, sr_recno: string}> */
    private function parentExamRows(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setCsvControl(';');
        $headers = $file->fgetcsv();
        if (! is_array($headers)) {
            throw new RuntimeException('Cabecalho invalido no catalogo Synclab.');
        }
        $headers = array_map(static fn (mixed $value): string => ltrim((string) $value, "\xEF\xBB\xBF"), $headers);
        $rows = [];
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            if (! is_array($values) || count($values) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, array_map(static fn (mixed $value): string => trim((string) $value), $values));
            if (($row['itemexame'] ?? '') !== '0') {
                continue;
            }
            /** @var array{codigo: string, nome: string, descricao: string, mnemonico: string, tipo: string, itemexame: string, sr_recno: string} $row */
            $rows[] = $row;
        }

        if (count($rows) !== 123) {
            throw new RuntimeException('O catalogo Synclab deve conter exatamente 123 exames-pai ativos.');
        }

        return $rows;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Curated mappings validated against DadosPadraoTabelaProcedimentoSUS.php.
     * Unmapped exams remain selectable because the Synclab contract uses its
     * own external code; a SUS code is never inferred from an ambiguous name.
     *
     * @return array<int, string>
     */
    private function procedureCodes(): array
    {
        return [
            '1' => '0202010120', '3' => '0202030091', '5' => '0202010180',
            '6' => '0202030636', '7' => '0202010767', '8' => '0202010708',
            '12' => '0202010694', '15' => '0202010392', '20' => '0202010422',
            '23' => '0202060250', '24' => '0202060233', '28' => '0202010465',
            '29' => '0202060381', '30' => '0202060217', '31' => '0202010201',
            '32' => '0202060268', '36' => '0202010503', '37' => '0202010600',
            '42' => '0202010643', '44' => '0202060306', '46' => '0202010325',
            '47' => '0202010333', '49' => '0202010317', '52' => '0202060241',
            '53' => '0202010554', '55' => '0202010562', '56' => '0202060160',
            '58' => '0202060276', '61' => '0202030075', '62' => '0202010635',
            '64' => '0202060349', '66' => '0202010384', '67' => '0202020134',
            '69' => '0202050114', '70' => '0202060292', '71' => '0202010627',
            '72' => '0202020142', '73' => '0202060136', '74' => '0202010651',
            '76' => '0202010210', '77' => '0202010228', '79' => '0202010430',
            '84' => '0202030202', '90' => '0202010368', '114' => '0202030105',
            '115' => '0202010473', '118' => '0202031217', '122' => '0202010660',
            '124' => '0202010678', '127' => '0202020380', '142' => '0202031209',
            '143' => '0202020150', '146' => '0202020037', '148' => '0202010295',
            '211' => '0202010732', '212' => '0202010538', '213' => '0202010260',
            '223' => '0202020576',
        ];
    }
}
