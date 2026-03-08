<?php

declare(strict_types=1);

namespace App\Ai\Admin;

use App\Ai\Models\AiAgent;
use App\Ai\Traits\AgentOpenAITrait;
use Core\Docs\Attribute\Docs;
use Core\Route\Attribute\RouteGroup;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[RouteGroup(app: 'admin', route: '/ai/message/v1', name: 'ai.message')]
class Message
{
    use AgentOpenAITrait;

    protected function prepareAgentContext(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $auth = (array)$request->getAttribute('auth');
        $adminId = isset($auth['id']) ? (int)$auth['id'] : 0;
        $this->agentUserType = 'admin';
        $this->agentUserId = $adminId;
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
