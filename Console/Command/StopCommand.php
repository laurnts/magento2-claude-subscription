<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stops the Node host started by claude:proxy:start.
 */
class StopCommand extends AbstractHostCommand
{
    /**
     * Name shown by bin/magento list.
     */
    public const NAME = 'claude:proxy:stop';

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME);
        $this->setDescription('Stop the Claude proxy.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $script = $this->scriptPath();
        if (!$this->filesystem->isFile($script)) {
            $output->writeln('<error>Host script was not found at ' . $script . '.</error>');
            return Cli::RETURN_FAILURE;
        }
        if (!$this->binaryRuns('node') || !$this->binaryRuns('claude')) {
            return $this->refuse($output, 'stop');
        }
        return $this->runScript($output, ['stop']);
    }
}
