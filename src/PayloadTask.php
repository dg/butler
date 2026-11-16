<?php declare(strict_types=1);

namespace Butler;


/**
 * Task with input. Every payload is processed, in the order of arrival.
 */
interface PayloadTask
{
	function run(Payload $payload): void;
}
