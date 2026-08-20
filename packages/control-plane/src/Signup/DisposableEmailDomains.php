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

namespace Xivi\ControlPlane\Signup;

/**
 * The addresses this installation will not make a customer out of (XIV-125).
 *
 * §8.12 bounded how *fast* signups arrive and said nothing about who is making
 * them, which was the right scope for an endpoint that wrote one row. [XIV-98]
 * changed what an accepted signup costs: a confirmed one becomes a PostgreSQL
 * database and a PostgreSQL role, so a hundred signups made from a ten-minute
 * mailbox are a hundred databases, a hundred roles, and a tenant list in which
 * the real customers are the minority.
 *
 * ## The judgement this class is built around
 *
 * **Refusing a real business is worse than admitting a throwaway.** A throwaway
 * signup costs an operator a `tenant:deprovision` they can see coming on the
 * list; a wrongly refused business is a customer who is told to use a different
 * address, does not, and never finds out why. Every decision below falls out of
 * that asymmetry, and the first of them is the one that is easiest to get
 * wrong:
 *
 * **A free mailbox is not a throwaway mailbox.** `gmail.com` is where a great
 * many one-person companies read their post, and so are `gmx.ch`, `bluewin.ch`,
 * `outlook.com` and `web.de`. Blocking them would refuse exactly the customer
 * this product is for, in the name of a defence against somebody who needed no
 * defending against: a Gmail address costs a phone number and a name, and
 * nobody registers a hundred of them to spend a hundred afternoons confirming
 * signups. What this list is about is the service whose entire product is *an
 * address you will never see again*: no password, no recovery, discarded in ten
 * minutes, often published on a page anybody can read. That is the thing an
 * automated signup run uses, and it is a small, well-known and slow-moving set.
 * `DisposableEmailDomainsTest` asserts the distinction from both sides rather
 * than leaving it to this paragraph.
 *
 * ## Where the list comes from, which is a maintenance question before it is a
 * technical one
 *
 * **Rejected: shipping a public list.** The large ones (`disposable-email-
 * domains` and its several forks) carry thousands of entries and are permissive
 * enough to vendor, so the licence is not what refuses them. Two other things
 * do. The first is that a vendored copy is a dependency nobody updates: it is
 * right on the day it is added and is quietly a year old by the second release,
 * with no test that can fail about it. The second is worse and is the reason
 * this is not a close call: those lists accept entries by pull request from
 * strangers, so a domain arrives on them for reasons this project never sees,
 * and the failure it produces here is a *silent refusal of a real company*. Six
 * thousand entries nobody in this repository has read is six thousand chances
 * at the one failure the ticket says must not happen.
 *
 * **Rejected: fetching one at run time.** It inherits the supply-chain problem
 * above, removes the diff a person could have reviewed, and adds a network call
 * to the path of an anonymous request. It then needs an answer to "what happens
 * when the fetch fails", and both answers are bad: failing open makes the
 * defence disappear on the day somebody's CDN is down, and failing closed turns
 * a third party's outage into a signup form that refuses everybody.
 *
 * **Adopted: a short list, kept by hand, in this file.** It is unglamorous and
 * it catches most of the volume, because throwaway mail is a handful of large
 * services with a long tail of clones nobody uses. Every entry is a line
 * somebody wrote in a diff that somebody else read, which is precisely the
 * property the public lists cannot offer, and it is the property that keeps the
 * refusal narrow.
 *
 * **How it is maintained, stated rather than implied.** Adding or removing a
 * domain is a change to this file, and the signal that one is needed is on the
 * operator's own screen: every refusal is counted by domain
 * ({@see \Xivi\ControlPlane\Entity\SignupRefusal}) and drawn on the tenant list,
 * so a domain that should never have been here shows up as refusals of a name
 * an operator recognises, and one that is missing shows up as customers nobody
 * can reach. There is deliberately **no environment variable** that extends or
 * overrides this: the reviewed diff is the whole argument for choosing a hand
 * list over a public one, and a deployment that can add domains from a `.env`
 * file has quietly gone back to a list nobody read.
 *
 * ## Matching
 *
 * The domain itself, or any subdomain of it. Mailinator hands out
 * `anything.mailinator.com` and Guerrilla Mail has done the same, so an exact
 * match alone would be a defence one dot deep. That widening is safe *because*
 * the list is short and hand-picked: every entry names a whole service, and a
 * service's subdomains belong to the same service. It would not be safe over a
 * list of six thousand entries somebody else maintains, which is the same
 * argument arriving from the other end.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DisposableEmailDomains
{
    /**
     * The services, with one comment per family rather than per line.
     *
     * Alias domains are listed beside their parent instead of being derived,
     * because they are not derivable: `sharklasers.com` and `grr.la` are
     * Guerrilla Mail and nothing about the strings says so. **Grouped by service
     * rather than sorted flat**, which is what makes the list reviewable: the
     * question somebody asks of it is *is this a service real people keep
     * mailboxes at*, and that question is about the family, not about the
     * string. The families are in alphabetical order; the aliases inside one are
     * not, and a test asserts uniqueness and lowercasing rather than an order.
     *
     * @var list<string>
     */
    public const array DOMAINS = [
        // 10 Minute Mail, and the whole product is in the name.
        '10minutemail.com',
        '10minutemail.net',
        // Dispostable.
        'dispostable.com',
        // Fake Inbox.
        'fakeinbox.com',
        // Guerrilla Mail, the largest of these, which answers on several names
        // at once. `grr.la` and `sharklasers.com` are the two that do not look
        // related to it.
        'grr.la',
        'guerrillamail.biz',
        'guerrillamail.com',
        'guerrillamail.de',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamailblock.com',
        'sharklasers.com',
        'spam4.me',
        // Inbox Kitten.
        'inboxkitten.com',
        // Maildrop.
        'maildrop.cc',
        // Mailinator, the other large one, and the reason the match covers
        // subdomains: every address it hands out can be taken under one.
        'mailinator.com',
        'mailinator.net',
        // Mailnesia.
        'mailnesia.com',
        // Mohmal.
        'mohmal.com',
        // Temp Mail.
        'temp-mail.org',
        // Throwaway Mail.
        'throwawaymail.com',
        // TrashMail, which spells itself both ways.
        'trash-mail.com',
        'trashmail.com',
        'trashmail.de',
        // YOPmail.
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
    ];

    /**
     * Whether this address belongs to one of them.
     *
     * Takes the whole address rather than a domain so that there is one place
     * that decides what the domain of an address is. The intake has an address
     * in hand and nothing else, and a caller splitting the string itself is a
     * caller who will one day split it differently from this class.
     *
     * Lowercased here as well as at the boundary. {@see SignupSubmission} already
     * does it for everything arriving over HTTP, and this is a check whose
     * failure is silent: an uppercase domain that slipped past would be admitted
     * rather than throwing, so the belt is worth its two microseconds.
     */
    public function covers(string $email): bool
    {
        $domain = self::domainOf($email);

        if ($domain === '') {
            return false;
        }

        foreach (self::DOMAINS as $listed) {
            if ($domain === $listed || str_ends_with($domain, '.' . $listed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The domain an address is at, lowercased, or the empty string when there is
     * not one.
     *
     * `strrpos` rather than `explode`, because an address may legally contain an
     * `@` in a quoted local part and the domain is what follows the *last* one.
     * The empty string for anything without an `@` at all: this is only ever
     * reached for a value `SignupIntake` has already validated, so a missing
     * domain is not a case that happens, and inventing an exception for it would
     * be a second way for a public endpoint to fail.
     *
     * Public because the refusal is counted by domain and the counter needs the
     * same answer this check used. Two implementations of "the domain of an
     * address" would be one place where the count and the refusal disagree.
     */
    public static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return '';
        }

        // A trailing dot is legal in a fully qualified name and means the same
        // domain, so `guerrillamail.com.` must not be a way past this.
        return rtrim(mb_strtolower(substr($email, $at + 1)), '.');
    }
}
