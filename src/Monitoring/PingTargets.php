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

namespace App\Monitoring;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where each watched command's ping goes (XIV-126, docs/architecture.md §4.5).
 *
 * `XIVI_MONITOR_PINGS` is a comma-separated list of `command=url` pairs, and
 * **empty — the default — means no pings and no behaviour change whatsoever**.
 * That is the same shape [XIV-93]'s trusted domains and [XIV-61]'s secret guard
 * have, for the same reason: an installation that configures nothing must behave
 * exactly as it did before the feature existed, or the feature is a migration
 * rather than an option.
 *
 * ```
 * XIVI_MONITOR_PINGS=signup:provision=https://hc-ping.com/<uuid>,tenant:usage:collect=https://hc-ping.com/<uuid>
 * ```
 *
 * ## Why one URL per command rather than one base URL for the installation
 *
 * Healthchecks can address a check by project ping key and slug, which would
 * make this a single variable with the command name derived into the path. It
 * was not taken, for two reasons that are both about what the variable *means*.
 * A per-check URL is what every one of the four services evaluated in §4.5
 * issues, so a deployment that later moves from a self-hosted Healthchecks to
 * anything else re-pastes four URLs instead of discovering that the shorthand
 * only ever worked on one of them. And a single base URL would silently make the
 * command name part of what is sent to a third party — which is harmless for
 * these three names and is a rule that only holds while nobody names a command
 * after a customer.
 *
 * ## Why a malformed entry is a refusal rather than a skipped line
 *
 * Ignoring an entry that does not parse would produce **a command nobody is
 * watching, on an installation whose operator believes they configured
 * watching** — which is the failure this whole ticket exists to remove, wearing
 * the costume of a defensive `continue`. So the constructor throws, and it
 * throws at the first console command anybody runs after setting the variable,
 * which on a deployment is within seconds of setting it.
 *
 * The exception cannot reach a web request: this class is only constructed for
 * {@see EventListener\JobMonitorSubscriber} and `deploy:crontab`, both of which
 * are console-only. It cannot reach the image build either, because the value
 * committed in `.env` is empty and an empty list is the one input that is always
 * valid.
 *
 * ## What is refused, and why a query string is one of them
 *
 * A scheme other than `http` or `https`, a missing or empty half, a duplicate
 * command, and a URL carrying a query string or a fragment. The last is not
 * fussiness: {@see JobMonitor} reports an outcome by appending a path segment —
 * `/start`, `/0`, `/3` — which is the protocol these services publish, and
 * appending a path to `…?key=abc` produces a URL that resolves to something
 * nobody meant. None of the ping URLs any of them issues has a query string, so
 * refusing one costs nothing and removes a way to be wrong in silence.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PingTargets
{
    /**
     * The environment variable, named here because every message about it names
     * it: an operator reading "entry 2 is malformed" without being told which
     * variable holds entry 2 has been told nothing they can act on.
     */
    public const string VARIABLE = 'XIVI_MONITOR_PINGS';

    /** @var array<string, string> command name => the ping base URL, no trailing slash */
    private array $urls;

    /**
     * @param list<string> $pings `app.monitor_pings`, each entry `command=url`
     */
    public function __construct(
        #[Autowire('%app.monitor_pings%')]
        array $pings,
    ) {
        $urls = [];

        foreach ($pings as $position => $entry) {
            $entry = trim($entry);

            // `%env(csv:…)%` of an empty variable is an empty list on every
            // Symfony this has been run on, but a deployment writing a trailing
            // comma is a thing that happens and produces an empty entry which
            // means nothing rather than something wrong. Skipping it is not the
            // silent-continue the docblock above refuses: there is no command
            // here to fail to watch.
            if ($entry === '') {
                continue;
            }

            [$command, $url] = self::split($entry, $position);

            if (isset($urls[$command])) {
                throw new \InvalidArgumentException(sprintf(
                    '%s names "%s" twice. One command has one ping URL; a second entry would '
                    . 'silently win or silently lose, and neither is something you could tell '
                    . 'from the monitor.',
                    self::VARIABLE,
                    $command,
                ));
            }

            $urls[$command] = $url;
        }

        $this->urls = $urls;
    }

    /**
     * Whether this installation watches anything at all.
     *
     * True is the shipped default and is not a fault: monitoring is somebody
     * else's service, and Xivi does not require one (§4.5, and [XIV-115] for the
     * same refusal about storage).
     */
    public function isEmpty(): bool
    {
        return $this->urls === [];
    }

    /**
     * The base URL to ping for a command, or null when it is not watched.
     */
    public function for(string $command): ?string
    {
        return $this->urls[$command] ?? null;
    }

    /**
     * Every command a URL is configured for, in the order they were configured.
     *
     * `deploy:crontab` uses this to answer the question the configuration cannot
     * answer about itself: whether anything is watched that this build does not
     * schedule — a typo, or a command that was renamed a release ago and whose
     * check has been reporting a healthy silence ever since.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_keys($this->urls);
    }

    /**
     * One entry into its two halves, or an exception naming the entry.
     *
     * Split on the **first** `=` rather than the last or on all of them: a
     * command name cannot contain one and a URL that could is refused below, so
     * the first separator is the only candidate and saying so is cheaper than a
     * regular expression somebody has to read.
     *
     * @return array{string, string}
     */
    private static function split(string $entry, int $position): array
    {
        $separator = strpos($entry, '=');

        if ($separator === false) {
            throw new \InvalidArgumentException(sprintf(
                '%s entry %d is "%s", which has no "=" in it. Each entry is a command name, '
                . 'an "=", and the ping URL the monitoring service gave you — for example '
                . '"signup:provision=https://hc-ping.com/<uuid>".',
                self::VARIABLE,
                $position + 1,
                $entry,
            ));
        }

        $command = trim(substr($entry, 0, $separator));
        $url = trim(substr($entry, $separator + 1));

        if ($command === '' || $url === '') {
            throw new \InvalidArgumentException(sprintf(
                '%s entry %d is "%s", which is missing its %s.',
                self::VARIABLE,
                $position + 1,
                $entry,
                $command === '' ? 'command name' : 'URL',
            ));
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(sprintf(
                '%s entry %d gives "%s" as the ping URL for "%s", which is not an absolute URL.',
                self::VARIABLE,
                $position + 1,
                $url,
                $command,
            ));
        }

        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s entry %d gives "%s" as the ping URL for "%s". A ping is an HTTP request, so '
                . 'the scheme has to be http or https.',
                self::VARIABLE,
                $position + 1,
                $url,
                $command,
            ));
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            // The reason is in the class docblock: the outcome is reported by
            // appending a path segment, and there is no way to append one to a
            // URL that already ends in a query string without changing what it
            // addresses. Every service in §4.5 issues a plain path, so this is a
            // typo or a copied browser address bar rather than a shape anybody
            // needs.
            throw new \InvalidArgumentException(sprintf(
                '%s entry %d gives "%s" as the ping URL for "%s". A ping URL must be a plain '
                . 'path with no query string and no fragment, because the exit code is reported '
                . 'by appending a segment to it.',
                self::VARIABLE,
                $position + 1,
                $url,
                $command,
            ));
        }

        return [$command, rtrim($url, '/')];
    }
}
