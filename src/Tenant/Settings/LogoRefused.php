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

namespace App\Tenant\Settings;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A logo the application will not store (XIV-49).
 *
 * The same shape as MailSettingsRefused and for the same reason: a refusal is
 * something a person caused and has to be told about in their own language, so
 * the sentence travels with the exception rather than being reconstructed by
 * whichever controller happens to catch it.
 *
 * **Two refusals rather than one**, and the difference is worth the extra case.
 * "That file is too big" is a fact somebody can act on by exporting it smaller;
 * "that is not a PNG or a JPEG" is a fact somebody acts on by exporting it as
 * something else. A single "we could not accept that" would send everybody who
 * tried an SVG looking for a size problem they do not have.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class LogoRefused extends \RuntimeException
{
    private TranslatableMessage $translatable;

    /** What to show the person who caused it, in their language (XIV-8). */
    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    public static function tooLarge(): self
    {
        $refusal = new self(sprintf('A logo may not be larger than %d bytes.', LogoFormat::MAX_BYTES));
        $refusal->translatable = new TranslatableMessage(
            'refusal.logo_too_large',
            // In kibibytes, because that is the unit the number was chosen in and
            // "512 KB" is a size somebody can compare against what their file
            // manager tells them.
            ['%size%' => (string) (LogoFormat::MAX_BYTES / 1024)],
            'messages',
        );

        return $refusal;
    }

    /**
     * Everything the format check refuses lands here: an SVG, a PDF renamed to
     * `.png`, a truncated upload, and an image whose dimensions would flatten the
     * browser drawing it. One sentence for all of them on purpose — the accepted
     * list is short and naming it is more use than diagnosing which of the four
     * ways the file failed.
     */
    public static function notAnImage(string $filename): self
    {
        $refusal = new self(sprintf('"%s" is not a PNG or a JPEG this application will serve.', $filename));
        $refusal->translatable = new TranslatableMessage(
            'refusal.logo_not_an_image',
            ['%filename%' => $filename],
            'messages',
        );

        return $refusal;
    }
}
