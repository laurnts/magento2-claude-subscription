<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Console\Command;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem\DriverInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Shared lookup for the host script used by start and stop.
 */
abstract class AbstractHostCommand extends Command
{
    /**
     * @param DriverInterface $filesystem
     * @param ComponentRegistrarInterface $registrar
     * @param string|null $script Absolute launcher path. Null uses this module's copy.
     */
    public function __construct(
        protected readonly DriverInterface $filesystem,
        protected readonly ComponentRegistrarInterface $registrar,
        private readonly ?string $script = null,
    ) {
        parent::__construct();
    }

    /**
     * Absolute path of bin/claude-subscription.
     *
     * @return string
     */
    protected function scriptPath(): string
    {
        if ($this->script !== null) {
            return $this->script;
        }
        $module = $this->registrar->getPath(
            ComponentRegistrar::MODULE,
            'MagoAssistant_ClaudeSubscription'
        );
        if (!is_string($module) || $module === '') {
            return '';
        }
        return $module . '/bin/claude-subscription';
    }

    /**
     * Failure text when this PHP process cannot see node and claude.
     *
     * @param OutputInterface $output
     * @param string $verb start or stop
     * @return int
     */
    protected function refuse(OutputInterface $output, string $verb): int
    {
        $output->writeln('<error>node and claude must both be on PATH in this process.</error>');
        $output->writeln('On the Docker host, from the Magento root:');
        $output->writeln('  ' . $this->printableScript() . ' ' . $verb);
        return Cli::RETURN_FAILURE;
    }

    /**
     * Run the launcher. The launcher returns as soon as start or stop is done.
     *
     * @param OutputInterface $output
     * @param string[] $arguments
     * @return int
     */
    protected function runScript(OutputInterface $output, array $arguments): int
    {
        $process = new Process(array_merge(['bash', $this->scriptPath()], $arguments));
        $process->setTimeout(20);
        try {
            $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });
        } catch (RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        $code = $process->getExitCode();
        return $code === null ? Cli::RETURN_FAILURE : $code;
    }

    /**
     * True when the binary runs and exits successfully.
     *
     * @param string $binary
     * @return bool
     */
    protected function binaryRuns(string $binary): bool
    {
        $process = new Process([$binary, '--version']);
        $process->setTimeout(5);
        try {
            $process->run();
        } catch (RuntimeException) {
            return false;
        }
        return $process->isSuccessful();
    }

    /**
     * Script path relative to the Magento root when that root is known.
     *
     * @return string
     */
    private function printableScript(): string
    {
        $script = $this->scriptPath();
        $root = defined('BP') ? BP : '';
        if ($root !== '' && str_starts_with($script, $root . '/')) {
            return substr($script, strlen($root) + 1);
        }
        return $script;
    }
}
