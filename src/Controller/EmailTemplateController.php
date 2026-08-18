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

namespace App\Controller;

use App\Tenant\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Document\DocumentMarker;
use Xivi\Core\Document\DocumentMarkers;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\EmailTemplate;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Mail\CollectionTables;
use Xivi\Core\Mail\EmailTemplateRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;

/**
 * What a customer's emails say, written here rather than uploaded (XIV-38).
 *
 * The deliberate counterpart to {@see DocumentController}, and the difference
 * between them is the whole point of the ticket. A document template is a .docx
 * somebody designs in Word and uploads, because a letter's layout is design work
 * and Word is where that work happens. An email has no layout worth designing —
 * it is text — so this is a form: a name, a subject and a Markdown body, edited
 * in place. Nobody has to open Word, upload a file, and upload it again to fix a
 * typo.
 *
 * **Its own controller and its own permission.** Writing what an email says is
 * not the same authority as keeping the stationery, and neither is sending one
 * (XIV-39): whoever words the dunning letter is not whoever presses send. That
 * is the same split `templates` and `document` already make, one level along.
 *
 * **The placeholder list is the other half of the page**, exactly as it is on the
 * document side, and it comes from the same class — `DocumentMarkers`, the
 * customer's own field definitions plus the general markers. There is no second
 * list to keep in step, so a field added this morning is a marker in an email
 * this afternoon.
 *
 * **The collections section is here now, and it is not the document one**
 * (XIV-62). The document page offers a token per *column* — `[lines.description]`
 * — because in Word the marker names a column and the table row around it is
 * what repeats. Here one token renders the whole table, so what is offered is
 * `[lines]`, the per-kind `[lines:article]`, and one worked example of the
 * named-column form. Listing the document's tokens instead would be listing a
 * vocabulary that means something else on the page it is printed on.
 *
 * **The image marker is absent for the same reason** (XIV-89). `[tenant.logo]`
 * becomes a `<w:drawing>` in a .docx, which is an operation on Word's XML and
 * has no counterpart in a Markdown body: putting a picture in an email means a
 * `<img>` and therefore a URL a recipient's mail client will fetch — or a CID
 * attachment — and choosing between those is a design question about email
 * rather than a line of code missing here. Until it is answered the marker does
 * in an email what every unfilled marker does, which is come out blank, and a
 * list is not the place to offer that.
 *
 * **Hand-rolled POSTs rather than a FormType**, like DocumentController,
 * FieldController and the rest of the administrative screens. The Symfony way
 * would be the form component and this is the exception the house rule allows:
 * these are three text inputs and a select, next to a sibling page that has
 * none, and one screen built differently from the five beside it costs more than
 * it buys. The record forms — where a customer's own definitions decide the
 * fields — are where the component earns its keep, and it is used there.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}/email-templates', requirements: ['module' => '[a-z][a-z0-9_]*'])]
#[IsGranted(ModuleAction::EmailTemplates->value, subject: 'module')]
final class EmailTemplateController extends AbstractController
{
    private const string CSRF = 'module-email-templates';

    /** What the column holds, and therefore what the form may accept. */
    private const int MAX_LINE = 255;

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly EmailTemplateRepository $templates,
        private readonly DocumentMarkers $markers,
        private readonly CollectionTables $tables,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** Everything this module can send, and nothing about any one record. */
    #[Route('', name: 'email_template_index', methods: ['GET'])]
    public function index(string $module): Response
    {
        $definition = $this->definition($module);

        return $this->render('email_template/index.html.twig', [
            'module' => $definition,
            'templates' => $this->templates->forModule($definition->getKey()),
            'variants' => $definition->getVariants(),
        ]);
    }

    /** The blank form, and the placeholders it may use. */
    #[Route('/new', name: 'email_template_new', methods: ['GET'])]
    public function new(string $module): Response
    {
        return $this->form($this->definition($module), null, self::blank());
    }

    #[Route('', name: 'email_template_create', methods: ['POST'])]
    public function create(string $module, Request $request): Response
    {
        $definition = $this->definition($module);

        if (!$this->submitted($request)) {
            return $this->redirectToRoute('email_template_index', ['module' => $module]);
        }

        $draft = self::draftOf($request);
        $problem = $this->problemWith($draft);

        if ($problem !== null) {
            // Back to the form with what they typed still in it. A body somebody
            // spent ten minutes on is not something to lose because the subject
            // line was empty.
            $this->addFlash('warning', $problem);

            return $this->form($definition, null, $draft);
        }

        $this->templates->save(new EmailTemplate(
            $definition->getKey(),
            $draft['name'],
            $draft['subject'],
            $draft['body'],
            $this->variantOf($definition, $draft['variant']),
            $this->currentUserLabel(),
        ));

        $this->addFlash('success', $this->translator->trans('flash.email_template_saved'));

        return $this->redirectToRoute('email_template_index', ['module' => $module]);
    }

    /** The same form, with one template's words in it. */
    #[Route('/{template}', name: 'email_template_edit', requirements: ['template' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function edit(string $module, int $template): Response
    {
        $definition = $this->definition($module);
        $found = $this->template($definition, $template);

        return $this->form($definition, $found, [
            'name' => $found->getName(),
            'subject' => $found->getSubject(),
            'body' => $found->getBody(),
            'variant' => $found->getVariant() ?? '',
        ]);
    }

    #[Route('/{template}', name: 'email_template_update', requirements: ['template' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function update(string $module, int $template, Request $request): Response
    {
        $definition = $this->definition($module);
        $found = $this->template($definition, $template);

        if (!$this->submitted($request)) {
            return $this->redirectToRoute('email_template_index', ['module' => $module]);
        }

        $draft = self::draftOf($request);
        $problem = $this->problemWith($draft);

        if ($problem !== null) {
            $this->addFlash('warning', $problem);

            return $this->form($definition, $found, $draft);
        }

        $found->rewrite(
            $draft['name'],
            $draft['subject'],
            $draft['body'],
            $this->variantOf($definition, $draft['variant']),
            $this->currentUserLabel(),
        );

        $this->templates->save($found);
        $this->addFlash('success', $this->translator->trans('flash.email_template_saved'));

        return $this->redirectToRoute('email_template_index', ['module' => $module]);
    }

    #[Route('/{template}/delete', name: 'email_template_delete', requirements: ['template' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function delete(string $module, int $template, Request $request): Response
    {
        $definition = $this->definition($module);
        $found = $this->template($definition, $template);

        if ($this->submitted($request)) {
            $this->templates->remove($found);
            $this->addFlash('success', $this->translator->trans('flash.email_template_deleted', ['%template%' => $found->getName()]));
        }

        return $this->redirectToRoute('email_template_index', ['module' => $module]);
    }

    /**
     * The write form, whether it is a new template or an old one.
     *
     * One template and one method for both, because the two differ in exactly
     * two things — where the form posts to, and whether anything is in it — and
     * a second copy would be a second place to add the next field to.
     *
     * @param array{name: string, subject: string, body: string, variant: string} $draft
     */
    private function form(ModuleDefinition $definition, ?EmailTemplate $template, array $draft): Response
    {
        return $this->render('email_template/form.html.twig', [
            'module' => $definition,
            'template' => $template,
            'draft' => $draft,
            'variants' => $definition->getVariants(),
            // The reason the page is worth opening: somebody writing an email
            // has to know what they may type, and the answer is this customer's
            // own definitions rather than documentation that goes stale the
            // first time they add a field. The very same lists the document page
            // draws, from the very same class (XIV-38).
            'markers' => $this->markersPerVariant($definition),
            // The rows a message lists rather than states (XIV-62). Its own
            // section, and not the document page's section with a different
            // heading: there a marker names a column, and here one marker is the
            // whole table.
            'collections' => $this->collectionMarkers($definition),
            // Minus the ones that draw rather than write — see the class
            // docblock. Filtered here rather than by a second method on
            // `DocumentMarkers`, because the reason is a fact about emails and
            // not about the vocabulary: the marker is real, the engine knows it,
            // and this one page cannot honour it.
            'general' => array_values(array_filter(
                $this->markers->general(),
                static fn (DocumentMarker $marker): bool => !$marker->isImage(),
            )),
            'maxLine' => self::MAX_LINE,
        ]);
    }

    /**
     * The reference list, per variant.
     *
     * Keyed by variant so the page can label each one; a module without variants
     * gets a single list under an empty key. Deliberately the same shape
     * DocumentController builds — it is the same question about the same
     * definitions.
     *
     * @return array<string, list<DocumentMarker>>
     */
    private function markersPerVariant(ModuleDefinition $definition): array
    {
        $variants = array_keys($definition->getVariants());

        if ($variants === []) {
            return ['' => $this->markers->forShape($definition, null)];
        }

        $lists = [];

        foreach ($variants as $variant) {
            $lists[$variant] = $this->markers->forShape($definition, $variant);
        }

        return $lists;
    }

    /**
     * The collections this module has, with the tokens that render each one
     * (XIV-62).
     *
     * A derived collection is offered like any other, deliberately: an order's
     * VAT breakdown is a table nobody types into and exactly the sort of thing
     * somebody wants restated in an email. "Nobody can edit it" is a fact about
     * the form, not about whether it can be read out.
     *
     * @return list<array{collection: CollectionDefinition, markers: list<DocumentMarker>, example: string}>
     */
    private function collectionMarkers(ModuleDefinition $definition): array
    {
        $sections = [];

        foreach ($definition->getCollections() as $collection) {
            $sections[] = [
                'collection' => $collection,
                'markers' => $this->tables->markersFor($collection),
                // What `[lines]` would have to be written as to say the same
                // thing column by column, built by the renderer itself so the
                // example on the screen cannot drift from what typing it does.
                'example' => $this->tables->exampleFor($collection),
            ];
        }

        return $sections;
    }

    /**
     * What is wrong with what they typed, or null.
     *
     * All three are required and none has a sensible default: a template with no
     * subject would send a blank subject line, and one with no body would send
     * an empty email. The document side can fall back to the file's name because
     * there is a file; here there is nothing to fall back to.
     *
     * @param array{name: string, subject: string, body: string, variant: string} $draft
     */
    private function problemWith(array $draft): ?string
    {
        if ($draft['name'] === '' || $draft['subject'] === '' || $draft['body'] === '') {
            return $this->translator->trans('email_template.incomplete');
        }

        if (mb_strlen($draft['name']) > self::MAX_LINE || mb_strlen($draft['subject']) > self::MAX_LINE) {
            return $this->translator->trans('email_template.too_long', ['%max%' => self::MAX_LINE]);
        }

        return null;
    }

    /**
     * @return array{name: string, subject: string, body: string, variant: string}
     */
    private static function draftOf(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name')),
            'subject' => trim((string) $request->request->get('subject')),
            // Not trimmed beyond its ends: the indentation inside a Markdown
            // body is content, and a code block or a nested list would stop
            // being one if this went any further than the outer whitespace.
            'body' => trim((string) $request->request->get('body')),
            'variant' => (string) $request->request->get('variant', ''),
        ];
    }

    /**
     * The form offers only this module's variants, so anything else is a
     * hand-edited request and becomes "every record" rather than an error nobody
     * can act on — the same call the document upload makes.
     */
    private function variantOf(ModuleDefinition $definition, string $variant): ?string
    {
        return isset($definition->getVariants()[$variant]) ? $variant : null;
    }

    /** @return array{name: string, subject: string, body: string, variant: string} */
    private static function blank(): array
    {
        return ['name' => '', 'subject' => '', 'body' => '', 'variant' => ''];
    }

    private function template(ModuleDefinition $definition, int $id): EmailTemplate
    {
        return $this->templates->find($definition->getKey(), $id) ?? throw $this->createNotFoundException();
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function currentUserLabel(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getName() : null;
    }

    private function submitted(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'));
    }
}
