<?php

declare(strict_types=1);

namespace plugin\sandpackage\app\command;

use plugin\sandpackage\app\service\TerminalRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI replacement for the removed HTTP dependency terminal.
 *
 *   php webman sandpackage:dependency run <插件标识> composer
 *   php webman sandpackage:dependency run <插件标识> npm [--manager pnpm|npm|yarn]
 *   php webman sandpackage:dependency inspect composer.update
 *
 * Interrupted tasks still use `php webman sandpackage:recover inspect-dependency`.
 */
class Dependency extends Command
{
    protected static $defaultName = 'sandpackage:dependency';
    protected static $defaultDescription = '在独立 CLI 进程中执行已登记的插件依赖命令';

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'run|inspect')
            ->addArgument('app', InputArgument::REQUIRED, 'run 时为插件标识；inspect 时为命令键')
            ->addArgument('task', InputArgument::OPTIONAL, 'run 时为 composer 或 npm')
            ->addOption('manager', null, InputOption::VALUE_REQUIRED, 'npm 任务的包管理器：pnpm、npm 或 yarn', 'pnpm');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        try {
            if ($action === 'inspect') {
                $result = TerminalRunner::inspectStart((string) $input->getArgument('app'));
                $io->writeln(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                return Command::SUCCESS;
            }
            if ($action !== 'run') {
                throw new \InvalidArgumentException('未知操作。使用 run 或 inspect。');
            }
            $app = (string) $input->getArgument('app');
            $task = (string) $input->getArgument('task');
            $runner = new TerminalRunner();
            $failed = false;
            foreach ($runner->runCli(self::commandKey($task, (string) $input->getOption('manager')), $app) as $chunk) {
                $decoded = json_decode($chunk, true);
                $line = is_array($decoded) && is_string($decoded['data'] ?? null) ? $decoded['data'] : $chunk;
                $io->writeln($line);
                if ($line === 'exec-error') {
                    $failed = true;
                }
            }
            return $failed ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        }
    }

    private static function commandKey(string $task, string $manager): string
    {
        if ($task === 'composer') {
            return 'composer.update';
        }
        if ($task !== 'npm') {
            throw new \InvalidArgumentException('run 的任务只能是 composer 或 npm。');
        }
        if (!in_array($manager, ['npm', 'yarn', 'pnpm'], true)) {
            throw new \InvalidArgumentException('包管理器只能是 pnpm、npm 或 yarn。');
        }
        return 'web-install.' . $manager;
    }
}
