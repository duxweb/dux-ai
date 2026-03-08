<?php

declare(strict_types=1);

namespace App\Ai\Traits;

use App\Ai\Models\AiAgent;
use App\Ai\Service\Agent as AgentService;
use App\Ai\Service\Agent\AttachmentConfig;
use App\Ai\Service\Agent\CardParser;
use App\Ai\Service\Agent\FileUploader;
use App\Ai\Service\Agent\HttpRequest;
use App\Ai\Service\Agent\OpenAiMessage;
use App\Ai\Service\Agent\OpenAiHttp;
use App\Ai\Service\Agent\SessionGuard;
use App\Ai\Event\ActionEvent;
use App\Ai\Support\AiRuntime;
use Core\App;
use Core\Handlers\ExceptionBusiness;
use Core\Route\Attribute\Route;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

trait AgentOpenAITrait
{
    protected ?string $agentUserType = null;
    protected ?int $agentUserId = null;

    /**
     * @var Collection<int, AiAgent>|null
     */
    protected ?Collection $agentAvailableModels = null;

    #[Route(methods: 'GET', route: '/models')]
    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->prepareAgentContext($request, $response);
            $agents = $this->ensureAvailableAgents();
            if ($agents->isEmpty()) {
                throw new ExceptionBusiness('当前未配置可用模型');
            }
            $payload = [
                'object' => 'list',
                'data' => $agents->map(function (AiAgent $agent) {
                    $agent->loadMissing('model.provider');
                    $attachments = AttachmentConfig::normalizeFromModel($agent->model);

                    return [
                        'id' => $agent->code,
                        'object' => 'model',
                        'created' => $agent->created_at?->timestamp ?? time(),
                        'owned_by' => 'agent',
                        'name' => $agent->name,
                        'description' => $agent->description,
                        'attachments' => $attachments,
                    ];
                })->values()->all(),
            ];

            return OpenAiHttp::json($response, $payload);
        } catch (ExceptionBusiness $e) {
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    #[Route(methods: 'POST', route: '/chat/completions')]
    public function chatCompletions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
            $stream = false;
        try {
            $this->prepareAgentContext($request, $response);
            $body = HttpRequest::jsonBody($request);
            $stream = (bool)($body['stream'] ?? false);
            $agents = $this->ensureAvailableAgents();
            $agent = $this->resolveAgentFromModels($agents, isset($body['model']) ? (string)$body['model'] : null);

            $messages = OpenAiMessage::normalize($body['messages'] ?? []);
            if ($messages === []) {
                throw new ExceptionBusiness('messages 不能为空');
            }

            $sessionId = null;
            if (isset($body['session_id']) && is_numeric($body['session_id'])) {
                $sessionId = (int)$body['session_id'];
            }

            [$userType, $userId] = $this->resolveUserContext();

            $generator = AgentService::streamChat($agent->code, $messages, $sessionId, $userType, $userId);
            $generator->rewind();

            if ($stream) {
                $modelForDisplay = (string)($agent->model ?? $agent->code);
                $pump = OpenAiHttp::ssePumpStream($generator, $modelForDisplay, function (\Throwable $e) {
                    AiRuntime::instance()->log('ai.agent')->error('Agent stream pump error', [
                        'message' => $e->getMessage(),
                    ]);
                });

                return OpenAiHttp::withSseHeaders($response)->withBody($pump);
            }

            $result = $this->collectCompletion($generator, $agent);
            $completionText = OpenAiMessage::stringifyContent($result['choices'][0]['message']['content'] ?? '');
            $this->recordTokenUsageForRequest(
                OpenAiMessage::promptTokens($messages),
                AgentService::estimateTokensForText($completionText),
                $agent,
                $result['session_id'] ?? null
            );
            return OpenAiHttp::json($response, $result);
        } catch (ExceptionBusiness $e) {
            if ($stream) {
                return OpenAiHttp::sseErrorResponse($response, 400, $e->getMessage());
            }
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            if ($stream) {
                AiRuntime::instance()->log('ai.agent')->error('Agent stream error', [
                    'message' => $e->getMessage(),
                ]);
                return OpenAiHttp::sseErrorResponse($response, 500, '服务器内部错误');
            }
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    #[Route(methods: 'POST', route: '/files')]
    public function uploadFile(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->prepareAgentContext($request, $response);
            $params = $request->getQueryParams();
            $modelCode = isset($params['model']) ? (string)$params['model'] : null;
            $agents = $this->ensureAvailableAgents();
            $agent = $this->resolveAgentFromModels($agents, $modelCode);
            $file = HttpRequest::extractUploadedFile($request);
            if (!$file) {
                throw new ExceptionBusiness('未检测到上传文件');
            }
            $data = (new FileUploader())->upload($agent, $file);
            return OpenAiHttp::json($response, [
                'object' => 'file',
                'data' => [
                    'id' => uniqid('file_', true),
                    'object' => 'file',
                    'created_at' => time(),
                    'filename' => $data['filename'],
                    'bytes' => $data['size'],
                    'purpose' => 'assistants',
                    'url' => $data['url'],
                    'mime_type' => $data['mime'],
                    'content' => $data['content'],
                    'provider_file_id' => $data['provider_file_id'] ?? null,
                    'provider' => $data['provider'] ?? null,
                    'ingestion_mode' => $data['ingestion_mode'] ?? null,
                    'media_kind' => $data['media_kind'] ?? 'file',
                    'mode_hint' => $data['mode_hint'] ?? 'auto',
                    'upload_channel' => $data['upload_channel'] ?? null,
                    'parse_mode' => $data['parse_mode'] ?? 'passthrough',
                    'parsed_text' => $data['parsed_text'] ?? null,
                    'parsed_parts_count' => $data['parsed_parts_count'] ?? 0,
                ],
            ], 201);
        } catch (ExceptionBusiness $e) {
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            AiRuntime::instance()->log('ai.agent')->error('agent.file.upload.failed', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    #[Route(methods: 'POST', route: '/actions')]
    public function runAction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->prepareAgentContext($request, $response);
            $body = HttpRequest::jsonBody($request);
            $agents = $this->ensureAvailableAgents();
            $sessionId = isset($body['session_id']) ? (int)$body['session_id'] : 0;
            if ($sessionId <= 0) {
                throw new ExceptionBusiness('session_id 不能为空');
            }

            $action = trim((string)($body['action'] ?? ''));
            if ($action === '') {
                throw new ExceptionBusiness('action 不能为空');
            }
            $payload = is_array($body['payload'] ?? null) ? (array)$body['payload'] : [];

            $session = SessionGuard::resolve($agents, $sessionId, $this->agentUserType, $this->agentUserId);

            $event = new ActionEvent([
                'action' => $action,
                'payload' => $payload,
                'session_id' => $session->id,
                'agent_id' => (int)$session->agent_id,
                'user_type' => $this->agentUserType,
                'user_id' => $this->agentUserId,
            ]);

            App::event()->dispatch($event, 'ai.action');

            $messages = $event->getMessages();
            $stored = [];
            foreach ($messages as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $role = (string)($message['role'] ?? 'assistant');
                $content = $message['content'] ?? '';
                $payloadData = [];
                $contentText = '';
                if (is_array($content) && ($content['type'] ?? '') === 'card' && isset($content['card']) && is_array($content['card'])) {
                    $payloadData['parts'] = [['type' => 'card', 'card' => $content['card']]];
                } else {
                    $contentText = is_string($content) ? $content : (string)($content ?? '');
                }
                AgentService::appendMessage((int)$session->agent_id, (int)$session->id, $role, $contentText, $payloadData);
                $stored[] = $message;
            }

            return OpenAiHttp::json($response, [
                'object' => 'action',
                'data' => [
                    'messages' => $stored,
                ],
            ]);
        } catch (ExceptionBusiness $e) {
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    #[Route(methods: 'GET', route: '/sessions')]
    public function listSessions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->prepareAgentContext($request, $response);
            $agents = $this->ensureAvailableAgents();
            if ($agents->isEmpty()) {
                throw new ExceptionBusiness('当前未配置可用模型');
            }
            $params = $request->getQueryParams();
            $modelCode = isset($params['model']) ? (string)$params['model'] : null;
            
            // Validate model if provided
            if ($modelCode) {
                $this->resolveAgentFromModels($agents, $modelCode);
            }

            $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
            $limit = max(1, min(100, $limit));
            $data = AgentService::listSessionsByCode($modelCode, $limit, $this->agentUserType, $this->agentUserId);

            // 列表为空时，自动创建一个默认会话，减少首屏空状态。
            if ($data === []) {
                $agent = $this->resolveAgentFromModels($agents, $modelCode);
                [$userType, $userId] = $this->resolveUserContext();
                AgentService::createSessionByCode($agent->code, $userType, $userId);
                $data = AgentService::listSessionsByCode($modelCode, $limit, $this->agentUserType, $this->agentUserId);
            }

            return OpenAiHttp::json($response, [
                'object' => 'list',
                'data' => $data,
            ]);
        } catch (ExceptionBusiness $e) {
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    #[Route(methods: 'POST', route: '/sessions')]
    public function createSession(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->prepareAgentContext($request, $response);
            $body = HttpRequest::jsonBody($request);
            $agents = $this->ensureAvailableAgents();
            $agent = $this->resolveAgentFromModels($agents, isset($body['model']) ? (string)$body['model'] : null);
            [$userType, $userId] = $this->resolveUserContext();
            $session = AgentService::createSessionByCode($agent->code, $userType, $userId);
            return OpenAiHttp::json($response, [
                'object' => 'session',
                'data' => $session,
            ], 201);
        } catch (ExceptionBusiness $e) {
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    #[Route(methods: 'GET', route: '/sessions/{id}/messages')]
    public function listMessages(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->prepareAgentContext($request, $response);
            $tokenAgents = $this->ensureAvailableAgents();
            $sessionId = (int)($args['id'] ?? 0);
            $session = SessionGuard::resolve($tokenAgents, $sessionId, $this->agentUserType, $this->agentUserId);
            $params = $request->getQueryParams();
            $limit = isset($params['limit']) ? (int)$params['limit'] : 50;
            $limit = max(1, min(200, $limit));
            $messages = AgentService::listMessages($session->id, $limit);
            return OpenAiHttp::json($response, [
                'object' => 'list',
                'data' => $messages,
            ]);
        } catch (ExceptionBusiness $e) {
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    #[Route(methods: 'PUT', route: '/sessions/{id}')]
    public function renameSession(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->prepareAgentContext($request, $response);
            $agents = $this->ensureAvailableAgents();
            $sessionId = (int)($args['id'] ?? 0);
            $session = SessionGuard::resolve($agents, $sessionId, $this->agentUserType, $this->agentUserId);
            $body = HttpRequest::jsonBody($request);
            if (!array_key_exists('title', $body)) {
                throw new ExceptionBusiness('缺少会话标题');
            }
            $updated = AgentService::renameSession($session->id, (string)$body['title']);
            return OpenAiHttp::json($response, [
                'object' => 'session',
                'data' => $updated,
            ]);
        } catch (ExceptionBusiness $e) {
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    #[Route(methods: 'DELETE', route: '/sessions/{id}')]
    public function deleteSession(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->prepareAgentContext($request, $response);
            $agents = $this->ensureAvailableAgents();
            $sessionId = (int)($args['id'] ?? 0);
            $session = SessionGuard::resolve($agents, $sessionId, $this->agentUserType, $this->agentUserId);
            AgentService::deleteSession($session->id);
            return OpenAiHttp::json($response, [
                'object' => 'session',
                'data' => ['id' => $session->id],
            ]);
        } catch (ExceptionBusiness $e) {
            return OpenAiHttp::errorJson($response, $e->getMessage(), 'invalid_request_error', 400);
        } catch (\Throwable $e) {
            return OpenAiHttp::errorJson($response, '服务器内部错误', 'internal_error', 500);
        }
    }

    private function collectCompletion(\Generator $generator, AiAgent $agent): array
    {
        $content = '';
        $sessionId = null;
        $model = $agent->model?->model ?? $agent->code;
        $completionId = null;
        $finishReason = 'stop';
        $assistantParts = [];

        foreach ($generator as $chunk) {
            $payload = OpenAiHttp::decodeSseChunk($chunk);
            if ($payload === null) {
                continue;
            }
            if (isset($payload['error'])) {
                $message = is_array($payload['error']) ? ($payload['error']['message'] ?? '推理失败') : '推理失败';
                throw new ExceptionBusiness($message);
            }
            if (isset($payload['session_id'])) {
                $sessionId = (int)$payload['session_id'];
            }
            if (isset($payload['model'])) {
                $model = (string)$payload['model'];
            }
            if (isset($payload['id'])) {
                $completionId = (string)$payload['id'];
            }
            $delta = $payload['choices'][0]['delta'] ?? [];
            if (isset($delta['content'])) {
                $deltaContent = $delta['content'];
                if (is_string($deltaContent)) {
                    $content .= $deltaContent;
                } elseif (is_array($deltaContent)) {
                    foreach ($deltaContent as $part) {
                        if (!is_array($part)) {
                            continue;
                        }
                        $assistantParts[] = $part;
                    }
                }
            }
            if (!empty($payload['choices'][0]['finish_reason'])) {
                $finishReason = (string)$payload['choices'][0]['finish_reason'];
            }
        }

        $messageContent = $content;
        if ($assistantParts !== []) {
            $parts = [];
            if (trim($content) !== '') {
                $parts[] = [
                    'type' => 'text',
                    'text' => trim($content),
                ];
            }
            foreach ($assistantParts as $part) {
                if (is_array($part)) {
                    $parts[] = $part;
                }
            }
            if ($parts !== []) {
                $messageContent = $parts;
            }
        } else {
            $structured = CardParser::extractStructuredResult($content);
            if (is_array($structured)) {
                $parts = is_array($structured['parts'] ?? null) ? ($structured['parts'] ?? []) : [];
                if ($parts !== []) {
                    $messageContent = $parts;
                }
            }
        }

        return [
            'id' => $completionId ?? ('chatcmpl_' . uniqid()),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $messageContent,
                    ],
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => null,
            'session_id' => $sessionId,
        ];
    }

    /**
     * 宿主可重写该方法，用于解析授权、设置用户上下文、可用模型等。
     */
    protected function prepareAgentContext(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->agentUserType = null;
        $this->agentUserId = null;
        $this->agentAvailableModels = null;
    }

    /**
     * @return Collection<int, AiAgent>
     */
    protected function getAvailableAgents(): Collection
    {
        return collect();
    }

    /**
     * @return Collection<int, AiAgent>
     */
    private function ensureAvailableAgents(): Collection
    {
        if ($this->agentAvailableModels === null) {
            $this->agentAvailableModels = $this->getAvailableAgents();
        }
        return $this->agentAvailableModels;
    }

    private function resolveAgentFromModels(Collection $agents, ?string $modelId): AiAgent
    {
        if ($agents->isEmpty()) {
            throw new ExceptionBusiness('当前未配置可用模型');
        }
        if ($modelId !== null && $modelId !== '') {
            $agent = $agents->firstWhere('code', $modelId);
            if (!$agent) {
                throw new ExceptionBusiness('模型标识与权限不匹配');
            }
            return $agent;
        }
        return $agents->first();
    }

    private function resolveUserContext(): array
    {
        return [
            $this->agentUserType ?? 'api_token',
            $this->agentUserId ?? 0,
        ];
    }

    /**
     * 宿主可重写，记录本次请求的 Token 用量（例如绑定到 AiToken）
     */
    protected function recordTokenUsageForRequest(int $promptTokens, int $completionTokens, AiAgent $agent, ?int $sessionId = null): void
    {
        // 默认不做处理，宿主可覆盖
    }

}
