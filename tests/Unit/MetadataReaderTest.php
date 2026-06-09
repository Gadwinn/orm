<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Exception\MetadataException;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\RelationType;
use G\Orm\Metadata\EntityMetadata;
use G\Orm\Metadata\MetadataReader;
use G\Orm\Metadata\PropertyMetadata;
use G\Orm\Metadata\RelationMetadata;
use G\Orm\Tests\Fixture\Address;
use G\Orm\Tests\Fixture\CustomUserRepository;
use G\Orm\Tests\Fixture\GeneratedCompositeKey;
use G\Orm\Tests\Fixture\Tag;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\Fixture\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive attribute extraction: #[Table], #[Column] (with all options),
 * #[Id] (scalar and composite), #[Repository], and every relation variant.
 * Also covers the per-instance memoisation guarantee.
 *
 * The reader produces the metadata value objects, whose accessors are asserted
 * throughout, so they are declared as covered here too.
 */
#[CoversClass(MetadataReader::class)]
#[CoversClass(EntityMetadata::class)]
#[CoversClass(PropertyMetadata::class)]
#[CoversClass(RelationMetadata::class)]
#[CoversClass(Id::class)]
final class MetadataReaderTest extends TestCase
{
    public function testReadsTableColumnsAndScalarPrimaryKey(): void
    {
        $schema = (new MetadataReader())->getSchemaFor(User::class);

        self::assertSame(User::class, $schema->getEntityClass());
        self::assertSame('users', $schema->getTableName());
        self::assertSame(['id'], $schema->getPrimaryKeyFields());

        $id = $schema->getProperty('id');
        self::assertNotNull($id);
        self::assertTrue($id->isIdentifier());
        self::assertSame('id', $id->getColumnName());

        $email = $schema->getProperty('email');
        self::assertNotNull($email);
        self::assertFalse($email->isIdentifier());
        self::assertTrue($email->isUnique());
    }

    public function testReadsAllColumnOptions(): void
    {
        $name = (new MetadataReader())->getSchemaFor(Tag::class)->getProperty('name');

        self::assertNotNull($name);
        self::assertSame('label', $name->getColumnName());
        self::assertTrue($name->isNullable());
        self::assertTrue($name->isUnique());
        self::assertSame(50, $name->getLength());
    }

    public function testReadsCustomRepositoryAttribute(): void
    {
        $reader = new MetadataReader();

        self::assertSame(CustomUserRepository::class, $reader->getSchemaFor(Tag::class)->getRepositoryClass());
        self::assertNull($reader->getSchemaFor(User::class)->getRepositoryClass());
    }

    public function testAccumulatesCompositePrimaryKeyInDeclarationOrder(): void
    {
        $schema = (new MetadataReader())->getSchemaFor(UserRole::class);

        self::assertSame(['userId', 'roleId'], $schema->getPrimaryKeyFields());
    }

    public function testRejectsCompositePrimaryKeyWithGeneratedColumn(): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('generated identifiers are only supported on single-column keys');

        (new MetadataReader())->getSchemaFor(GeneratedCompositeKey::class);
    }

    public function testReadsBelongsToRelation(): void
    {
        $relation = (new MetadataReader())->getSchemaFor(User::class)->getRelation('address');

        self::assertNotNull($relation);
        self::assertSame(RelationType::BelongsTo, $relation->getType());
        self::assertSame(Address::class, $relation->getTargetEntity());
        self::assertSame('address_id', $relation->getLocalKey());
        self::assertSame('id', $relation->getForeignKey());
    }

    public function testReadsHasManyRelation(): void
    {
        $relation = (new MetadataReader())->getSchemaFor(Address::class)->getRelation('users');

        self::assertNotNull($relation);
        self::assertSame(RelationType::HasMany, $relation->getType());
        self::assertSame(User::class, $relation->getTargetEntity());
        self::assertSame('id', $relation->getLocalKey());
        self::assertSame('address_id', $relation->getForeignKey());
    }

    public function testMemoisesSchemaPerClass(): void
    {
        $reader = new MetadataReader();

        // The same instance returned twice proves the class is reflected once
        // and the result cached, not rebuilt on every call.
        self::assertSame($reader->getSchemaFor(User::class), $reader->getSchemaFor(User::class));
    }
}
