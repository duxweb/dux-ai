<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiAgentMessage;
use App\Ai\Models\AiAgentSession;
use App\Ai\Service\Agent as AgentServiceEntry;
use App\Boot\Models\BootBot;
use App\Boot\Service\BotService;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Message as BotMessage;
use Core\App;
use Symfony\Component\Lock\LockInterface;

final class BotBridgeService
{
    public const REPLY_MODE_REPLY_TEXT = 'reply_text';
    public const REPLY_MODE_ACK_ONLY = 'ack_only';

    private const SESSION_LOCK_TTL = 180;
    private const EVENT_LOCK_TTL = 180;
    private const EVENT_STATUS_TTL_PROCESSING = 180;
    private const EVENT_STATUS_TTL_DONE = 86400;
    private const EVENT_STATUS_PROCESSING = 'processing';
    private const EVENT_STATUS_DONE = 'done';
    private const BUSY_REPLY = '当前会话正在处理中，请稍后重试';
    private const UNBOUND_REPLY = '系统已收到您的消息';
    /** @var array<int, bool> */
    private static array $relaySuppressedSessions = [];

    /**
     * @return array{mode:string,text?:string}
     */
    public function handleInbound(BootBot $bot, InboundMessage $message): array
    {
        $text = trim((string)$message->text);
        if ($text === '') {
            return $this->ackOnly();
        }

        $agent = $this->resolveBoundAgentByBotCode((string)$bot->code);
        if (!$agent) {
            return $this->fallbackWhenUnbound($bot);
        }

        $eventStatus = $this->getEventStatus($bot, $message);
        if ($eventStatus === self::EVENT_STATUS_DONE || $eventStatus === self::EVENT_STATUS_PROCESSING) {
            App::log('ai.agent')->info('ai.agent.bot.event.ignored', [
                'bot_code' => (string)$bot->code,
                'conversation_id' => (string)$message->conversationId,
                'event_id' => (string)$message->eventId,
                'event_status' => $eventStatus,
                'event_lock' => false,
                'session_lock' => false,
                'reply_mode' => self::REPLY_MODE_ACK_ONLY,
            ]);
            return $this->ackOnly();
        }

        $eventLock = $this->acquireEventLock($bot, $message);
        if (!$eventLock) {
            App::log('ai.agent')->info('ai.agent.bot.event.lock.busy', [
                'bot_code' => (string)$bot->code,
                'conversation_id' => (string)$message->conversationId,
                'event_id' => (string)$message->eventId,
                'event_status' => 'unknown',
                'event_lock' => false,
                'session_lock' => false,
                'reply_mode' => self::REPLY_MODE_ACK_ONLY,
            ]);
            return $this->ackOnly();
        }

        $status = $this->getEventStatus($bot, $message);
        if ($status === self::EVENT_STATUS_DONE || $status === self::EVENT_STATUS_PROCESSING) {
            $eventLock->release();
            App::log('ai.agent')->info('ai.agent.bot.event.ignored', [
                'bot_code' => (string)$bot->code,
                'conversation_id' => (string)$message->conversationId,
                'event_id' => (string)$message->eventId,
                'event_status' => $status,
                'event_lock' => true,
                'session_lock' => false,
                'reply_mode' => self::REPLY_MODE_ACK_ONLY,
            ]);
            return $this->ackOnly();
        }

        $this->setEventStatus($bot, $message, self::EVENT_STATUS_PROCESSING, self::EVENT_STATUS_TTL_PROCESSING);

        $lock = $this->acquireSessionLock($bot, $message);
        if (!$lock) {
            $this->clearEventStatus($bot, $message);
            $eventLock->release();
            App::log('ai.agent')->info('ai.agent.bot.lock.busy', [
                'bot_code' => (string)$bot->code,
                'conversation_id' => (string)$message->conversationId,
                'sender_id' => (string)$message->senderId,
                'event_id' => (string)$message->eventId,
                'event_status' => 'new',
                'event_lock' => true,
                'session_lock' => false,
                'reply_mode' => self::REPLY_MODE_REPLY_TEXT,
            ]);
            return $this->replyText(self::BUSY_REPLY);
        }

        $session = null;
        $syncReplyPreferred = (string)$bot->platform !== 'wecom';
        $suppressRelay = $syncReplyPreferred;

        try {
            $session = $this->resolveInboundSession($agent, $bot, $message);
            if ($suppressRelay && (int)$session->id > 0) {
                self::$relaySuppressedSessions[(int)$session->id] = true;
            }

            $messages = [[
                'role' => 'user',
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ]],
            ]];

            $generator = AgentServiceEntry::streamChat(
                (string)$agent->code,
                $messages,
                (int)$session->id,
                'boot_bot',
                (int)$bot->id,
            );
            $reply = $this->collectReplyText($generator);
            $finalReply = $this->latestAssistantMessageText((int)$session->id);
            if ($finalReply !== '') {
                $reply = $finalReply;
            }
            if ($reply === '') {
                $reply = '已处理，请稍后查看结果';
            }
            $this->setEventStatus($bot, $message, self::EVENT_STATUS_DONE, self::EVENT_STATUS_TTL_DONE);
            App::log('ai.agent')->info('ai.agent.bot.event.handled', [
                'bot_code' => (string)$bot->code,
                'conversation_id' => (string)$message->conversationId,
                'event_id' => (string)$message->eventId,
                'event_status' => self::EVENT_STATUS_DONE,
                'event_lock' => true,
                'session_lock' => true,
                'reply_mode' => $syncReplyPreferred ? self::REPLY_MODE_REPLY_TEXT : self::REPLY_MODE_ACK_ONLY,
                'reply_length' => mb_strlen($reply, 'UTF-8'),
            ]);
            if (!$syncReplyPreferred) {
                return $this->ackOnly();
            }
            return $this->replyText($reply);
        } catch (\Throwable $e) {
            App::log('ai.agent')->warning('ai.agent.bot.inbound.failed', [
                'bot_code' => (string)$bot->code,
                'conversation_id' => (string)$message->conversationId,
                'sender_id' => (string)$message->senderId,
                'error' => $e->getMessage(),
                'event_status' => self::EVENT_STATUS_DONE,
                'event_lock' => true,
                'session_lock' => true,
                'reply_mode' => self::REPLY_MODE_REPLY_TEXT,
            ]);
            $this->setEventStatus($bot, $message, self::EVENT_STATUS_DONE, self::EVENT_STATUS_TTL_DONE);
            return $this->replyText('当前服务繁忙，请稍后再试。');
        } finally {
            if ($suppressRelay && $session instanceof AiAgentSession) {
                unset(self::$relaySuppressedSessions[(int)$session->id]);
            }
            $lock->release();
            $eventLock->release();
        }
    }

    public function relayAssistantMessage(AiAgentMessage $message): void
    {
        if ((string)$message->role !== 'assistant') {
            return;
        }
        $payload = is_array($message->payload ?? null) ? ($message->payload ?? []) : [];
        if ((bool)($payload['error'] ?? false)) {
            return;
        }

        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()->find((int)$message->session_id);
        if (!$session || (string)$session->user_type !== 'boot_bot') {
            return;
        }
        if ((bool)(self::$relaySuppressedSessions[(int)$session->id] ?? false)) {
            return;
        }

        $state = is_array($session->state ?? null) ? ($session->state ?? []) : [];
        $bridge = is_array($state['bridge'] ?? null) ? ($state['bridge'] ?? []) : [];

        $botCode = trim((string)($bridge['bot_code'] ?? ''));
        if ($botCode === '') {
            /** @var BootBot|null $bot */
            $bot = BootBot::query()->find((int)($session->user_id ?? 0));
            $botCode = trim((string)($bot?->code ?? ''));
        }
        if ($botCode === '') {
            return;
        }

        $outbound = $this->buildOutboundMessage($message);
        if (!$outbound) {
            return;
        }

        $conversationId = trim((string)($bridge['conversation_id'] ?? ''));
        if ($conversationId === '') {
            $conversationId = trim((string)($bridge['sender_id'] ?? ''));
        }
        if ($conversationId !== '') {
            $outbound->conversationId($conversationId);
        }

        $platform = trim((string)($bridge['platform'] ?? ''));
        if ($platform === 'qq_bot') {
            $outbound->meta($this->qqMetaFromBridge($bridge));
        }

        try {
            (new BotService())->sendMessageByCode($botCode, $outbound);
        } catch (\Throwable $e) {
            App::log('ai.agent')->error('ai.agent.bot.relay.failed', [
                'session_id' => (int)$session->id,
                'agent_id' => (int)$session->agent_id,
                'bot_code' => $botCode,
                'message_id' => (int)$message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveBoundAgentByBotCode(string $botCode): ?AiAgent
    {
        $botCode = trim($botCode);
        if ($botCode === '') {
            return null;
        }

        /** @var \Illuminate\Support\Collection<int, AiAgent> $agents */
        $agents = AiAgent::query()
            ->where('active', true)
            ->orderByDesc('id')
            ->get();

        foreach ($agents as $agent) {
            $settings = is_array($agent->settings ?? null) ? ($agent->settings ?? []) : [];
            if (!is_array($settings['bot_codes'] ?? null)) {
                continue;
            }
            foreach (($settings['bot_codes'] ?? []) as $code) {
                if (trim((string)$code) === $botCode) {
                    return $agent;
                }
            }
        }

        return null;
    }

    private function resolveInboundSession(AiAgent $agent, BootBot $bot, InboundMessage $message): AiAgentSession
    {
        $externalId = $this->buildExternalId($bot, $message);
        /** @var AiAgentSession|null $session */
        $session = AiAgentSession::query()
            ->where('agent_id', (int)$agent->id)
            ->where('user_type', 'boot_bot')
            ->where('user_id', (int)$bot->id)
            ->where('external_id', $externalId)
            ->first();

        if ($session) {
            $session->state = $this->mergeBridgeState($session, $bot, $message, null);
            $session->save();
            return $session;
        }

        /** @var AiAgentSession $created */
        $created = AiAgentSession::query()->create([
            'agent_id' => (int)$agent->id,
            'title' => trim((string)$message->senderName) ?: null,
            'external_id' => $externalId,
            'user_type' => 'boot_bot',
            'user_id' => (int)$bot->id,
            'state' => [
                'bridge' => $this->buildBridgeState($bot, $message),
            ],
            'active' => true,
        ]);

        return $created;
    }

    private function buildExternalId(BootBot $bot, InboundMessage $message): string
    {
        $conversationId = trim((string)$message->conversationId);
        $senderId = trim((string)$message->senderId);
        if ($conversationId === '') {
            $conversationId = $senderId !== '' ? $senderId : 'unknown';
        }

        return sprintf('%s:%s', (string)$bot->code, $conversationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBridgeState(BootBot $bot, InboundMessage $message): array
    {
        $state = [
            'bot_code' => (string)$bot->code,
            'platform' => (string)$bot->platform,
            'conversation_id' => trim((string)$message->conversationId),
            'sender_id' => trim((string)$message->senderId),
            'last_event_id' => trim((string)$message->eventId),
        ];

        $qq = $this->extractQqMeta($message);
        if ($qq !== []) {
            $state['qq'] = $qq;
        }

        return $state;
    }

    /**
     * @param array<string, mixed>|null $override
     * @return array<string, mixed>
     */
    private function mergeBridgeState(AiAgentSession $session, BootBot $bot, InboundMessage $message, ?array $override): array
    {
        $state = is_array($session->state ?? null) ? ($session->state ?? []) : [];
        $bridge = is_array($state['bridge'] ?? null) ? ($state['bridge'] ?? []) : [];
        $bridge = array_merge($bridge, $this->buildBridgeState($bot, $message));
        if (is_array($override)) {
            $bridge = array_merge($bridge, $override);
        }
        $state['bridge'] = $bridge;
        return $state;
    }

    private function collectReplyText(\Generator $generator): string
    {
        $reply = '';
        foreach ($generator as $chunk) {
            $payload = OpenAiHttp::decodeSseChunk((string)$chunk);
            if (!is_array($payload)) {
                continue;
            }
            if (is_array($payload['error'] ?? null)) {
                $message = trim((string)($payload['error']['message'] ?? ''));
                if ($message !== '') {
                    return $this->friendlyErrorReply($message);
                }
            }
            $delta = $payload['choices'][0]['delta']['content'] ?? '';
            if (is_string($delta)) {
                $reply .= $delta;
            }
        }
        return trim($reply);
    }

    private function friendlyErrorReply(string $message): string
    {
        $lower = strtolower(trim($message));
        if ($lower === '') {
            return '当前服务繁忙，请稍后再试。';
        }

        foreach ([
            'requestbursttoofast',
            'toomanyrequests',
            'too many requests',
            'rate limit',
            'http 429',
            'timed out',
            'timeout',
            'network error during post chat/completions',
            'connection',
            'service unavailable',
            'overloaded',
            'request id:',
            '"error":{',
        ] as $keyword) {
            if (str_contains($lower, $keyword)) {
                return '当前服务繁忙，请稍后再试。';
            }
        }

        if (mb_strlen($message, 'UTF-8') > 160) {
            return '当前服务繁忙，请稍后再试。';
        }

        return $message;
    }

    private function latestAssistantMessageText(int $sessionId): string
    {
        $content = (string)(AiAgentMessage::query()
            ->where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->value('content') ?? '');
        return trim($content);
    }

    private function buildOutboundMessage(AiAgentMessage $message): ?BotMessage
    {
        $payload = is_array($message->payload ?? null) ? ($message->payload ?? []) : [];
        $text = trim((string)($message->content ?? ''));
        if ($text === '') {
            $text = trim((string)($payload['summary'] ?? $payload['tool_summary'] ?? $payload['message'] ?? ''));
        }

        $imageUrl = $this->extractImageUrl($payload);
        if ($imageUrl !== '') {
            return BotMessage::image($imageUrl, $text !== '' ? $text : '图片已生成');
        }
        $videoUrl = $this->extractVideoUrl($payload);
        if ($videoUrl !== '') {
            $title = $text;
            if ($title !== '' && str_contains($title, $videoUrl)) {
                $title = trim(str_replace($videoUrl, '', $title));
            }
            if (preg_match('/^视频任务已完成（[^）]+）$/u', $title) === 1) {
                $title = '视频已生成';
            }
            $message = BotMessage::video($videoUrl, $title !== '' ? $title : '视频已生成');
            $meta = [];
            $async = is_array($payload['async'] ?? null) ? ($payload['async'] ?? []) : [];
            if (is_array($async['video_compress'] ?? null)) {
                $meta['video_compress'] = $async['video_compress'];
            }
            $storedVideos = is_array($async['stored_videos'] ?? null) ? ($async['stored_videos'] ?? []) : [];
            foreach ($storedVideos as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (trim((string)($item['storage_url'] ?? '')) !== $videoUrl) {
                    continue;
                }
                $meta['video_source'] = [
                    'local_path' => trim((string)($item['local_path'] ?? '')) ?: null,
                    'remote_url' => trim((string)($item['remote_url'] ?? '')),
                    'storage_url' => trim((string)($item['storage_url'] ?? '')),
                ];
                break;
            }
            if ($meta !== []) {
                $message->meta($meta);
            }
            return $message;
        }
        if ($text === '') {
            return null;
        }
        return BotMessage::text($text);
    }

    private function extractImageUrl(array $payload): string
    {
        $paths = [
            ['result', 'data', 'image_url'],
            ['result', 'data', 'image'],
            ['result', 'data', 'images', 0],
            ['result', 'image_url'],
            ['result', 'image'],
            ['result', 'images', 0],
            ['image_url'],
            ['image'],
            ['images', 0],
            ['parts', 0, 'image_url', 'url'],
        ];

        foreach ($paths as $path) {
            $value = $this->valueByPath($payload, $path);
            $url = $this->firstUrlValue($value);
            if ($url !== '') {
                return $url;
            }
        }

        $parts = is_array($payload['parts'] ?? null) ? ($payload['parts'] ?? []) : [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (($part['type'] ?? '') !== 'image_url') {
                continue;
            }
            $url = $this->firstUrlValue($part['image_url'] ?? null);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function extractVideoUrl(array $payload): string
    {
        $paths = [
            ['result', 'data', 'video_url'],
            ['result', 'data', 'video'],
            ['result', 'data', 'videos', 0],
            ['result', 'video_url'],
            ['result', 'video'],
            ['result', 'videos', 0],
            ['video_url'],
            ['video'],
            ['videos', 0],
            ['parts', 0, 'video_url', 'url'],
        ];

        foreach ($paths as $path) {
            $value = $this->valueByPath($payload, $path);
            $url = $this->firstUrlValue($value);
            if ($url !== '') {
                return $url;
            }
        }

        $parts = is_array($payload['parts'] ?? null) ? ($payload['parts'] ?? []) : [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (($part['type'] ?? '') !== 'video_url') {
                continue;
            }
            $url = $this->firstUrlValue($part['video_url'] ?? null);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function valueByPath(array $data, array $path): mixed
    {
        $current = $data;
        foreach ($path as $key) {
            if (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
                continue;
            }
            return null;
        }
        return $current;
    }

    private function firstUrlValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value)) {
            foreach (['url', 'image_url', 'image'] as $key) {
                if (isset($value[$key])) {
                    $url = $this->firstUrlValue($value[$key]);
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
            foreach (['video_url', 'video', 'download_url', 'output_url'] as $key) {
                if (isset($value[$key])) {
                    $url = $this->firstUrlValue($value[$key]);
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
            foreach ($value as $item) {
                $url = $this->firstUrlValue($item);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractQqMeta(InboundMessage $message): array
    {
        $raw = is_array($message->raw ?? null) ? ($message->raw ?? []) : [];
        $data = is_array($raw['d'] ?? null) ? ($raw['d'] ?? []) : $raw;

        $meta = [];
        $groupOpenId = trim((string)($data['group_openid'] ?? ''));
        if ($groupOpenId !== '') {
            $meta['group_openid'] = $groupOpenId;
        }
        $userOpenId = trim((string)($data['author']['user_openid'] ?? $data['author']['member_openid'] ?? ''));
        if ($userOpenId !== '') {
            $meta['user_openid'] = $userOpenId;
        }
        return $meta;
    }

    /**
     * @param array<string, mixed> $bridge
     * @return array<string, mixed>
     */
    private function qqMetaFromBridge(array $bridge): array
    {
        $meta = [];
        $qq = is_array($bridge['qq'] ?? null) ? ($bridge['qq'] ?? []) : [];
        if (trim((string)($qq['group_openid'] ?? '')) !== '') {
            $meta['group_openid'] = (string)$qq['group_openid'];
        }
        if (trim((string)($qq['user_openid'] ?? '')) !== '') {
            $meta['user_openid'] = (string)$qq['user_openid'];
        }
        $msgId = trim((string)($bridge['last_event_id'] ?? ''));
        if ($msgId !== '') {
            $meta['msg_id'] = $msgId;
        }
        if ($meta !== [] && !array_key_exists('msg_seq', $meta)) {
            $meta['msg_seq'] = 1;
        }
        return $meta;
    }

    private function acquireSessionLock(BootBot $bot, InboundMessage $message): ?LockInterface
    {
        $externalId = $this->buildExternalId($bot, $message);
        $key = sprintf('ai:bot_bridge:session:%s', sha1($externalId));
        $lock = App::lock()->createLock($key, self::SESSION_LOCK_TTL);
        if (!$lock->acquire(false)) {
            return null;
        }
        return $lock;
    }

    private function acquireEventLock(BootBot $bot, InboundMessage $message): ?LockInterface
    {
        $fingerprint = $this->eventFingerprint($message);
        $key = sprintf('ai:bot_bridge:event_lock:%d:%s', (int)$bot->id, sha1($fingerprint));
        $lock = App::lock()->createLock($key, self::EVENT_LOCK_TTL);
        if (!$lock->acquire(false)) {
            return null;
        }
        return $lock;
    }

    private function getEventStatus(BootBot $bot, InboundMessage $message): string
    {
        $key = $this->eventStatusCacheKey($bot, $message);
        return trim((string)App::cache()->get($key, ''));
    }

    private function setEventStatus(BootBot $bot, InboundMessage $message, string $status, int $ttl): void
    {
        $key = $this->eventStatusCacheKey($bot, $message);
        App::cache()->set($key, $status, $ttl);
    }

    private function clearEventStatus(BootBot $bot, InboundMessage $message): void
    {
        $key = $this->eventStatusCacheKey($bot, $message);
        App::cache()->delete($key);
    }

    private function eventStatusCacheKey(BootBot $bot, InboundMessage $message): string
    {
        $fingerprint = $this->eventFingerprint($message);
        return sprintf('ai:bot_bridge:event_status:%d:%s', (int)$bot->id, sha1($fingerprint));
    }

    private function eventFingerprint(InboundMessage $message): string
    {
        $eventId = trim((string)$message->eventId);
        if ($eventId !== '') {
            return $eventId;
        }
        return sha1(implode('|', [
            trim((string)$message->platform),
            trim((string)$message->conversationId),
            trim((string)$message->senderId),
            (string)$message->timestamp,
            trim((string)$message->text),
        ]));
    }


    /**
     * 未绑定智能体时，所有机器人渠道都返回可见兜底，避免用户误以为是系统无响应。
     *
     * @return array{mode:string,text?:string}
     */
    private function fallbackWhenUnbound(BootBot $bot): array
    {
        return $this->replyText(self::UNBOUND_REPLY);
    }

    /**
     * @return array{mode:string}
     */
    private function ackOnly(): array
    {
        return [
            'mode' => self::REPLY_MODE_ACK_ONLY,
        ];
    }

    /**
     * @return array{mode:string,text:string}
     */
    private function replyText(string $text): array
    {
        return [
            'mode' => self::REPLY_MODE_REPLY_TEXT,
            'text' => trim($text),
        ];
    }
}
