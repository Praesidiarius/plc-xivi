<?php

declare(strict_types=1);

// Gives phpstan-doctrine a real entity manager, so DQL and repository generics
// are checked rather than assumed. The control plane is the manager with the
// mapped entities; the tenant manager shares the same configuration.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager('control');
