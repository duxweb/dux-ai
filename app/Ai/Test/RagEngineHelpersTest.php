<?php

use App\Ai\Service\RagEngine;
use App\Ai\Service\Rag\KnowledgeId;
use App\Ai\Service\Rag\SourceId;
use App\Ai\Service\Rag\SourceType;

it('配置归一化：对象配置保持原样', function () {
    expect(RagEngine::normalizeConfig(['a' => 1, 'b' => 'x']))->toBe(['a' => 1, 'b' => 'x']);
});

it('配置归一化：不再兼容 KV 列表（直接抛错）', function () {
    $cfg = [
        ['name' => ' api_key ', 'value' => 'k'],
        ['name' => 'empty', 'value' => null],
        ['name' => '', 'value' => 'x'],
        'bad',
    ];
    expect(fn () => RagEngine::normalizeConfig($cfg))
        ->toThrow(\Core\Handlers\ExceptionBusiness::class, '配置格式错误');
});

it('知识库 ID：从 remote_id 解析 knowledge_id', function () {
    expect(KnowledgeId::parse('neuron:123'))->toBe(123)
        ->and(KnowledgeId::parse(' neuron:0009 '))->toBe(9)
        ->and(KnowledgeId::parse('neuron:abc'))->toBe(0);
});

it('SourceId：格式化与解析', function () {
    $id = SourceId::format('rag_doc', 'k1_d2');
    expect($id)->toBe('rag_doc::k1_d2');

    expect(SourceId::parse($id))->toBe(['rag_doc', 'k1_d2'])
        ->and(SourceId::parse('  rag_doc::k1_d2  '))->toBe(['rag_doc', 'k1_d2'])
        ->and(SourceId::parse('invalid'))->toBeNull();
});

it('SourceType：根据内容类型映射', function () {
    expect(SourceType::forAssetType('qa'))->toBe('rag_qa')
        ->and(SourceType::forAssetType('sheet'))->toBe('rag_sheet')
        ->and(SourceType::forAssetType('document'))->toBe('rag_doc')
        ->and(SourceType::forAssetType(''))->toBe('rag_data');
});
