<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Test\Unit\Console;

use MagoAssistant\ClaudeSubscription\Console\Command\StartCommand;
use MagoAssistant\ClaudeSubscription\Console\Command\StopCommand;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\DriverInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class HostCommandTest extends TestCase
{
    #[Test]
    public function startAndStopHaveTheirOwnNames(): void
    {
        $filesystem = $this->filesystem(false);
        $registrar = $this->createStub(ComponentRegistrarInterface::class);

        $start = new StartCommand($filesystem, $registrar, '/not/a/real/claude-subscription');
        $stop = new StopCommand($filesystem, $registrar, '/not/a/real/claude-subscription');

        self::assertSame('claude:proxy:start', $start->getName());
        self::assertSame('claude:proxy:stop', $stop->getName());
    }

    #[Test]
    public function aMissingScriptDoesNotStartNode(): void
    {
        $command = new StartCommand(
            $this->filesystem(false),
            $this->createStub(ComponentRegistrarInterface::class),
            '/not/a/real/claude-subscription'
        );
        $tester = new CommandTester($command);

        $status = $tester->execute([]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Host script was not found', $tester->getDisplay());
    }

    #[Test]
    public function aPortOutsideTheRangeIsRejected(): void
    {
        $command = new StartCommand(
            $this->filesystem(true),
            $this->createStub(ComponentRegistrarInterface::class),
            '/not/a/real/claude-subscription'
        );
        $tester = new CommandTester($command);

        $status = $tester->execute(['--port' => '70000']);

        self::assertSame(1, $status);
        self::assertStringContainsString('Port must be a number from 1 to 65535.', $tester->getDisplay());
    }

    #[Test]
    public function stopWithAMissingScriptDoesNotLookForAProcess(): void
    {
        $command = new StopCommand(
            $this->filesystem(false),
            $this->createStub(ComponentRegistrarInterface::class),
            '/not/a/real/claude-subscription'
        );
        $tester = new CommandTester($command);

        $status = $tester->execute([]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Host script was not found', $tester->getDisplay());
    }

    #[Test]
    public function theLauncherStartsAndStopsABackgroundProcess(): void
    {
        $root = sys_get_temp_dir() . '/claude-subscription-' . bin2hex(random_bytes(4));
        $moduleBin = $root . '/mod/bin';
        $fakeBin = $root . '/fakebin';
        mkdir($moduleBin, 0777, true);
        mkdir($root . '/bin', 0777, true);
        mkdir($fakeBin, 0777, true);
        file_put_contents($root . '/bin/magento', '');
        file_put_contents($fakeBin . '/node', "#!/bin/sh\nexec sleep 60\n");
        chmod($fakeBin . '/node', 0755);
        $source = dirname(__DIR__, 3) . '/bin/claude-subscription';
        copy($source, $moduleBin . '/claude-subscription');
        chmod($moduleBin . '/claude-subscription', 0755);
        file_put_contents($moduleBin . '/claude-subscription-host.mjs', '');
        $env = [
            'PATH' => $fakeBin . ':/usr/bin:/bin',
            'HOME' => $root,
        ];
        $script = $moduleBin . '/claude-subscription';

        try {
            $start = new Process(['bash', $script, 'start', '--port', '3456'], null, $env);
            $start->setTimeout(10);
            $start->mustRun();
            self::assertStringContainsString('Started', $start->getOutput());
            $pid = trim((string) file_get_contents($root . '/var/claude-subscription.pid'));
            self::assertMatchesRegularExpression('/^[0-9]+$/', $pid);

            $again = new Process(['bash', $script, 'start'], null, $env);
            $again->setTimeout(10);
            $again->mustRun();
            self::assertStringContainsString('Already running', $again->getOutput());

            $stop = new Process(['bash', $script, 'stop'], null, $env);
            $stop->setTimeout(15);
            $stop->mustRun();
            self::assertStringContainsString('Stopped ' . $pid, $stop->getOutput());
            self::assertFileDoesNotExist($root . '/var/claude-subscription.pid');
        } finally {
            $cleanup = new Process(['bash', $script, 'stop'], null, $env);
            $cleanup->setTimeout(15);
            $cleanup->run();
            $this->removeTree($root);
        }
    }

    /**
     * Filesystem double. The flag is what isFile() reports.
     *
     * @param bool $exists
     * @return DriverInterface
     */
    private function filesystem(bool $exists): DriverInterface
    {
        $filesystem = $this->createStub(DriverInterface::class);
        $filesystem->method('isFile')->willReturn($exists);
        return $filesystem;
    }

    /**
     * Delete a temp Magento root created by the launcher test.
     *
     * @param string $root
     * @return void
     */
    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($root);
    }
}
