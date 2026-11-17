<?php declare(strict_types=1);

namespace Butler;

use function array_key_exists, function_exists, strlen;


/**
 * HTTP entry for webhooks and cron: verifies the caller, records the triggers, answers at once
 * and only then processes the pending work. Answering before the work needs PHP-FPM; elsewhere
 * the caller waits until the work is done.
 */
final readonly class Endpoint
{
	/** query parameter that may carry a token, never stored with a payload */
	public const TokenParameter = 'token';


	public function __construct(
		private Runner $runner,
		private Verifier $verifier,
		/** seconds after which no new run starts */
		private float $budget = 300,
		private int $maxBodySize = 65_536,
	) {
	}


	/**
	 * Handles the current request. The action is the first key of $actions present in the query.
	 * @param array<string, class-string<Task|PayloadTask>|list<class-string<Task|PayloadTask>>|Action> $actions
	 */
	public function handle(array $actions): void
	{
		header('Content-Type: text/plain; charset=utf-8');
		try {
			$tasks = $this->accept($actions, Payload::fromGlobals());
		} catch (RequestException $e) {
			http_response_code($e->getCode());
			if ($e->getCode() === 503) {
				header('Retry-After: 60');
			}
			echo $e->getMessage(), "\n";
			return;
		}

		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}

		set_time_limit(0);
		ignore_user_abort(enable: true);
		ob_start(fn() => '', 4096); // the response is complete, output of the tasks would only corrupt it
		try {
			$this->runner->drainMany($tasks, budget: $this->budget);
		} finally {
			ob_end_clean();
		}
	}


	/**
	 * Verifies the request and records its triggers, without processing them.
	 * @param array<string, class-string<Task|PayloadTask>|list<class-string<Task|PayloadTask>>|Action> $actions
	 * @return list<class-string<Task|PayloadTask>> triggered tasks, empty when the condition of the action is not met
	 * @throws RequestException
	 */
	public function accept(array $actions, Payload $payload): array
	{
		$name = array_find_key($actions, fn($action, $name) => array_key_exists($name, $payload->query)) ?? throw new RequestException(
			'Unknown action.',
			404,
		);

		$action = $actions[$name] instanceof Action ? $actions[$name] : new Action($actions[$name]);
		if (strlen($payload->body) > $this->maxBodySize) {
			throw new RequestException('Payload too large.', 413);
		} elseif (!($action->verifier ?? $this->verifier)->verify($payload)) {
			throw new RequestException('Forbidden.', 403);
		}

		try {
			if ($action->when && !($action->when)($payload)) {
				return [];
			}
		} catch (\JsonException $e) {
			throw new RequestException('Invalid payload.', 400, $e);
		}

		foreach ($action->tasks as $task) {
			$this->runner->checkTask($task); // before the first is recorded
		}

		$payload = $this->stripCredentials($payload);
		try {
			foreach ($action->tasks as $task) {
				$this->runner->trigger($task, $payload);
			}
		} catch (QueueFullException $e) {
			throw new RequestException('Too many pending requests.', 503, $e);
		}

		return $action->tasks;
	}


	private function stripCredentials(Payload $payload): Payload
	{
		return new Payload(
			$payload->body,
			array_diff_key($payload->headers, ['authorization' => true, 'cookie' => true]),
			array_diff_key($payload->query, [self::TokenParameter => true]),
		);
	}
}
