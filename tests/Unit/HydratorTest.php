<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use DateTime;
use DateTimeImmutable;
use G\Orm\Hydrator\Hydrator;
use G\Orm\Metadata\MetadataReader;
use G\Orm\Tests\Fixture\Article;
use G\Orm\Tests\Fixture\Level;
use G\Orm\Tests\Fixture\Status;
use G\Orm\Tests\Fixture\Visit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Hydrator edge cases: strict-typed scalars, native backed-enum conversion in
 * both directions, and the structural distinction between a real NULL and an
 * uninitialised (never-loaded) property.
 */
#[CoversClass(Hydrator::class)]
final class HydratorTest extends TestCase
{
    public function testHydratesStrictTypedAndEnumProperties(): void
    {
        $article = $this->hydrator()->hydrate(Article::class, [
            'id' => 1,
            'title' => 'Hello',
            'subtitle' => null,
            'status' => 'published',
        ]);

        self::assertInstanceOf(Article::class, $article);
        self::assertSame(1, $article->id);
        self::assertSame('Hello', $article->title);
        self::assertNull($article->subtitle);
        // String backing value converted up to the enum instance.
        self::assertSame(Status::Published, $article->status);
    }

    public function testHydratesAnIntBackedEnumReadAsAString(): void
    {
        // pdo_sqlsrv (and DECIMAL columns) return integers as strings.
        $visit = $this->hydrator()->hydrate(Visit::class, ['id' => '3', 'level' => '1', 'seen_at' => null]);

        self::assertInstanceOf(Visit::class, $visit);
        self::assertSame(3, $visit->id);
        self::assertSame(Level::Admin, $visit->level);
        self::assertNull($visit->seenAt);
    }

    public function testConvertsADateTimeInterfacePropertyBothWays(): void
    {
        $hydrator = $this->hydrator();
        $visit = $hydrator->hydrate(Visit::class, ['id' => 1, 'level' => 0, 'seen_at' => '2024-03-15 10:30:45']);

        self::assertInstanceOf(Visit::class, $visit);
        self::assertInstanceOf(DateTimeImmutable::class, $visit->seenAt);
        self::assertSame('2024-03-15 10:30:45', $visit->seenAt->format('Y-m-d H:i:s'));

        $visit->seenAt = new DateTime('2025-01-02 03:04:05');
        self::assertSame('2025-01-02 03:04:05', $hydrator->extractState($visit)['seen_at']);
    }

    public function testNeverMapsAStaticProperty(): void
    {
        $visit = $this->hydrator()->hydrate(Visit::class, ['id' => 1, 'level' => 0, 'seen_at' => null]);

        self::assertArrayNotHasKey('instances', $this->hydrator()->extractState($visit));
    }

    public function testExtractsEnumBackToItsBackingValue(): void
    {
        $article = new Article();
        $article->id = 7;
        $article->title = 'Hello';
        $article->subtitle = null;
        $article->status = Status::Draft;

        // The enum instance is flattened back to its scalar backing value.
        self::assertSame(
            ['id' => 7, 'title' => 'Hello', 'subtitle' => null, 'status' => 'draft'],
            $this->hydrator()->extractState($article)
        );
    }

    public function testExtractIdentifierReturnsScalarKey(): void
    {
        $article = new Article();
        $article->id = 7;

        self::assertSame(7, $this->hydrator()->extractIdentifier($article));
    }

    public function testNullColumnIsHydratedAsRealNull(): void
    {
        $article = $this->hydrator()->hydrate(Article::class, [
            'id' => 1,
            'title' => 'X',
            'subtitle' => null,
            'status' => 'draft',
        ]);

        // A column present in the row but NULL is a genuine null value.
        self::assertNull($article->subtitle);
        self::assertArrayHasKey('subtitle', $this->hydrator()->extractState($article));
    }

    public function testUninitialisedPropertiesAreSkippedNotNulled(): void
    {
        // Partial row: only the primary key and title are loaded; the nullable
        // subtitle and the enum status stay uninitialised.
        $partial = $this->hydrator()->hydrate(Article::class, ['id' => 1, 'title' => 'X'], ['title']);

        // Extraction skips uninitialised properties entirely rather than
        // emitting a phantom null for them.
        self::assertSame(
            ['id' => 1, 'title' => 'X'],
            $this->hydrator()->extractState($partial, ['title'])
        );
    }

    private function hydrator(): Hydrator
    {
        return new Hydrator(new MetadataReader());
    }
}
