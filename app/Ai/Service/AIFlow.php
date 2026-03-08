<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Models\AiFlow as AiFlowModel;
use App\Ai\Service\AIFlow\Service as AIFlowService;
use Core\App;

final class AIFlow
{
    public const DI_KEY = 'ai.flow.service';

    private static ?AIFlowService $service = null;

    public static function setService(?AIFlowService $service): void
    {
        self::$service = $service;
        if ($service) {
            App::di()->set(self::DI_KEY, $service);
        }
    }

    public static function reset(): void
    {
        self::$service = null;
    }

    public static function execute(string|AiFlowModel $flow, array $input = [], array $options = [], ?callable $onNode = null): array
    {
        return self::service()->execute($flow, $input, $options, $onNode);
    }

    /**
     * @return array{status: int, message: string, data: mixed}
     */
    public static function executeFinal(string|AiFlowModel $flow, array $input = [], array $options = []): array
    {
        return self::service()->executeFinal($flow, $input, $options);
    }

    /**
     * @return \Generator<string>
     */
    public static function stream(string|AiFlowModel $flow, array $input = [], array $options = []): \Generator
    {
        return self::service()->stream($flow, $input, $options);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function orderedNodes(string|AiFlowModel $flow): array
    {
        return self::service()->orderedNodes($flow);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getEditorNodes(): array
    {
        return self::service()->getEditorNodes();
    }

    private static function service(): AIFlowService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof AIFlowService) {
                return self::$service = $resolved;
            }
        }

        $instance = new AIFlowService();
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}

