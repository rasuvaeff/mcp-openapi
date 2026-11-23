<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Tests\Support;

use Rasuvaeff\Mcp\Identity\ExecutionIdentity;
use Rasuvaeff\Mcp\Identity\ExecutionIdentityProviderInterface;

final class MutableExecutionIdentityProvider implements ExecutionIdentityProviderInterface
{
    public function __construct(
        public ExecutionIdentity $identity,
    ) {}

    #[\Override]
    public function current(): ExecutionIdentity
    {
        return $this->identity;
    }
}
