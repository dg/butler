<?php declare(strict_types=1);

namespace Butler;


/**
 * Action of the Endpoint: the tasks it triggers, who may trigger them and when.
 */
final readonly class Action
{
	/** @var list<class-string<Task|PayloadTask>> */
	public array $tasks;


	/**
	 * @param class-string<Task|PayloadTask>|list<class-string<Task|PayloadTask>> $tasks
	 */
	public function __construct(
		string|array $tasks,
		/** @var ?Verifier null inherits the verifier of the Endpoint */
		public ?Verifier $verifier = null,
		/** @var ?\Closure(Payload): bool the request is accepted without triggering anything when it returns false */
		public ?\Closure $when = null,
	) {
		$this->tasks = (array) $tasks;
	}
}
