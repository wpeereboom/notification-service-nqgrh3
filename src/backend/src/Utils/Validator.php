<?php

declare(strict_types=1);

namespace Backend\Utils;

use Backend\Exceptions\NotificationException;
use App\Contracts\NotificationInterface;
use App\Contracts\TemplateInterface;
use JsonException;

/**
 * Validator class providing comprehensive validation functionality for the notification service.
 * 
 * Handles validation of:
 * - Notification payloads
 * - Template content and structure
 * - Recipient formats
 * - Channel-specific requirements
 * 
 * @version 1.0
 * @package Backend\Utils
 */
final class Validator
{
    /**
     * Regular expression for email validation
     */
    private const EMAIL_REGEX = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';

    /**
     * Regular expression for phone number validation (E.164 format)
     */
    private const PHONE_REGEX = '/^\+[1-9]\d{1,14}$/';

    /**
     * Maximum allowed template size in bytes
     */
    private const MAX_TEMPLATE_SIZE = 1048576; // 1MB

    /**
     * @var array<string> List of supported notification channels
     */
    private array $allowedChannels;

    /**
     * @var array<string, array<string>> Required fields for each channel type
     */
    private array $requiredFields;

    /**
     * @var TemplateInterface Template validator instance
     */
    private TemplateInterface $templateValidator;

    /**
     * Initialize validator with dependencies and configuration.
     *
     * @param TemplateInterface $templateValidator Template validation service
     */
    public function __construct(TemplateInterface $templateValidator)
    {
        $this->templateValidator = $templateValidator;

        // Initialize supported channels
        $this->allowedChannels = [
            NotificationInterface::CHANNEL_EMAIL,
            NotificationInterface::CHANNEL_SMS,
            NotificationInterface::CHANNEL_PUSH
        ];

        // Define required fields per channel
        $this->requiredFields = [
            NotificationInterface::CHANNEL_EMAIL => ['recipient', 'template_id', 'context'],
            NotificationInterface::CHANNEL_SMS => ['recipient', 'template_id', 'context'],
            NotificationInterface::CHANNEL_PUSH => ['recipient', 'template_id', 'context', 'title']
        ];
    }

    /**
     * Validates a notification payload for processing.
     *
     * @param array $payload The notification payload to validate
     * @return bool True if payload is valid
     * @throws NotificationException If validation fails
     * @throws JsonException If JSON parsing fails
     */
    public function validateNotificationPayload(array $payload): bool
    {
        // Validate basic payload structure
        if (empty($payload)) {
            throw new NotificationException(
                'Empty notification payload',
                NotificationException::INVALID_PAYLOAD,
                ['payload' => $payload]
            );
        }

        // Validate channel
        if (!isset($payload['channel']) || !in_array($payload['channel'], $this->allowedChannels, true)) {
            throw new NotificationException(
                'Invalid notification channel',
                NotificationException::INVALID_PAYLOAD,
                ['channel' => $payload['channel'] ?? null, 'allowed_channels' => $this->allowedChannels]
            );
        }

        // Validate required fields for the channel
        foreach ($this->requiredFields[$payload['channel']] as $field) {
            if (!isset($payload[$field]) || empty($payload[$field])) {
                throw new NotificationException(
                    sprintf('Missing required field: %s', $field),
                    NotificationException::INVALID_PAYLOAD,
                    ['field' => $field, 'channel' => $payload['channel']]
                );
            }
        }

        // Validate recipient format
        if (!$this->validateRecipient($payload['recipient'], $payload['channel'])) {
            throw new NotificationException(
                'Invalid recipient format',
                NotificationException::INVALID_PAYLOAD,
                ['recipient' => '[REDACTED]', 'channel' => $payload['channel']]
            );
        }

        // Validate context data
        if (!is_array($payload['context'])) {
            throw new NotificationException(
                'Invalid context data format',
                NotificationException::INVALID_PAYLOAD,
                ['context_type' => gettype($payload['context'])]
            );
        }

        // Validate template if template_id is provided
        if (isset($payload['template_id'])) {
            $template = $this->templateValidator->find($payload['template_id']);
            if ($template === null) {
                throw new NotificationException(
                    'Template not found',
                    NotificationException::INVALID_PAYLOAD,
                    ['template_id' => $payload['template_id']]
                );
            }
        }

        return true;
    }

    /**
     * Validates template content and structure.
     *
     * @param string $content Template content to validate
     * @param string $channel Notification channel
     * @return bool True if template is valid
     * @throws NotificationException If validation fails
     */
    public function validateTemplate(string $content, string $channel): bool
    {
        // Check template size
        if (strlen($content) > self::MAX_TEMPLATE_SIZE) {
            throw new NotificationException(
                'Template exceeds maximum size limit',
                NotificationException::INVALID_PAYLOAD,
                ['size' => strlen($content), 'max_size' => self::MAX_TEMPLATE_SIZE]
            );
        }

        // Validate channel-specific requirements
        switch ($channel) {
            case NotificationInterface::CHANNEL_EMAIL:
                if (!$this->validateEmailTemplate($content)) {
                    throw new NotificationException(
                        'Invalid email template structure',
                        NotificationException::INVALID_PAYLOAD,
                        ['channel' => $channel]
                    );
                }
                break;

            case NotificationInterface::CHANNEL_SMS:
                if (!$this->validateSmsTemplate($content)) {
                    throw new NotificationException(
                        'Invalid SMS template structure',
                        NotificationException::INVALID_PAYLOAD,
                        ['channel' => $channel]
                    );
                }
                break;

            case NotificationInterface::CHANNEL_PUSH:
                if (!$this->validatePushTemplate($content)) {
                    throw new NotificationException(
                        'Invalid push notification template structure',
                        NotificationException::INVALID_PAYLOAD,
                        ['channel' => $channel]
                    );
                }
                break;

            default:
                throw new NotificationException(
                    'Unsupported notification channel',
                    NotificationException::INVALID_PAYLOAD,
                    ['channel' => $channel]
                );
        }

        // Validate template syntax using template validator
        return $this->templateValidator->validate($content);
    }

    /**
     * Validates recipient format based on channel type.
     *
     * @param string $recipient Recipient identifier
     * @param string $channel Notification channel
     * @return bool True if recipient format is valid
     */
    private function validateRecipient(string $recipient, string $channel): bool
    {
        switch ($channel) {
            case NotificationInterface::CHANNEL_EMAIL:
                return (bool) preg_match(self::EMAIL_REGEX, $recipient);

            case NotificationInterface::CHANNEL_SMS:
                return (bool) preg_match(self::PHONE_REGEX, $recipient);

            case NotificationInterface::CHANNEL_PUSH:
                // Push notification tokens should be non-empty alphanumeric strings
                return (bool) preg_match('/^[a-zA-Z0-9_-]{1,255}$/', $recipient);

            default:
                return false;
        }
    }

    /**
     * Validates email template structure.
     *
     * @param string $content Template content
     * @return bool True if template is valid
     */
    private function validateEmailTemplate(string $content): bool
    {
        // Email templates must contain subject and body sections
        $requiredSections = ['subject', 'body'];
        foreach ($requiredSections as $section) {
            if (stripos($content, $section) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validates SMS template structure.
     *
     * @param string $content Template content
     * @return bool True if template is valid
     */
    private function validateSmsTemplate(string $content): bool
    {
        // SMS templates should not exceed 1600 characters (allowing for variable expansion)
        return strlen($content) <= 1600;
    }

    /**
     * Validates push notification template structure.
     *
     * @param string $content Template content
     * @return bool True if template is valid
     */
    private function validatePushTemplate(string $content): bool
    {
        // Push templates must contain title and body
        try {
            $template = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return isset($template['title'], $template['body']);
        } catch (JsonException) {
            return false;
        }
    }
}