<?php declare(strict_types=1);

namespace Butler;

use function is_string;


/**
 * Body, headers and query of the request that triggered a task.
 */
final readonly class Payload
{
	public function __construct(
		public string $body = '',
		/** @var array<lowercase-string, string> */
		public array $headers = [],
		/** @var array<array-key, string> */
		public array $query = [],
	) {
	}


	public static function fromGlobals(): self
	{
		$headers = [];
		foreach ($_SERVER as $key => $value) {
			if (!is_string($value)) {
				continue;
			} elseif (str_starts_with($key, 'HTTP_')) {
				$key = substr($key, 5);
			} elseif ($key !== 'CONTENT_TYPE' && $key !== 'CONTENT_LENGTH') {
				continue;
			}

			$headers[strtolower(strtr($key, '_', '-'))] = $value;
		}

		$query = [];
		foreach ($_GET as $key => $value) {
			if (is_string($value)) {
				$query[$key] = $value;
			}
		}

		return new self((string) file_get_contents('php://input'), $headers, $query);
	}


	public function getHeader(string $name): ?string
	{
		return $this->headers[strtolower($name)] ?? null;
	}


	/**
	 * Decodes the body as JSON, null for an empty body.
	 * @throws \JsonException
	 */
	public function decodeJson(): mixed
	{
		return $this->body === ''
			? null
			: json_decode($this->body, flags: JSON_THROW_ON_ERROR);
	}


	/**
	 * Decodes the body as a form.
	 * @return array<mixed>
	 */
	public function decodeForm(): array
	{
		parse_str($this->body, $data);
		return $data;
	}
}
