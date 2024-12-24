<?php

declare(strict_types=1);

namespace App\Lambda\Handlers;

use App\Services\Vendor\VendorService;
use App\Models\VendorStatus;
use App\Exceptions\VendorException;
use Aws\Lambda\LambdaClient;
use Psr\Log\LoggerInterface;
use Predis\Client as Redis;

/**
 * AWS Lambda handler for managing vendor operations, health checks, and failover logic.
 * Implements high-throughput vendor management with comprehensive monitoring and distributed state.
 *
 * @package App\Lambda\Handlers
 * @version 1.0.0
 */
class VendorHandler
{
    /**
     * Operation types
     */
    private const OPERATION_SEND = 'send';
    private const OPERATION_STATUS = 'status';
    private const OPERATION_HEALTH_CHECK = 'health_check';

    /**
     * @var VendorService Vendor service instance
     */
    private VendorService $vendorService;

    /**
     * @var LoggerInterface Logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var Redis Redis client for distributed state
     */
    private Redis $redis;

    /**
     * @var array Performance metrics collection
     */
    private array $metrics = [
        'start_time' => 0,
        'operation_type' => '',
        'processing_time' => 0,
        'vendor_latency' => 0,
    ];

    /**
     * Creates new vendor handler instance with monitoring capabilities.
     *
     * @param VendorService $vendorService Core vendor service
     * @param LoggerInterface $logger PSR-3 logger
     * @param Redis $redis Redis client
     */
    public function __construct(
        VendorService $vendorService,
        LoggerInterface $logger,
        Redis $redis
    ) {
        $this->vendorService = $vendorService;
        $this->logger = $logger;
        $this->redis = $redis;
    }

    /**
     * Main Lambda handler function for vendor operations.
     *
     * @param array $event Lambda event data
     * @param object $context Lambda context
     * @return array Response with operation result
     * @throws VendorException When operation fails
     */
    public function handle(array $event, object $context): array
    {
        $this->metrics['start_time'] = microtime(true);
        $this->metrics['operation_type'] = $event['operation'] ?? '';

        try {
            // Validate operation type
            if (!isset($event['operation'])) {
                throw new VendorException(
                    'Missing operation type',
                    VendorException::VENDOR_INVALID_REQUEST,
                    null,
                    ['event' => $event]
                );
            }

            // Route to appropriate handler
            $response = match ($event['operation']) {
                self::OPERATION_SEND => $this->handleSend($event['payload'] ?? []),
                self::OPERATION_STATUS => $this->handleStatus($event['payload'] ?? []),
                self::OPERATION_HEALTH_CHECK => $this->handleHealthCheck($event['payload'] ?? []),
                default => throw new VendorException(
                    'Invalid operation type',
                    VendorException::VENDOR_INVALID_REQUEST,
                    null,
                    ['operation' => $event['operation']]
                )
            };

            // Record metrics
            $this->metrics['processing_time'] = microtime(true) - $this->metrics['start_time'];
            
            return array_merge($response, [
                'metrics' => $this->metrics,
                'request_id' => $context->getAwsRequestId(),
                'success' => true
            ]);

        } catch (VendorException $e) {
            $this->logger->error('Vendor operation failed', [
                'operation' => $event['operation'] ?? 'unknown',
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'context' => $e->getVendorContext()
            ]);

            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'vendor' => $e->getVendorName(),
                    'channel' => $e->getChannel()
                ],
                'metrics' => $this->metrics,
                'request_id' => $context->getAwsRequestId()
            ];
        }
    }

    /**
     * Handles vendor send operation with failover support.
     *
     * @param array $payload Send operation payload
     * @return array Delivery status with metrics
     * @throws VendorException When send operation fails
     */
    private function handleSend(array $payload): array
    {
        // Validate required payload fields
        if (!isset($payload['channel'], $payload['tenant_id'])) {
            throw new VendorException(
                'Missing required payload fields',
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                ['payload' => $payload]
            );
        }

        $startTime = microtime(true);

        // Attempt send operation
        $result = $this->vendorService->send(
            $payload,
            $payload['channel'],
            $payload['tenant_id']
        );

        $this->metrics['vendor_latency'] = microtime(true) - $startTime;

        return $result;
    }

    /**
     * Handles vendor status check with enhanced monitoring.
     *
     * @param array $payload Status check payload
     * @return array Detailed status information
     * @throws VendorException When status check fails
     */
    private function handleStatus(array $payload): array
    {
        // Validate required payload fields
        if (!isset($payload['message_id'], $payload['vendor'], $payload['tenant_id'])) {
            throw new VendorException(
                'Missing required status fields',
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                ['payload' => $payload]
            );
        }

        $startTime = microtime(true);

        // Get message status
        $status = $this->vendorService->getStatus(
            $payload['message_id'],
            $payload['vendor'],
            $payload['tenant_id']
        );

        $this->metrics['vendor_latency'] = microtime(true) - $startTime;

        return $status;
    }

    /**
     * Handles vendor health check with distributed state management.
     *
     * @param array $payload Health check payload
     * @return array Health check results
     * @throws VendorException When health check fails
     */
    private function handleHealthCheck(array $payload): array
    {
        // Validate vendor name
        if (!isset($payload['vendor'])) {
            throw new VendorException(
                'Missing vendor name',
                VendorException::VENDOR_INVALID_REQUEST,
                null,
                ['payload' => $payload]
            );
        }

        $startTime = microtime(true);

        // Check if health check is needed
        $vendorStatus = VendorStatus::byVendor($payload['vendor'])->first();
        
        if ($vendorStatus && !$vendorStatus->needsHealthCheck()) {
            return [
                'vendor' => $payload['vendor'],
                'status' => $vendorStatus->status,
                'success_rate' => $vendorStatus->success_rate,
                'last_check' => $vendorStatus->last_check->format('c'),
                'cached' => true
            ];
        }

        // Perform health check
        $health = $this->vendorService->checkVendorHealth($payload['vendor']);
        
        $this->metrics['vendor_latency'] = microtime(true) - $startTime;

        // Update vendor status
        VendorStatus::updateOrCreate(
            ['vendor' => $payload['vendor']],
            [
                'status' => $health['isHealthy'] ? VendorStatus::VENDOR_STATUS_HEALTHY : VendorStatus::VENDOR_STATUS_UNHEALTHY,
                'success_rate' => $health['metrics']['success_rate'] ?? 0.0,
                'last_check' => now()
            ]
        );

        return array_merge($health, [
            'cached' => false,
            'check_duration' => $this->metrics['vendor_latency']
        ]);
    }
}