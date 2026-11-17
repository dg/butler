<?php declare(strict_types=1);

// Child process of Runner.processes.phpt: triggers and drains LocalTask in the store given by the parent.
// Usage: php child.php <dir> <hold|crash>

use Butler\{FileStore, Runner};

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/tasks.php';

set_time_limit(0);
[, $dir, $mode] = $_SERVER['argv'];
$log = fn(string $line) => file_put_contents("$dir/log", $line . "\n", FILE_APPEND | LOCK_EX);

$task = new LocalTask;
$task->onRun = function () use ($dir, $mode, $log) {
	$log('child start');
	if ($mode === 'crash') {
		exit(3);
	}

	$deadline = microtime(as_float: true) + 10;
	while (!is_file("$dir/release") && microtime(as_float: true) < $deadline) {
		usleep(10_000);
	}
	$log('child end');
};

$runner = new Runner(new FileStore("$dir/store"), fn() => $task, production: false, onRun: function () {});
$runner->trigger(LocalTask::class);
$log('child ' . $runner->drain(LocalTask::class)->name);
