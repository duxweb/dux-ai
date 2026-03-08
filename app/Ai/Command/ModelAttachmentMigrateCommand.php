<?php

declare(strict_types=1);

namespace App\Ai\Command;

use App\Ai\Service\Agent\AttachmentConfig;
use Core\Command\Attribute\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Command]
class ModelAttachmentMigrateCommand extends SymfonyCommand
{
    protected function configure(): void
    {
        $this->setName('ai:model-attachments:migrate')
            ->setDescription('补齐 chat 模型附件配置 options.attachments 默认结构');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = AttachmentConfig::migrateModelAttachments();
        $output->writeln(sprintf('<info>模型附件配置迁移完成：%d 条</info>', $count));
        return SymfonyCommand::SUCCESS;
    }
}

