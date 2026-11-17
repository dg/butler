<?php declare(strict_types=1);

namespace Butler;


/**
 * The task runs only in production, see #[Butler\AllowedLocally].
 */
class ProductionOnlyException extends \LogicException
{
}


/**
 * The queue of a PayloadTask refuses another payload.
 */
class QueueFullException extends \RuntimeException
{
}


/**
 * The store failed to read or write its files.
 */
class IOException extends \RuntimeException
{
}
