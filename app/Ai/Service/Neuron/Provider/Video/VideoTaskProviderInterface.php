<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Provider\Video;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;

interface VideoTaskProviderInterface extends AIProviderInterface
{
    public function createTask(Message ...$messages): Message;

    public function queryTask(string $taskId): Message;

    public function cancelTask(string $taskId): Message;
}
