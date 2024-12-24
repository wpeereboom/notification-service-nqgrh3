/**
 * Validation utilities for CLI command options and inputs
 * Provides comprehensive validation with type safety and error handling
 * @packageDocumentation
 * @version 1.0.0
 */

import validator from 'validator';
import {
  CommandOptions,
  NotifyCommandOptions,
  OutputFormat,
  NotificationChannel
} from '../Types/index';

/**
 * Custom validation error class for CLI input validation
 */
export class ValidationError extends Error {
  constructor(message: string, public readonly code: string) {
    super(message);
    this.name = 'ValidationError';
  }
}

/**
 * Maximum lengths for various input fields
 */
const MAX_LENGTHS = {
  EMAIL: 254, // RFC 5321
  TEMPLATE_ID: 64,
  DEVICE_TOKEN: 4096,
  PHONE: 15 // E.164 format
};

/**
 * Validates base command options common to all commands
 * @param options - Command options to validate
 * @throws {ValidationError} If validation fails
 * @returns {boolean} True if validation passes
 */
export function validateCommandOptions(options: CommandOptions): boolean {
  // Validate verbose flag
  if (options.verbose !== undefined && typeof options.verbose !== 'boolean') {
    throw new ValidationError(
      'Verbose flag must be a boolean value',
      'INVALID_VERBOSE_FLAG'
    );
  }

  // Validate config path if provided
  if (options.config !== undefined) {
    if (typeof options.config !== 'string') {
      throw new ValidationError(
        'Config path must be a string',
        'INVALID_CONFIG_PATH'
      );
    }
    // Sanitize config path to prevent path traversal
    const sanitizedPath = validator.trim(options.config);
    if (validator.contains(sanitizedPath, '../')) {
      throw new ValidationError(
        'Invalid config path: directory traversal not allowed',
        'INVALID_CONFIG_PATH'
      );
    }
  }

  // Validate output format
  if (!Object.values(OutputFormat).includes(options.format)) {
    throw new ValidationError(
      `Invalid output format. Must be one of: ${Object.values(OutputFormat).join(', ')}`,
      'INVALID_OUTPUT_FORMAT'
    );
  }

  return true;
}

/**
 * Validates notify command specific options
 * @param options - Notify command options to validate
 * @throws {ValidationError} If validation fails
 * @returns {boolean} True if validation passes
 */
export function validateNotifyCommand(options: NotifyCommandOptions): boolean {
  // Validate base options first
  validateCommandOptions(options);

  // Validate template ID
  if (!options.template || typeof options.template !== 'string') {
    throw new ValidationError(
      'Template ID is required and must be a string',
      'INVALID_TEMPLATE'
    );
  }
  if (options.template.length > MAX_LENGTHS.TEMPLATE_ID) {
    throw new ValidationError(
      `Template ID exceeds maximum length of ${MAX_LENGTHS.TEMPLATE_ID}`,
      'INVALID_TEMPLATE'
    );
  }

  // Validate channel
  if (!Object.values(NotificationChannel).includes(options.channel)) {
    throw new ValidationError(
      `Invalid channel. Must be one of: ${Object.values(NotificationChannel).join(', ')}`,
      'INVALID_CHANNEL'
    );
  }

  // Validate recipient based on channel
  if (!options.recipient) {
    throw new ValidationError(
      'Recipient is required',
      'INVALID_RECIPIENT'
    );
  }

  switch (options.channel) {
    case NotificationChannel.EMAIL:
      if (!validateEmail(options.recipient)) {
        throw new ValidationError(
          'Invalid email address format',
          'INVALID_EMAIL'
        );
      }
      break;

    case NotificationChannel.SMS:
      if (!validatePhoneNumber(options.recipient)) {
        throw new ValidationError(
          'Invalid phone number format',
          'INVALID_PHONE'
        );
      }
      break;

    case NotificationChannel.PUSH:
      if (!validateDeviceToken(options.recipient)) {
        throw new ValidationError(
          'Invalid device token format',
          'INVALID_DEVICE_TOKEN'
        );
      }
      break;
  }

  // Validate context if provided
  if (options.context && typeof options.context !== 'object') {
    throw new ValidationError(
      'Context must be a valid object',
      'INVALID_CONTEXT'
    );
  }

  return true;
}

/**
 * Validates email address format
 * @param email - Email address to validate
 * @returns {boolean} True if email is valid
 */
export function validateEmail(email: string): boolean {
  if (!email || typeof email !== 'string') {
    return false;
  }

  const sanitizedEmail = validator.trim(email).toLowerCase();
  
  if (sanitizedEmail.length > MAX_LENGTHS.EMAIL) {
    return false;
  }

  return validator.isEmail(sanitizedEmail, {
    allow_utf8_local_part: true,
    require_tld: true,
    allow_ip_domain: false
  });
}

/**
 * Validates phone number format
 * @param phone - Phone number to validate
 * @returns {boolean} True if phone number is valid
 */
export function validatePhoneNumber(phone: string): boolean {
  if (!phone || typeof phone !== 'string') {
    return false;
  }

  const sanitizedPhone = validator.trim(phone).replace(/\s+/g, '');
  
  if (sanitizedPhone.length > MAX_LENGTHS.PHONE) {
    return false;
  }

  // Validate international phone number format (E.164)
  return validator.isMobilePhone(sanitizedPhone, 'any', {
    strictMode: true
  });
}

/**
 * Validates device token format for push notifications
 * @param token - Device token to validate
 * @returns {boolean} True if token is valid
 */
function validateDeviceToken(token: string): boolean {
  if (!token || typeof token !== 'string') {
    return false;
  }

  const sanitizedToken = validator.trim(token);
  
  if (sanitizedToken.length > MAX_LENGTHS.DEVICE_TOKEN) {
    return false;
  }

  // Basic device token format validation
  // Allows alphanumeric characters and common separators
  return validator.matches(sanitizedToken, /^[A-Za-z0-9\-_]+$/);
}