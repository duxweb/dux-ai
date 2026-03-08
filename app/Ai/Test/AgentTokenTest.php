<?php

use App\Ai\Service\Agent\Token;

it('估算 Token：空文本返回 0', function () {
    expect(Token::estimateTokensForText(''))->toBe(0)
        ->and(Token::estimateTokensForText(" \n\t "))->toBe(0);
});

it('估算 Token：ASCII 文本按长度约 4 字符=1 Token', function () {
    expect(Token::estimateTokensForText('a'))->toBe(1)
        ->and(Token::estimateTokensForText('abcd'))->toBe(1)
        ->and(Token::estimateTokensForText('abcde'))->toBe(2)
        ->and(Token::estimateTokensForText(str_repeat('a', 8)))->toBe(2)
        ->and(Token::estimateTokensForText(str_repeat('a', 9)))->toBe(3);
});

it('估算 Token：UTF-8 文本也按字符长度估算', function () {
    expect(Token::estimateTokensForText('你好'))->toBe(1)
        ->and(Token::estimateTokensForText('你好世界'))->toBe(1)
        ->and(Token::estimateTokensForText('你好世界你好'))->toBe(2);
});
