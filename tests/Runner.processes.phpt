<?php declare(strict_types=1);

/**
 * Test: Runner across real processes
 */

use Butler\{FileStore, Outcome, Runner};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/tasks.php';


/** Starts the child fixture; a child never starts children of its own. */
function startChild(string $dir, string $mode): mixed
{
	if (getenv('BUTLER_TEST_CHILD')) {
		throw new LogicException('A child process must not start another one.');
	}

	$process = proc_open(
		[PHP_BINARY, __DIR__ . '/fixtures/child.php', $dir, $mode],
		[],
		$pipes,
		env_vars: [...getenv(), 'BUTLER_TEST_CHILD' => '1'],
	);
	return $process ?: throw new RuntimeException('Unable to start the child process.');
}


/** Waits for the child to exit, kills it after the deadline. */
function waitForChild(mixed $process): int
{
	$deadline = microtime(as_float: true) + 15;
	while (($status = proc_get_status($process))['running']) {
		if (microtime(as_float: true) > $deadline) {
			proc_terminate($process);
			Assert::fail('The child process did not finish in time.');
		}
		usleep(10_000);
	}
	proc_close($process);
	return $status['exitcode'];
}


function waitForLog(string $dir, string $line): void
{
	$deadline = microtime(as_float: true) + 15;
	while (!in_array($line, file("$dir/log", FILE_IGNORE_NEW_LINES) ?: [], strict: true)) {
		if (microtime(as_float: true) > $deadline) {
			Assert::fail("The child process did not log '$line' in time.");
		}
		usleep(10_000);
	}
}


test('a trigger refused by the lock of another process runs in that process after its run', function () {
	$dir = createTempDir();
	touch("$dir/log");
	$child = startChild($dir, 'hold');
	waitForLog($dir, 'child start');

	$task = new LocalTask;
	$runner = new Runner(new FileStore("$dir/store"), fn() => $task, production: false, onRun: function () {});
	$runner->trigger(LocalTask::class);
	Assert::same(Outcome::Locked, $runner->drain(LocalTask::class));

	touch("$dir/release");
	Assert::same(0, waitForChild($child));
	Assert::same(0, $task->runs);
	Assert::same(
		['child start', 'child end', 'child start', 'child end', 'child Done'],
		file("$dir/log", FILE_IGNORE_NEW_LINES),
	);
});


test('the work of a process that dies during the run stays pending and its lock is free', function () {
	$dir = createTempDir();
	touch("$dir/log");
	Assert::same(3, waitForChild(startChild($dir, 'crash')));
	Assert::same(['child start'], file("$dir/log", FILE_IGNORE_NEW_LINES));

	$task = new LocalTask;
	$runner = new Runner(new FileStore("$dir/store"), fn() => $task, production: false, onRun: function () {});
	Assert::same(Outcome::Done, $runner->drain(LocalTask::class));
	Assert::same(1, $task->runs);
});
