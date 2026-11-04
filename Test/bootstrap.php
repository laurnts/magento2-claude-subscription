<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

$autoload = dirname(__DIR__, 5) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Run composer install from the Magento root before PHPUnit.');
}
$loader = require $autoload;
// Prepend the worktree module root so tests run against this copy, not the
// main-checkout copy that the PSR-0 fallback would otherwise find first.
$loader->addPsr4('MagoAssistant\\ClaudeSubscription\\', [dirname(__DIR__)]);
