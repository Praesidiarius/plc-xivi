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

namespace App\Tests\Functional\ControlPlane;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\ControlPlane\Command\PruneSignupsCommand;
use Xivi\ControlPlane\Entity\SignupRequest;
use Xivi\ControlPlane\Repository\SignupRequestRepository;
use Xivi\ControlPlane\Signup\ConfirmationToken;

/**
 * `signup:prune`, and the three rows it has to tell apart (XIV-125).
 *
 * The decision under test is that **an unconfirmed signup is eventually
 * discarded and nothing else ever is**. Everything worth asserting here is a
 * boundary:
 *
 *   * a pending row whose confirmation window closed long ago is somebody's
 *     address and company name kept for no reason, and goes;
 *   * a pending row whose window closed recently stays, because somebody may
 *     still open that mail and deserves to be told their link *expired* rather
 *     than that it is unknown (§8.12 made those two different answers on
 *     purpose);
 *   * a confirmed row stays whatever its dates say, because it is holding a name
 *     and is on its way to being a customer. Only provisioning removes one.
 *
 * There is no fixture here for the fourth case, a tenant, and there could not be:
 * this command cannot see one. That is the point rather than a gap: §4.1 and
 * §4.6 between them say a customer's database is never removed by anything on a
 * schedule, and the way that is guaranteed is that the scheduled thing has no
 * reach into the registry at all.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupPruningTest extends KernelTestCase
{
    /** Every address this class writes ends with this, so cleanup can find them. */
    private const string SUFFIX = '@xiv125prune.test';

    private const string ABANDONED = 'abandoned' . self::SUFFIX;
    private const string RECENT = 'recent' . self::SUFFIX;
    private const string CONFIRMED = 'confirmed' . self::SUFFIX;

    protected function setUp(): void
    {
        // The table is read out of the command's output as well as out of the
        // repository, and SymfonyStyle wraps its columns to the terminal.
        putenv('COLUMNS=240');

        self::bootKernel();

        // Both ends, because the control plane is not rolled back between tests
        // (see config/packages/test/dama_doctrine_test.yaml, and
        // `SignupEndpointTest` for the reason).
        $this->removeFixtures();
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        putenv('COLUMNS');

        parent::tearDown();
    }

    /**
     * The one that goes, and the two that stay.
     */
    public function testOnlyALongExpiredUnconfirmedSignupIsDiscarded(): void
    {
        $tester = $this->prune();
        $tester->assertCommandIsSuccessful();

        self::assertNull($this->signup(self::ABANDONED), 'nobody was ever going to answer this one');

        self::assertNotNull(
            $this->signup(self::RECENT),
            'inside the grace period, so their link still answers "expired" rather than "unknown"',
        );
        self::assertNotNull(
            $this->signup(self::CONFIRMED),
            'confirmed, so it is holding a name and belongs to signup:provision',
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString(self::ABANDONED, $display, 'the operator can see what went');
        self::assertStringNotContainsString(self::RECENT, $display);
        self::assertStringNotContainsString(self::CONFIRMED, $display);
    }

    /**
     * `--dry-run` prints the same list and removes none of it.
     *
     * Worth a test of its own rather than a flag somebody trusts: the whole
     * value of a dry run is being able to look before deleting, and a dry run
     * that deleted anyway would be the most expensive possible bug in a command
     * of this shape.
     */
    public function testADryRunRemovesNothing(): void
    {
        $tester = $this->prune(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString(self::ABANDONED, $tester->getDisplay());
        self::assertStringContainsString('Nothing was removed', $tester->getDisplay());

        self::assertNotNull($this->signup(self::ABANDONED));
    }

    /**
     * An empty run is a success and says so quietly.
     *
     * This is nightly on an installation where most nights there is nothing to
     * do, and a scheduled command that mails somebody every night is one whose
     * mail nobody reads within a fortnight. That is
     * {@see \Xivi\ControlPlane\Command\ProvisionSignupsCommand}'s argument for
     * the same shape.
     */
    public function testAnEmptyRunIsASuccess(): void
    {
        $this->prune();

        $tester = $this->prune();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No unconfirmed signups', $tester->getDisplay());
    }

    /**
     * @param array<string, bool|string> $input
     */
    private function prune(array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        $tester = new CommandTester(new Application($kernel)->find('signup:prune'));
        $tester->execute($input);

        return $tester;
    }

    private function signup(string $email): ?SignupRequest
    {
        self::service(EntityManagerInterface::class)->clear();

        return self::service(SignupRequestRepository::class)->findOneByEmail($email);
    }

    /**
     * Three signups, differing in the two things the command looks at.
     *
     * The dates are relative to the command's own grace period rather than
     * written out, so that changing the constant changes the fixture with it: a
     * test that hard-coded thirty-one days would keep passing while meaning
     * something else.
     */
    private function createFixtures(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $grace = new \DateInterval(PruneSignupsCommand::GRACE);

        $abandoned = new \DateTimeImmutable()->sub($grace)->modify('-1 day');
        $recent = new \DateTimeImmutable()->modify('-2 days');

        $manager->persist($this->pending(self::ABANDONED, 'Abandoned AG', 'xiv125-abandoned', $abandoned));
        $manager->persist($this->pending(self::RECENT, 'Recent AG', 'xiv125-recent', $recent));

        // Confirmed and just as old, which is the row that proves the status
        // filter is doing something: on dates alone this one would go first.
        $confirmed = $this->pending(self::CONFIRMED, 'Confirmed AG', 'xiv125-confirmed', $abandoned);
        $confirmed->confirm();
        $manager->persist($confirmed);

        $manager->flush();
    }

    private function pending(
        string $email,
        string $company,
        string $slug,
        \DateTimeImmutable $expiresAt,
    ): SignupRequest {
        return new SignupRequest(
            $email,
            $company,
            $slug,
            'standard',
            'en',
            ConfirmationToken::generate()->hash(),
            $expiresAt,
        );
    }

    private function removeFixtures(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $manager->clear();

        foreach ($manager->createQuery(
            'SELECT s FROM ' . SignupRequest::class . ' s WHERE s.email LIKE :suffix',
        )->setParameter('suffix', '%' . self::SUFFIX)->toIterable() as $signup) {
            \assert($signup instanceof SignupRequest);
            $manager->remove($signup);
        }

        $manager->flush();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
