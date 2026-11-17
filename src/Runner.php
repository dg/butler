<?php declare(strict_types=1);

namespace Butler;


/**
 * Records triggers of tasks and processes their pending work, one process per task at a time.
 * A task is named by its class, a Task or a PayloadTask.
 */
final class Runner
{
	/** @var array<string, true> tasks this process holds the lock of; locking one again would wait for itself */
	private array $locked = [];


	public function __construct(
		public readonly Store $store,
		/** @var \Closure(class-string<Task|PayloadTask>): (Task|PayloadTask) */
		private readonly \Closure $factory,
		private readonly bool $production,
		/** @var \Closure(RunReport): void receives every run, failures included */
		private readonly \Closure $onRun,
	) {
	}


	/**
	 * Records a trigger of the task. Once it returns, the work is guaranteed to run at least once.
	 * A PayloadTask needs the payload.
	 */
	public function trigger(string $task, ?Payload $payload = null): void
	{
		$task = $this->checkTask($task);
		if (!is_a($task, PayloadTask::class, allow_string: true)) {
			$this->store->markPending($task);
		} elseif ($payload) {
			$this->store->enqueue($task, $payload);
		} else {
			throw new \InvalidArgumentException("Task $task needs a payload.");
		}
	}


	/**
	 * Checks that the class is a task allowed to run in this environment.
	 * @return class-string<Task|PayloadTask>
	 * @throws ProductionOnlyException
	 */
	public function checkTask(string $task): string
	{
		if (!is_a($task, Task::class, allow_string: true) && !is_a($task, PayloadTask::class, allow_string: true)) {
			throw new \InvalidArgumentException("Class $task is neither Butler\\Task nor Butler\\PayloadTask.");
		}

		if (!$this->production && !new \ReflectionClass($task)->getAttributes(AllowedLocally::class)) {
			throw new ProductionOnlyException("Task $task runs only in production, see #[Butler\\AllowedLocally].");
		}

		return $task;
	}


	/**
	 * Processes the pending work of the task, unless another process does.
	 * @param ?float $budget  seconds after which no new run starts
	 */
	public function drain(string $task, bool $wait = false, ?float $budget = null): Outcome
	{
		return $this->drainUntil($this->checkTask($task), $wait, $this->getDeadline($budget));
	}


	/**
	 * Processes the pending work of all tasks.
	 * @param ?float $budget  seconds after which no new run starts
	 * @return array<string, Outcome>
	 */
	public function drainAll(bool $wait = false, ?float $budget = null): array
	{
		return $this->drainMany($this->store->getPendingTasks(), $wait, $budget);
	}


	/**
	 * Processes the pending work of the tasks within one time budget. A task that fails as a whole,
	 * because it is unknown, not allowed here or its store fails, is reported and the others go on.
	 * @param list<string> $tasks
	 * @param ?float $budget  seconds after which no new run starts
	 * @return array<string, Outcome>
	 */
	public function drainMany(array $tasks, bool $wait = false, ?float $budget = null): array
	{
		$deadline = $this->getDeadline($budget);
		$res = [];
		foreach ($tasks as $task) {
			try {
				$res[$task] = $this->drainUntil($this->checkTask($task), $wait, $deadline);
			} catch (\Throwable $e) {
				($this->onRun)(new RunReport($task, null, 0, $e));
				$res[$task] = Outcome::Failed;
			}
		}

		return $res;
	}


	/** @param class-string<Task|PayloadTask> $task */
	private function drainUntil(string $task, bool $wait, ?float $deadline): Outcome
	{
		do {
			$lock = isset($this->locked[$task]) ? null : $this->store->lock($task, $wait);
			if (!$lock) {
				return Outcome::Locked;
			}

			$this->locked[$task] = true;
			try {
				$outcome = $this->process($task, $deadline);
			} finally {
				unset($this->locked[$task]);
				$lock->release();
			}

		// a trigger that found the lock still held just before the release is seen only now
		} while ($outcome === Outcome::Done && $this->store->peek($task));

		return $outcome;
	}


	/** @param class-string<Task|PayloadTask> $task */
	private function process(string $task, ?float $deadline): Outcome
	{
		$instance = null;
		while ($entry = $this->store->peek($task)) {
			if ($deadline !== null && hrtime(as_number: true) >= $deadline) {
				return Outcome::Postponed;
			}

			$instance ??= $this->createInstance($task); // a failure here is not the fault of the entry
			$entry = $this->store->startAttempt($entry);
			if (!$entry) {
				continue; // rejected after too many attempts
			}

			$start = hrtime(as_number: true);
			try {
				if ($instance instanceof PayloadTask) {
					$instance->run($entry->payload ?? new Payload);
				} else {
					$instance->run();
				}
			} catch (\Throwable $e) {
				($this->onRun)(new RunReport($task, $entry, (hrtime(as_number: true) - $start) / 1e9, $e));
				return Outcome::Failed;
			}

			$this->store->acknowledge($entry);
			($this->onRun)(new RunReport($task, $entry, (hrtime(as_number: true) - $start) / 1e9));
		}

		return Outcome::Done;
	}


	/** @param class-string<Task|PayloadTask> $task */
	private function createInstance(string $task): Task|PayloadTask
	{
		$instance = ($this->factory)($task);
		return $instance instanceof $task
			? $instance
			: throw new \LogicException('Factory returned ' . get_debug_type($instance) . " instead of $task.");
	}


	private function getDeadline(?float $budget): ?float
	{
		return $budget === null ? null : hrtime(as_number: true) + $budget * 1e9;
	}
}
