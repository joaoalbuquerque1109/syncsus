<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Medical\Infrastructure\Eloquent\SusProcedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SusProcedureSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);
        $term = trim(str_replace(['%', '_'], '', (string) $data['q']));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $code = mb_strtoupper($term);
        $procedures = SusProcedure::query()
            ->select([
                'id',
                'code',
                'description',
                'complexity',
                'sex_restriction',
                'minimum_age_months',
                'maximum_age_months',
            ])
            ->where('is_active', true)
            ->where(function ($query) use ($code, $term): void {
                $query->where('code', 'like', $code.'%')
                    ->orWhere('description', 'like', '%'.$term.'%');
            })
            ->orderByRaw(
                'CASE WHEN code = ? THEN 0 WHEN code LIKE ? THEN 1 ELSE 2 END',
                [$code, $code.'%'],
            )
            ->orderBy('code')
            ->limit(20)
            ->get()
            ->map(fn (SusProcedure $procedure): array => [
                'id' => $procedure->getKey(),
                'code' => $procedure->code,
                'description' => $procedure->description,
                'complexity' => $procedure->complexity,
                'sex_restriction' => $procedure->sex_restriction,
                'minimum_age_months' => $procedure->minimum_age_months,
                'maximum_age_months' => $procedure->maximum_age_months,
                'label' => $procedure->code.' · '.$procedure->description,
            ]);

        return response()->json(['data' => $procedures]);
    }
}
