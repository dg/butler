<?php declare(strict_types=1);

namespace Butler\Verifiers;

use Butler\{Payload, Verifier};


/**
 * Webhook of GitHub signed by the secret configured in the webhook settings.
 */
final readonly class GitHubSignature implements Verifier
{
	public function __construct(
		#[\SensitiveParameter]
		private string $secret,
	) {
		if ($secret === '') {
			throw new \InvalidArgumentException('The secret of a GitHub webhook must not be empty.');
		}
	}


	public function verify(Payload $payload): bool
	{
		return hash_equals(
			'sha256=' . hash_hmac('sha256', $payload->body, $this->secret),
			$payload->getHeader('X-Hub-Signature-256') ?? '',
		);
	}
}
