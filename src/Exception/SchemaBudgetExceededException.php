<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Exception;

use LogicException;

/**
 * A schema has too many nodes once its shared definitions are inlined.
 *
 * @internal
 */
final class SchemaBudgetExceededException extends LogicException {}
