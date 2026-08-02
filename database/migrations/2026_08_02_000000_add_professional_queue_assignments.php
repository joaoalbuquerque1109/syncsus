<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_professional_queue', function (Blueprint $table): void {
            $table->foreignId('health_professional_id')->constrained('health_professionals')->cascadeOnDelete();
            $table->foreignId('queue_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['health_professional_id', 'queue_id']);
            $table->index('queue_id');
        });

        Schema::create('health_professional_service_point', function (Blueprint $table): void {
            $table->foreignId('health_professional_id')->constrained('health_professionals')->cascadeOnDelete();
            $table->foreignId('service_point_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['health_professional_id', 'service_point_id']);
            $table->index('service_point_id');
        });

        $now = now();
        DB::table('health_professionals')
            ->whereNotNull('user_id')
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (object $professional) use ($now): void {
                $unitIds = DB::table('health_professional_health_unit')
                    ->where('health_professional_id', $professional->id)
                    ->pluck('health_unit_id');
                if ($unitIds->isEmpty()) {
                    return;
                }

                $queues = DB::table('queues')
                    ->join('departments', 'departments.id', '=', 'queues.department_id')
                    ->whereIn('queues.health_unit_id', $unitIds)
                    ->where('queues.is_active', true);

                if ($professional->profession_type === 'doctor') {
                    $specialtyIds = DB::table('health_professional_specialty')
                        ->where('health_professional_id', $professional->id)
                        ->pluck('specialty_id');
                    if ($specialtyIds->isEmpty()) {
                        return;
                    }
                    $queues->where('departments.type', 'medical')
                        ->whereIn('queues.specialty_id', $specialtyIds);
                } elseif (in_array($professional->profession_type, ['nurse', 'technician'], true)) {
                    $queues->where('departments.type', 'triage');
                } else {
                    return;
                }

                $queueIds = $queues->pluck('queues.id');
                foreach ($queueIds as $queueId) {
                    DB::table('health_professional_queue')->insertOrIgnore([
                        'health_professional_id' => $professional->id,
                        'queue_id' => $queueId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $pointIds = DB::table('queue_service_point')
                    ->whereIn('queue_id', $queueIds)
                    ->pluck('service_point_id')
                    ->unique();
                foreach ($pointIds as $pointId) {
                    DB::table('health_professional_service_point')->insertOrIgnore([
                        'health_professional_id' => $professional->id,
                        'service_point_id' => $pointId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_professional_service_point');
        Schema::dropIfExists('health_professional_queue');
    }
};
