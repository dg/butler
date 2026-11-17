<?php declare(strict_types=1);

namespace Butler;

use Attribute;


/**
 * Allows the task to run outside production too. A task without it runs only in production.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AllowedLocally
{
}
