<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Benchmarks;

use Rasuvaeff\Mcp\OpenApi\Operation;
use Rasuvaeff\Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\OpenApiFixture;
use Testo\Bench;

final class SpecIndexBench
{
    /**
     * @return array<string, Operation>
     */
    public static function selectTwo(): array
    {
        return (new SpecIndex(OpenApiFixture::spec()))->select(['getBlogTags', 'getCreators']);
    }

    /**
     * @return array<string, Operation>
     */
    #[Bench(
        callables: [
            'two operations' => [self::class, 'selectTwo'],
        ],
        calls: 100,
        iterations: 5,
    )]
    public static function selectOne(): array
    {
        return (new SpecIndex(OpenApiFixture::spec()))->select(['getBlogTags']);
    }
}
