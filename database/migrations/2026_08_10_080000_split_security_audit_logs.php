<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $securityPrefixes = ['user.', 'professional.', 'organization.', 'tenant.', 'security.'];

    public function up(): void
    {
        $connection = DB::connection()->getName();

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->ulid('correlation_id')->nullable()->index()->after('action');
        });

        DB::table('audit_logs')->select('id')->whereNull('correlation_id')->orderBy('id')
            ->chunkById(500, function ($logs): void {
                foreach ($logs as $log) {
                    DB::table('audit_logs')->where('id', $log->id)->update([
                        'correlation_id' => (string) Str::ulid(),
                    ]);
                }
            });

        if ($connection === 'core') {
            Schema::create('security_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('health_unit_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 100)->index();
                $table->ulid('correlation_id')->index();
                $table->nullableMorphs('auditable');
                $table->json('changed_fields')->nullable();
                $table->json('context')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
            });

            $this->securityQuery()->orderBy('id')->chunkById(500, function ($logs): void {
                foreach ($logs as $log) {
                    $attributes = (array) $log;
                    unset($attributes['patient_id'], $attributes['encounter_id']);
                    DB::table('security_audit_logs')->insert($attributes);
                }
            });

            // Só apaga o que acabou de copiar: uma migration roda presa a uma única
            // conexão e não consegue gravar em security_audit_logs (sempre 'core')
            // enquanto executa numa conexão de unidade, então apagar fora deste bloco
            // destruiria histórico sem nunca copiá-lo. Ver MigrateTenantSecurityAuditHistory
            // para completar a divisão em conexões de unidade que já têm dado real.
            $this->securityQuery()->delete();
        }
    }

    public function down(): void
    {
        if (DB::connection()->getName() === 'core' && Schema::hasTable('security_audit_logs')) {
            DB::table('security_audit_logs')->orderBy('id')->chunkById(500, function ($logs): void {
                foreach ($logs as $log) {
                    DB::table('audit_logs')->insert([
                        ...(array) $log,
                        'patient_id' => null,
                        'encounter_id' => null,
                    ]);
                }
            });
            Schema::drop('security_audit_logs');
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn('correlation_id');
        });
    }

    private function securityQuery(): Builder
    {
        return DB::table('audit_logs')->where(function ($query): void {
            foreach ($this->securityPrefixes as $index => $prefix) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}('action', 'like', $prefix.'%');
            }
        });
    }
};
