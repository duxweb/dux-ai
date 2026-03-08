<?php

declare(strict_types=1);

namespace App\Ai\Interface;

interface FlowCapabilityContextInterface extends CapabilityContextInterface
{
    public function flowId(): int;
}

