<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgentApproval;
use App\Ai\Service\Neuron\Agent\ChatOrchestrator as NeuronChatOrchestrator;
use App\Ai\Service\Neuron\Agent\ToolFactory as NeuronToolFactory;
use App\Ai\Service\Neuron\History\DbChatHistoryAdapter;
use App\Ai\Service\AgentApproval\Service as ApprovalService;
use App\Ai\Support\AiRuntime;
use App\Ai\Event\AgentPromptEvent;
use App\Ai\Service\Skill\PromptBuilder as SkillPromptBuilder;
use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentMessage;
use App\Ai\Models\AiAgentSession;
use App\Ai\Service\AI;
use App\Ai\Models\AiModel;
use Core\Handlers\ExceptionBusiness;
use Generator;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use Symfony\Component\Lock\LockInterface;

final class Service
{
    private const SESSION_BUSY_MESSAGE = '当前会话正在处理中，请等待本轮完成后再发送';

    /**
     * @return array<string, mixed>
     */
    public static function createSessionByCode(string $agentCode, ?string $userType = null, ?int $userId = null): array
    {
        return SessionManager::createSessionByCode($agentCode, $userType, $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listSessionsByCode(?string $agentCode = null, int $limit = 20, ?string $userType = null, ?int $userId = null): array
    {
        return SessionManager::listSessionsByCode($agentCode, $limit, $userType, $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listMessages(int $sessionId, int $limit = 0, int $afterId = 0): array
    {
        return MessageQuery::listMessagesForUI($sessionId, $limit, $afterId);
    }

    public static function renameSession(int $sessionId, ?string $title): array
    {
        return SessionManager::renameSession($sessionId, $title);
    }

    public static function deleteSession(int $sessionId): void
    {
        SessionManager::deleteSession($sessionId);
    }

    public static function appendMessage(int $agentId, int $sessionId, string $role, mixed $content = null, array $payload = [], ?string $tool = null, ?string $toolCallId = null): AiAgentMessage
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
    public static function streamChat(string $agentCode, array $messages, ?int $sessionId = null, ?string $userType = null, ?int $userId = null): Generator
    {
        $agent = AgentResolver::requireByCode($agentCode, true);
        if (!$agent->model) {
            throw new ExceptionBusiness('智能体未绑定模型');
        }
        if ((string)($agent->model->type ?? AiModel::TYPE_CHAT) !== AiModel::TYPE_CHAT) {
            throw new ExceptionBusiness('当前智能体主模型不是 Chat 类型，请改为 Chat 模型并通过工具调用图片/视频模型');
        }

        $sessionId = SessionManager::ensureSessionId($agent, $sessionId, $userType, $userId);
        $retryMessage = MessageQuery::latestRetryableUserMessage($sessionId);
        if ($retryMessage && IncomingMessageHandler::sameAsFailedMessage($retryMessage, $messages)) {
            $incoming = IncomingMessageHandler::buildRetryInput($retryMessage);
            return self::guardedStreamChat(
                $sessionId,
                static fn () => self::runStreamChat($agent, $agentCode, $incoming['messages'], $sessionId, $incoming)
            );
        }
        $incoming = IncomingMessageHandler::appendLatestUserMessage($agent, $sessionId, $messages);
        $approvalDecision = self::resolveApprovalDecision((string)($incoming['user_text'] ?? ''));
        if ($approvalDecision !== null) {
            $approval = ApprovalService::findPendingBySession($sessionId);
            if ($approval) {
                return self::guardedStreamChat(
                    $sessionId,
                    static fn () => self::runApprovalReply($approval, $approvalDecision, (int)($incoming['message_id'] ?? 0), $userType, $userId)
                );
            }
        }
        return self::guardedStreamChat(
            $sessionId,
            static fn () => self::runStreamChat($agent, $agentCode, $messages, $sessionId, $incoming)
        );
    }

    public static function retryChat(int $sessionId, ?string $userType = null, ?int $userId = null): Generator
    {
        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->with('agent.model')->find($sessionId);
        if (!$session || !$session->agent) {
            throw new ExceptionBusiness('会话不存在');
        }
        if ($userType && $userId && ($session->user_type !== $userType || (int)$session->user_id !== $userId)) {
            throw new ExceptionBusiness('会话不存在或无访问权限');
        }

        $retryMessage = MessageQuery::latestRetryableUserMessage($sessionId);
        if (!$retryMessage) {
            throw new ExceptionBusiness('当前会话没有可重试的失败消息');
        }

        $incoming = IncomingMessageHandler::buildRetryInput($retryMessage);
        return self::guardedStreamChat(
            $sessionId,
            static fn () => self::runStreamChat($session->agent, $session->agent->code, $incoming['messages'], $sessionId, $incoming)
        );
    }

    public static function approve(int $approvalId, ?string $userType = null, ?int $userId = null, ?string $feedback = null): array
    {
        $approval = ApprovalService::requireById($approvalId);
        self::assertApprovalAccess($approval, $userType, $userId);
        $approval = ApprovalService::claimApprove($approval, $userType, $userId, $feedback);
        $resumeRequest = ApprovalService::buildResumeRequest($approval, 'approve', $feedback);
        try {
            self::resumeApprovalWorkflow($approval, $resumeRequest);
            return $approval->fresh()?->transform() ?? $approval->transform();
        } catch (\Throwable $e) {
            AiRuntime::instance()->log('ai.agent')->error('approval.approve.failed', [
                'approval_id' => $approvalId,
                'workflow_id' => (string)$approval->workflow_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public static function reject(int $approvalId, ?string $userType = null, ?int $userId = null, ?string $feedback = null): array
    {
        $approval = ApprovalService::requireById($approvalId);
        self::assertApprovalAccess($approval, $userType, $userId);
        $approval = ApprovalService::claimReject($approval, $userType, $userId, $feedback);
        $resumeRequest = ApprovalService::buildResumeRequest($approval, 'reject', $feedback);
        try {
            self::resumeApprovalWorkflow($approval, $resumeRequest);
            return $approval->fresh()?->transform() ?? $approval->transform();
        } catch (\Throwable $e) {
            AiRuntime::instance()->log('ai.agent')->error('approval.reject.failed', [
                'approval_id' => $approvalId,
                'workflow_id' => (string)$approval->workflow_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $initialToolResult
     * @param array<int, array<string, mixed>> $continuationMessages
     * @param array<int, string> $excludeTools
     */
    public static function continueAfterToolResult(
        int $sessionId,
        array $initialToolResult = [],
        array $continuationMessages = [],
        array $excludeTools = [],
    ): void
    {
        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->with('agent.model')->find($sessionId);
        if (!$session || !$session->agent) {
            throw new ExceptionBusiness('会话不存在');
        }
        $continuationPrompt = trim((string)($continuationMessages[0]['content'] ?? ''));

        $generator = self::guardedStreamChat(
            $sessionId,
            static fn () => self::runStreamChat(
                $session->agent,
                $session->agent->code,
                $continuationMessages,
                $sessionId,
                [
                    'use_openai_messages' => true,
                    'user_text' => $continuationPrompt,
                    'stored_content' => null,
                    'message_id' => 0,
                    'exclude_tools' => $excludeTools,
                    'initial_tool_result' => $initialToolResult,
                ]
            ),
            false
        );

        foreach ($generator as $_chunk) {
            unset($_chunk);
        }
    }

    /**
     * @param array<string, mixed> $incoming
     */
    private static function runStreamChat(AiAgent $agent, string $agentCode, array $messages, int $sessionId, array $incoming): Generator
    {
        $userContent = (string)($incoming['user_text'] ?? '');
        $userMessageId = (int)($incoming['message_id'] ?? 0);

        $settings = is_array($agent->settings) ? $agent->settings : [];
        $attachments = AttachmentConfig::normalizeFromModel($agent->model);
        $supportImage = AttachmentConfig::supportsImage($attachments);
        $supportFile = AttachmentConfig::supportsFile($attachments);
        $openaiMessages = !empty($incoming['use_openai_messages']) ? $messages : [];

        $providerCode = $agent->model?->code ?? '';
        $modelName = $agent->model?->model ?? '';
        $modelForDisplay = $providerCode ?: $modelName;
        if (!$modelName) {
            throw new ExceptionBusiness('模型标识缺失');
        }

        $disableTools = !empty($incoming['disable_tools']);
        $excludeTools = is_array($incoming['exclude_tools'] ?? null) ? ($incoming['exclude_tools'] ?? []) : [];
        $toolsConfig = $disableTools
            ? ['map' => [], 'tools' => []]
            : NeuronToolFactory::buildForAgent($agent, $sessionId, $excludeTools);
        $toolMap = $toolsConfig['map'];
        $tools = $toolsConfig['tools'];
        $workflowId = trim((string)($incoming['workflow_id'] ?? ''));
        if ($workflowId === '' && $tools !== [] && $userMessageId > 0) {
            $workflowId = ApprovalService::workflowId($sessionId, $userMessageId);
        }

        $instructions = trim((string)($agent->instructions ?? ''));
        if ($instructions === '') {
            $instructions = '你是一个有帮助、可靠的 AI 助手。';
        }
        $skillInstructions = SkillPromptBuilder::buildForAgent($agent);
        if ($skillInstructions !== '') {
            $instructions = trim($instructions . "\n\n" . $skillInstructions);
        }
        $promptEvent = new AgentPromptEvent($sessionId);
        \Core\App::event()->dispatch($promptEvent, 'ai.agent.prompt');
        foreach ($promptEvent->getInstructions() as $instructionBlock) {
            $instructions = trim($instructions . "\n\n" . $instructionBlock);
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

        $promptTokens = self::estimateTokensForText($userContent);
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
            static fn () => new DbChatHistoryAdapter(
                sessionId: $sessionId,
                contextWindow: 50000,
                supportImage: $supportImage,
                supportFile: $supportFile,
                imageMode: $imageMode,
                documentMode: $fileMode,
            ),
            $userMessageId,
            [
                'support_image' => $supportImage,
                'support_image_model' => $imageMode,
                'support_file' => $supportFile,
                'support_file_model' => $fileMode,
            ],
            is_array($incoming['initial_tool_result'] ?? null) ? ($incoming['initial_tool_result'] ?? []) : [],
            $workflowId,
            ($incoming['resume_request'] ?? null) instanceof ApprovalRequest ? $incoming['resume_request'] : null,
            [
                'user_type' => $incoming['user_type'] ?? null,
                'user_id' => isset($incoming['user_id']) ? (int)$incoming['user_id'] : null,
                'source_type' => $incoming['source_type'] ?? null,
                'source_id' => isset($incoming['source_id']) ? (int)$incoming['source_id'] : null,
            ],
        );
    }

    private static function resumeApprovalWorkflow(AiAgentApproval $approval, ApprovalRequest $resumeRequest): void
    {
        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->with('agent.model')->find((int)$approval->session_id);
        if (!$session || !$session->agent) {
            throw new ExceptionBusiness('审批关联会话不存在');
        }

        $generator = self::guardedStreamChat(
            (int)$session->id,
            static fn () => self::runStreamChat(
                $session->agent,
                (string)$session->agent->code,
                [],
                (int)$session->id,
                [
                    'use_openai_messages' => true,
                    'user_text' => '',
                    'message_id' => (int)($approval->user_message_id ?? 0),
                    'workflow_id' => (string)$approval->workflow_id,
                    'resume_request' => $resumeRequest,
                    'source_type' => 'approval',
                    'source_id' => (int)$approval->id,
                ]
            )
        );

        foreach ($generator as $_chunk) {
            unset($_chunk);
        }
    }

    private static function runApprovalReply(AiAgentApproval $approval, string $decision, int $replyMessageId, ?string $userType, ?int $userId): Generator
    {
        self::assertApprovalAccess($approval, $userType, $userId);
        if ($replyMessageId > 0) {
            MessageStore::markUserMessageRunning($replyMessageId);
        }

        if ($decision === 'approve') {
            $approval = ApprovalService::claimApprove($approval, $userType, $userId, null);
        } else {
            $approval = ApprovalService::claimReject($approval, $userType, $userId, null);
        }
        $resumeRequest = ApprovalService::buildResumeRequest($approval, $decision, null);

        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->with('agent.model')->find((int)$approval->session_id);
        if (!$session || !$session->agent) {
            throw new ExceptionBusiness('审批关联会话不存在');
        }

        yield from self::runStreamChat(
            $session->agent,
            (string)$session->agent->code,
            [],
            (int)$session->id,
            [
                'use_openai_messages' => true,
                'user_text' => '',
                'message_id' => $replyMessageId,
                'workflow_id' => (string)$approval->workflow_id,
                'resume_request' => $resumeRequest,
                'source_type' => 'approval',
                'source_id' => (int)$approval->id,
            ]
        );
    }

    private static function resolveApprovalDecision(string $text): ?string
    {
        $text = trim($text);
        return match ($text) {
            '同意', '批准', '允许' => 'approve',
            '拒绝', '不同意', '取消' => 'reject',
            default => null,
        };
    }

    private static function assertApprovalAccess(AiAgentApproval $approval, ?string $userType, ?int $userId): void
    {
        if ($userType === null || $userId === null) {
            return;
        }
        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->find((int)$approval->session_id);
        if (!$session || $session->user_type !== $userType || (int)$session->user_id !== (int)$userId) {
            throw new ExceptionBusiness('审批记录不存在或无访问权限');
        }
    }

    public static function estimateTokensForText(string $text): int
    {
        return Token::estimateTokensForText($text);
    }

    /**
     * @param callable():Generator $runner
     * @return Generator<int, string>
     */
    private static function guardedStreamChat(int $sessionId, callable $runner, bool $lockSession = true): Generator
    {
        return (function () use ($sessionId, $runner, $lockSession) {
            $lock = $lockSession ? self::acquireSessionLock($sessionId) : null;
            try {
                $generator = $runner();
                foreach ($generator as $chunk) {
                    if ($lockSession) {
                        SessionExecutionGuard::refresh($lock);
                    }
                    yield $chunk;
                }
            } finally {
                if ($lockSession) {
                    SessionExecutionGuard::release($lock);
                }
            }
        })();
    }

    private static function acquireSessionLock(int $sessionId): ?LockInterface
    {
        $lock = SessionExecutionGuard::acquire($sessionId);
        if ($sessionId > 0 && !$lock) {
            throw new ExceptionBusiness(self::SESSION_BUSY_MESSAGE);
        }
        return $lock;
    }
}
