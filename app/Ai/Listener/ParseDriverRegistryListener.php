<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Event\ParseDriverEvent;
use App\Ai\Service\Parse\Drivers\BaiduPaddleCloudDriver;
use App\Ai\Service\Parse\Drivers\BigModelDriver;
use App\Ai\Service\Parse\Drivers\LocalDriver;
use App\Ai\Service\Parse\Drivers\MoonshotDriver;
use App\Ai\Service\Parse\Drivers\VolcengineDriver;
use Core\Event\Attribute\Listener;

final class ParseDriverRegistryListener
{
    #[Listener(name: 'ai.parse.driver')]
    public function handle(ParseDriverEvent $event): void
    {
        $event->register('local', LocalDriver::class, LocalDriver::meta());
        $event->register('baidu_paddle_cloud', BaiduPaddleCloudDriver::class, BaiduPaddleCloudDriver::meta());
        $event->register('moonshot', MoonshotDriver::class, MoonshotDriver::meta());
        $event->register('volcengine_doc', VolcengineDriver::class, VolcengineDriver::meta());
        $event->register('bigmodel', BigModelDriver::class, BigModelDriver::meta());
    }
}
