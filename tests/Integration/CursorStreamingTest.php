<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Query\QueryBuilder;
use G\Orm\Tests\OrmTestCase;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The streaming row terminal: cursor() yields the same rows as get(), one at
 * a time, honouring every builder condition. It is the in-builder equivalent
 * of looping fetchAssociative() on the raw driver, so an export keeps the
 * builder's compilation while preserving its memory profile.
 */
#[CoversClass(QueryBuilder::class)]
final class CursorStreamingTest extends OrmTestCase
{
    public function testCursorYieldsEachRowInTurn(): void
    {
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@test.com')");
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (2, 'Grace', 'grace@test.com')");
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (3, 'Linus', 'linus@test.com')");

        $cursor = $this->builder()->select('id', 'name')->from('users')->orderBy('id')->cursor();
        self::assertInstanceOf(Generator::class, $cursor);

        $collected = [];
        foreach ($cursor as $row) {
            $collected[] = $row;
        }

        self::assertSame(
            [
                ['id' => 1, 'name' => 'Ada'],
                ['id' => 2, 'name' => 'Grace'],
                ['id' => 3, 'name' => 'Linus'],
            ],
            $collected
        );
    }

    public function testCursorHonoursBuilderConditions(): void
    {
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@test.com')");
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (2, 'Grace', 'grace@test.com')");

        $names = [];
        foreach ($this->builder()->from('users')->where('id', '=', 2)->cursor() as $row) {
            $names[] = $row['name'];
        }

        self::assertSame(['Grace'], $names);
    }

    public function testCursorOverAnEmptyResultYieldsNothing(): void
    {
        $rows = iterator_to_array($this->builder()->from('users')->cursor());

        self::assertSame([], $rows);
    }

    public function testCursorAndGetReturnTheSameRows(): void
    {
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@test.com')");
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (2, 'Grace', 'grace@test.com')");

        $streamed = iterator_to_array($this->builder()->from('users')->orderBy('id')->cursor());
        $eager = $this->builder()->from('users')->orderBy('id')->get();

        self::assertSame($eager, $streamed);
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->compiler, $this->connection, $this->metadata);
    }
}
