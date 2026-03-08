<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Flow;

final class FlowErrorFormatter
{
    public static function formatNodeThrowableMessage(\Throwable $throwable, int $timeoutMs): string
    {
        $message = trim($throwable->getMessage());
        if ($message === '') {
            $message = sprintf('节点执行异常（%s）', get_class($throwable));
        }

        if (str_contains($message, 'cURL error 28') || str_contains($message, 'Operation timed out')) {
            $timeoutHint = $timeoutMs > 0
                ? sprintf('当前节点 timeout_ms=%d', $timeoutMs)
                : '当前节点未设置 timeout_ms（将使用服务商默认超时）';
            $message = rtrim($message, '。') . sprintf('。%s，可在流程全局或节点配置中调大 timeout_ms。', $timeoutHint);
        }

        return $message;
    }
}

