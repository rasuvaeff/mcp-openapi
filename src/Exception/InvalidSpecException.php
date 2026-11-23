<?php

declare(strict_types=1);

namespace Rasuvaeff\Mcp\OpenApi\Exception;

use LogicException;

/**
 * The OpenAPI document cannot be used by the bridge (unreadable file,
 * malformed JSON, no paths).
 *
 * @api
 */
final class InvalidSpecException extends LogicException {}
