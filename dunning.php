<?php

declare(strict_types=1);

namespace JunuDunning;

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use JunuDunning\Config\ShopConfig;
use JunuDunning\Service\BrevoMailer;
use JunuDunning\Service\DunningProcessor;
use JunuDunning\Service\ShopwareClient;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/vendor/autoload.php';

// Parse command-line options
$options = getopt('', ['dry-run']);
$isDryRun = isset($options['dry-run']);

// Load environment variables
Dotenv::createImmutable(__DIR__)->load();

// Initialize logger
$logger = new Logger('junu_dunning');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/dunning.log', Logger::INFO));

// Initialize services
$httpClient = new Client([
    'timeout' => 10.0,
    'headers' => ['Accept' => 'application/json'],
]);
$shops = ShopConfig::fromEnv();
$mailer = new BrevoMailer();
$processor = new DunningProcessor($httpClient, $mailer, $logger, $isDryRun);

// Create dry-run directories if needed
if ($isDryRun) {
    $baseDryRunDir = __DIR__ . '/dry-run';
    if (!is_dir($baseDryRunDir)) {
        mkdir($baseDryRunDir, 0777, true);
    }
    foreach ($shops as $shop) {
        $dir = $baseDryRunDir . '/' . $shop->salesChannelId;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}

// Handle SIGTERM for Supervisor
$shutdown = false;
pcntl_signal(SIGTERM, function () use (&$shutdown, $logger) {
    $logger->info('Received SIGTERM, shutting down');
    $shutdown = true;
});

// Main loop
$logger->info('Starting dunning process', [
    'shops' => count($shops),
    'dry_run' => $isDryRun,
]);
while (!$shutdown) {
    foreach ($shops as $shop) {
        try {
            $client = new ShopwareClient($httpClient, $shop, $logger);
            $processor->processShop($client, $shop);
        } catch (\Exception $e) {
            $logger->error('Failed to process sales channel', [
                'url' => $shop->url,
                'sales_channel_id' => $shop->salesChannelId,
                'error' => $e->getMessage(),
            ]);
        }
        usleep(100000); // 100ms delay to respect API rate limits
    }

    $logger->info('Completed dunning cycle, sleeping for 1 hour');
    sleep(3600);
}

$logger->info('Dunning process terminated');
?>