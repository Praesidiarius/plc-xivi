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

namespace App\Registry\Notice;

use App\Registry\Entity\Notice;
use App\Registry\Entity\NoticeAudience;
use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writing a notice down, and taking one back (XIV-120, docs/architecture.md
 * §8.16).
 *
 * ## A writer in `src/` whose only callers are in the administration surface
 *
 * That arrangement already exists and §4.4 names the instances:
 * `ModuleCatalog::moveTo()` and `ModuleCatalog::priceAt()` are in the
 * application and are called only from the `module:*` commands and the operator
 * pricing screen, both of which are in `packages/control-plane` and therefore
 * absent from the customer-facing image. This is the third, and it is the same
 * shape for the same reason: the *entity* has to be `App\Registry\Entity` for the
 * customer-facing role to be granted `SELECT` on its table (see {@see Notice}),
 * and a class that writes those rows cannot be anywhere the application may not
 * depend on.
 *
 * **The guarantee is still the grant and not this file's reachability.** §4.4
 * says so at length: a method that cannot be called today is one refactor from
 * being called, and what stops a customer's instance writing a notice is a
 * database role with no `INSERT`. `RegistryGrantsTest` and `NoticeGrantsTest`
 * are where that is proved; this docblock is where somebody adding a call site
 * is asked to think about it.
 *
 * ## Everything it refuses, and why each refusal is here rather than on a form
 *
 * A form is one caller. These are conditions under which a notice would be a
 * lie or a no-op, and the class that writes the row is the last place that can
 * tell:
 *
 * * **Nobody addressed.** "Named customers" with no names is a notice with no
 *   readers, which is worse than none at all — the operator believes they have
 *   told somebody.
 * * **A customer that does not exist.** A slug that resolves to nothing is
 *   usually a customer who has been deprovisioned since the page was opened, and
 *   silently dropping them would mean an operator addressing four companies and
 *   reaching three.
 * * **An expiry that has already passed.** A notice nobody will ever see,
 *   published successfully. The clock is what makes this reachable rather than
 *   silly: a form left open over lunch.
 * * **An empty title or body.** Not validation theatre — this row is drawn on a
 *   customer's landing page, and an empty card there is a defect somebody
 *   reports.
 *
 * The messages are sentences rather than translation keys, for the reason
 * {@see \Xivi\ControlPlane\Controller\ModulePricingController} gives about
 * `module:state`'s refusals: they are read by an operator, who is one of us, and
 * they are diagnostics rather than page copy.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NoticeBoard
{
    /** The column is `VARCHAR(200)`; refused here so the failure is a sentence rather than a driver exception. */
    private const int MAX_TITLE = 200;

    public function __construct(
        private EntityManagerInterface $control,
        private TenantRepository $tenants,
    ) {
    }

    /**
     * Publishes a notice, from now.
     *
     * @param list<string>|null $tenantSlugs null addresses every customer of this
     *                                       installation; a list names them. The
     *                                       two are alternatives rather than a
     *                                       list that happens to be empty — see
     *                                       {@see Notice} for why an empty list is
     *                                       refused instead of meaning everybody
     *
     * @throws \InvalidArgumentException when the notice would be a lie or a no-op
     */
    public function publish(
        string $title,
        string $body,
        NoticeAudience $audience,
        ?array $tenantSlugs,
        /*
         * Who is publishing this, copied onto the row and shown to customers.
         *
         * Required rather than defaulted, because the default would be a blank
         * line under the title on somebody's dashboard — an announcement with no
         * author, which is most of the difference between a notice and a popup.
         * The caller decides what to write when there is no operator to name; the
         * operator screen writes an em dash.
         */
        string $authorLabel,
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $now = null,
    ): Notice {
        $now ??= new \DateTimeImmutable();
        $title = trim($title);
        $body = trim($body);

        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('A notice needs a title and something to say.');
        }

        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new \InvalidArgumentException(sprintf(
                'A notice title is at most %d characters; this one is %d. The body is the place for the rest.',
                self::MAX_TITLE,
                mb_strlen($title),
            ));
        }

        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new \InvalidArgumentException(
                'That notice would expire before anybody could read it; leave the end date empty or put it in the future.',
            );
        }

        $recipients = $tenantSlugs === null ? null : $this->resolve($tenantSlugs);

        $notice = new Notice(
            $title,
            $body,
            $audience,
            everyTenant: $recipients === null,
            authorLabel: $authorLabel,
            publishedAt: $now,
        );

        $notice->expireAt($expiresAt);

        foreach ($recipients ?? [] as $tenant) {
            $notice->addRecipient($tenant);
        }

        $this->control->persist($notice);
        $this->control->flush();

        return $notice;
    }

    /**
     * Stops showing a notice, from now.
     *
     * Not a delete, which is the decision worth stating: an operator who
     * announced a maintenance window and then withdrew it has done two things
     * that both happened, and a customer who is asked "did you not see the
     * notice?" is entitled to a system where the answer is knowable. The row
     * stays on the operator's screen, expired.
     */
    public function withdraw(Notice $notice, ?\DateTimeImmutable $now = null): void
    {
        $notice->withdraw($now ?? new \DateTimeImmutable());

        $this->control->flush();
    }

    public function find(int $id): ?Notice
    {
        return $this->control->find(Notice::class, $id);
    }

    /**
     * Every notice this installation has ever published, newest first.
     *
     * **The operator's screen shows expired ones too**, dimmed, because *"what
     * did we tell them in March"* is a question somebody asks and a list that
     * answered it by having no row would answer it wrongly — the same argument
     * the purchase screen makes for keeping fulfilled requests on the page.
     *
     * The recipients and their tenants are fetch-joined: the screen prints who
     * each notice was addressed to, and doing that lazily would be one query per
     * notice on the page whose whole content is this list.
     *
     * @return list<Notice>
     */
    public function newestFirst(): array
    {
        /** @var list<Notice> $notices */
        $notices = $this->control->createQuery(
            <<<'DQL'
                SELECT n, r, t FROM App\Registry\Entity\Notice n
                LEFT JOIN n.recipients r
                LEFT JOIN r.tenant t
                ORDER BY n.publishedAt DESC, n.id DESC
                DQL,
        )->getResult();

        return $notices;
    }

    /**
     * Slugs to customers, refusing the whole notice if any one of them is not a
     * customer.
     *
     * @param list<string> $slugs
     *
     * @return list<Tenant>
     */
    private function resolve(array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));

        if ($slugs === []) {
            throw new \InvalidArgumentException(
                'A notice addressed to named customers has to name at least one; nothing would ever show it.',
            );
        }

        $tenants = [];

        foreach ($slugs as $slug) {
            $tenant = $this->tenants->findOneBySlug($slug);

            if (!$tenant instanceof Tenant) {
                // All or nothing. Publishing to the three that resolved would
                // leave an operator believing they had reached four, which is
                // this feature's characteristic failure and the one thing it
                // must not do quietly.
                throw new \InvalidArgumentException(sprintf(
                    'There is no customer "%s" on this installation, so nothing was published.',
                    $slug,
                ));
            }

            $tenants[] = $tenant;
        }

        return $tenants;
    }
}
