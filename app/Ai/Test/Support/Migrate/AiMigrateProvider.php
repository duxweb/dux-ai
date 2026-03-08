<?php

declare(strict_types=1);

namespace App\Ai\Test\Support\Migrate;

final class AiMigrateProvider
{
    /**
     * @return array<int, class-string>
     */
    public static function models(): array
    {
        return [
            \App\Ai\Models\AiAgent::class,
            \App\Ai\Models\AiAgentSession::class,
            \App\Ai\Models\AiAgentMessage::class,
            \App\Ai\Models\AiFlow::class,
            \App\Ai\Models\AiFlowLog::class,
            \App\Ai\Models\AiFlowInterrupt::class,
            \App\Ai\Models\AiModel::class,
            \App\Ai\Models\AiProvider::class,
            \App\Ai\Models\AiScheduler::class,
            \App\Ai\Models\AiToken::class,
            \App\Ai\Models\AiVector::class,
            \App\Ai\Models\ParseProvider::class,
            \App\Ai\Models\RagKnowledge::class,
            \App\Ai\Models\RagKnowledgeData::class,
            \App\Ai\Models\RegProvider::class,
        ];
    }
}
