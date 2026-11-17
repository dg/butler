<?php declare(strict_types=1);

namespace Butler;


/**
 * Result of processing the pending work of a task.
 */
enum Outcome
{
	/** Nothing is left pending. */
	case Done;

	/** Another process holds the lock and will see the work recorded before. */
	case Locked;

	/** A run failed. Its entry stays pending; a payload that has run too many times is rejected at its next turn. */
	case Failed;

	/** The time budget ran out, the rest stays pending. */
	case Postponed;
}
