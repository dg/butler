[Butler](https://butler.deegee.dev)
===================================

[![Coverage Status](https://coveralls.io/repos/github/dg/butler/badge.svg?branch=master)](https://coveralls.io/github/dg/butler?branch=master)
[![Latest Stable Version](https://poser.pugx.org/dg/butler/v/stable)](https://github.com/dg/butler/releases)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/dg/butler/blob/master/license.md)

 <!---->

<h3>

✅ A burst of webhooks [never piles up on a lock](#why-not-just-a-lock) and exhausts PHP-FPM<br>
✅ An accepted trigger [is never lost](#what-is-guaranteed), even when the process dies<br>
✅ [Verifies the caller](#endpoint): GitHub signatures and tokens<br>
✅ Tasks that touch real services [do not run on your laptop](#local-runs)

</h3>

 <!---->

**Status: in development.** Until the first release the API, names and behavior may still change.

**Background tasks for a PHP web application, triggered by webhooks, cron or the command line.** A task is
a class with one method:

```php
#[Butler\AllowedLocally]
final class ImportReleasesTask implements Butler\Task
{
	public function run(): void
	{
		// download releases from GitHub, update the list
	}
}
```

and an endpoint for webhooks is a map of actions:

```php
$endpoint = new Butler\Endpoint($runner, verifier: new Butler\Verifiers\GitHubSignature($secret));
$endpoint->handle([
	'release' => ImportReleasesTask::class,      // https://example.org/endpoint.php?release
]);
```

GitHub gets its answer in milliseconds, the import runs after the connection is closed, and when GitHub
sends a hundred webhooks at once, the import runs once or twice, not a hundred times in a queue.


Installation
============

```shell
composer require dg/butler
```

Butler requires PHP 8.4 or newer.

 <!---->

Why not just a lock
===================

The usual way to keep a task from running twice is a lock: every request waits until the running one
finishes. With a burst of webhooks every waiting request holds a PHP-FPM worker, the pool runs out and the
whole site answers 504. Raising the limits only moves the point where it breaks.

Butler never waits. A request **records the trigger on disk, answers, and tries the lock**. If another
process holds it, the request ends at once: its work is recorded and the process holding the lock will see
it. One worker per task processes the work, the others are busy for a few milliseconds.

 <!---->

Tasks
=====

There are two kinds, and the signature decides how triggers combine:

- **`Butler\Task`** has no input. Triggers that arrive before it starts merge into one run, because a task
  that reconciles a state gives the same result however many requests asked for it.
- **`Butler\PayloadTask`** receives the request as `Butler\Payload` and every payload is processed, in the
  order of arrival:

```php
final class SponsorTask implements Butler\PayloadTask
{
	public function run(Butler\Payload $payload): void
	{
		$event = $payload->decodeJson();
		// ...
	}
}
```

`Payload` holds the body, headers and query of the request (`getHeader()`, `decodeJson()`, `decodeForm()`),
so a task never reads `$_POST` or `php://input` and can be tested with a payload made by hand.

 <!---->

What is guaranteed
==================

A trigger is written to the store before the endpoint answers and removed only after the task succeeds.
When the process dies in the middle of the run (a restart of PHP-FPM, a timeout, out of memory), the work
stays pending and the next trigger or the next `drain` from cron runs it. The guarantee is **at least once**,
so a task must cope with running the same work twice: a payment already recorded is acknowledged and left
alone.

A failing payload stays first in its queue, so that events of the same source never swap their order. Every
run is counted before it starts, so a payload that kills the process counts too; after ten attempts it is
rejected to a `.rejected` directory, `drain` keeps reporting it, `retry` returns it, and the queue goes on.
A failing `Task` is simply run again by the next trigger or `drain`.

 <!---->

Endpoint
========

The action is the first key of the map present in the query. Each action can set its own verifier and a
condition:

```php
$endpoint = new Butler\Endpoint($runner, verifier: new Butler\Verifiers\GitHubSignature($secrets['github']));
$endpoint->handle([
	'release' => ImportReleasesTask::class,
	'sponsor' => new Butler\Action(SponsorTask::class, verifier: new Butler\Verifiers\GitHubSignature($secrets['sponsors'])),
	'cron' => new Butler\Action([ReconcileTask::class, CleanupTask::class], verifier: new Butler\Verifiers\BearerToken($secrets['cron'])),
	'paypal' => new Butler\Action(PaypalTask::class, verifier: new Butler\Verifiers\None),  // the task verifies the IPN itself
	'doc' => new Butler\Action(UpdateDocsTask::class, when: fn(Butler\Payload $p) => ($p->decodeJson()->ref ?? null) === 'refs/heads/master'),
]);
```

| Request | Answer |
|---|---|
| accepted, or the condition is not met | 200, empty body |
| unknown action | 404 |
| caller not verified | 403 |
| body over 64 kB | 413 |
| condition fails on an invalid payload | 400 |
| too many payloads pending | 503 |

The answer says the work was accepted, not that it succeeded. Failures go to the handler `onRun` of the
runner (the Tracy log with the Nette extension) and the work is retried. Under PHP-FPM the answer leaves
before the work starts; elsewhere the caller waits until the work is done.

Verifiers: `GitHubSignature` (HMAC SHA-256 of the body), `BearerToken` (header `Authorization: Bearer`, and
with `allowQuery: true` also `?token=` for callers that cannot send a header, keeping in mind that the query
ends up in the server log), `AnyOf` and `None`. An empty secret is refused. Tokens and cookies are stripped
before a payload is stored.

 <!---->

Command line and cron
=====================

```php
// bin/task.php
exit($container->getByType(Butler\Cli::class)->handle(array_slice($argv, 1)));
```

```shell
php bin/task.php run App.Tasks.ReconcileTask           # runs it now, waiting for the lock
php bin/task.php run App.Tasks.PaypalTask ipn.txt      # runs it with this request body, - reads stdin
php bin/task.php drain                                 # for cron, every few minutes
php bin/task.php retry App.Tasks.PaypalTask            # returns rejected payloads to the queue
```

A task is written with dots, because a shell eats unquoted backslashes. `drain` processes everything pending,
takes over work of processes that died, and reports work pending for more than an hour and rejected payloads.
The exit code is then 1, so cron sends the output.

 <!---->

Local runs
==========

A task without `#[Butler\AllowedLocally]` runs only in production. Outside it, the runner refuses the
trigger before the task is even created, so no client of a real service is built on a development machine.
Whether you are in production comes from the configuration, never from the host name. In Nette it is not
`%productionMode%`, which is false in the console and for a developer's IP address.

 <!---->

Nette
=====

```neon
extensions:
	butler: Butler\Bridges\Nette\ButlerExtension

butler:
	production: true          # mandatory, false in the local configuration
	dir: %tempDir%/butler     # default; applications sharing one temp need a directory each
	maxQueueSize: 1000        # payloads waiting in the queue of one task
	maxAttempts: 10           # runs of a payload before it is rejected
	staleAfter: 3600          # seconds after which drain reports pending work
	onRun: App\Monitor::record(...)  # receives every run; by default failures go to the Tracy log
```

The runner creates tasks as services of the container, so tasks are ordinary services with dependencies.

Without Nette, the runner needs a store, a factory of tasks, the environment and a handler of runs:

```php
$runner = new Butler\Runner(
	new Butler\FileStore(__DIR__ . '/data/butler'),
	fn(string $task) => new $task,
	production: getenv('APP_ENV') === 'production',
	onRun: fn(Butler\RunReport $report) => $report->error && error_log((string) $report->error),
);
```

 <!---->

Limits
======

- One server: the store is a directory and the locks are `flock`. The store sits behind an interface, so a
  database store can come later.
- The store directory must survive deploys, must not be emptied with the cache, and must be writable by both
  PHP-FPM and the command line.
- Butler is not a scheduler. Cron calls `drain` or an action of the endpoint; how often is up to cron.
- A task is retried on the next trigger or `drain`, without a backoff.

 <!---->

Credits
-------

Butler comes from David Grudl, the author of [Nette](https://nette.org), [Latte](https://latte.nette.org) and
[Tracy](https://tracy.nette.org).
