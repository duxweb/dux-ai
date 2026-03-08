<?php

declare(strict_types=1);

namespace App\Ai\Support;

use Core\App;
use Core\Event\Event;
use Illuminate\Database\Capsule\Manager;
use Monolog\Level;
use Monolog\Logger;

/**
 * Ai runtime context accessor.
 *
 * Default implementation uses Core\App, but can be replaced for tests/CLI.
 */
final class AiRuntime
{
    private static ?AiRuntimeInterface $instance = null;

    public static function set(?AiRuntimeInterface $runtime): void
    {
        self::$instance = $runtime;
        if ($runtime) {
            App::di()->set(AiRuntimeInterface::class, $runtime);
        }
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function runtime(): AiRuntimeInterface
    {
        if (self::$instance) {
            return self::$instance;
        }

        $runtime = new CoreAiRuntime();
        self::$instance = $runtime;
        App::di()->set(AiRuntimeInterface::class, $runtime);

        return $runtime;
    }

    public static function instance(): AiRuntimeInterface
    {
        return self::runtime();
    }

    public static function event(): Event
    {
        return self::runtime()->event();
    }

    public static function db(): Manager
    {
        return self::runtime()->db();
    }

    public static function log(string $channel = 'app', Level $level = Level::Debug): Logger
    {
        return self::runtime()->log($channel, $level);
    }
}
