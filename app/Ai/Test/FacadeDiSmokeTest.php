<?php

use App\Ai\Service\Agent as AgentFacade;
use App\Ai\Service\Agent\Service as AgentService;
use App\Ai\Service\AI as AIFacade;
use App\Ai\Service\AI\Service as AIService;
use App\Ai\Service\AIFlow as AIFlowFacade;
use App\Ai\Service\AIFlow\Service as AIFlowService;
use App\Ai\Service\Capability as CapabilityFacade;
use App\Ai\Service\Capability\Service as CapabilityService;
use App\Ai\Service\FunctionCall as FunctionCallFacade;
use App\Ai\Service\FunctionCall\Service as FunctionCallService;
use App\Ai\Service\Parse as ParseFacade;
use App\Ai\Service\Parse\Service as ParseService;
use App\Ai\Service\Rag as RagFacade;
use App\Ai\Service\Rag\Service as RagService;
use App\Ai\Service\RagEngine as RagEngineFacade;
use App\Ai\Service\RagEngine\Service as RagEngineService;
use App\Ai\Service\Tool as ToolFacade;
use App\Ai\Service\Tool\Service as ToolService;
use App\Ai\Service\VectorStore as VectorStoreFacade;
use App\Ai\Service\VectorStore\Service as VectorStoreService;
use App\Ai\Support\AiRuntime;
use App\Ai\Support\AiRuntimeInterface;
use Core\App;

function facade_internal_service(string $facadeClass): object
{
    $fn = \Closure::bind(static function () use ($facadeClass) {
        return $facadeClass::service();
    }, null, $facadeClass);

    return $fn();
}

it('DI：AiRuntime::instance 会写入容器绑定', function () {
    AiRuntime::reset();
    $runtime = AiRuntime::instance();

    expect(App::di()->has(AiRuntimeInterface::class))->toBeTrue()
        ->and(App::di()->get(AiRuntimeInterface::class))->toBe($runtime);
});

it('DI：Facade 会优先使用 DI_KEY，并缓存到静态实例', function () {
    $cases = [
        [CapabilityFacade::class, CapabilityService::class, fn () => new CapabilityService(AiRuntime::instance())],
        [ParseFacade::class, ParseService::class, fn () => new ParseService()],
        [FunctionCallFacade::class, FunctionCallService::class, fn () => new FunctionCallService(AiRuntime::instance())],
        [VectorStoreFacade::class, VectorStoreService::class, fn () => new VectorStoreService(AiRuntime::instance())],
        [RagEngineFacade::class, RagEngineService::class, fn () => new RagEngineService(AiRuntime::instance())],
        [RagFacade::class, RagService::class, fn () => new RagService()],
        [ToolFacade::class, ToolService::class, fn () => new ToolService(new CapabilityService(AiRuntime::instance()))],
        [AIFlowFacade::class, AIFlowService::class, fn () => new AIFlowService()],
        [AIFacade::class, AIService::class, fn () => new AIService()],
        [AgentFacade::class, AgentService::class, fn () => new AgentService()],
    ];

    foreach ($cases as [$facadeClass, $serviceClass, $factory]) {
        $facadeClass::reset();

        $service = $factory();
        expect($service)->toBeInstanceOf($serviceClass);

        App::di()->set($facadeClass::DI_KEY, $service);

        $resolved = facade_internal_service($facadeClass);
        expect($resolved)->toBe($service);

        $next = $factory();
        App::di()->set($facadeClass::DI_KEY, $next);

        // Should still return cached instance, not the replaced DI entry.
        $resolvedAgain = facade_internal_service($facadeClass);
        expect($resolvedAgain)->toBe($service);

        $facadeClass::reset();
    }
});

it('DI：setService 会同步写入容器（DI_KEY）', function () {
    $cases = [
        [CapabilityFacade::class, fn () => new CapabilityService(AiRuntime::instance())],
        [ParseFacade::class, fn () => new ParseService()],
        [FunctionCallFacade::class, fn () => new FunctionCallService(AiRuntime::instance())],
        [VectorStoreFacade::class, fn () => new VectorStoreService(AiRuntime::instance())],
        [RagEngineFacade::class, fn () => new RagEngineService(AiRuntime::instance())],
        [RagFacade::class, fn () => new RagService()],
        [ToolFacade::class, fn () => new ToolService(new CapabilityService(AiRuntime::instance()))],
        [AIFlowFacade::class, fn () => new AIFlowService()],
        [AIFacade::class, fn () => new AIService()],
        [AgentFacade::class, fn () => new AgentService()],
    ];

    foreach ($cases as [$facadeClass, $factory]) {
        $facadeClass::reset();

        $service = $factory();
        $facadeClass::setService($service);

        expect(App::di()->has($facadeClass::DI_KEY))->toBeTrue()
            ->and(App::di()->get($facadeClass::DI_KEY))->toBe($service);

        $facadeClass::reset();
    }
});
