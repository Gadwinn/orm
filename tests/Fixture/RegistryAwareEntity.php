<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Contract\DbManagerInterface;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;
use G\Orm\Trait\EntityTrait;

/**
 * Minimal entity fixture that exercises EntityTrait so static analysis can
 * verify the trait in a concrete class context, and the active-record helpers
 * can be driven end to end against a registry-bound manager.
 */
#[Table('registry_aware')]
final class RegistryAwareEntity
{
    use EntityTrait;

    #[Id]
    #[Column]
    public int $id;

    #[Column]
    public string $name;

    /**
     * Hand-written accessor demonstrating the trait's protected orm() helper,
     * the seam real relation accessors would use to reach the active manager.
     */
    public function activeManager(): DbManagerInterface
    {
        return $this->orm();
    }
}
