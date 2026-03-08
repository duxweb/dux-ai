<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Service\Parse\ParseFactory;
use Core\App\AppExtend;
use Core\Bootstrap;

class App extends AppExtend
{
    public function register(Bootstrap $app): void
    {
        ParseFactory::migrateLegacyProviders();
        // Registered by attribute listeners in app/Ai/Listener.
    }
}
