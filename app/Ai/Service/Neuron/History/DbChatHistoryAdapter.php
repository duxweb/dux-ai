<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\History;

use App\Ai\Service\Agent\HistoryBuilder;
use App\Ai\Service\Agent\MessageQuery;
use App\Ai\Service\Neuron\MessageAdapter;
use NeuronAI\Chat\History\InMemoryChatHistory;

final class DbChatHistoryAdapter extends InMemoryChatHistory
{
    public function __construct(
        private readonly int $sessionId,
        int $contextWindow = 50000,
        private readonly bool $supportImage = true,
        private readonly bool $supportFile = true,
        private readonly string $imageMode = 'url',
        private readonly string $documentMode = 'base64',
    ) {
        parent::__construct($contextWindow);
        $this->loadFromDatabase();
    }

    private function loadFromDatabase(): void
    {
        $history = MessageQuery::listMessagesForHistory($this->sessionId, 200);
        if ($history === []) {
            return;
        }

        $openaiMessages = HistoryBuilder::buildOpenAIMessagesFromHistory(
            $history,
            $this->supportImage,
            $this->supportFile,
        );

        $this->history = MessageAdapter::fromOpenAIMessages(
            $openaiMessages,
            $this->supportImage,
            $this->supportFile,
            [
                'image_mode' => $this->imageMode,
                'document_mode' => $this->documentMode,
            ]
        );
    }
}
