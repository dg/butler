<?php declare(strict_types=1);

namespace Butler;


/**
 * Command line entry: runs a task by hand, processes pending work from cron and returns rejected payloads.
 * A task can be written with dots instead of backslashes, which a shell would eat.
 */
final readonly class Cli
{
	private const Usage = <<<'TXT'
		Usage:
		  run <task> [<file>|-]   records a trigger and processes it, waiting for the lock; a PayloadTask
		                          takes the body from the file or from the standard input (-), without
		                          one it only processes its queue
		  drain                   processes all pending work and reports what is stuck, for cron
		  retry <task>            returns the rejected payloads of the task to its queue

		TXT;


	public function __construct(
		private Runner $runner,
		/** seconds after which pending work is reported */
		private int $staleAfter = 3600,
	) {
	}


	/**
	 * @param list<string> $args  arguments without the script name
	 * @return int exit code
	 */
	public function handle(array $args): int
	{
		$command = $args[0] ?? null;
		$task = isset($args[1]) ? strtr($args[1], '.', '\\') : null;
		try {
			return match (true) {
				$command === 'run' && $task !== null => $this->runTask($task, $args[2] ?? null),
				$command === 'drain' => $this->drain(),
				$command === 'retry' && $task !== null => $this->retry($task),
				default => $this->printUsage(),
			};
		} catch (\Throwable $e) {
			fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
			return 1;
		}
	}


	private function runTask(string $task, ?string $file): int
	{
		$task = $this->runner->checkTask($task);
		if (!is_a($task, PayloadTask::class, allow_string: true)) {
			$this->runner->trigger($task);
		} elseif ($file !== null) {
			$body = @file_get_contents($file === '-' ? 'php://stdin' : $file); // @ is escalated to exception
			if ($body === false) {
				throw new IOException("Unable to read $file.");
			}
			$this->runner->trigger($task, new Payload($body));
		}

		return $this->printOutcomes([$task => $this->runner->drain($task, wait: true)]);
	}


	private function drain(): int
	{
		$code = $this->printOutcomes($this->runner->drainAll());
		$store = $this->runner->store;
		foreach ($store->getPendingTasks() as $task) {
			$since = $store->peek($task)?->pendingSince;
			if ($since && time() - $since->getTimestamp() > $this->staleAfter) {
				echo "$task: pending since {$since->format('Y-m-d H:i:s T')}\n";
				$code = 1;
			}
		}

		foreach ($store->countRejected() as $task => $count) {
			echo "$task: $count payloads rejected, see retry\n";
			$code = 1;
		}

		return $code;
	}


	private function retry(string $task): int
	{
		$task = $this->runner->checkTask($task);
		echo "$task: ", $this->runner->store->retryRejected($task), " payloads returned to the queue\n";
		return $this->printOutcomes([$task => $this->runner->drain($task, wait: true)]);
	}


	/** @param array<string, Outcome> $outcomes */
	private function printOutcomes(array $outcomes): int
	{
		$code = 0;
		foreach ($outcomes as $task => $outcome) {
			echo "$task: ", strtolower($outcome->name), "\n";
			$code = $outcome === Outcome::Failed ? 1 : $code;
		}

		return $code;
	}


	private function printUsage(): int
	{
		echo self::Usage;
		return 2;
	}
}
