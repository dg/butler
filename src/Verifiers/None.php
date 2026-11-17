<?php declare(strict_types=1);

namespace Butler\Verifiers;

use Butler\{Payload, Verifier};


/**
 * Lets anyone trigger the action; the task itself has to verify what it receives.
 */
final readonly class None implements Verifier
{
	public function verify(Payload $payload): bool
	{
		return true;
	}
}
