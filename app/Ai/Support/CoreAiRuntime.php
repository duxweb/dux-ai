<?php

declare(strict_types=1);

namespace App\Ai\Support;

use Core\App;
use Core\Event\Event;
use Illuminate\Database\Capsule\Manager;
use Monolog\Level;
use Monolog\Logger;

final class CoreAiRuntime implements AiRuntimeInterface
{
    public function event(): Event
    {
        return App::event();
    }

    public function db(): Manager
    {
        return App::db();
    }

    public function log(string $channel = 'app', Level $level = Level::Debug): Logger
    {
        return App::log($channel, $level);
    }
}

