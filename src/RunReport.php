<?php declare(strict_types=1);

namespace Butler;


/**
 * One run of a task, or a task that could not run, passed to the handler of the Runner.
 */
final readonly class RunReport
{
	public function __construct(
		public string $task,
		/** null when the task failed before any of its entries ran */
		public ?Entry $entry,
		/** seconds */
		public float $duration,
		public ?\Throwable $error = null,
	) {
	}
}
