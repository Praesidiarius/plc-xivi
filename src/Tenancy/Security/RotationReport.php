<?php

declare(strict_types=1);

namespace App\Tenancy\Security;

/**
 * @phpstan-type Slug string
 */
final readonly class RotationReport
{
    /**
     * @param list<string>          $rotated tenants moved onto the active key
     * @param list<string>          $skipped tenants already on the active key
     * @param array<string, string> $failed  slug => why it could not be rotated
     */
    public function __construct(
        public array $rotated,
        public array $skipped,
        public array $failed,
        public string $activeKeyId,
    ) {
    }

    /** True once every tenant is on the active key, i.e. the old key can be dropped. */
    public function isComplete(): bool
    {
        return $this->failed === [];
    }
}
