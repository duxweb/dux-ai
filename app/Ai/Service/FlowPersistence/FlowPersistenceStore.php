<?php

declare(strict_types=1);

namespace App\Ai\Service\FlowPersistence;

use App\Ai\Models\AiFlowInterrupt;
use NeuronAI\Workflow\Persistence\EloquentPersistence;

final class FlowPersistenceStore
{
    public static function make(): EloquentPersistence
    {
        return new EloquentPersistence(AiFlowInterrupt::class);
    }
}
