<?php declare(strict_types=1);

use Butler\{Action, Endpoint, Entry, FileStore, Payload, PayloadTask, RequestException, Runner, Task};
use Butler\Verifiers\{BearerToken, None};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/tasks.php';


function createEndpoint(FileStore $store, int $maxBodySize = 65_536): Endpoint
{
	$runner = new Runner($store, fn(string $class): Task|PayloadTask => new $class, production: false, onRun: function () {});
	return new Endpoint($runner, new BearerToken('secret'), maxBodySize: $maxBodySize);
}


/**
 * @param array<string, string> $query
 * @param array<lowercase-string, string> $headers
 */
function createRequest(array $query, string $body = '', array $headers = ['authorization' => 'Bearer secret']): Payload
{
	return new Payload($body, $headers, $query);
}


test('the first action present in the query is triggered, nothing runs yet', function () {
	$store = new FileStore(createTempDir());
	$endpoint = createEndpoint($store);
	$tasks = $endpoint->accept(
		['release' => LocalTask::class, 'hook' => [LocalTask::class, LocalPayloadTask::class]],
		createRequest(['hook' => ''], 'body'),
	);

	Assert::same([LocalTask::class, LocalPayloadTask::class], $tasks);
	Assert::same([LocalTask::class, LocalPayloadTask::class], $store->getPendingTasks());
});


test('the stored payload keeps no credentials', function () {
	$store = new FileStore(createTempDir());
	createEndpoint($store)->accept(
		['hook' => new Action(LocalPayloadTask::class, verifier: new BearerToken('secret', allowQuery: true))],
		createRequest(['hook' => '', 'token' => 'secret'], 'body', ['authorization' => 'x', 'cookie' => 'c', 'x-event' => 'push']),
	);

	$entry = $store->peek(LocalPayloadTask::class);
	Assert::type(Entry::class, $entry);
	Assert::equal(new Payload('body', ['x-event' => 'push'], ['hook' => '']), $entry->payload);
});


test('requests are refused with the HTTP code', function () {
	$endpoint = createEndpoint(new FileStore(createTempDir()), maxBodySize: 3);
	$actions = ['release' => LocalTask::class];

	Assert::exception(fn() => $endpoint->accept($actions, createRequest(['other' => ''])), RequestException::class, 'Unknown action.', 404);
	Assert::exception(
		fn() => $endpoint->accept($actions, createRequest(['release' => ''], headers: [])),
		RequestException::class,
		'Forbidden.',
		403,
	);
	Assert::exception(
		fn() => $endpoint->accept($actions, createRequest(['release' => ''], 'long')),
		RequestException::class,
		'Payload too large.',
		413,
	);
});


test('an action inherits the verifier of the Endpoint unless it sets its own', function () {
	$endpoint = createEndpoint(new FileStore(createTempDir()));
	$anonymous = createRequest(['hook' => ''], headers: []);

	Assert::exception(
		fn() => $endpoint->accept(['hook' => new Action(LocalTask::class)], $anonymous),
		RequestException::class,
		'Forbidden.',
		403,
	);
	Assert::same([LocalTask::class], $endpoint->accept(['hook' => new Action(LocalTask::class, verifier: new None)], $anonymous));
});


test('an unmet condition triggers nothing, an invalid payload is refused', function () {
	$store = new FileStore(createTempDir());
	$endpoint = createEndpoint($store);
	$actions = ['doc' => new Action(LocalTask::class, when: fn(Payload $p) => ($p->decodeJson()->ref ?? null) === 'refs/heads/master')];

	Assert::same([], $endpoint->accept($actions, createRequest(['doc' => ''], '{"ref":"refs/heads/other"}')));
	Assert::same([], $store->getPendingTasks());
	Assert::exception(
		fn() => $endpoint->accept($actions, createRequest(['doc' => ''], '{')),
		RequestException::class,
		'Invalid payload.',
		400,
	);
	Assert::same([LocalTask::class], $endpoint->accept($actions, createRequest(['doc' => ''], '{"ref":"refs/heads/master"}')));
});


test('a full queue is reported as unavailable', function () {
	$endpoint = createEndpoint(new FileStore(createTempDir(), maxQueueSize: 1));
	$actions = ['hook' => LocalPayloadTask::class];
	$endpoint->accept($actions, createRequest(['hook' => '']));
	Assert::exception(
		fn() => $endpoint->accept($actions, createRequest(['hook' => ''])),
		RequestException::class,
		'Too many pending requests.',
		503,
	);
});
