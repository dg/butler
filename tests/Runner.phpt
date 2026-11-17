<?php declare(strict_types=1);

use Butler\{Entry, FileStore, Lock, Outcome, Payload, PayloadTask, ProductionOnlyException, Runner, RunReport, Store, Task};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/tasks.php';


/**
 * @param array<class-string, Task|PayloadTask> $tasks
 * @param ?list<RunReport> $reports
 * @param-out list<RunReport> $reports
 */
function createRunner(array $tasks, ?Store $store = null, bool $production = false, ?array &$reports = null): Runner
{
	$reports = [];
	return new Runner(
		$store ?? new FileStore(createTempDir()),
		fn(string $class): Task|PayloadTask => $tasks[$class] ?? throw new LogicException("Unexpected $class"),
		$production,
		function (RunReport $report) use (&$reports): void {
			$reports[] = $report;
		},
	);
}


test('triggers recorded before the run merge into one run', function () {
	$task = new LocalTask;
	$runner = createRunner([LocalTask::class => $task]);
	$runner->trigger(LocalTask::class);
	$runner->trigger(LocalTask::class);
	$runner->trigger(LocalTask::class);

	Assert::same(Outcome::Done, $runner->drain(LocalTask::class));
	Assert::same(1, $task->runs);
	Assert::same(Outcome::Done, $runner->drain(LocalTask::class));
	Assert::same(1, $task->runs);
});


test('every payload runs, in the order of arrival', function () {
	$task = new LocalPayloadTask;
	$runner = createRunner([LocalPayloadTask::class => $task]);
	$runner->trigger(LocalPayloadTask::class, new Payload('a'));
	$runner->trigger(LocalPayloadTask::class, new Payload('b'));

	Assert::same(Outcome::Done, $runner->drain(LocalPayloadTask::class));
	Assert::same(['a', 'b'], $task->bodies);
});


test('a PayloadTask needs a payload', function () {
	$runner = createRunner([]);
	Assert::exception(
		fn() => $runner->trigger(LocalPayloadTask::class),
		InvalidArgumentException::class,
		'Task LocalPayloadTask needs a payload.',
	);
});


test('a trigger during the run causes one more run', function () {
	$task = new LocalTask;
	$runner = createRunner([LocalTask::class => $task]);
	$task->onRun = function () use ($runner, $task) {
		if ($task->runs === 1) {
			$runner->trigger(LocalTask::class);
			Assert::same(Outcome::Locked, $runner->drain(LocalTask::class));
		}
	};

	$runner->trigger(LocalTask::class);
	Assert::same(Outcome::Done, $runner->drain(LocalTask::class));
	Assert::same(2, $task->runs);
});


test('a trigger arriving just before the lock is released is processed after it', function () {
	$inner = new FileStore(createTempDir());
	$store = new class ($inner) implements Store {
		public bool $injected = false;


		public function __construct(
			private Store $inner,
		) {
		}


		public function lock(string $task, bool $wait): ?Lock
		{
			$lock = $this->inner->lock($task, $wait);
			return $lock
				? new Lock(function () use ($lock, $task) {
					if (!$this->injected) {
						$this->injected = true;
						$this->inner->markPending($task);
					}
					$lock->release();
				})
				: null;
		}


		public function markPending(string $task): void
		{
			$this->inner->markPending($task);
		}


		public function enqueue(string $task, Payload $payload): void
		{
			$this->inner->enqueue($task, $payload);
		}


		public function peek(string $task): ?Entry
		{
			return $this->inner->peek($task);
		}


		public function startAttempt(Entry $entry): ?Entry
		{
			return $this->inner->startAttempt($entry);
		}


		public function acknowledge(Entry $entry): void
		{
			$this->inner->acknowledge($entry);
		}


		public function getPendingTasks(): array
		{
			return $this->inner->getPendingTasks();
		}


		public function countRejected(): array
		{
			return $this->inner->countRejected();
		}


		public function retryRejected(string $task): int
		{
			return $this->inner->retryRejected($task);
		}
	};

	$task = new LocalTask;
	$runner = createRunner([LocalTask::class => $task], $store);
	$runner->trigger(LocalTask::class);
	Assert::same(Outcome::Done, $runner->drain(LocalTask::class));
	Assert::true($store->injected);
	Assert::same(2, $task->runs);
	Assert::null($inner->peek(LocalTask::class));
});


test('the handler receives every run and a failure stops the processing of the task', function () {
	$task = new LocalPayloadTask;
	$task->onRun = fn(Payload $payload) => $payload->body === 'bad' ? throw new RuntimeException('Boom') : null;
	$store = new FileStore(createTempDir());
	$runner = createRunner([LocalPayloadTask::class => $task], $store, reports: $reports);
	$runner->trigger(LocalPayloadTask::class, new Payload('good'));
	$runner->trigger(LocalPayloadTask::class, new Payload('bad'));
	$runner->trigger(LocalPayloadTask::class, new Payload('later'));

	Assert::same(Outcome::Failed, $runner->drain(LocalPayloadTask::class));
	Assert::same(
		[['good', null], ['bad', 'Boom']],
		array_map(fn(RunReport $r) => [$r->entry?->payload?->body, $r->error?->getMessage()], $reports),
	);
	$entry = $store->peek(LocalPayloadTask::class);
	Assert::same('bad', $entry?->payload?->body);
	Assert::same(1, $entry->attempts);
});


test('a task that cannot be created leaves its work untouched', function () {
	$store = new FileStore(createTempDir());
	$runner = createRunner([LocalPayloadTask::class => new LocalTask], $store, reports: $reports);
	$runner->trigger(LocalPayloadTask::class, new Payload('a'));

	Assert::same([LocalPayloadTask::class => Outcome::Failed], $runner->drainAll());
	Assert::same('Factory returned LocalTask instead of LocalPayloadTask.', $reports[0]->error?->getMessage());
	Assert::null($reports[0]->entry);
	Assert::same(0, $store->peek(LocalPayloadTask::class)?->attempts);
});


test('outside production a task without #[AllowedLocally] is refused before it is created', function () {
	$runner = createRunner([]);
	Assert::exception(
		fn() => $runner->trigger(ProductionTask::class),
		ProductionOnlyException::class,
		'Task ProductionTask runs only in production, see #[Butler\AllowedLocally].',
	);
	Assert::exception(
		fn() => $runner->drain(ProductionTask::class),
		ProductionOnlyException::class,
	);
});


test('in production every task runs', function () {
	$runner = createRunner([ProductionTask::class => new ProductionTask], production: true);
	$runner->trigger(ProductionTask::class);
	Assert::same(Outcome::Done, $runner->drain(ProductionTask::class));
});


test('a class that is not a task is refused before anything else', function () {
	$runner = createRunner([]);
	Assert::exception(
		fn() => $runner->trigger(stdClass::class),
		InvalidArgumentException::class,
		'Class stdClass is neither Butler\Task nor Butler\PayloadTask.',
	);
});


test('an exhausted budget postpones the pending work', function () {
	$task = new LocalPayloadTask;
	$store = new FileStore(createTempDir());
	$runner = createRunner([LocalPayloadTask::class => $task], $store);
	$runner->trigger(LocalPayloadTask::class, new Payload('a'));

	Assert::same(Outcome::Postponed, $runner->drain(LocalPayloadTask::class, budget: 0));
	Assert::same([], $task->bodies);
	Assert::notNull($store->peek(LocalPayloadTask::class));
});


test('drainAll() processes every task with pending work', function () {
	$task = new LocalTask;
	$payloadTask = new LocalPayloadTask;
	$runner = createRunner([LocalTask::class => $task, LocalPayloadTask::class => $payloadTask]);
	$runner->trigger(LocalTask::class);
	$runner->trigger(LocalPayloadTask::class, new Payload('a'));

	Assert::same(
		[LocalTask::class => Outcome::Done, LocalPayloadTask::class => Outcome::Done],
		$runner->drainAll(),
	);
	Assert::same(1, $task->runs);
	Assert::same(['a'], $payloadTask->bodies);
});


test('drainAll() reports work of an unknown task as failed and goes on', function () {
	$task = new LocalTask;
	$store = new FileStore(createTempDir());
	$store->markPending('App\RenamedTask');
	$runner = createRunner([LocalTask::class => $task], $store, reports: $reports);
	$runner->trigger(LocalTask::class);

	Assert::same(
		['App\RenamedTask' => Outcome::Failed, LocalTask::class => Outcome::Done],
		$runner->drainAll(),
	);
	Assert::same('App\RenamedTask', $reports[0]->task);
	Assert::same('Class App\RenamedTask is neither Butler\Task nor Butler\PayloadTask.', $reports[0]->error?->getMessage());
	Assert::same(1, $task->runs);
});
