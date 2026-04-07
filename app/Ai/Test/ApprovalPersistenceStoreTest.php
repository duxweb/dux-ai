<?php

use App\Ai\Models\AiAgentApproval;
use App\Ai\Service\AgentApproval\ApprovalPersistenceStore;
use App\Ai\Service\Neuron\Agent\AgentToolExecutor;
use App\Ai\Service\Neuron\Flow\Interrupt\AsyncWaitInterruptRequest;
use Core\App;
use Illuminate\Database\Schema\Blueprint;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

beforeEach(function () {
    $schema = App::db()->schema();
    $schema->dropIfExists('ai_agent_approvals');
    $schema->create('ai_agent_approvals', function (Blueprint $table) {
        $table->id();
        $table->string('workflow_id')->unique();
        $table->longText('interrupt')->nullable();
        $table->unsignedBigInteger('agent_id');
        $table->unsignedBigInteger('session_id');
        $table->unsignedBigInteger('user_message_id')->nullable();
        $table->unsignedBigInteger('assistant_message_id')->nullable();
        $table->string('tool_name')->nullable();
        $table->string('action_name')->nullable();
        $table->string('risk_level')->default('dangerous');
        $table->string('status')->default('pending');
        $table->string('source_type')->nullable();
        $table->unsignedBigInteger('source_id')->nullable();
        $table->text('summary')->nullable();
        $table->json('request_json')->nullable();
        $table->text('feedback')->nullable();
        $table->string('approved_by_type')->nullable();
        $table->unsignedBigInteger('approved_by')->nullable();
        $table->string('rejected_by_type')->nullable();
        $table->unsignedBigInteger('rejected_by')->nullable();
        $table->timestamp('approved_at')->nullable();
        $table->timestamp('rejected_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
});

it('审批持久化：delete 只清空 interrupt，不删除审批记录', function () {
    $approval = AiAgentApproval::query()->create([
        'workflow_id' => 'approval_test_1',
        'agent_id' => 1,
        'session_id' => 1,
        'status' => 'pending',
        'risk_level' => 'dangerous',
    ]);

    $interrupt = new WorkflowInterrupt(
        new AsyncWaitInterruptRequest('审批等待', ['x' => 1]),
        new ToolNode(),
        tap(new WorkflowState(), fn ($state) => $state->set('__workflowId', 'approval_test_1')),
        new StopEvent()
    );

    $store = ApprovalPersistenceStore::make();
    $store->save('approval_test_1', $interrupt);
    expect(AiAgentApproval::query()->where('workflow_id', 'approval_test_1')->value('interrupt'))->toBeNull();
    expect($store->load('approval_test_1'))->toBeInstanceOf(WorkflowInterrupt::class);

    $store->delete('approval_test_1');

    $fresh = AiAgentApproval::query()->find($approval->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh?->status)->toBe('pending');
});

it('审批持久化：能力配置里存在 handler 闭包时也能序列化恢复', function () {
    AiAgentApproval::query()->create([
        'workflow_id' => 'approval_test_closure',
        'agent_id' => 1,
        'session_id' => 1,
        'status' => 'pending',
        'risk_level' => 'dangerous',
    ]);

    $tool = Tool::make('desktop_action')
        ->setCallable(new AgentToolExecutor('desktop_action', [
            'label' => '桌面动作',
            'handler' => static fn (array $input): array => $input,
            'action' => 'screen.capture',
        ], 1, 1))
        ->setInputs([
            'action' => 'screen.capture',
            'payload' => [],
        ])
        ->setCallId('call_desktop_1');

    $message = new ToolCallMessage(null, [$tool]);
    $event = new ToolCallEvent($message, new AIInferenceEvent('system prompt', [$tool]));
    $interrupt = new WorkflowInterrupt(
        new AsyncWaitInterruptRequest('审批等待', ['x' => 1]),
        new ToolNode(),
        tap(new WorkflowState(), fn ($state) => $state->set('__workflowId', 'approval_test_closure')),
        $event
    );

    $store = ApprovalPersistenceStore::make();
    $store->save('approval_test_closure', $interrupt);

    $loaded = $store->load('approval_test_closure');
    expect($loaded)->toBeInstanceOf(WorkflowInterrupt::class)
        ->and($loaded->getEvent())->toBeInstanceOf(ToolCallEvent::class)
        ->and($loaded->getEvent()->toolCallMessage->getTools()[0]->getName())->toBe('desktop_action');
});
