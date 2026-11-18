<?php declare(strict_types=1);

use Butler\{Cli, FileStore, Payload, PayloadTask, Runner, Task};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/tasks.php';


/**
 * @param list<string> $args
 * @return array{int, string}
 */
function runCli(Cli $cli, array $args): array
{
	ob_start();
	$code = $cli->handle($args);
	return [$code, (string) ob_get_clean()];
}


function createCli(FileStore $store, Task|PayloadTask $task, int $staleAfter = 3600): Cli
{
	$runner = new Runner($store, fn() => $task, production: false, onRun: function () {});
	return new Cli($runner, $staleAfter);
}


test('run executes the task and reports it', function () {
	$task = new LocalTask;
	$cli = createCli(new FileStore(createTempDir()), $task);
	Assert::same([0, "LocalTask: done\n"], runCli($cli, ['run', LocalTask::class]));
	Assert::same(1, $task->runs);
});


test('run of a PayloadTask takes the body from a file, without one it only processes the queue', function () {
	$dir = createTempDir();
	$store = new FileStore($dir);
	$task = new LocalPayloadTask;
	$cli = createCli($store, $task);
	file_put_contents("$dir/body.txt", 'from file');

	Assert::same([0, "LocalPayloadTask: done\n"], runCli($cli, ['run', LocalPayloadTask::class, "$dir/body.txt"]));
	Assert::same(['from file'], $task->bodies);

	$store->enqueue(LocalPayloadTask::class, new Payload('queued'));
	Assert::same([0, "LocalPayloadTask: done\n"], runCli($cli, ['run', LocalPayloadTask::class]));
	Assert::same(['from file', 'queued'], $task->bodies);
});


test('a failed run ends with exit code 1, although the handler took the failure', function () {
	$task = new LocalTask;
	$task->onRun = fn() => throw new RuntimeException('Boom');
	Assert::same([1, "LocalTask: failed\n"], runCli(createCli(new FileStore(createTempDir()), $task), ['run', LocalTask::class]));
});


test('drain processes all pending work', function () {
	$store = new FileStore(createTempDir());
	$task = new LocalTask;
	$store->markPending(LocalTask::class);
	Assert::same([0, "LocalTask: done\n"], runCli(createCli($store, $task), ['drain']));
	Assert::same(1, $task->runs);
});


test('drain reports work pending for too long', function () {
	$store = new FileStore(createTempDir());
	$task = new LocalPayloadTask;
	$task->onRun = fn() => throw new RuntimeException('Boom');
	$store->enqueue(LocalPayloadTask::class, new Payload('x'));

	[$code, $output] = runCli(createCli($store, $task, staleAfter: -1), ['drain']);
	Assert::same(1, $code);
	Assert::match("LocalPayloadTask: failed\nLocalPayloadTask: pending since %d%-%d%-%d% %d%:%d%:%d% UTC\n", $output);
});


test('drain reports rejected payloads and retry returns them', function () {
	$store = new FileStore(createTempDir(), maxAttempts: 1);
	$task = new LocalPayloadTask;
	$task->onRun = fn() => throw new RuntimeException('Boom');
	$store->enqueue(LocalPayloadTask::class, new Payload('x'));
	$cli = createCli($store, $task);

	runCli($cli, ['drain']);
	Assert::same([1, "LocalPayloadTask: done\nLocalPayloadTask: 1 payloads rejected, see retry\n"], runCli($cli, ['drain']));

	$task->onRun = null;
	Assert::same(
		[0, "LocalPayloadTask: 1 payloads returned to the queue\nLocalPayloadTask: done\n"],
		runCli($cli, ['retry', 'LocalPayloadTask']),
	);
	Assert::same(['x', 'x'], $task->bodies);
});


test('wrong arguments print the usage', function () {
	$cli = createCli(new FileStore(createTempDir()), new LocalTask);
	[$code, $output] = runCli($cli, []);
	Assert::same(2, $code);
	Assert::match('Usage:%A%', $output);
});
