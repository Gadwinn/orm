<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Hydrator\Hydrator;
use G\Orm\Metadata\MetadataReader;
use G\Orm\Tests\Fixture\Article;
use G\Orm\Tests\Fixture\Status;
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
