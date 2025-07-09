<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Junu\Dunning\Config\ShopConfig;
use Junu\Dunning\Exception\ConfigurationException;
use Junu\Dunning\Service\DunningProcessor;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dryRun = in_array('--dry-run', $argv, true);
file_put_contents(__DIR__ . '/logs/dunning.log', '');
$log = new Logger('dunning');
$log->pushHandler(new StreamHandler(__DIR__ . '/logs/dunning.log', Logger::DEBUG));

try {
    $config = new ShopConfig(__DIR__ . '/config/shops.json');
    $processor = new DunningProcessor($config, $log, $dryRun);

    pcntl_signal(SIGTERM, function () use ($log, $processor) {
        $log->info('Received SIGTERM, shutting down gracefully');
        $processor->shutdown();
        exit(0);
    });

    $log->info('Starting dunning cycle', ['dry_run' => $dryRun]);
    $processor->process();
    $log->info('Dunning cycle completed');
    exit(0);
} catch (ConfigurationException $e) {
    $log->error('Configuration error: ' . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    $log->error('Unexpected error: ' . $e->getMessage());
    exit(1);
}