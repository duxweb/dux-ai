<?php

declare(strict_types=1);

namespace App\Ai\Event;

final class ActionEvent
{
    /**
     * @var array<string, mixed>
     */
    private array $params;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $messages = [];

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param array<string, mixed> $message
     */
    public function addMessage(array $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
