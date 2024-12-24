/**
 * Core TypeScript types and interfaces for the Notification Service CLI
 * @packageDocumentation
 */

/**
 * Supported output formats for CLI commands
 * @enum {string}
 */
export enum OutputFormat {
  JSON = 'JSON',
  TABLE = 'TABLE',
  PLAIN = 'PLAIN'
}

/**
 * Supported notification delivery channels
 * @enum {string}
 */
export enum NotificationChannel {
  EMAIL = 'EMAIL',
  SMS = 'SMS',
  PUSH = 'PUSH'
}

/**
 * All possible notification delivery statuses
 * @enum {string}
 */
export enum NotificationStatus {
  QUEUED = 'QUEUED',
  PROCESSING = 'PROCESSING',
  DELIVERED = 'DELIVERED',
  FAILED = 'FAILED',
  CANCELLED = 'CANCELLED'
}

/**
 * CLI error and logging levels
 * @enum {string}
 */
export enum ErrorLevel {
  ERROR = 'ERROR',
  WARN = 'WARN',
  INFO = 'INFO',
  DEBUG = 'DEBUG'
}

/**
 * Base interface for all CLI command options
 * Provides common options available across all commands
 * @interface
 */
export interface CommandOptions {
  /**
   * Enable verbose output logging
   * @readonly
   */
  readonly verbose?: boolean;

  /**
   * Path to configuration file
   * @readonly
   */
  readonly config?: string;

  /**
   * Output format type
   * @readonly
   */
  readonly format: OutputFormat;
}

/**
 * Options for notification sending command
 * Extends base CommandOptions with notification-specific parameters
 * @interface
 */
export interface NotifyCommandOptions extends CommandOptions {
  /**
   * Template ID to use for notification
   * @readonly
   */
  readonly template: string;

  /**
   * Notification recipient address/identifier
   * @readonly
   */
  readonly recipient: string;

  /**
   * Notification channel to use
   * @readonly
   */
  readonly channel: NotificationChannel;

  /**
   * Optional template context data
   * @readonly
   */
  readonly context: Record<string, unknown>;
}

/**
 * Options for checking notification status
 * Extends base CommandOptions with status-specific parameters
 * @interface
 */
export interface StatusCommandOptions extends CommandOptions {
  /**
   * Notification ID to check
   * @readonly
   */
  readonly id: string;

  /**
   * Enable continuous status watching
   * @readonly
   */
  readonly watch?: boolean;
}

/**
 * Options for template management commands
 * Extends base CommandOptions with template-specific parameters
 * @interface
 */
export interface TemplateCommandOptions extends CommandOptions {
  /**
   * List all templates
   * @readonly
   */
  readonly list?: boolean;

  /**
   * Create new template
   * @readonly
   */
  readonly create?: boolean;

  /**
   * Update existing template
   * @readonly
   */
  readonly update?: boolean;

  /**
   * Delete template
   * @readonly
   */
  readonly delete?: boolean;

  /**
   * Filter templates by channel type
   * @readonly
   */
  readonly type?: NotificationChannel;
}