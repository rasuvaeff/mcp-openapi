<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Tests;

use Mcp\Schema\Content\TextContent;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Rasuvaeff\Mcp\OpenApi\BridgedToolHandler;
use Rasuvaeff\Mcp\OpenApi\Operation;
use Rasuvaeff\Mcp\OpenApi\OperationExecutorInterface;
use Rasuvaeff\Mcp\OpenApi\SpecIndex;
use Rasuvaeff\Mcp\OpenApi\Tests\Support\OpenApiFixture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(BridgedToolHandler::class)]
final class BridgedToolHandlerTest
{
    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('resultProvider')]
    public function onlyAPreviewBecomesTextContent(bool $dryRunnable, array $arguments, mixed $result, bool $isContent): void
    {
        $handler = new BridgedToolHandler(
            operation: (new SpecIndex(OpenApiFixture::spec()))->get('getBlogTags'),
            executor: new readonly class ($result) implements OperationExecutorInterface {
                public function __construct(private mixed $result) {}

                #[\Override]
                public function execute(Operation $operation, array $arguments, bool $dryRunnable = false, ?string $toolName = null): mixed
                {
                    return $this->result;
                }
            },
            dryRunnable: $dryRunnable,
        );

        $actual = $handler->execute($arguments, new ClientGateway(new Session(new InMemorySessionStore())));

        if ($isContent) {
            Assert::instanceOf($actual, TextContent::class);
            Assert::same($actual->text, $result);

            return;
        }

        Assert::same($actual, $result);
    }

    public static function resultProvider(): iterable
    {
        yield 'preview' => [true, ['dryRun' => true], '{"dryRun":true}', true];
        yield 'text answer without the flag' => [true, [], 'plain', false];
        yield 'text answer with the flag off' => [true, ['dryRun' => false], 'plain', false];
        yield 'flag on an operation without dry run' => [false, ['dryRun' => true], 'plain', false];
        yield 'array answer' => [true, [], ['ok' => true], false];
        yield 'array answer to a preview' => [true, ['dryRun' => true], ['ok' => true], false];
    }
}
