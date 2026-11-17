<?php declare(strict_types=1);

namespace Butler;


/**
 * Verifies that a request comes from a caller allowed to trigger the action.
 */
interface Verifier
{
	function verify(Payload $payload): bool;
}
