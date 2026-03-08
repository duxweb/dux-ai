<?php

declare(strict_types=1);

namespace App\Ai\Interface;

interface CapabilityContextInterface
{
    /**
     * @return 'agent'|'flow'
     */
    public function scope(): string;
}
