<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Attribute\Event\AfterInsert;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;
use G\Orm\Registry\OrmRegistry;

/**
 * Fixture whose #[AfterInsert] hook stages a follow-up audit row via persist()
 * — without flushing. It proves that an intention staged during a running
 * flush survives in the queue and is written by the next flush, instead of
 * being silently destroyed by a bulk queue reset.
 */
#[Table('registry_aware')]
final class AuditingEntity
{
    private const AUDIT_PREFIX = 'audit:';

    #[Id(generated: true)]
    #[Column]
    public int $id;

    #[Column]
    public string $name;

    #[AfterInsert]
    public function stageAuditTrail(): void
    {
        // Audit rows do not audit themselves, so the chain stops after one hop.
        if (str_starts_with($this->name, self::AUDIT_PREFIX)) {
            return;
        }

        $audit = new self();
        $audit->name = self::AUDIT_PREFIX . $this->name;

        OrmRegistry::get()->persist($audit);
    }
}
