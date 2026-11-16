<?php declare(strict_types=1);

namespace Butler;


/**
 * Task without input. Triggers that arrive before it starts merge into one run.
 */
interface Task
{
	function run(): void;
}
