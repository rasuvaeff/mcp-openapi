<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Tests;

use Mcp\Server\Capability\Discovery\SchemaValidator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Mcp\OpenApi\Exception\InvalidToolArgumentException;
use Rasuvaeff\Mcp\OpenApi\HttpOperationExecutor;
use Rasuvaeff\Mcp\OpenApi\InputSchemaBuilder;
use Rasuvaeff\Mcp\OpenApi\LocalDefinitions;
use Rasuvaeff\Mcp\OpenApi\Operation;
use Rasuvaeff\Mcp\OpenApi\OperationRequestBuilder;
use Rasuvaeff\Mcp\OpenApi\Psr15OperationExecutor;
use Rasuvaeff\Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\GeneratedCalls;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\RecordingRequestHandler;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The bridge between a document and the calls made from it: what the document
 * allows is accepted and reaches the API as a request the same document
 * accepts; what it forbids never gets that far.
 */
#[Test]
#[Covers(OperationRequestBuilder::class)]
#[Covers(Psr15OperationExecutor::class)]
#[Covers(HttpOperationExecutor::class)]
#[Covers(InputSchemaBuilder::class)]
#[Covers(LocalDefinitions::class)]
#[Covers(SpecIndex::class)]
final class GeneratedCallsTest
{
    /** @var array<string, Operation> */
    private static array $operations = [];

    /**
     * @param array<string, mixed> $case
     */
    #[Property(runs: 300, generators: [GeneratedCalls::class, 'valid'])]
    public function aValidCaseIsAValidCallAndSendsAValidRequest(array $case): void
    {
        $operation = $this->operation($case['operationKey']);
        $arguments = GeneratedCalls::arguments($case);
        Classify::cover($case['operationKey'] === 'getNote', 'path values', 10.0);
        Classify::cover(isset($arguments['tags']), 'array query', 5.0);
        Classify::cover($case['body'] !== null, 'body', 15.0);

        Assert::same($this->inputErrors($operation, $arguments), []);

        $request = $this->send($operation, $arguments);

        Assert::same(
            array_map(static fn(object $violation): string => $violation->code, GeneratedCalls::contract()->validateRequest($request)->violations),
            [],
        );
        Assert::same(GeneratedCalls::contract()->requireMatch($request)->operation->key, $case['operationKey']);

        if (isset($arguments['ratio'])) {
            Assert::same((float) $request->getQueryParams()['ratio'], (float) $arguments['ratio']);
        }

        if ($case['body'] !== null) {
            Assert::same(json_decode((string) $request->getBody(), associative: true, flags: JSON_THROW_ON_ERROR), json_decode(json_encode($case['body']['value'], JSON_THROW_ON_ERROR), associative: true));
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[Property(runs: 300, generators: [GeneratedCalls::class, 'negative'])]
    public function anInvalidCaseIsRefusedByTheInputSchema(array $case): void
    {
        $operation = $this->operation($case['operationKey']);
        Classify::cover($case['misuse']['location'] === 'body', 'body misuse', 5.0);
        Classify::cover($case['misuse']['location'] === 'query', 'query misuse', 5.0);
        Classify::label($case['misuse']['kind']);

        Assert::true($this->inputErrors($operation, GeneratedCalls::arguments($case)) !== []);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[Property(runs: 100, generators: [GeneratedCalls::class, 'valid'])]
    public function bothTransportsSendTheSameRequest(array $case): void
    {
        $operation = $this->operation($case['operationKey']);
        $arguments = GeneratedCalls::arguments($case);
        $inProcess = $this->send($operation, $arguments);
        $client = new class implements ClientInterface {
            public ?RequestInterface $request = null;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
            }
        };
        $psr17 = new Psr17Factory();
        (new HttpOperationExecutor($client, $psr17, $psr17, 'https://api.test'))->execute($operation, $arguments);

        Assert::notNull($client->request);
        Assert::same($client->request->getMethod(), $inProcess->getMethod());
        Assert::same((string) $client->request->getUri(), (string) $inProcess->getUri());
        Assert::same((string) $client->request->getBody(), (string) $inProcess->getBody());
        Assert::same($client->request->getHeaderLine('Content-Type'), $inProcess->getHeaderLine('Content-Type'));
    }

    #[Property(runs: 500)]
    public function aPathValueNeverLeavesItsSegment(string $slug): void
    {
        $refused = $slug === '' || $slug === '.' || str_contains($slug, '..') || strpbrk($slug, '/\\') !== false;
        Classify::cover($refused, 'refused', 5.0);
        Classify::cover(!$refused, 'sent', 5.0);

        $request = null;

        try {
            $request = $this->send($this->operation('getNote'), ['id' => 7, 'slug' => $slug]);
        } catch (InvalidToolArgumentException) {
        }

        Assert::same(!$request instanceof \Psr\Http\Message\ServerRequestInterface, $refused);

        if ($request instanceof \Psr\Http\Message\ServerRequestInterface) {
            $uri = substr((string) $request->getUri(), strlen('https://api.test'));
            Assert::same(explode('/', $uri), ['', 'items', '7', 'notes', rawurlencode($slug)]);
            Assert::same($request->getUri()->getQuery(), '');
            Assert::same($request->getUri()->getFragment(), '');
        }
    }

    /**
     * @return array<string, ArbitraryInterface<string>>
     */
    public static function aPathValueNeverLeavesItsSegmentGenerators(): array
    {
        return ['slug' => Gen::frequency([
            [2, Gen::naughtyString()],
            [2, Gen::stringFrom("a./\\%?#{} \n", maxLength: 8)],
            [1, Gen::string()],
        ])];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function aPathValueNeverLeavesItsSegmentExamples(): iterable
    {
        yield 'encoded traversal stays encoded twice' => ['%2e%2e%2f'];
        yield 'the other placeholder' => ['{id}'];
        yield 'its own placeholder' => ['{slug}'];
        yield 'query and fragment' => ['a?b=c#d'];
        yield 'trailing newline' => ["a\n"];
        yield 'dot pair inside' => ['a..b'];
    }

    private function operation(string $operationId): Operation
    {
        if (self::$operations === []) {
            self::$operations = (new SpecIndex(GeneratedCalls::document()))->select(['getNote', 'findItems', 'createItem']);
        }

        return self::$operations[$operationId];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array<array-key, mixed>>
     */
    private function inputErrors(Operation $operation, array $arguments): array
    {
        $data = json_decode(json_encode((object) $arguments, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

        return (new SchemaValidator())->validateAgainstJsonSchema($data, (new InputSchemaBuilder())->build($operation));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function send(Operation $operation, array $arguments): ServerRequestInterface
    {
        $handler = new RecordingRequestHandler();
        $psr17 = new Psr17Factory();
        (new Psr15OperationExecutor($handler, $psr17, $psr17, 'https://api.test'))->execute($operation, $arguments);

        return $handler->requests[0];
    }
}
