<?php declare(strict_types=1);

namespace Butler;


/**
 * Exclusive right to process the pending work of a task.
 */
final class Lock
{
	public function __construct(
		/** @var ?\Closure(): void */
		private ?\Closure $release,
	) {
	}


	public function release(): void
	{
		if ($this->release) {
			($this->release)();
			$this->release = null;
		}
	}


	public function __destruct()
	{
		$this->release();
	}
}
