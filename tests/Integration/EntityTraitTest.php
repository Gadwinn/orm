<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Contract\QueryBuilderInterface;
use G\Orm\Contract\RepositoryInterface;
use G\Orm\Registry\OrmRegistry;
use G\Orm\Tests\Fixture\RegistryAwareEntity;
use G\Orm\Tests\OrmTestCase;
use G\Orm\Traits\EntityTrait;
use PHPUnit\Framework\Attributes\CoversTrait;

/**
 * The optional active-record trait, driven end to end against a registry-bound
 * manager. The trait keeps entities pure POPOs: every helper resolves the
 * active DbManager through OrmRegistry and routes persistence through the Unit
 * Of Work, never issuing SQL directly.
 */
#[CoversTrait(EntityTrait::class)]
final class EntityTraitTest extends OrmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OrmRegistry::set($this->manager);
    }

    public function testSavePersistsThroughTheActiveManager(): void
    {
        $entity = new RegistryAwareEntity();
        $entity->name = 'Ada';
        $entity->save();

        self::assertSame(1, $this->countRows('registry_aware'));
        self::assertTrue(isset($entity->id));
        self::assertGreaterThan(0, $entity->id);
    }

    public function testFindResolvesAPersistedEntityByKey(): void
    {
        $entity = new RegistryAwareEntity();
        $entity->name = 'Grace';
        $entity->save();

        $found = RegistryAwareEntity::find($entity->id);

        self::assertInstanceOf(RegistryAwareEntity::class, $found);
        self::assertSame('Grace', $found->name);
    }

    public function testRemoveDeletesThroughTheActiveManager(): void
    {
        $entity = new RegistryAwareEntity();
        $entity->name = 'Temp';
        $entity->save();
        self::assertSame(1, $this->countRows('registry_aware'));

        $entity->remove();

        self::assertSame(0, $this->countRows('registry_aware'));
    }

    public function testQueryReturnsAnEntityScopedBuilder(): void
    {
        $builder = RegistryAwareEntity::query();

        self::assertInstanceOf(QueryBuilderInterface::class, $builder);
        self::assertSame('SELECT * FROM registry_aware', $builder->toSql());
    }

    public function testRepositoryReturnsTheEntityRepository(): void
    {
        self::assertInstanceOf(RepositoryInterface::class, RegistryAwareEntity::repository());
    }

    public function testOrmHelperExposesTheActiveManagerToAccessors(): void
    {
        $entity = new RegistryAwareEntity();

        self::assertSame($this->manager, $entity->activeManager());
    }
}
