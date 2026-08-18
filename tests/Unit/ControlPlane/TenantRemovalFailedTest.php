<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\ControlPlane;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Xivi\ControlPlane\Provisioning\TenantRemovalFailed;

/**
 * What a half-finished removal says about itself (XIV-94).
 *
 * The functional test beside this one proves the removal *works* through a live
 * session. This one is about the case nobody can arrange on demand: the drop that
 * fails anyway. Each state has to describe itself well enough that an operator
 * standing in front of it knows which of three objects still exist, and that is a
 * property of a message rather than of a database — so it is asserted here, where
 * every state can be constructed, rather than left to whichever one happened to
 * be reproducible.
 *
 * **The message and the report are asserted separately on purpose.** They are
 * read by different people in different places: `getMessage()` is what reaches a
 * log, and what `tenant:reset` shows when it lets this fly (§4.1), while
 * {@see TenantRemovalFailed::state()} is what the command draws on a terminal.
 * A change that fixes one and forgets the other should fail here.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[CoversClass(TenantRemovalFailed::class)]
final class TenantRemovalFailedTest extends TestCase
{
    private const string SLUG = 'acme';
    private const string DATABASE = 'tenant_acme';
    private const string ROLE = 'tenant_acme';

    /**
     * The privilege failure names the grant, because the operator reading it is
     * the only person who can apply it.
     *
     * A message that said "permission denied" and stopped would leave somebody to
     * work out, from a Postgres error about a process, that the fix is a role
     * membership — and the rule is genuinely obscure, having changed twice since
     * Postgres 14.
     */
    public function testTheUnprivilegedCaseNamesTheGrantAndSaysNothingWasDestroyed(): void
    {
        $failure = TenantRemovalFailed::mayNotDisconnectSessions(
            self::SLUG,
            self::DATABASE,
            self::ROLE,
            3,
            'provisioner',
            new \RuntimeException('permission denied to terminate process'),
        );

        self::assertStringContainsString('3 sessions are attached', $failure->reason());
        self::assertStringContainsString('GRANT pg_signal_backend TO provisioner', $failure->reason());
        self::assertStringContainsString('Nothing was destroyed', $failure->getMessage());

        self::assertFalse($failure->databaseDropped);
        self::assertFalse($failure->roleDropped);
    }

    /** One session is one session, not "1 session(s)". */
    public function testASingleSessionIsSaidInTheSingular(): void
    {
        $failure = TenantRemovalFailed::mayNotDisconnectSessions(
            self::SLUG,
            self::DATABASE,
            self::ROLE,
            1,
            'provisioner',
            new \RuntimeException('nope'),
        );

        self::assertStringContainsString('1 session is attached', $failure->reason());
    }

    /**
     * A reconnecting client is described as a reconnecting client.
     *
     * The distinction is the whole value of the message: after the terminate has
     * run, "somebody is connected" can only mean somebody who connected *since*,
     * and telling an operator to close a stale connection they no longer have
     * would send them looking for the wrong thing.
     */
    public function testTheRaceCaseSaysSomethingReconnectedRatherThanSomethingWasNeverClosed(): void
    {
        $failure = TenantRemovalFailed::sessionsCameBack(
            self::SLUG,
            self::DATABASE,
            self::ROLE,
            new \RuntimeException('is being accessed by other users'),
        );

        self::assertStringContainsString('reconnected', $failure->reason());
        self::assertStringContainsString('Nothing was destroyed', $failure->getMessage());
    }

    /**
     * The three states, and the three different things they leave standing.
     *
     * Asserted as a set rather than one by one because the failure worth catching
     * is not a wrong word in one of them — it is two of them saying the same
     * thing, which is what happens when somebody simplifies the composition and
     * loses a branch.
     */
    public function testEachStateDescribesADifferentAmountOfWreckage(): void
    {
        $previous = new \RuntimeException('because');

        $nothingGone = TenantRemovalFailed::databaseSurvived(self::SLUG, self::DATABASE, self::ROLE, $previous);
        $databaseGone = TenantRemovalFailed::roleSurvived(self::SLUG, self::DATABASE, self::ROLE, $previous);
        $bothGone = TenantRemovalFailed::registryRowSurvived(self::SLUG, self::DATABASE, self::ROLE, $previous);

        self::assertSame(
            [[false, false], [true, false], [true, true]],
            [
                [$nothingGone->databaseDropped, $nothingGone->roleDropped],
                [$databaseGone->databaseDropped, $databaseGone->roleDropped],
                [$bothGone->databaseDropped, $bothGone->roleDropped],
            ],
        );

        self::assertStringContainsString('Nothing was destroyed', $nothingGone->getMessage());
        self::assertStringContainsString('Database "tenant_acme" is gone for good', $databaseGone->getMessage());
        self::assertStringContainsString('only the control-plane row', $bothGone->getMessage());
    }

    /**
     * Every state points at the same next step, and that is the design rather
     * than an accident.
     *
     * The order the removal runs in — cluster first, registry last — was chosen
     * so that re-running it is correct from anywhere it can stop, because both
     * drops are `IF EXISTS`. If a state ever appeared that this line would *not*
     * repair, it would have to be a state where the row is already gone, which is
     * exactly the one the reordering removed.
     */
    public function testEveryStateIsRepairedByRunningTheSameCommandAgain(): void
    {
        $previous = new \RuntimeException('because');

        foreach ([
            TenantRemovalFailed::databaseSurvived(self::SLUG, self::DATABASE, self::ROLE, $previous),
            TenantRemovalFailed::roleSurvived(self::SLUG, self::DATABASE, self::ROLE, $previous),
            TenantRemovalFailed::registryRowSurvived(self::SLUG, self::DATABASE, self::ROLE, $previous),
        ] as $failure) {
            self::assertSame('bin/console tenant:deprovision acme --force', $failure->nextStep());

            $rows = $failure->state();
            self::assertCount(3, $rows, 'the database, the role and the row: all three, always');
            self::assertStringContainsString('still there', json_encode($rows[2], \JSON_THROW_ON_ERROR));
        }
    }
}
