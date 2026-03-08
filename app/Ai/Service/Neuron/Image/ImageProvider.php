<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Image;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;

final class ImageProvider extends Agent
{
    public function __construct(private readonly AIProviderInterface $imageProvider)
    {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return $this->imageProvider;
    }

    protected function instructions(): string
    {
        return '';
    }
}
