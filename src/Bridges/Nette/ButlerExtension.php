<?php declare(strict_types=1);

namespace Butler\Bridges\Nette;

use Butler\{Cli, FileStore, Runner, RunReport};
use Nette;
use Nette\DI\Definitions\{Reference, Statement};
use Nette\Schema\Expect;
use Tracy;
use function is_string;


/**
 * Registers the store, the runner and the command line entry of Butler.
 *
 * @property-read \stdClass $config
 */
final class ButlerExtension extends Nette\DI\CompilerExtension
{
	public function getConfigSchema(): Nette\Schema\Schema
	{
		$tempDir = $this->getContainerBuilder()->parameters['tempDir'] ?? null;
		return Expect::structure([
			// not %productionMode%, which is false in the console and for a developer's IP address
			'production' => Expect::bool()->required(),
			// temp survives deploys, only temp/cache is purged
			'dir' => is_string($tempDir) ? Expect::string($tempDir . '/butler') : Expect::string()->required(),
			'maxQueueSize' => Expect::int(1000),
			'maxAttempts' => Expect::int(10),
			'staleAfter' => Expect::int(3600),
			'onRun' => Expect::mixed(),
		]);
	}


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('store'))
			->setFactory(FileStore::class, [$this->config->dir, $this->config->maxQueueSize, $this->config->maxAttempts]);

		$builder->addDefinition($this->prefix('runner'))
			->setFactory(Runner::class, [
				'factory' => new Statement(
					'Closure::fromCallable',
					[[new Reference(Nette\DI\ContainerBuilder::ThisContainer), 'getByType']],
				),
				'production' => $this->config->production,
				'onRun' => $this->config->onRun ?? new Statement('Closure::fromCallable', [[self::class, 'logFailure']]),
			]);

		$builder->addDefinition($this->prefix('cli'))
			->setFactory(Cli::class, ['staleAfter' => $this->config->staleAfter]);
	}


	/** @internal default handler of runs: logs failures */
	public static function logFailure(RunReport $report): void
	{
		if (!$report->error) {
			return;
		} elseif (class_exists(Tracy\Debugger::class)) {
			Tracy\Debugger::log($report->error, Tracy\ILogger::EXCEPTION);
		} else {
			error_log((string) $report->error);
		}
	}
}
