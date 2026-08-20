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
 * What the message to a stranded signup will say, shown to the operator who is
 * about to send it (XIV-108, §8.14).
 *
 * Two strings and a class, which looks like more ceremony than the job needs
 * until you write the alternative down: an `array{subject: string, body: string}`
 * threaded from {@see SignupMailer} through {@see StalledSignups} into a view
 * model and out into a template, with the keys spelled correctly at every step
 * or nothing happens and nothing says so. Two named properties cost one file and
 * are checked by the compiler.
 *
 * **Both values are rendered, never composed.** The body is the mail's own
 * plain-text template and the subject is the mail's own catalogue key, both
 * produced in the language the signup recorded. Nothing here builds a sentence
 * of its own, because a preview that is assembled separately from the message is
 * a preview that can be wrong, and being right is the only reason this object
 * exists.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ApologyPreview
{
    /**
     * Private, like every other value object in this namespace, and for a reason
     * that is about the container rather than about taste: this package's
     * `services.php` loads `Xivi\ControlPlane\` wholesale, so a class here with
     * a public constructor of two strings is a service definition the autowirer
     * is asked to satisfy and cannot. A named factory says the same thing and
     * compiles.
     */
    private function __construct(
        public string $subject,
        public string $body,
    ) {
    }

    public static function of(string $subject, string $body): self
    {
        return new self($subject, $body);
    }
}
