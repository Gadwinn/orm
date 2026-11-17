<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger keeping every record in memory.
 */
final class MemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
