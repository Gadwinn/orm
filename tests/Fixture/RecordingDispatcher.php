<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Minimal PSR-14 dispatcher test double that records, in order, every event it
 * is handed so a test can assert which lifecycle events were emitted.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /**
     * @var list<object>
     */
    private array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * @return list<object>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Whether an event of the given class was dispatched.
     *
     * @param class-string $eventClass
     */
    public function has(string $eventClass): bool
    {
        foreach ($this->events as $event) {
            if ($event instanceof $eventClass) {
                return true;
            }
        }

        return false;
    }
}
