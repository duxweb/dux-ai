<?php

declare(strict_types=1);

namespace App\Ai\Support;

use Core\Event\Event;
use Illuminate\Database\Capsule\Manager;
use Monolog\Level;
use Monolog\Logger;

interface AiRuntimeInterface
{
    public function event(): Event;

    public function db(): Manager;

    public function log(string $channel = 'app', Level $level = Level::Debug): Logger;
}

