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
// Prepend this checkout so the tests run against it, not a copy the PSR-0 fallback finds first.
$loader->addPsr4('MagoAssistant\\ClaudeSubscription\\', [dirname(__DIR__)]);
// Generated factories exist only after Magento has generated them.
require_once __DIR__ . '/Unit/Fakes/FieldDescriptorInterfaceFactoryStub.php';
require_once __DIR__ . '/Unit/Fakes/ClaudeSubscriptionClientFactoryStub.php';
