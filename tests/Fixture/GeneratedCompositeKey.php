<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Invalid mapping fixture: a composite primary key whose first member is also
 * marked database-generated. The metadata reader must reject this combination,
 * since a database can only auto-assign a single-column key.
 */
#[Table('generated_composite')]
final class GeneratedCompositeKey
{
    #[Id(generated: true)]
    #[Column(name: 'tenant_id')]
    public int $tenantId;

    #[Id]
    #[Column(name: 'sequence_no')]
    public int $sequenceNo;
}
