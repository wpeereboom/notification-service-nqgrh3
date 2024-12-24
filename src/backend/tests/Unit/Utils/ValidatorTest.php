<?php

declare(strict_types=1);

namespace Backend\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use Backend\Utils\Validator;
use App\Contracts\NotificationInterface;
use App\Contracts\TemplateInterface;
use Backend\Exceptions\NotificationException;

/**
 * Comprehensive test suite for the Validator utility class.
 * 
 * Ensures complete coverage of:
 * - Notification payload validation
 * - Template validation
 * - Channel-specific validations
 * - Edge cases and error conditions
 * - Performance requirements
 *
 * @covers \Backend\Utils\Validator
 */
class ValidatorTest extends TestCase
{
    private Validator $validator;
    private MockObject&TemplateInterface $templateValidatorMock;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        // Create mock for template validator with strict mode
        $this->templateValidatorMock = $this->createMock(TemplateInterface::class);
        
        // Initialize validator with mock
        $this->validator = new Validator($this->templateValidatorMock);
    }

    /**
     * Data provider for valid notification test cases.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function provideValidNotificationData(): array
    {
        return [
            'valid_email' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_EMAIL,
                    'recipient' => 'test@example.com',
                    'template_id' => 'welcome_email',
                    'context' => ['name' => 'Test User']
                ]
            ],
            'valid_sms' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_SMS,
                    'recipient' => '+12345678901',
                    'template_id' => 'verification',
                    'context' => ['code' => '123456']
                ]
            ],
            'valid_push' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_PUSH,
                    'recipient' => 'device_token_123',
                    'template_id' => 'alert',
                    'context' => ['title' => 'Test', 'body' => 'Message'],
                    'title' => 'Test Notification'
                ]
            ],
            'email_with_special_chars' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_EMAIL,
                    'recipient' => 'test.user+tag@example.co.uk',
                    'template_id' => 'special_template',
                    'context' => ['data' => 'Test & Special < > Characters']
                ]
            ],
            'sms_international' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_SMS,
                    'recipient' => '+447911123456',
                    'template_id' => 'intl_template',
                    'context' => ['message' => 'International SMS']
                ]
            ]
        ];
    }

    /**
     * Data provider for invalid notification test cases.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function provideInvalidNotificationData(): array
    {
        return [
            'empty_payload' => [
                'payload' => [],
                'expectedError' => 'Empty notification payload'
            ],
            'missing_channel' => [
                'payload' => [
                    'recipient' => 'test@example.com',
                    'template_id' => 'template'
                ],
                'expectedError' => 'Invalid notification channel'
            ],
            'invalid_channel' => [
                'payload' => [
                    'channel' => 'invalid',
                    'recipient' => 'test@example.com',
                    'template_id' => 'template'
                ],
                'expectedError' => 'Invalid notification channel'
            ],
            'invalid_email' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_EMAIL,
                    'recipient' => 'invalid-email',
                    'template_id' => 'template',
                    'context' => []
                ],
                'expectedError' => 'Invalid recipient format'
            ],
            'invalid_sms' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_SMS,
                    'recipient' => '123456', // Missing + prefix
                    'template_id' => 'template',
                    'context' => []
                ],
                'expectedError' => 'Invalid recipient format'
            ],
            'missing_push_title' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_PUSH,
                    'recipient' => 'device_token',
                    'template_id' => 'template',
                    'context' => []
                ],
                'expectedError' => 'Missing required field: title'
            ],
            'invalid_context' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_EMAIL,
                    'recipient' => 'test@example.com',
                    'template_id' => 'template',
                    'context' => 'invalid' // Should be array
                ],
                'expectedError' => 'Invalid context data format'
            ],
            'template_not_found' => [
                'payload' => [
                    'channel' => NotificationInterface::CHANNEL_EMAIL,
                    'recipient' => 'test@example.com',
                    'template_id' => 'non_existent',
                    'context' => []
                ],
                'expectedError' => 'Template not found'
            ]
        ];
    }

    /**
     * Test validation with valid notification payloads.
     *
     * @dataProvider provideValidNotificationData
     */
    public function testValidateNotificationPayload(array $payload): void
    {
        // Configure template validator mock
        $this->templateValidatorMock
            ->expects($this->once())
            ->method('find')
            ->with($payload['template_id'])
            ->willReturn(['id' => $payload['template_id'], 'content' => 'template content']);

        // Execute validation
        $result = $this->validator->validateNotificationPayload($payload);

        // Assert validation passed
        $this->assertTrue($result);
    }

    /**
     * Test validation fails with invalid payloads.
     *
     * @dataProvider provideInvalidNotificationData
     */
    public function testValidateNotificationPayloadInvalid(array $payload, string $expectedError): void
    {
        // Configure template validator mock for template not found case
        if (isset($payload['template_id']) && $payload['template_id'] === 'non_existent') {
            $this->templateValidatorMock
                ->expects($this->once())
                ->method('find')
                ->with('non_existent')
                ->willReturn(null);
        }

        // Assert exception is thrown with expected message
        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage($expectedError);

        $this->validator->validateNotificationPayload($payload);
    }

    /**
     * Test template validation for different channels.
     */
    public function testValidateTemplate(): void
    {
        // Configure template validator mock
        $this->templateValidatorMock
            ->expects($this->exactly(3))
            ->method('validate')
            ->willReturn(true);

        // Test email template validation
        $emailTemplate = "subject: Welcome\nbody: Hello {{name}}";
        $this->assertTrue(
            $this->validator->validateTemplate($emailTemplate, NotificationInterface::CHANNEL_EMAIL)
        );

        // Test SMS template validation
        $smsTemplate = "Your verification code is {{code}}";
        $this->assertTrue(
            $this->validator->validateTemplate($smsTemplate, NotificationInterface::CHANNEL_SMS)
        );

        // Test push notification template validation
        $pushTemplate = json_encode([
            'title' => 'Alert {{type}}',
            'body' => 'Message {{content}}'
        ]);
        $this->assertTrue(
            $this->validator->validateTemplate($pushTemplate, NotificationInterface::CHANNEL_PUSH)
        );
    }

    /**
     * Test template validation failures.
     */
    public function testValidateTemplateInvalid(): void
    {
        // Test oversized template
        $largeTemplate = str_repeat('a', 1048577); // Exceeds MAX_TEMPLATE_SIZE
        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('Template exceeds maximum size limit');
        $this->validator->validateTemplate($largeTemplate, NotificationInterface::CHANNEL_EMAIL);

        // Test invalid email template
        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('Invalid email template structure');
        $this->validator->validateTemplate('invalid template', NotificationInterface::CHANNEL_EMAIL);

        // Test invalid push notification template
        $this->expectException(NotificationException::class);
        $this->expectExceptionMessage('Invalid push notification template structure');
        $this->validator->validateTemplate('invalid json', NotificationInterface::CHANNEL_PUSH);
    }

    /**
     * Test validation performance under load.
     */
    public function testValidationPerformance(): void
    {
        $payload = [
            'channel' => NotificationInterface::CHANNEL_EMAIL,
            'recipient' => 'test@example.com',
            'template_id' => 'template',
            'context' => ['name' => 'Test User']
        ];

        // Configure template validator mock
        $this->templateValidatorMock
            ->method('find')
            ->willReturn(['id' => 'template', 'content' => 'content']);

        // Measure time to validate 1000 payloads
        $startTime = microtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $this->validator->validateNotificationPayload($payload);
        }
        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // Assert performance meets requirements (less than 1 second for 1000 validations)
        $this->assertLessThan(1.0, $duration);
    }
}