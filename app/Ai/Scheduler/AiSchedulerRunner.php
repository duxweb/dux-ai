<?php

declare(strict_types=1);

namespace App\Ai\Scheduler;

use App\Ai\Service\Scheduler\AiSchedulerService;
use Core\App;
use Core\Scheduler\Attribute\Scheduler;

final class AiSchedulerRunner
{
    #[Scheduler(name: 'ai_scheduler_runner', cron: '* * * * *', desc: 'AI Scheduler')]
    public function handle(array $params = []): void
    {
        $limit = max(1, (int)($params['limit'] ?? 100));
        $count = AiSchedulerService::dispatchDueJobs($limit);

        App::log('ai_scheduler')->info('ai.scheduler.scan', [
            'limit' => $limit,
            'dispatched' => $count,
            'time' => date('Y-m-d H:i:s'),
        ]);
    }
}
