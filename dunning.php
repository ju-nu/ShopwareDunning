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
$processor = new DunningProcessor($httpClient, $mailer, $logger);

// Handle SIGTERM for Supervisor
$shutdown = false;
pcntl_signal(SIGTERM, function () use (&$shutdown, $logger) {
    $logger->info('Received SIGTERM, shutting down');
    $shutdown = true;
});

// Main loop
$logger->info('Starting dunning process', ['shops' => count($shops)]);
while (!$shutdown) {
    foreach ($shops as $shop) {
        try {
            $client = new ShopwareClient($httpClient, $shop, $logger);
            $processor->processShop($client, $shop);
        } catch (\Exception $e) {
            $logger->error('Failed to process shop', [
                'url' => $shop->url,
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