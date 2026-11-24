<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Tests;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Rasuvaeff\Mcp\Identity\ExecutionIdentity;
use Rasuvaeff\Mcp\OpenApi\OpenApiBridgeFactory;
use Rasuvaeff\Mcp\OpenApi\OpenApiConfiguratorResolver;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\CallbackOperationModifier;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\IdentityDelegatedHeaderProvider;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\MutableExecutionIdentityProvider;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\OpenApiFixture;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\RecordingRequestHandler;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\RecordingScope;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\SubjectRequestAttributes;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Container\SimpleContainer;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(OpenApiConfiguratorResolver::class)]
final class OpenApiConfiguratorResolverTest
{
    private string $spec;

    #[BeforeTest]
    public function writeSpec(): void
    {
        $this->spec = sys_get_temp_dir() . '/mcp-openapi-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->spec, json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
    }

    #[AfterTest]
    public function removeSpec(): void
    {
        @unlink($this->spec);
    }

    #[DataProvider('disabledProvider')]
    public function withoutASpecOrOperationsThereIsNoBridge(array $params): void
    {
        Assert::null((new OpenApiConfiguratorResolver(new SimpleContainer([]), $params))->resolve());
    }

    public static function disabledProvider(): iterable
    {
        yield 'nothing' => [[]];
        yield 'no spec' => [['operations' => ['getBlogTags']]];
        yield 'no operations' => [['spec_path' => '/nowhere.json']];
        yield 'empty' => [['spec_path' => '', 'operations' => []]];
    }

    public function omittedKeysUseTheFactoryDefaults(): void
    {
        $psr17 = new Psr17Factory();
        $client = Understudy::for(ClientInterface::class);

        Assert::equals(
            (new OpenApiConfiguratorResolver($this->httpContainer($client, $psr17), $this->params()))->resolve(),
            OpenApiBridgeFactory::create(
                spec: $this->spec,
                baseUrl: 'https://api.test',
                httpClient: $client,
                requestFactory: $psr17,
                streamFactory: $psr17,
                operations: ['getBlogTags'],
            ),
        );
    }

    public function everyKeyReachesTheBridge(): void
    {
        $psr17 = new Psr17Factory();
        $client = Understudy::for(ClientInterface::class);
        $identity = new MutableExecutionIdentityProvider(new ExecutionIdentity(subjectId: 'u', tenantId: 't'));
        $headers = new IdentityDelegatedHeaderProvider();
        $modifier = new CallbackOperationModifier(static fn(mixed $value): mixed => $value);

        Assert::equals(
            (new OpenApiConfiguratorResolver($this->httpContainer($client, $psr17, [
                MutableExecutionIdentityProvider::class => $identity,
                IdentityDelegatedHeaderProvider::class => $headers,
                CallbackOperationModifier::class => $modifier,
            ]), $this->params([
                'headers' => ['X-Token' => 't'],
                'safe_methods_only' => true,
                'tool_names' => ['getBlogTags' => 'tags'],
                'operation_modifier' => CallbackOperationModifier::class,
                'dry_run' => ['getBlogTags'],
                'identity_provider' => MutableExecutionIdentityProvider::class,
                'delegated_header_provider' => IdentityDelegatedHeaderProvider::class,
                'max_response_bytes' => 100,
                'opaque_errors' => true,
                'multi_segment_path_params' => ['id' => 3],
                'array_query_style' => 'comma',
                'array_query_params' => ['getBlogTags' => ['locale' => 'brackets']],
            ])))->resolve(),
            OpenApiBridgeFactory::create(
                spec: $this->spec,
                baseUrl: 'https://api.test',
                httpClient: $client,
                requestFactory: $psr17,
                streamFactory: $psr17,
                operations: ['getBlogTags'],
                headers: ['X-Token' => 't'],
                safeMethodsOnly: true,
                toolNames: ['getBlogTags' => 'tags'],
                modifier: $modifier,
                dryRunOperations: ['getBlogTags'],
                identityProvider: $identity,
                delegatedHeaderProvider: $headers,
                maxResponseBytes: 100,
                opaqueErrors: true,
                multiSegmentPathParams: ['id' => 3],
                arrayQueryStyle: 'comma',
                arrayQueryParams: ['getBlogTags' => ['locale' => 'brackets']],
            ),
        );
    }

    public function inProcessExecutionNeedsNoHttpTransport(): void
    {
        $psr17 = new Psr17Factory();
        $handler = new RecordingRequestHandler();
        $attributes = new SubjectRequestAttributes();
        $scope = new RecordingScope();

        Assert::equals(
            (new OpenApiConfiguratorResolver(new SimpleContainer([
                StreamFactoryInterface::class => $psr17,
                ServerRequestFactoryInterface::class => $psr17,
                RecordingRequestHandler::class => $handler,
                SubjectRequestAttributes::class => $attributes,
                RecordingScope::class => $scope,
            ]), $this->params([
                'executor' => 'psr15',
                'handler' => RecordingRequestHandler::class,
                'request_attributes' => SubjectRequestAttributes::class,
                'in_process_scope' => RecordingScope::class,
            ])))->resolve(),
            OpenApiBridgeFactory::create(
                spec: $this->spec,
                baseUrl: 'https://api.test',
                httpClient: null,
                requestFactory: null,
                streamFactory: $psr17,
                operations: ['getBlogTags'],
                inProcessHandler: $handler,
                serverRequestFactory: $psr17,
                requestAttributes: $attributes,
                inProcessScope: $scope,
            ),
        );
    }

    #[DataProvider('urlProvider')]
    public function aUrlSpecIsFetchedInProcessTooWithItsHeadersAndCache(string $url): void
    {
        $client = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            #[\Override]
            public function sendRequest(RequestInterface $request): Response
            {
                $this->requests[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], json_encode(OpenApiFixture::spec(), JSON_THROW_ON_ERROR));
            }
        };
        $cache = Understudy::for(CacheInterface::class);
        when(fn() => $cache->get(Arg::any(), Arg::any()))->returns(null);
        when(fn() => $cache->set(Arg::any(), Arg::any(), Arg::any()))->returns(true);
        $psr17 = new Psr17Factory();
        $container = $this->httpContainer($client, $psr17, [
            ServerRequestFactoryInterface::class => $psr17,
            RecordingRequestHandler::class => new RecordingRequestHandler(),
            CacheInterface::class => $cache,
        ]);
        $bridge = (new OpenApiConfiguratorResolver($container, ['spec_path' => $url] + $this->params([
            'executor' => 'psr15',
            'handler' => RecordingRequestHandler::class,
            'spec_headers' => ['X-Spec' => 's'],
            'cache_ttl' => 1,
        ])))->resolve();

        Assert::notNull($bridge);
        Assert::same((string) $client->requests[0]->getUri(), $url);
        Assert::same($client->requests[0]->getHeaderLine('X-Spec'), 's');
        verify(fn() => $cache->set(Arg::any(), Arg::any(), 1));
    }

    public static function urlProvider(): iterable
    {
        yield 'http' => ['http://api.test/openapi.json'];
        yield 'https' => ['https://api.test/openapi.json'];
    }

    #[DataProvider('invalidProvider')]
    public function aContradictionFailsNamingTheKey(array $params, string $message): void
    {
        $caught = null;

        try {
            (new OpenApiConfiguratorResolver(new SimpleContainer([RecordingRequestHandler::class => new \stdClass(), ServerRequestFactoryInterface::class => new Psr17Factory(), StreamFactoryInterface::class => new Psr17Factory()]), $this->params($params)))->resolve();
        } catch (LogicException $caught) {
        }

        Assert::notNull($caught);
        Assert::string($caught->getMessage())->contains($message);
    }

    public static function invalidProvider(): iterable
    {
        $inProcess = 'are only read when openapi.executor is "psr15"';

        yield 'executor' => [['executor' => 'grpc'], 'Unsupported openapi.executor "grpc"; supported: http, psr15'];
        yield 'no handler' => [['executor' => 'psr15'], 'openapi.executor "psr15" requires openapi.handler'];
        yield 'handler over http' => [['handler' => RecordingRequestHandler::class], $inProcess];
        yield 'attributes over http' => [['request_attributes' => SubjectRequestAttributes::class], $inProcess];
        yield 'scope over http' => [['in_process_scope' => RecordingScope::class], $inProcess];
        yield 'handler of another type' => [['executor' => 'psr15', 'handler' => RecordingRequestHandler::class], 'must be an instance of ' . RecordingRequestHandler::class];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function params(array $params = []): array
    {
        return $params + ['spec_path' => $this->spec, 'base_url' => 'https://api.test', 'operations' => ['getBlogTags']];
    }

    /**
     * @param array<string, mixed> $services
     */
    private function httpContainer(ClientInterface $client, Psr17Factory $psr17, array $services = []): SimpleContainer
    {
        return new SimpleContainer($services + [
            ClientInterface::class => $client,
            RequestFactoryInterface::class => $psr17,
            StreamFactoryInterface::class => $psr17,
        ]);
    }
}
