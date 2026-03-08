<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Toolkit;

use App\Ai\Support\AiRuntime;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make()
 */
final class SystemToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return '系统工具包，提供日志记录等基础能力。';
    }

    public function provide(): array
    {
        return [
            Tool::make('system_log', '写入系统日志')
                ->addProperty(ToolProperty::make('level', PropertyType::STRING, '日志级别(info|warning|error)', false))
                ->addProperty(ToolProperty::make('message', PropertyType::STRING, '日志内容', true))
                ->addProperty(ToolProperty::make('context', PropertyType::STRING, '上下文(JSON字符串)', false))
                ->setCallable(static function (?string $level = 'info', string $message = '', ?string $context = null): array {
                    $logger = AiRuntime::instance()->log('ai.toolkit.system');
                    $payload = [];
                    if (is_string($context) && $context !== '' && json_validate($context)) {
                        $decoded = json_decode($context, true);
                        if (is_array($decoded)) {
                            $payload = $decoded;
                        }
                    }

                    $level = strtolower(trim((string)$level));
                    if ($level === 'warning') {
                        $logger->warning($message, $payload);
                    } elseif ($level === 'error') {
                        $logger->error($message, $payload);
                    } else {
                        $logger->info($message, $payload);
                    }

                    return ['ok' => true];
                }),
        ];
    }
}
