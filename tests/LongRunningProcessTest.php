<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Mcp\Identity\ExecutionIdentity;
use Rasuvaeff\Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Mcp\OpenApi\Operation;
use Rasuvaeff\Mcp\OpenApi\OperationRequestBuilder;
use Rasuvaeff\Mcp\OpenApi\OperationResponseDecoder;
use Rasuvaeff\Mcp\OpenApi\Psr15OperationExecutor;
use Rasuvaeff\Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\IdentityDelegatedHeaderProvider;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\MutableExecutionIdentityProvider;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\OpenApiFixture;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\RecordingScope;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\SubjectRequestAttributes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * One worker process serving many calls: nothing of a call is left for the next one.
 */
#[Test]
#[Covers(Psr15OperationExecutor::class)]
#[Covers(OperationRequestBuilder::class)]
#[Covers(OperationResponseDecoder::class)]
#[Covers(SpecIndex::class)]
final class LongRunningProcessTest
{
    public function aThousandCallsCarryTheirOwnIdentityAndArguments(): void
    {
        $identities = new MutableExecutionIdentityProvider($this->identity('start'));
        $seen = new \ArrayObject();
        $executor = $this->executor($this->handler(static function (ServerRequestInterface $request) use ($seen): ResponseInterface {
            $seen[] = [
                $request->getHeaderLine('Authorization'),
                $request->getAttribute('current_user_id'),
                $request->getQueryParams(),
            ];
            $i = count($seen) - 1;

            return match (true) {
                $i % 10 === 3 => new Response(500, ['Content-Type' => 'application/json'], '{"error":"call ' . $i . '"}'),
                $i % 10 === 5 => new Response(200, ['Content-Type' => 'application/json'], '{"broken'),
                $i % 10 === 7 => new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 5_000)),
                $i % 10 === 9 => throw new \RuntimeException('Call ' . $i . ' was cancelled'),
                default => new Response(200, ['Content-Type' => 'application/json'], '{"call":' . $i . '}'),
            };
        }), $identities);
        $operation = $this->operation();
        $failed = [];

        for ($i = 0; $i < 1_000; ++$i) {
            $identities->identity = $this->identity('user-' . $i);

            try {
                $result = $executor->execute($operation, $i % 2 === 0 ? ['locale' => 'l' . $i] : []);
            } catch (OperationFailedException|\RuntimeException) {
                $failed[$i % 10] = ($failed[$i % 10] ?? 0) + 1;

                continue;
            }

            if (!in_array($i % 10, [5, 7], strict: true)) {
                Assert::same($result, ['call' => $i]);
            }
        }

        Assert::same(count($seen), 1_000);
        Assert::same($failed[3], 100);
        Assert::same($failed[9], 100);
        Assert::same($failed[7], 100);

        foreach ($seen as $i => $call) {
            Assert::same($call, ['Bearer t:user-' . $i, 'user-' . $i, $i % 2 === 0 ? ['locale' => 'l' . $i] : []]);
        }
    }

    public function anInterleavedCallKeepsItsIdentityAndHeaders(): void
    {
        $identities = new MutableExecutionIdentityProvider($this->identity('a'));
        $seen = new \ArrayObject();
        $executor = $this->executor($this->handler(static function (ServerRequestInterface $request) use ($seen): ResponseInterface {
            $locale = $request->getQueryParams()['locale'];

            if ($locale === 'a') {
                \Fiber::suspend();
            }

            $seen[$locale] = [$request->getHeaderLine('Authorization'), $request->getAttribute('current_user_id')];

            return new Response(200, ['Content-Type' => 'application/json'], '{"locale":"' . $locale . '"}');
        }), $identities);
        $operation = $this->operation();
        $first = new \Fiber(static fn(): mixed => $executor->execute($operation, ['locale' => 'a']));
        $first->start();
        Assert::true($first->isSuspended());

        $identities->identity = $this->identity('b');
        Assert::same($executor->execute($operation, ['locale' => 'b']), ['locale' => 'b']);
        $first->resume();

        Assert::same($first->getReturn(), ['locale' => 'a']);
        Assert::same((array) $seen, ['b' => ['Bearer t:b', 'b'], 'a' => ['Bearer t:a', 'a']]);
    }

    public function everyCallLeavesTheScopeItEnteredEvenWhenItFails(): void
    {
        $scope = new RecordingScope();
        $psr17 = new Psr17Factory();
        $executor = new Psr15OperationExecutor(
            handler: $this->handler(static fn(): ResponseInterface => throw new \RuntimeException('Cancelled')),
            serverRequestFactory: $psr17,
            streamFactory: $psr17,
            baseUrl: 'https://api.test',
            inProcessScope: $scope,
        );

        for ($i = 0; $i < 10; ++$i) {
            try {
                $executor->execute($this->operation(), []);
            } catch (\RuntimeException) {
            }
        }

        Assert::same(array_count_values($scope->log), ['enter' => 10, 'leave' => 10]);
    }

    public function reloadingTheDocumentDoesNotChangeAnOperationInUse(): void
    {
        $spec = OpenApiFixture::spec();
        $before = (new SpecIndex($spec))->get('getBlogTags');
        $snapshot = serialize($before);

        $spec['paths']['/rest/v2/blog-tags'] = $spec['paths']['/rest/blog-tags'];
        unset($spec['paths']['/rest/blog-tags']);
        $after = (new SpecIndex($spec))->get('getBlogTags');

        Assert::same($after->path, '/rest/v2/blog-tags');
        Assert::same(serialize($before), $snapshot);
        Assert::same(serialize((new SpecIndex(OpenApiFixture::spec()))->get('getBlogTags')), $snapshot);
    }

    public function rebuildingInOneProcessDoesNotAccumulateMemory(): void
    {
        $build = function (): void {
            $this->executor($this->handler(static fn(): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], '{}')), new MutableExecutionIdentityProvider($this->identity('u')))
                ->execute($this->operation(), []);
        };

        for ($i = 0; $i < 20; ++$i) {
            $build();
        }

        gc_collect_cycles();
        $base = memory_get_usage();

        for ($i = 0; $i < 100; ++$i) {
            $build();
        }

        gc_collect_cycles();
        Assert::true(memory_get_usage() - $base < 1024 * 1024);
    }

    private function executor(RequestHandlerInterface $handler, MutableExecutionIdentityProvider $identities): Psr15OperationExecutor
    {
        $psr17 = new Psr17Factory();

        return new Psr15OperationExecutor(
            handler: $handler,
            serverRequestFactory: $psr17,
            streamFactory: $psr17,
            baseUrl: 'https://api.test',
            identityProvider: $identities,
            delegatedHeaderProvider: new IdentityDelegatedHeaderProvider(),
            requestAttributes: new SubjectRequestAttributes(),
            maxResponseBytes: 1_000,
        );
    }

    private function operation(): Operation
    {
        return (new SpecIndex(OpenApiFixture::spec()))->get('getBlogTags');
    }

    private function identity(string $subject): ExecutionIdentity
    {
        return new ExecutionIdentity(subjectId: $subject, tenantId: 't');
    }

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $handle
     */
    private function handler(\Closure $handle): RequestHandlerInterface
    {
        return new readonly class ($handle) implements RequestHandlerInterface {
            public function __construct(private \Closure $handle) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)($request);
            }
        };
    }
}
