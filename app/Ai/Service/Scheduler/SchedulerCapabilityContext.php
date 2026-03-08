<?php

declare(strict_types=1);

namespace App\Ai\Service\Scheduler;

use App\Ai\Interface\CapabilityContextInterface;

final class SchedulerCapabilityContext implements CapabilityContextInterface
{
    public function __construct(
        private readonly string $scope,
    ) {
    }

    public function scope(): string
    {
        return $this->scope === 'flow' ? 'flow' : 'agent';
    }
}

