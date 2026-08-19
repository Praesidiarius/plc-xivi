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

namespace App\Command;

use App\Deployment\ControlPlaneAllowList;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the control plane's address allow-list admits, before anybody depends on
 * it (XIV-124, docs/architecture/identity-and-access.md §8.9).
 *
 * ## The failure mode this exists for, and it is worse than §4.3's
 *
 * `deploy:check-hosts` exists because a too-narrow trusted-host pattern takes a
 * customer off the air, and the saving grace there is that **somebody notices**:
 * a customer whose installation answers 400 is a customer who telephones.
 *
 * An address allow-list has no such person. Getting it wrong locks out the
 * operator and nobody else — every customer keeps working, every dashboard stays
 * green, and the only symptom is a 403 on a console that one person visits, at
 * whatever hour they next need it, which by the nature of consoles is the hour
 * something is already wrong. There is no customer-facing signal at all, so
 * "find out by deploying it" is not a strategy here in the way it half is for a
 * hostname.
 *
 * So this reports the list **before** it is depended on, and it needs neither a
 * database nor a request to do it: everything it says comes out of the
 * environment the process was started with, which is exactly what the listener
 * will see.
 *
 * ## Why it guesses at the address you are asking from
 *
 * A console command has no client address — that is the whole difficulty, and it
 * is why the honest answer is `--address` and why the guess below is offered
 * rather than trusted.
 *
 * An operator running this is nearly always doing it over SSH, and `sshd` puts
 * the far end of that connection in `SSH_CONNECTION`. That is the address **the
 * shell** came from, which is the same address the browser will present only if
 * both leave the operator's network by the same route — usually true for one
 * office or one VPN, and false for anybody tunnelling. So it is printed with
 * that caveat attached and it never decides the exit code. What it buys is the
 * common case: an operator about to set this variable is told, in the same
 * breath, whether the connection they are typing on would survive it.
 *
 * ## The exit codes
 *
 * `deploy:check-hosts`' convention (§4.2, §4.3), so that a deploy script reads
 * the `deploy:check-*` family the same way rather than learning a third one:
 *
 * | code | meaning |
 * | --- | --- |
 * | 0 | nothing is configured, or the list is usable and any address explicitly asked about is admitted |
 * | 3 | the list holds entries that are not addresses, or an address given with `--address` would be refused |
 *
 * There is no 1. That code means "the check could not happen", which for
 * `deploy:check-hosts` is an unreadable registry; this command reads nothing
 * that can fail.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'deploy:check-control-plane',
    description: 'Report which addresses may reach the control plane, and whether a given one may',
)]
final readonly class CheckControlPlaneAccessCommand
{
    /** The list is unusable, or it would refuse an address somebody asked about. */
    private const int WOULD_REFUSE = 3;

    public function __construct(
        private ControlPlaneAllowList $allowList,
        #[Autowire('%app.control_plane_host%')]
        private string $controlPlaneHost,
    ) {
    }

    /**
     * @param string|null $address an address to test against the list, instead of the one
     *                             this shell appears to have come from
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Ask whether one particular address would be admitted')]
        ?string $address = null,
    ): int {
        $failed = false;

        // Said first, and said even when there is no allow-list, because a build
        // without an administration surface serves no control plane at all
        // (§4.4: a public deployment sets CONTROL_PLANE_HOST empty) and an
        // operator running this against the wrong container should be told that
        // rather than shown a list that governs nothing.
        if (trim($this->controlPlaneHost) === '') {
            $io->writeln(
                '<comment>Control plane:</comment> CONTROL_PLANE_HOST is empty, so this installation '
                . 'serves no control plane and nothing below governs anything.',
            );
        } else {
            $io->writeln(sprintf('<info>Control plane:</info> served on %s.', $this->controlPlaneHost));
        }

        if (!$this->allowList->isConfigured()) {
            // Not an error, and spelled out rather than passed over: an
            // unrestricted control plane is the shipped default and is correct
            // for development, but it is also a fact an operator should be able
            // to read off a deploy log rather than infer from an absence — the
            // call `deploy:check-hosts` makes about an empty XIVI_TRUSTED_DOMAINS.
            $io->writeln(sprintf(
                '  %s is empty, so the control plane accepts a connection from any address that '
                . 'reaches it.',
                ControlPlaneAllowList::VARIABLE,
            ));
            $io->writeln(
                "  That is the default. What keeps people out is the sign-in, the operator-only user\n"
                . "  provider and ROLE_OPERATOR — all of which hold. Setting this variable adds a\n"
                . '  layer in front of them (see docs/architecture/identity-and-access.md §8.9).',
            );

            // Deliberately still answered, because "would this address be
            // admitted" has a truthful answer here — yes, along with every other
            // — and a command that went silent on the unconfigured case would be
            // one an operator could not use to check their reasoning *before*
            // writing the variable.
            $this->reportAddresses($io, $address);

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            '  %s admits %s.',
            ControlPlaneAllowList::VARIABLE,
            $this->allowList->entries() === []
                ? '<error>nothing at all</error>'
                : implode(', ', $this->allowList->entries()),
        ));
        $io->writeln(
            '  Every other address gets an empty 403, whatever it asks for on that host.',
        );

        if ($this->allowList->rejected() !== []) {
            $failed = true;

            // The likeliest real failure, and it is loud on purpose. A rejected
            // entry is not ignored — the list stays in force and that entry
            // admits nobody — so an operator whose only entry is a typo has
            // locked themselves out of a console that is otherwise working
            // perfectly.
            $io->getErrorStyle()->error(sprintf(
                "%d entr%s in %s %s not an address or a CIDR range and admit%s nobody:\n  %s",
                \count($this->allowList->rejected()),
                \count($this->allowList->rejected()) === 1 ? 'y' : 'ies',
                ControlPlaneAllowList::VARIABLE,
                \count($this->allowList->rejected()) === 1 ? 'is' : 'are',
                \count($this->allowList->rejected()) === 1 ? 's' : '',
                implode("\n  ", $this->allowList->rejected()),
            ));
            $io->getErrorStyle()->writeln(
                "  Entries are addresses (198.51.100.7, 2001:db8::1) or CIDR ranges\n"
                . "  (198.51.100.0/24, 2001:db8::/32), comma separated. A bad entry does not switch\n"
                . '  the restriction off — it just never matches.',
            );
        }

        return $this->reportAddresses($io, $address) || $failed
            ? self::WOULD_REFUSE
            : Command::SUCCESS;
    }

    /**
     * Answers "would this address get in", for the one that was asked about and
     * for the one this shell appears to have come from.
     *
     * @return bool whether an address given with `--address` would be refused — which is
     *              the only one of the two that decides an exit code, because the detected
     *              one is a guess and a deploy script stopped by a guess is a deploy script
     *              that gets run with `|| true`
     */
    private function reportAddresses(SymfonyStyle $io, ?string $address): bool
    {
        $refused = false;

        if ($address !== null) {
            $admitted = $this->allowList->admits($address);
            $refused = !$admitted;

            $io->writeln(sprintf(
                $admitted
                    ? '  <info>%s would be admitted.</info>'
                    : '  <error>%s would be refused.</error>',
                $address,
            ));
        }

        $detected = self::addressThisShellCameFrom();

        if ($detected !== null && $detected !== $address) {
            $io->writeln(sprintf(
                $this->allowList->admits($detected)
                    ? '  This shell appears to come from %s, which would be admitted.'
                    : '  <comment>This shell appears to come from %s, which would be refused.</comment>',
                $detected,
            ));
            $io->writeln(
                "  That is where your SSH connection came from, not where your browser will come\n"
                . '  from. They agree only if both leave your network the same way.',
            );
        }

        return $refused;
    }

    /**
     * The far end of this SSH connection, if this is one.
     *
     * `SSH_CONNECTION` is `<client ip> <client port> <server ip> <server port>`
     * and `SSH_CLIENT` is the older three-field form; both are set by `sshd` and
     * neither exists in a container `exec` or a local shell, which is why this
     * returns null rather than guessing further. The value is validated as an
     * address before it is used at all — it comes out of the environment, and an
     * environment variable is something a caller of this command can set.
     */
    private static function addressThisShellCameFrom(): ?string
    {
        foreach (['SSH_CONNECTION', 'SSH_CLIENT'] as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;

            if (!\is_string($value)) {
                continue;
            }

            $address = strtok(trim($value), ' ');

            if (\is_string($address) && filter_var($address, \FILTER_VALIDATE_IP) !== false) {
                return $address;
            }
        }

        return null;
    }
}
