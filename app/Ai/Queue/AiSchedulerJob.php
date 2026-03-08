<?php

declare(strict_types=1);

namespace App\Ai\Queue;

use App\Ai\Service\Scheduler\AiSchedulerExecutor;
use Core\App;

final class AiSchedulerJob
{
    public function __invoke(int $id): void
    {
        $result = (new AiSchedulerExecutor())->executeById($id);

        App::log('ai_scheduler')->info('ai.scheduler.execute', [
            'id' => $id,
            'result' => $result,
            'time' => date('Y-m-d H:i:s'),
        ]);
    }
}
