<?php

declare(strict_types=1);

namespace App\Providers;

use Aws\CloudWatch\CloudWatchClient;
use Aws\Sqs\SqsClient;
use Illuminate\Support\ServiceProvider;
use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use App\Services\Queue\SqsService;
use App\Utils\CircuitBreaker;
use App\Exceptions\VendorException;

/**
 * Enterprise-grade service provider for queue services with enhanced monitoring,
 * high availability, and fault tolerance capabilities.
 *
 * @package App\Providers
 * @version 1.0.0
 */
class QueueServiceProvider extends ServiceProvider
{
    /**
     * @var array Queue configuration
     */
    private array $config;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var CloudWatchClient CloudWatch client
     */
    private CloudWatchClient $cloudWatch;

    /**
     * Initialize queue service provider with monitoring capabilities.
     *
     * @param LoggerInterface $logger PSR-3 logger instance
     * @param CloudWatchClient $cloudWatch AWS CloudWatch client
     */
    public function __construct(LoggerInterface $logger, CloudWatchClient $cloudWatch)
    {
        parent::__construct();
        
        $this->logger = $logger;
        $this->cloudWatch = $cloudWatch;
        $this->config = $this->loadConfiguration();
    }

    /**
     * Register queue services in the container with enhanced monitoring.
     *
     * @return void
     */
    public function register(): void
    {
        // Register SQS client with monitoring
        $this->app->singleton(SqsClient::class, function ($app) {
            return new SqsClient([
                'version' => '2012-11-05',
                'region'  => $this->config['connections']['sqs']['region'],
                'credentials' => [
                    'key'    => $this->config['connections']['sqs']['key'],
                    'secret' => $this->config['connections']['sqs']['secret'],
                ],
            ]);
        });

        // Register Redis client for rate limiting and circuit breaker
        $this->app->singleton(Redis::class, function ($app) {
            return new Redis([
                'scheme' => 'tcp',
                'host'   => $this->config['redis']['host'],
                'port'   => $this->config['redis']['port'],
                'database' => $this->config['redis']['database'] ?? 0,
            ]);
        });

        // Register SQS service with circuit breaker
        $this->app->singleton(SqsService::class, function ($app) {
            return new SqsService(
                $app->make(SqsClient::class),
                $this->logger,
                $app->make(Redis::class),
                $this->config['connections']['sqs']
            );
        });

        // Register CloudWatch metrics publisher
        $this->registerCloudWatchMetrics();
    }

    /**
     * Bootstrap queue services with enhanced reliability features.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->configureWorkers();
        $this->setupHealthMonitoring();
        $this->setupMetricAlarms();
        $this->configureDeadLetterQueues();
    }

    /**
     * Configure queue workers with optimal settings for high throughput.
     *
     * @return void
     */
    private function configureWorkers(): void
    {
        $workerConfig = $this->config['workers'];

        // Calculate optimal worker count based on throughput requirements
        $workerCount = min(
            (int) ($workerConfig['max_workers'] ?? 10),
            (int) ceil($this->config['throughput']['messages_per_minute'] / 60 / 1000)
        );

        // Configure worker processes
        $this->app['config']->set('queue.workers', [
            'process-count' => $workerCount,
            'memory-limit' => $workerConfig['memory_limit'] ?? 512,
            'timeout' => $workerConfig['timeout'] ?? 60,
            'sleep' => $workerConfig['sleep'] ?? 3,
            'max-jobs' => $workerConfig['max_jobs'] ?? 1000,
            'max-time' => $workerConfig['max_time'] ?? 3600,
            'force' => false,
        ]);
    }

    /**
     * Setup comprehensive health monitoring for queue services.
     *
     * @return void
     */
    private function setupHealthMonitoring(): void
    {
        // Configure CloudWatch dashboard
        $this->cloudWatch->putDashboard([
            'DashboardName' => 'QueueMetrics-' . $this->config['environment'],
            'DashboardBody' => json_encode([
                'widgets' => [
                    [
                        'type' => 'metric',
                        'properties' => [
                            'metrics' => [
                                ['AWS/SQS', 'ApproximateNumberOfMessagesVisible'],
                                ['AWS/SQS', 'ApproximateAgeOfOldestMessage'],
                                ['AWS/SQS', 'NumberOfMessagesReceived'],
                                ['AWS/SQS', 'NumberOfMessagesSent'],
                            ],
                            'period' => 300,
                            'stat' => 'Average',
                            'region' => $this->config['connections']['sqs']['region'],
                            'title' => 'Queue Metrics',
                        ],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Configure CloudWatch metric alarms for queue monitoring.
     *
     * @return void
     */
    private function setupMetricAlarms(): void
    {
        $alarmConfig = $this->config['monitoring']['alarms'];

        // Queue depth alarm
        $this->cloudWatch->putMetricAlarm([
            'AlarmName' => 'QueueDepthHigh-' . $this->config['environment'],
            'MetricName' => 'ApproximateNumberOfMessagesVisible',
            'Namespace' => 'AWS/SQS',
            'Statistic' => 'Average',
            'Period' => 300,
            'EvaluationPeriods' => 2,
            'Threshold' => $alarmConfig['queue_depth_threshold'] ?? 10000,
            'ComparisonOperator' => 'GreaterThanThreshold',
            'AlarmActions' => [$alarmConfig['alarm_topic_arn']],
        ]);

        // Message age alarm
        $this->cloudWatch->putMetricAlarm([
            'AlarmName' => 'MessageAgeHigh-' . $this->config['environment'],
            'MetricName' => 'ApproximateAgeOfOldestMessage',
            'Namespace' => 'AWS/SQS',
            'Statistic' => 'Maximum',
            'Period' => 300,
            'EvaluationPeriods' => 2,
            'Threshold' => $alarmConfig['message_age_threshold'] ?? 300,
            'ComparisonOperator' => 'GreaterThanThreshold',
            'AlarmActions' => [$alarmConfig['alarm_topic_arn']],
        ]);
    }

    /**
     * Configure dead-letter queues for failed message handling.
     *
     * @return void
     */
    private function configureDeadLetterQueues(): void
    {
        $dlqConfig = $this->config['dead_letter_queue'];

        // Set up DLQ redrive policy
        $this->app['sqs']->setQueueAttributes([
            'QueueUrl' => $this->config['connections']['sqs']['queue'],
            'Attributes' => [
                'RedrivePolicy' => json_encode([
                    'deadLetterTargetArn' => $dlqConfig['target_arn'],
                    'maxReceiveCount' => $dlqConfig['max_receive_count'] ?? 3,
                ]),
            ],
        ]);
    }

    /**
     * Register CloudWatch metrics collection and publishing.
     *
     * @return void
     */
    private function registerCloudWatchMetrics(): void
    {
        $this->app->singleton('queue.metrics', function ($app) {
            return new class($this->cloudWatch, $this->config['monitoring']['namespace']) {
                private $cloudWatch;
                private $namespace;

                public function __construct($cloudWatch, $namespace)
                {
                    $this->cloudWatch = $cloudWatch;
                    $this->namespace = $namespace;
                }

                public function publishMetric(string $name, float $value, array $dimensions = []): void
                {
                    $this->cloudWatch->putMetricData([
                        'Namespace' => $this->namespace,
                        'MetricData' => [
                            [
                                'MetricName' => $name,
                                'Value' => $value,
                                'Unit' => 'Count',
                                'Dimensions' => array_map(
                                    fn($k, $v) => ['Name' => $k, 'Value' => $v],
                                    array_keys($dimensions),
                                    array_values($dimensions)
                                ),
                            ],
                        ],
                    ]);
                }
            };
        });
    }

    /**
     * Load and validate queue configuration.
     *
     * @return array Validated configuration
     * @throws \InvalidArgumentException If configuration is invalid
     */
    private function loadConfiguration(): array
    {
        $config = config('queue');

        if (empty($config['connections']['sqs']['queue'])) {
            throw new \InvalidArgumentException('SQS queue URL is required in configuration');
        }

        if (empty($config['connections']['sqs']['region'])) {
            throw new \InvalidArgumentException('AWS region is required in configuration');
        }

        return $config;
    }
}