<?php

declare(strict_types=1);

namespace App\Ai\Interface;

final class NullCapabilityContext implements CapabilityContextInterface
{
    public function scope(): string
    {
        return 'agent';
    }
}
