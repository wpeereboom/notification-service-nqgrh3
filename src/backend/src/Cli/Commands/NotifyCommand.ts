/**
 * NotifyCommand - Enterprise-grade CLI command for sending notifications
 * Implements robust error handling, accessibility, and internationalization support
 * @packageDocumentation
 */

import { CommandInterface } from '../Interfaces/CommandInterface';
import { 
  NotifyCommandOptions, 
  NotificationChannel, 
  OutputFormat, 
  ErrorLevel 
} from '../Types';
import { ApiService } from '../Services/ApiService';
import { OutputService } from '../Services/OutputService';

/**
 * Validation rule type definition
 */
interface ValidationRule {
  validate: (value: string) => boolean;
  message: string;
}

/**
 * Retry configuration for API calls
 */
interface RetryConfig {
  maxRetries: number;
  baseDelay: number;
  maxDelay: number;
}

/**
 * Implementation of the notify command for sending notifications
 * through various channels with comprehensive validation and error handling
 */
export class NotifyCommand implements CommandInterface {
  public readonly name: string = 'notify';
  public readonly description: string = 'Send notifications through various channels';
  public readonly aliases: string[] = ['n'];
  private readonly validationRules: Map<string, ValidationRule>;
  private readonly retryConfig: RetryConfig;

  /**
   * Initialize notify command with required services
   * 
   * @param apiService - Service for API communication
   * @param outputService - Service for CLI output
   * @param retryConfig - Configuration for retry mechanism
   */
  constructor(
    private readonly apiService: ApiService,
    private readonly outputService: OutputService,
    retryConfig: RetryConfig = {
      maxRetries: 3,
      baseDelay: 1000,
      maxDelay: 5000
    }
  ) {
    this.retryConfig = retryConfig;
    this.validationRules = this.initializeValidationRules();
  }

  /**
   * Execute the notify command with comprehensive error handling
   * 
   * @param args - Command arguments
   * @param options - Command options with accessibility support
   */
  public async execute(
    args: string[], 
    options: NotifyCommandOptions
  ): Promise<void> {
    try {
      // Validate command input
      if (!this.validate(args)) {
        return;
      }

      // Extract and validate required options
      const { template, recipient, channel } = options;

      // Validate template format
      if (!this.validateTemplate(template)) {
        this.outputService.error(
          'INVALID_TEMPLATE',
          'Template ID must be a valid UUID',
          { ariaLive: 'assertive' }
        );
        return;
      }

      // Validate recipient format based on channel
      if (!this.validateRecipient(recipient, channel)) {
        this.outputService.error(
          'INVALID_RECIPIENT',
          `Invalid recipient format for ${channel} channel`,
          { ariaLive: 'assertive' }
        );
        return;
      }

      // Send notification with retry mechanism
      const response = await this.apiService.sendNotification(
        template,
        recipient,
        channel,
        options.context || {}
      );

      if (response.success) {
        this.outputService.print(
          response.data,
          {
            ariaLabel: 'Notification sent successfully',
            ariaLive: 'polite'
          }
        );
      } else {
        this.outputService.error(
          response.error?.code || 'UNKNOWN_ERROR',
          response.error?.message || 'Failed to send notification',
          { ariaLive: 'assertive' }
        );
      }
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Validate command arguments and options
   * 
   * @param args - Command arguments to validate
   * @returns boolean indicating if validation passed
   */
  public validate(args: string[]): boolean {
    if (args.length < 2) {
      this.outputService.error(
        'INVALID_ARGS',
        'Required arguments missing: template and recipient',
        { ariaLive: 'assertive' }
      );
      return false;
    }
    return true;
  }

  /**
   * Get comprehensive help documentation with accessibility support
   * 
   * @returns Formatted help text
   */
  public getHelp(): string {
    return `
Usage: notify [options] <template> <recipient>

Send notifications through various channels

Options:
  --channel    Notification channel (email|sms|push)
  --template   Template ID to use
  --recipient  Recipient address/identifier
  --format     Output format (json|table|plain)
  --verbose    Enable verbose output
  --help       Show this help message

Examples:
  notify --template welcome-email --recipient user@example.com --channel email
  notify --template verify-phone --recipient +1234567890 --channel sms
  notify --template app-update --recipient device_123 --channel push

For more information, visit: https://docs.example.com/cli/notify
    `.trim();
  }

  /**
   * Get command completion suggestions
   * 
   * @param partial - Partial command input
   * @returns Array of completion suggestions
   */
  public getSuggestions(partial: string): string[] {
    const suggestions: string[] = [
      '--channel=email',
      '--channel=sms',
      '--channel=push',
      '--template=',
      '--recipient=',
      '--format=json',
      '--format=table',
      '--format=plain',
      '--verbose',
      '--help'
    ];

    return suggestions.filter(suggestion => 
      suggestion.startsWith(partial)
    );
  }

  /**
   * Initialize validation rules for command options
   * 
   * @returns Map of validation rules
   */
  private initializeValidationRules(): Map<string, ValidationRule> {
    const rules = new Map<string, ValidationRule>();

    rules.set('email', {
      validate: (value: string) => 
        /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(value),
      message: 'Invalid email address format'
    });

    rules.set('sms', {
      validate: (value: string) => 
        /^\+[1-9]\d{1,14}$/.test(value),
      message: 'Invalid phone number format (E.164 required)'
    });

    rules.set('push', {
      validate: (value: string) => 
        /^[a-zA-Z0-9_-]{1,64}$/.test(value),
      message: 'Invalid device token format'
    });

    return rules;
  }

  /**
   * Validate template ID format
   * 
   * @param template - Template ID to validate
   * @returns boolean indicating if template is valid
   */
  private validateTemplate(template: string): boolean {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
      .test(template);
  }

  /**
   * Validate recipient format based on channel
   * 
   * @param recipient - Recipient to validate
   * @param channel - Notification channel
   * @returns boolean indicating if recipient is valid
   */
  private validateRecipient(
    recipient: string,
    channel: NotificationChannel
  ): boolean {
    const rule = this.validationRules.get(channel.toLowerCase());
    return rule ? rule.validate(recipient) : false;
  }

  /**
   * Handle command execution errors with proper context
   * 
   * @param error - Error to handle
   */
  private handleError(error: unknown): void {
    const errorMessage = error instanceof Error ? 
      error.message : 
      'An unexpected error occurred';

    this.outputService.error(
      'COMMAND_ERROR',
      errorMessage,
      { 
        ariaLive: 'assertive',
        ariaLabel: 'Command execution failed'
      }
    );

    if (this.outputService.getConfig<boolean>('verbose')) {
      this.outputService.debug(
        error instanceof Error ? error.stack || '' : ''
      );
    }
  }
}

export default NotifyCommand;