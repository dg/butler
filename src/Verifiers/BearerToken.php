<?php declare(strict_types=1);

namespace Butler\Verifiers;

use Butler\{Endpoint, Payload, Verifier};


/**
 * Token in the header Authorization: Bearer, or in the query parameter token where allowed.
 * A token in the query ends up in the server log, so it suits only callers that cannot send a header.
 */
final readonly class BearerToken implements Verifier
{
	public function __construct(
		#[\SensitiveParameter]
		private string $token,
		private bool $allowQuery = false,
	) {
		if ($token === '') {
			throw new \InvalidArgumentException('The token must not be empty.');
		}
	}


	public function verify(Payload $payload): bool
	{
		$header = $payload->getHeader('Authorization') ?? '';
		return hash_equals('Bearer ' . $this->token, $header)
			|| ($this->allowQuery && hash_equals($this->token, $payload->query[Endpoint::TokenParameter] ?? ''));
	}
}
