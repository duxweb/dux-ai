<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class AiFlowInterrupt extends Model
{
    protected $table = 'ai_flow_interrupt';

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('workflow_id')->unique()->comment('Neuron工作流ID');
        $table->longText('interrupt')->nullable()->comment('Neuron 中断序列化数据');
        $table->timestamps();
    }
}

