<?php declare(strict_types=1);

use Butler\{AllowedLocally, Payload, PayloadTask, Task};


#[AllowedLocally]
class LocalTask implements Task
{
	public int $runs = 0;

	/** @var ?Closure(): void */
	public ?Closure $onRun = null;


	public function run(): void
	{
		$this->runs++;
		if ($this->onRun) {
			($this->onRun)();
		}
	}
}


#[AllowedLocally]
class LocalPayloadTask implements PayloadTask
{
	/** @var list<string> */
	public array $bodies = [];

	/** @var ?Closure(Payload): void */
	public ?Closure $onRun = null;


	public function run(Payload $payload): void
	{
		$this->bodies[] = $payload->body;
		if ($this->onRun) {
			($this->onRun)($payload);
		}
	}
}


class ProductionTask implements Task
{
	public function run(): void
	{
	}
}
