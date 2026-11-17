<?php declare(strict_types=1);

namespace Butler;


/**
 * Durable record of pending work and the locks of its processing.
 */
interface Store
{
	/**
	 * Locks the processing of the task, null when another process holds the lock.
	 */
	function lock(string $task, bool $wait): ?Lock;

	/**
	 * Records a trigger of a Task. Triggers pending at the same time merge into one entry.
	 * @throws IOException
	 */
	function markPending(string $task): void;

	/**
	 * Adds a payload of a PayloadTask to its queue.
	 * @throws QueueFullException
	 * @throws IOException
	 */
	function enqueue(string $task, Payload $payload): void;

	/**
	 * Returns the oldest pending entry of the task.
	 */
	function peek(string $task): ?Entry;

	/**
	 * Records that a run of the entry starts, before it runs, so that a run killed with its process
	 * counts too. Returns the entry to run, or null when it has run too many times and was rejected.
	 */
	function startAttempt(Entry $entry): ?Entry;

	/**
	 * Removes the entry after a successful run. A marker triggered again since it was read stays pending.
	 */
	function acknowledge(Entry $entry): void;

	/**
	 * @return list<string>
	 */
	function getPendingTasks(): array;

	/**
	 * Returns the number of rejected payloads, by task.
	 * @return array<string, int>
	 */
	function countRejected(): array;

	/**
	 * Returns the rejected payloads of the task to its queue, with the attempts reset.
	 * @return int number of payloads returned
	 */
	function retryRejected(string $task): int;
}
