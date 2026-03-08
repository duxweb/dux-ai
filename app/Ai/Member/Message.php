<?php

declare(strict_types=1);

namespace App\Ai\Member;

use App\Ai\Models\AiAgent;
use App\Ai\Traits\AgentOpenAITrait;
use Core\Auth\Auth;
use Core\Handlers\ExceptionBusiness;
use Core\Route\Attribute\RouteGroup;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[RouteGroup(app: 'api', route: '/ai/message/v1', name: 'ai.message')]
class Message
{
    use AgentOpenAITrait;

    protected function prepareAgentContext(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $auth = Auth::decode($request, 'member');
        $userId = isset($auth['id']) ? (int)$auth['id'] : 0;

        if ($userId <= 0) {
            throw new ExceptionBusiness('Invalid user id');
        }


        $this->agentUserType = 'user';
        $this->agentUserId = $userId;
        $this->agentAvailableModels = $this->loadAllAgents();
    }

    protected function getAvailableAgents(): Collection
    {
        return $this->agentAvailableModels ?? $this->loadAllAgents();
    }

    /**
     * @return Collection<int, AiAgent>
     */
    private function loadAllAgents(): Collection
    {
        return AiAgent::query()
            ->with('model.provider')
            ->where('active', true)
            ->orderByDesc('id')
            ->get();
    }
}
