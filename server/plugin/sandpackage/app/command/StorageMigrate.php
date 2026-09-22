<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\command;

use plugin\sandpackage\app\service\PluginStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Maintenance-window-only persistent storage relocation. */
class StorageMigrate extends Command
{
    protected static $defaultName = 'sandpackage:storage-migrate';
    protected static $defaultDescription = '检查或迁移 SandPackage 旧运行时存储根';

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, '执行同盘整根原子迁移')
            ->addOption('maintenance', null, InputOption::VALUE_NONE, '确认所有 Web、终端和恢复写者已停止');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        if ($apply && !$input->getOption('maintenance')) {
            $io->error('执行迁移必须显式提供 --maintenance，确认维护窗口内所有写者已停止。');
            return Command::FAILURE;
        }
        try {
            $result = (new PluginStorage())->migrate($apply);
            $io->writeln(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        }
    }
}
