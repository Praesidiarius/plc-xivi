<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Support;

use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Saving a record the way the application does it (XIV-33).
 *
 * The record form is a Live Component, so there is no page to fill in and
 * submit: saving is an action on the component, reached through the library's
 * own harness. Every test that used to press "Save" goes through here.
 *
 * **What this costs, said once so it is not rediscovered per test.** These tests
 * no longer drive what a person drives — they call the component directly,
 * never rendering the page it sits on and never touching the route somebody
 * visits. That is the trade the framework decision made, and it is why the
 * browser tests exist (XIV-31): they are the only thing left that presses a
 * button.
 *
 * The using class provides `$this->client`, `$this->tenant`, and the constants
 * `HOST` and `EMAIL` — the same shape every functional test here already has.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
trait SavesRecords
{
    use InteractsWithLiveComponents;

    /**
     * Save a record, and give back what the component answered.
     *
     * A redirect means it saved; a rendered body means it refused, and the
     * messages are in it.
     *
     * @param array<string, mixed>                      $fields       the record's own values
     * @param array<string, list<array<string, mixed>>> $rows         collection rows, keyed by collection
     * @param string|null                               $as           who is saving; the class's own
     *                                                                `EMAIL` unless a test is about
     *                                                                somebody else's permissions
     * @param array<string, list<array<string, mixed>>> $seeded       rows the component is mounted
     *                                                                with (XIV-19)
     * @param array<string, mixed>                      $seededFields and the values beside them
     */
    protected function saveRecord(
        string $module,
        array $fields,
        array $rows = [],
        ?int $recordId = null,
        ?string $variant = null,
        ?string $as = null,
        array $seeded = [],
        array $seededFields = [],
    ): Response {
        // The live-component endpoint is one global route and this application
        // resolves the customer from the host, so the harness's client has to be
        // told which tenant it is talking to.
        $this->client->setServerParameter('HTTP_HOST', static::HOST);

        $switcher = self::liveService(TenantSwitcher::class);
        $user = $this->signingUser($as);

        $values = ['module_record' => ['fields' => $fields]];

        if ($rows !== []) {
            $values['module_record']['collections'] = $rows;
        }

        // The same props the page mounts the component with. They matter more
        // than they look: a *derived* field keeps its value from the form's
        // initial data rather than from what is submitted, so a component
        // mounted without its seeding loses the back-reference an invoice line
        // holds to the order line it came from (XIV-19).
        $props = ['module' => $module];

        if ($seeded !== []) {
            $props['seeded'] = $seeded;
        }

        if ($seededFields !== []) {
            $props['seededFields'] = $seededFields;
        }

        if ($recordId !== null) {
            $props['recordId'] = $recordId;
        }

        if ($variant !== null) {
            $props['variant'] = $variant;
        }

        $response = $switcher->runFor($this->tenant, fn (): Response => $this
            ->createLiveComponent('RecordForm', $props, $this->client)
            ->actingAs($user)
            // The whole form's values in one write, rather than `submitForm()`,
            // which flattens them to dotted paths and does not reliably reach
            // rows a collection does not have yet — and every row here is one
            // the form has never seen.
            ->set('module_record', $values['module_record'])
            ->call('save')
            ->response());

        // The harness turns exception catching off so a broken component shows
        // its stack rather than a 500 page — and it does that on the *shared*
        // client, so the next ordinary request in the same test would throw
        // where it used to be answered with a 403.
        $this->client->catchExceptions(true);

        return $response;
    }

    /**
     * The record form itself, ready to be acted on.
     *
     * For the tests that are about the form rather than about saving — that a
     * derived field is shown and disabled, that a button adds a row of its own
     * kind. They render the component; nothing renders the page it sits on,
     * which is what the browser tests are for (XIV-31).
     *
     * @param array<string, mixed> $props beyond the module: `recordId`, `variant`
     */
    protected function recordForm(string $module, array $props = []): TestLiveComponent
    {
        $this->client->setServerParameter('HTTP_HOST', static::HOST);

        $user = $this->signingUser(null);

        return $this
            ->createLiveComponent('RecordForm', ['module' => $module, ...$props], $this->client)
            ->actingAs($user);
    }

    /**
     * The values a rendered record form is showing, shaped as it would submit
     * them.
     *
     * For the pages that arrive already filled in — an invoice seeded from an
     * order (XIV-19) — where the test's business is what somebody *changes*
     * rather than what they type from nothing. Reading them off the page is what
     * a browser does, and it keeps the seeding itself under test rather than
     * reimplemented here.
     *
     * @return array<string, mixed>
     */
    protected static function formValuesOn(Crawler $page): array
    {
        /** @var array<string, mixed> $values */
        $values = [];

        foreach ($page->filter('[name^="module_record["]') as $node) {
            \assert($node instanceof \DOMElement);

            $name = $node->getAttribute('name');
            $value = self::valueOf($node);

            if (preg_match_all('/\[([^\]]*)\]/', $name, $keys) === 0) {
                continue;
            }

            self::place($values, $keys[1], $value);
        }

        return $values;
    }

    /**
     * Put one value at the end of a path of keys, making the way as it goes.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $keys
     */
    private static function place(array &$values, array $keys, string $value): void
    {
        $key = array_shift($keys);

        if ($key === null) {
            return;
        }

        if ($keys === []) {
            $values[$key] = $value;

            return;
        }

        if (!\is_array($values[$key] ?? null)) {
            $values[$key] = [];
        }

        /** @var array<string, mixed> $nested */
        $nested = $values[$key];
        self::place($nested, $keys, $value);
        $values[$key] = $nested;
    }

    /**
     * What one control is currently showing.
     *
     * Three shapes, because HTML has three: an input carries its value in an
     * attribute, a textarea in its body, and a select on whichever option is
     * marked — reading `value` off a `<select>` gets nothing at all, which
     * arrives later as "this value should not be null" about a field that looked
     * filled in.
     */
    private static function valueOf(\DOMElement $node): string
    {
        if ($node->nodeName === 'textarea') {
            return $node->textContent;
        }

        if ($node->nodeName !== 'select') {
            return $node->getAttribute('value');
        }

        foreach ($node->getElementsByTagName('option') as $option) {
            if ($option->hasAttribute('selected')) {
                return $option->getAttribute('value');
            }
        }

        return '';
    }

    /**
     * The id of the record a save redirected to.
     *
     * Fails loudly rather than returning zero: a test that reads an id out of a
     * refusal would go on to assert about a record that was never written.
     */
    protected function savedId(Response $response): int
    {
        $location = (string) $response->headers->get('Location');

        // The refusal carries its reasons in the body, and a test that only said
        // "no redirect" would send somebody hunting for them.
        self::assertNotSame('', $location, sprintf(
            "the save was refused:\n%s",
            implode("\n", array_unique(array_map(
                'strip_tags',
                (array) (preg_match_all('#<[^>]*invalid-feedback[^>]*>(.*?)</#s', (string) $response->getContent(), $m) ? $m[1] : []),
            ))) ?: 'no messages in the response',
        ));

        return (int) basename((string) parse_url($location, \PHP_URL_PATH));
    }

    /**
     * One row of a collection, as the form would submit it.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected static function row(array $fields, ?int $id = null, ?int $position = null): array
    {
        return [
            'id' => $id === null ? '' : (string) $id,
            'position' => $position === null ? '' : (string) $position,
            'fields' => $fields,
        ];
    }

    /** Whoever the component is acting as; a missing one is a broken fixture. */
    private function signingUser(?string $as): User
    {
        $user = self::liveService(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ?User => self::liveService(UserRepository::class)->findOneByEmail($as ?? static::EMAIL),
        );

        self::assertInstanceOf(User::class, $user, 'the user a record is saved as exists');

        return $user;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function liveService(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
