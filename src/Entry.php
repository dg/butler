<?php declare(strict_types=1);

namespace Butler;


/**
 * Pending work of a task: the marker of a Task, or one payload of a PayloadTask.
 * Acknowledging a marker removes it only if no trigger came since it was read.
 */
final readonly class Entry
{
	public function __construct(
		public string $task,
		/** identifies the entry in the store; a marker gets a new one with every trigger */
		public string $id,
		public \DateTimeImmutable $pendingSince,
		/** null for the marker of a Task */
		public ?Payload $payload = null,
		/** started runs of a payload, the current one included */
		public int $attempts = 0,
	) {
	}
}
