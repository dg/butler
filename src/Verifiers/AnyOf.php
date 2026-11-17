<?php declare(strict_types=1);

namespace Butler\Verifiers;

use Butler\{Payload, Verifier};


/**
 * Passes when any of the verifiers passes.
 */
final readonly class AnyOf implements Verifier
{
	/** @var list<Verifier> */
	private array $verifiers;


	public function __construct(Verifier ...$verifiers)
	{
		$this->verifiers = array_values($verifiers);
	}


	public function verify(Payload $payload): bool
	{
		return array_any($this->verifiers, fn($verifier) => $verifier->verify($payload));
	}
}
