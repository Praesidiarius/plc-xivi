<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Tenancy\TenantResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantResolver::class)]
final class TenantResolverTest extends TestCase
{
    /**
     * Hostname normalisation decides which tenant a request reaches, so the
     * shapes that a Host header can legitimately take all have to land on the
     * same stored hostname.
     */
    #[DataProvider('hosts')]
    public function testNormalize(string $host, string $expected): void
    {
        self::assertSame($expected, TenantResolver::normalize($host));
    }

    /** @return iterable<string, array{string, string}> */
    public static function hosts(): iterable
    {
        yield 'plain' => ['acme.localhost', 'acme.localhost'];
        yield 'uppercase' => ['ACME.Localhost', 'acme.localhost'];
        yield 'with port' => ['acme.localhost:8443', 'acme.localhost'];
        yield 'fully qualified' => ['acme.1plc.ch.', 'acme.1plc.ch'];
        yield 'padded' => ["  acme.1plc.ch\t", 'acme.1plc.ch'];
    }
}
