<?php declare(strict_types=1);

use Butler\Bridges\Nette\ButlerExtension;
use Butler\{Cli, Outcome, Runner};
use Nette\DI;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/tasks.php';


function createContainer(string $config): DI\Container
{
	$dir = createTempDir();
	$loader = new DI\ContainerLoader($dir . '/container', autoRebuild: true);
	$class = $loader->load(function (DI\Compiler $compiler) use ($config, $dir): ?string {
		$compiler->addExtension('butler', new ButlerExtension);
		$compiler->addConfig(['parameters' => ['tempDir' => $dir]]);
		$compiler->loadConfig(Tester\FileMock::create($config, 'neon'));
		return null;
	}, $config);
	return new $class;
}


test('the runner creates tasks from the container and keeps its work in tempDir', function () {
	$container = createContainer(<<<'NEON'
		butler:
			production: false
		services:
			- LocalTask
		NEON);

	$runner = $container->getByType(Runner::class);
	$runner->trigger(LocalTask::class);
	Assert::true(is_file($container->getParameter('tempDir') . '/butler/LocalTask.marker'));
	Assert::same(Outcome::Done, $runner->drain(LocalTask::class));
	Assert::same(1, $container->getByType(LocalTask::class)->runs);
	Assert::notNull($container->getByType(Cli::class, throw: false));
});


class RunCollector
{
	/** @var list<Butler\RunReport> */
	public static array $reports = [];


	public static function collect(Butler\RunReport $report): void
	{
		self::$reports[] = $report;
	}
}


test('the handler of runs can be configured', function () {
	$container = createContainer(<<<'NEON'
		butler:
			production: false
			onRun: RunCollector::collect(...)
		services:
			- LocalTask
		NEON);

	$runner = $container->getByType(Runner::class);
	$runner->trigger(LocalTask::class);
	$runner->drain(LocalTask::class);
	Assert::count(1, RunCollector::$reports);
	Assert::same(LocalTask::class, RunCollector::$reports[0]->task);
});


test('production is required', function () {
	Assert::exception(
		fn() => createContainer("butler:\n\tmaxQueueSize: 5\n"),
		DI\InvalidConfigurationException::class,
		"The mandatory item 'butler%a%production' is missing.",
	);
});
