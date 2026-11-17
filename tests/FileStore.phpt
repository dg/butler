<?php declare(strict_types=1);

use Butler\{Entry, FileStore, Payload, QueueFullException};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function peekEntry(FileStore $store, string $task): Entry
{
	$entry = $store->peek($task);
	Assert::type(Entry::class, $entry);
	return $entry;
}


function startAttempt(FileStore $store, Entry $entry): Entry
{
	$entry = $store->startAttempt($entry);
	Assert::type(Entry::class, $entry);
	return $entry;
}


test('triggers of a Task merge into one entry carrying the latest token', function () {
	$store = new FileStore(createTempDir());
	Assert::null($store->peek('App\Task'));

	$store->markPending('App\Task');
	$first = peekEntry($store, 'App\Task');
	$store->markPending('App\Task');
	$second = peekEntry($store, 'App\Task');

	Assert::notSame($first->id, $second->id);
	Assert::same($first->pendingSince->format('Y-m-d H:i:s.u'), $second->pendingSince->format('Y-m-d H:i:s.u'));
	Assert::null($second->payload);
	Assert::same(['App\Task'], $store->getPendingTasks());
});


test('acknowledging a stale marker keeps the newer trigger pending since the acknowledgement', function () {
	$store = new FileStore(createTempDir());
	$store->markPending('App\Task');
	$stale = peekEntry($store, 'App\Task');
	usleep(10_000);
	$store->markPending('App\Task');

	$store->acknowledge($stale);
	$current = peekEntry($store, 'App\Task');
	Assert::true($current->pendingSince > $stale->pendingSince);

	$store->acknowledge($current);
	Assert::null($store->peek('App\Task'));
	Assert::same([], $store->getPendingTasks());
});


test('payloads come back in the order of arrival, body byte for byte', function () {
	$store = new FileStore(createTempDir());
	$store->enqueue('App\Hook', new Payload("first\x00\xFF", ['content-type' => 'text/plain'], ['doc' => '']));
	$store->enqueue('App\Hook', new Payload('second'));

	$entry = peekEntry($store, 'App\Hook');
	Assert::equal(new Payload("first\x00\xFF", ['content-type' => 'text/plain'], ['doc' => '']), $entry->payload);
	Assert::same(0, $entry->attempts);
	Assert::same('UTC', $entry->pendingSince->getTimezone()->getName());

	$store->acknowledge(startAttempt($store, $entry));
	Assert::same('second', peekEntry($store, 'App\Hook')->payload?->body);
	Assert::same(['App\Hook'], $store->getPendingTasks());
});


test('an attempt is counted before the run, so a run that never ends counts too', function () {
	$store = new FileStore(createTempDir(), maxAttempts: 2);
	$store->enqueue('App\Hook', new Payload('a'));
	$store->enqueue('App\Hook', new Payload('b'));

	Assert::same(1, startAttempt($store, peekEntry($store, 'App\Hook'))->attempts);
	Assert::same(2, startAttempt($store, peekEntry($store, 'App\Hook'))->attempts);
	Assert::same('a', peekEntry($store, 'App\Hook')->payload?->body);

	Assert::null($store->startAttempt(peekEntry($store, 'App\Hook')));
	Assert::same('b', peekEntry($store, 'App\Hook')->payload->body);
	Assert::same(['App\Hook' => 1], $store->countRejected());
});


test('rejected payloads return to the queue with the attempts reset', function () {
	$store = new FileStore(createTempDir(), maxAttempts: 1);
	$store->enqueue('App\Hook', new Payload('a'));
	startAttempt($store, peekEntry($store, 'App\Hook'));
	Assert::null($store->startAttempt(peekEntry($store, 'App\Hook')));
	Assert::null($store->peek('App\Hook'));

	Assert::same(1, $store->retryRejected('App\Hook'));
	$entry = peekEntry($store, 'App\Hook');
	Assert::same('a', $entry->payload?->body);
	Assert::same(0, $entry->attempts);
	Assert::same([], $store->countRejected());
});


test('an unreadable payload is rejected instead of blocking the queue', function () {
	$dir = createTempDir();
	$store = new FileStore($dir);
	$store->enqueue('App\Hook', new Payload('b'));
	file_put_contents("$dir/App.Hook.queue/20000101-000000-000000-0000.0.json", '{broken');

	Assert::same('b', peekEntry($store, 'App\Hook')->payload?->body);
	Assert::same(['App\Hook' => 1], $store->countRejected());
});


test('a marker is never rejected', function () {
	$store = new FileStore(createTempDir(), maxAttempts: 1);
	$store->markPending('App\Task');
	$entry = peekEntry($store, 'App\Task');
	Assert::same($entry, $store->startAttempt($store->startAttempt($entry) ?? $entry));
});


test('a full queue refuses another payload', function () {
	$store = new FileStore(createTempDir(), maxQueueSize: 2);
	$store->enqueue('App\Hook', new Payload('a'));
	$store->enqueue('App\Hook', new Payload('b'));
	Assert::exception(
		fn() => $store->enqueue('App\Hook', new Payload('c')),
		QueueFullException::class,
		'The queue of App\Hook is full.',
	);
});


test('a held lock is refused to another handle until released', function () {
	$store = new FileStore(createTempDir());
	$lock = $store->lock('App\Task', wait: false);
	Assert::notNull($lock);
	Assert::null($store->lock('App\Task', wait: false));
	Assert::notNull($store->lock('App\Other', wait: false));

	$lock->release();
	Assert::notNull($store->lock('App\Task', wait: false));
});
