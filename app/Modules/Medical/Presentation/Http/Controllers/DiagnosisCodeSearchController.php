<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DiagnosisCodeSearchController extends Controller
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
        $codes = DiagnosisCode::query()
            ->select(['id', 'code', 'description'])
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
            ->map(fn (DiagnosisCode $item): array => [
                'id' => $item->getKey(),
                'code' => $item->code,
                'description' => $item->description,
                'label' => $item->code.' · '.$item->description,
            ]);

        return response()->json(['data' => $codes]);
    }
}
