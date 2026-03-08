<?php

declare(strict_types=1);

namespace App\Ai\Web;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiToken;
use App\Ai\Support\AiRuntime;
use App\Ai\Traits\AgentOpenAITrait;
use Core\Docs\Attribute\Docs;
use Core\Handlers\ExceptionBusiness;
use Core\Route\Attribute\RouteGroup;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[RouteGroup(app: 'web', route: '/agent/v1', name: 'agent')]
class Agent
{
    use AgentOpenAITrait;

    protected ?AiToken $currentToken = null;

    protected function prepareAgentContext(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->currentToken = $this->authenticateToken($request);
        $this->agentUserType = 'api_token';
        $this->agentUserId = (int)$this->currentToken->id;
        $this->agentAvailableModels = $this->loadAgentsFromToken($this->currentToken);
    }

    protected function getAvailableAgents(): Collection
    {
        if ($this->agentAvailableModels !== null) {
            return $this->agentAvailableModels;
        }
        if ($this->currentToken) {
            return $this->loadAgentsFromToken($this->currentToken);
        }
        return collect();
    }

    private function authenticateToken(ServerRequestInterface $request): AiToken
    {
        $apiKey = $this->extractApiKey($request);
        if (!$apiKey) {
            throw new ExceptionBusiness('缺少 Authorization');
        }
        $token = AiToken::query()->where('token', $apiKey)->first();
        if (!$token || !$token->active) {
            throw new ExceptionBusiness('API Key 无效');
        }
        if ($token->expired_at && $token->expired_at->isPast()) {
            throw new ExceptionBusiness('API Key 已过期');
        }
        $token->last_used_at = now();
        $token->save();
        return $token;
    }

    private function extractApiKey(ServerRequestInterface $request): ?string
    {
        $authorization = $request->getHeaderLine('Authorization');
        if ($authorization && preg_match('/Bearer\s+(\S+)/i', $authorization, $matches)) {
            return trim($matches[1]);
        }
        $headerKey = $request->getHeaderLine('X-API-Key');
        if ($headerKey) {
            return trim($headerKey);
        }
        $params = $request->getQueryParams();
        if (!empty($params['api_key'])) {
            return (string)$params['api_key'];
        }
        return null;
    }

    /**
     * @return Collection<int, AiAgent>
     */
    private function loadAgentsFromToken(AiToken $token): Collection
    {
        $query = AiAgent::query()->where('active', true);
        $modelIds = array_filter(array_map('intval', $token->models ?? []));
        if ($modelIds) {
            $query->whereIn('id', $modelIds);
        }
        return $query->orderByDesc('id')->get();
    }

    protected function recordTokenUsageForRequest(int $promptTokens, int $completionTokens, AiAgent $agent, ?int $sessionId = null): void
    {
        if (!$this->currentToken) {
            return;
        }
        $promptTokens = max(0, $promptTokens);
        $completionTokens = max(0, $completionTokens);
        $totalTokens = $promptTokens + $completionTokens;
        if ($totalTokens === 0) {
            return;
        }

        AiToken::query()
            ->where('id', $this->currentToken->id)
            ->update([
                'prompt_tokens' => AiRuntime::instance()->db()->getConnection()->raw(sprintf('GREATEST(0, COALESCE(prompt_tokens,0) + %d)', $promptTokens)),
                'completion_tokens' => AiRuntime::instance()->db()->getConnection()->raw(sprintf('GREATEST(0, COALESCE(completion_tokens,0) + %d)', $completionTokens)),
                'total_tokens' => AiRuntime::instance()->db()->getConnection()->raw(sprintf('GREATEST(0, COALESCE(total_tokens,0) + %d)', $totalTokens)),
            ]);
    }
}
