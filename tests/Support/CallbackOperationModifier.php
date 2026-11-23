<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Tests\Support;

use Mcp\Schema\Tool;
use Rasuvaeff\Mcp\OpenApi\Operation;
use Rasuvaeff\Mcp\OpenApi\OperationModifierInterface;

final readonly class CallbackOperationModifier implements OperationModifierInterface
{
    /**
     * @param \Closure(Operation, Tool): Tool $callback
     */
    public function __construct(
        private \Closure $callback,
    ) {}

    #[\Override]
    public function modify(Operation $operation, Tool $tool): Tool
    {
        return ($this->callback)($operation, $tool);
    }
}
