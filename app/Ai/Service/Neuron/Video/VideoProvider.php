<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Video;

use App\Ai\Service\Neuron\Provider\Video\VideoTaskProviderInterface;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

final class VideoProvider extends Agent
{
    public function __construct(private readonly VideoTaskProviderInterface $videoProvider)
    {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return $this->videoProvider;
    }

    protected function instructions(): string
    {
        return '';
    }

    public function createTask(string $prompt): Message
    {
        return $this->chat(new UserMessage($prompt))->getMessage();
    }

    public function queryTask(string $taskId): Message
    {
        return $this->videoProvider->queryTask($taskId);
    }

    public function cancelTask(string $taskId): Message
    {
        return $this->videoProvider->cancelTask($taskId);
    }
}
