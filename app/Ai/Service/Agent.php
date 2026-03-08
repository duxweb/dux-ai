<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Models\AiAgentMessage;
use App\Ai\Service\Agent\Service as AgentService;
use Core\App;
use Generator;

final class Agent
{
    public const DI_KEY = 'ai.agent.service';

    private static ?AgentService $service = null;

    public static function setService(?AgentService $service): void
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

    /**
     * @return array<string, mixed>
     */
    public static function createSessionByCode(string $agentCode, ?string $userType = null, ?int $userId = null): array
    {
        return self::service()->createSessionByCode($agentCode, $userType, $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listSessionsByCode(?string $agentCode = null, int $limit = 20, ?string $userType = null, ?int $userId = null): array
    {
        return self::service()->listSessionsByCode($agentCode, $limit, $userType, $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listMessages(int $sessionId, int $limit = 0): array
    {
        return self::service()->listMessages($sessionId, $limit);
    }

    public static function renameSession(int $sessionId, ?string $title): array
    {
        return self::service()->renameSession($sessionId, $title);
    }

    public static function deleteSession(int $sessionId): void
    {
        self::service()->deleteSession($sessionId);
    }

    public static function appendMessage(int $agentId, int $sessionId, string $role, mixed $content = null, array $payload = [], ?string $tool = null, ?string $toolCallId = null): AiAgentMessage
    {
        return self::service()->appendMessage($agentId, $sessionId, $role, $content, $payload, $tool, $toolCallId);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return Generator<int, string>
     */
    public static function streamChat(string $agentCode, array $messages, ?int $sessionId = null, ?string $userType = null, ?int $userId = null): Generator
    {
        return self::service()->streamChat($agentCode, $messages, $sessionId, $userType, $userId);
    }

    public static function estimateTokensForText(string $text): int
    {
        return self::service()->estimateTokensForText($text);
    }

    private static function service(): AgentService
    {
        if (self::$service) {
            return self::$service;
        }

        $di = App::di();
        if ($di->has(self::DI_KEY)) {
            $resolved = $di->get(self::DI_KEY);
            if ($resolved instanceof AgentService) {
                return self::$service = $resolved;
            }
        }

        $instance = new AgentService();
        $di->set(self::DI_KEY, $instance);
        return self::$service = $instance;
    }
}
