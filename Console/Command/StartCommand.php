<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Starts the Node host in the background.
 */
class StartCommand extends AbstractHostCommand
{
    /**
     * Name shown by bin/magento list.
     */
    public const NAME = 'claude:proxy:start';

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME);
        $this->setDescription('Start the Claude proxy in the background.');
        $this->addOption('bind', null, InputOption::VALUE_REQUIRED, 'Address the host listens on', '127.0.0.1');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port the host listens on', '3456');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bind = (string) $input->getOption('bind');
        $port = (string) $input->getOption('port');
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $output->writeln('<error>Port must be a number from 1 to 65535.</error>');
            return Cli::RETURN_FAILURE;
        }
        $script = $this->scriptPath();
        if (!$this->filesystem->isFile($script)) {
            $output->writeln('<error>Host script was not found at ' . $script . '.</error>');
            return Cli::RETURN_FAILURE;
        }
        if (!$this->binaryRuns('node') || !$this->binaryRuns('claude')) {
            return $this->refuse($output, 'start');
        }
        return $this->runScript($output, ['start', '--host', $bind, '--port', $port]);
    }
}
