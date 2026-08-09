<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Laboratory\Application\Services\ExamNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ExamNameNormalizerTest extends TestCase
{
    #[DataProvider('regularPluralProvider')]
    public function test_regular_es_plurals_normalize_to_the_same_form_as_their_singular(
        string $plural,
        string $singular,
    ): void {
        $normalizer = new ExamNameNormalizer;

        $this->assertSame(
            $normalizer->normalize($singular),
            $normalizer->normalize($plural),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function regularPluralProvider(): iterable
    {
        yield 'gases' => ['GASES', 'GÁS'];
        yield 'flores' => ['FLORES', 'FLOR'];
        yield 'luzes' => ['LUZES', 'LUZ'];
        yield 'exames' => ['EXAMES', 'EXAME'];
        yield 'componentes' => ['COMPONENTES', 'COMPONENTE'];
    }
}
