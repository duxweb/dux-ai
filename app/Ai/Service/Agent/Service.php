<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Service\Neuron\Agent\ChatOrchestrator as NeuronChatOrchestrator;
use App\Ai\Service\Neuron\Agent\ToolFactory as NeuronToolFactory;
use App\Ai\Service\Neuron\History\DbChatHistoryAdapter;
use App\Ai\Models\AiAgentMessage;
use App\Ai\Service\AI;
use App\Ai\Models\AiModel;
use Core\Handlers\ExceptionBusiness;
use Generator;

final class Service
{
    /**
     * @return array<string, mixed>
     */
    public function createSessionByCode(string $agentCode, ?string $userType = null, ?int $userId = null): array
    {
        return SessionManager::createSessionByCode($agentCode, $userType, $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessionsByCode(?string $agentCode = null, int $limit = 20, ?string $userType = null, ?int $userId = null): array
    {
        return SessionManager::listSessionsByCode($agentCode, $limit, $userType, $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(int $sessionId, int $limit = 0): array
    {
        return MessageQuery::listMessagesForUI($sessionId, $limit);
    }

    public function renameSession(int $sessionId, ?string $title): array
    {
        return SessionManager::renameSession($sessionId, $title);
    }

    public function deleteSession(int $sessionId): void
    {
        SessionManager::deleteSession($sessionId);
    }

    public function appendMessage(int $agentId, int $sessionId, string $role, mixed $content = null, array $payload = [], ?string $tool = null, ?string $toolCallId = null): AiAgentMessage
    {
        return MessageStore::appendMessage($agentId, $sessionId, $role, $content, $payload, $tool, $toolCallId);
    }

    /**
     * 简单占位的会话流，回显最新一条用户消息。
     *
     * @param string $agentCode
     * @param array<int, array<string, mixed>> $messages
     * @param int|null $sessionId
     * @param string|null $userType
     * @param int|null $userId
     * @return Generator<int, string>
     */
    public function streamChat(string $agentCode, array $messages, ?int $sessionId = null, ?string $userType = null, ?int $userId = null): Generator
    {
        $agent = AgentResolver::requireByCode($agentCode, true);
        if (!$agent->model) {
            throw new ExceptionBusiness('智能体未绑定模型');
        }
        if ((string)($agent->model->type ?? AiModel::TYPE_CHAT) !== AiModel::TYPE_CHAT) {
            throw new ExceptionBusiness('当前智能体主模型不是 Chat 类型，请改为 Chat 模型并通过工具调用图片/视频模型');
        }

        $sessionId = SessionManager::ensureSessionId($agent, $sessionId, $userType, $userId);
        $incoming = IncomingMessageHandler::appendLatestUserMessage($agent, $sessionId, $messages);
        $userContent = (string)($incoming['user_text'] ?? '');

        $settings = is_array($agent->settings) ? $agent->settings : [];
        $attachments = AttachmentConfig::normalizeFromModel($agent->model);
        $supportImage = AttachmentConfig::supportsImage($attachments);
        $supportFile = AttachmentConfig::supportsFile($attachments);
        $openaiMessages = [];

        $providerCode = $agent->model?->code ?? '';
        $modelName = $agent->model?->model ?? '';
        $modelForDisplay = $providerCode ?: $modelName;
        if (!$modelName) {
            throw new ExceptionBusiness('模型标识缺失');
        }

        $toolsConfig = NeuronToolFactory::buildForAgent($agent, $sessionId);
        $toolMap = $toolsConfig['map'];
        $tools = $toolsConfig['tools'];

        $instructions = trim((string)($agent->instructions ?? ''));
        if ($instructions === '') {
            $instructions = '你是一个有帮助、可靠的 AI 助手。';
        }
        $instructions = trim($instructions . "\n\n工具结果回复规则：当本轮刚收到工具结果时，请仅输出自然语言说明，不要输出 JSON、代码块或结构化对象。图片/视频等多媒体结构由系统侧统一封装。");

        $debugEnabled = (bool)($settings['debug_enabled'] ?? false);
        Logger::debug($debugEnabled, 'agent.chat.start', [
            'agent' => $agentCode,
            'session_id' => $sessionId,
            'history_count' => 0,
            'tools' => array_keys($toolMap),
            'last_user' => $userContent,
        ]);

        $promptTokens = $this->estimateTokensForText($userContent);
        $temperature = null;
        if (array_key_exists('temperature', $settings) && $settings['temperature'] !== null && $settings['temperature'] !== '') {
            $temperature = (float)$settings['temperature'];
        }

        // NeuronAI provider parameters override (e.g. per-agent temperature).
        $overrides = [];
        if ($temperature !== null) {
            $overrides['temperature'] = $temperature;
        }
        $provider = AI::forModel($agent->model, $overrides);

        $imageMode = (string)AttachmentConfig::modeFor($attachments, 'image');
        $fileMode = (string)AttachmentConfig::modeFor($attachments, 'file');
        $historyAdapter = new DbChatHistoryAdapter(
            sessionId: $sessionId,
            contextWindow: 50000,
            supportImage: $supportImage,
            supportFile: $supportFile,
            imageMode: $imageMode,
            documentMode: $fileMode,
        );

        yield from NeuronChatOrchestrator::run(
            $provider,
            $agent,
            $agentCode,
            $sessionId,
            $modelForDisplay,
            $instructions,
            $openaiMessages,
            $toolMap,
            $tools,
            $promptTokens,
            $historyAdapter,
            [
                'support_image' => $supportImage,
                'support_image_model' => $imageMode,
                'support_file' => $supportFile,
                'support_file_model' => $fileMode,
            ],
        );
    }

    public function estimateTokensForText(string $text): int
    {
        return Token::estimateTokensForText($text);
    }
}
