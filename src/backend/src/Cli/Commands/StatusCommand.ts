/**
 * Status Command Implementation
 * Provides functionality to check notification delivery status with enhanced
 * accessibility support and comprehensive error handling
 * @packageDocumentation
 */

import { CommandInterface } from '../Interfaces/CommandInterface';
import { StatusCommandOptions, OutputFormat, NotificationStatus } from '../Types';
import { ApiService } from '../Services/ApiService';
import { OutputService } from '../Services/OutputService';

/**
 * Implements the status command for checking notification delivery status
 * with enhanced accessibility and error handling features
 * 
 * @implements {CommandInterface}
 * @version 1.0.0
 */
export class StatusCommand implements CommandInterface {
  public readonly name = 'status';
  public readonly description = 'Check notification delivery status';
  public readonly aliases = ['st', 'check'];
  public readonly options: StatusCommandOptions = {
    format: OutputFormat.TABLE,
    verbose: false,
    id: ''
  };

  private readonly maxRetries: number = 3;
  private readonly retryDelay: number = 1000;

  /**
   * Initialize status command with required services
   * 
   * @param apiService - Service for API communication
   * @param outputService - Service for formatted output
   */
  constructor(
    private readonly apiService: ApiService,
    private readonly outputService: OutputService
  ) {}

  /**
   * Execute the status command with comprehensive error handling
   * 
   * @param args - Command arguments
   * @param options - Command options with type safety
   * @returns Promise indicating command completion
   */
  public async execute(args: string[], options: StatusCommandOptions): Promise<void> {
    try {
      // Validate command arguments
      if (!this.validate(args)) {
        this.outputService.error(
          'INVALID_ARGS',
          'Please provide a valid notification ID'
        );
        return;
      }

      const notificationId = args[0];
      
      // Show progress for screen readers
      this.outputService.print('Checking notification status...', {
        ariaLive: 'polite',
        ariaLabel: 'Status Check Progress'
      });

      // Fetch notification status with retry logic
      const response = await this.getNotificationStatus(notificationId);

      if (!response.success || !response.data) {
        this.outputService.error(
          response.error?.code || 'STATUS_ERROR',
          response.error?.message || 'Failed to retrieve notification status'
        );
        return;
      }

      // Format and display status information
      const statusData = {
        id: response.data.id,
        status: response.data.status,
        channel: response.data.channel,
        created: response.data.createdAt,
        tracking: response.data.tracking
      };

      // Enhanced output with accessibility support
      this.outputService.print(statusData, {
        ariaLabel: `Notification ${response.data.status}`,
        ariaDescription: `Status for notification ${notificationId}`
      });

      // Show delivery attempts in verbose mode
      if (options.verbose) {
        this.outputService.debug(`Request ID: ${response.requestId}`);
        this.outputService.debug(`Attempts: ${response.data.tracking.attempts}`);
        if (response.data.tracking.lastAttempt) {
          this.outputService.debug(`Last attempt: ${response.data.tracking.lastAttempt}`);
        }
      }

    } catch (error) {
      this.handleError(error as Error);
    }
  }

  /**
   * Validate command arguments with enhanced type checking
   * 
   * @param args - Command arguments to validate
   * @returns Validation result
   */
  public validate(args: string[]): boolean {
    if (!args || args.length !== 1) {
      return false;
    }

    const notificationId = args[0];
    // Validate UUID format
    const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    return uuidRegex.test(notificationId);
  }

  /**
   * Get comprehensive help documentation for the status command
   * 
   * @returns Formatted help text
   */
  public getHelp(): string {
    return `
Command: notify status <notification-id> [options]

Description:
  Check the delivery status of a notification

Arguments:
  notification-id    UUID of the notification to check

Options:
  --format          Output format (json|table|plain) [default: table]
  --verbose, -v     Show detailed status information
  
Examples:
  notify status abc123-def456              Check status in default format
  notify status abc123-def456 --format json Show status in JSON format
  notify status abc123-def456 -v           Show detailed status information

Notes:
  - Supports screen readers and keyboard navigation
  - Uses WCAG compliant color schemes
  - Provides detailed error messages
    `.trim();
  }

  /**
   * Get command completion suggestions
   * 
   * @param partial - Partial command input
   * @returns Array of completion suggestions
   */
  public getSuggestions(partial: string): string[] {
    const suggestions = ['--format', '--verbose', '-v'];
    return suggestions.filter(suggestion => 
      suggestion.toLowerCase().startsWith(partial.toLowerCase())
    );
  }

  /**
   * Fetch notification status with retry logic
   * 
   * @param notificationId - ID of notification to check
   * @returns API response with status information
   */
  private async getNotificationStatus(notificationId: string) {
    let attempts = 0;
    
    while (attempts < this.maxRetries) {
      const response = await this.apiService.getNotificationStatus(notificationId);
      
      if (response.success || !response.error?.retryable) {
        return response;
      }

      attempts++;
      if (attempts < this.maxRetries) {
        await new Promise(resolve => 
          setTimeout(resolve, this.retryDelay * Math.pow(2, attempts - 1))
        );
      }
    }

    return {
      success: false,
      data: null,
      error: {
        code: 'MAX_RETRIES_EXCEEDED',
        message: 'Failed to retrieve status after multiple attempts',
        retryable: false
      }
    };
  }

  /**
   * Handle command execution errors with detailed feedback
   * 
   * @param error - Error to handle
   */
  private handleError(error: Error): void {
    this.outputService.error(
      'COMMAND_ERROR',
      `Failed to execute status command: ${error.message}`
    );

    if (this.options.verbose) {
      this.outputService.debug(`Stack trace: ${error.stack}`);
    }
  }
}

export default StatusCommand;