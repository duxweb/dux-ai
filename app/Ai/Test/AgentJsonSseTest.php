<?php

use App\Ai\Service\Agent\Json;
use App\Ai\Service\Agent\Sse;

function decodeSse(string $chunk): array
{
    expect($chunk)->toStartWith('data: ');
    $json = trim(substr($chunk, 6));
    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

it('JSON：判断是否像 JSON 的字符串', function () {
    expect(Json::isJsonLikeString(''))->toBeFalse()
        ->and(Json::isJsonLikeString('  '))->toBeFalse()
        ->and(Json::isJsonLikeString('{\"a\":1}'))->toBeTrue()
        ->and(Json::isJsonLikeString('[1,2]'))->toBeTrue()
        ->and(Json::isJsonLikeString('"x"'))->toBeTrue()
        ->and(Json::isJsonLikeString('nope'))->toBeFalse();
});

it('JSON：tryDecode 仅在合法 JSON 时返回结果', function () {
    expect(Json::tryDecode('{"a":1}'))->toBe(['a' => 1])
        ->and(Json::tryDecode('nope'))->toBeNull()
        ->and(Json::tryDecode('{bad'))->toBeNull();
});

it('SSE：openAIChunk 生成标准 chunk 格式', function () {
    $chunk = Sse::openAIChunk('hi', 123, 9, 'gpt');
    $data = decodeSse($chunk);

    expect($data['session_id'])->toBe(123)
        ->and($data['id'])->toBe('msg_9')
        ->and($data['object'])->toBe('chat.completion.chunk')
        ->and($data['model'])->toBe('gpt')
        ->and($data['choices'][0]['delta'])->toBe(['content' => 'hi'])
        ->and($data['choices'][0]['finish_reason'])->toBeNull();
});

it('SSE：errorChunk 会包含 error 且 finish_reason=error', function () {
    $chunk = Sse::errorChunk(123, 'gpt', 9, 'boom');
    $data = decodeSse($chunk);

    expect($data['choices'][0]['finish_reason'])->toBe('error')
        ->and($data['error']['message'])->toBe('boom')
        ->and($data['error']['type'])->toBe('agent_error');
});
