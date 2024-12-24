/**
 * Template Management Command
 * Provides comprehensive functionality for managing notification templates
 * with enhanced validation, error handling, and accessibility support.
 * @packageDocumentation
 */

import { CommandInterface } from '../Interfaces/CommandInterface';
import { TemplateCommandOptions, NotificationChannel, OutputFormat, ErrorLevel } from '../Types';
import { ApiService } from '../Services/ApiService';
import { OutputService } from '../Services/OutputService';

/**
 * Interface for template creation/update payload
 */
interface TemplatePayload {
  name: string;
  channel: NotificationChannel;
  content: Record<string, unknown>;
  active?: boolean;
}

/**
 * Implementation of the template management command
 * Provides functionality for listing, creating, updating, and deleting templates
 */
export class TemplateCommand implements CommandInterface {
  public readonly name = 'template';
  public readonly description = 'Manage notification templates with comprehensive validation and versioning support';
  public readonly aliases = ['t', 'tmpl'];
  public readonly options = {
    format: OutputFormat.TABLE,
    verbose: false
  };

  private readonly operationHandlers: Map<string, Function>;

  /**
   * Initialize template command with required services
   * 
   * @param apiService - API communication service
   * @param outputService - Output formatting service
   */
  constructor(
    private readonly apiService: ApiService,
    private readonly outputService: OutputService
  ) {
    this.operationHandlers = new Map([
      ['list', this.handleList.bind(this)],
      ['create', this.handleCreate.bind(this)],
      ['update', this.handleUpdate.bind(this)],
      ['delete', this.handleDelete.bind(this)]
    ]);

    // Handle cleanup on process termination
    process.on('SIGINT', this.cleanup.bind(this));
    process.on('SIGTERM', this.cleanup.bind(this));
  }

  /**
   * Execute template command with comprehensive error handling
   * 
   * @param args - Command arguments
   * @param options - Command options
   */
  public async execute(args: string[], options: TemplateCommandOptions): Promise<void> {
    try {
      if (!this.validate(args)) {
        this.outputService.error('INVALID_ARGS', 'Invalid command arguments');
        return;
      }

      const operation = this.determineOperation(options);
      if (!operation) {
        this.outputService.error('INVALID_OPERATION', 'No valid operation specified');
        return;
      }

      const handler = this.operationHandlers.get(operation);
      if (!handler) {
        this.outputService.error('UNKNOWN_OPERATION', `Unknown operation: ${operation}`);
        return;
      }

      this.outputService.debug(`Executing ${operation} operation`);
      await handler(args, options);

    } catch (error) {
      this.handleError(error as Error);
    }
  }

  /**
   * Validate command arguments and options
   * 
   * @param args - Command arguments to validate
   * @returns boolean indicating if arguments are valid
   */
  public validate(args: string[]): boolean {
    if (args.length === 0) return true; // No args needed for list operation

    // Validate template name format if provided
    if (args[0] && !/^[a-zA-Z0-9-_]+$/.test(args[0])) {
      this.outputService.error('INVALID_NAME', 'Template name must contain only alphanumeric characters, hyphens, and underscores');
      return false;
    }

    return true;
  }

  /**
   * Get comprehensive help documentation
   * 
   * @returns Formatted help text
   */
  public getHelp(): string {
    return `
Template Management Command

Usage:
  notify template [options] [template_name]

Options:
  --list              List all templates
  --create            Create a new template
  --update            Update an existing template
  --delete            Delete a template
  --type <channel>    Filter by notification channel (email|sms|push)
  --verbose           Enable verbose output
  --format <format>   Output format (json|table|plain)

Examples:
  notify template --list
  notify template --list --type email
  notify template welcome-email --create
  notify template welcome-email --update
  notify template welcome-email --delete

For more information, visit: https://docs.example.com/cli/template
    `.trim();
  }

  /**
   * Get command completion suggestions
   * 
   * @param partial - Partial command input
   * @returns Array of completion suggestions
   */
  public getSuggestions(partial: string): string[] {
    const suggestions = [
      '--list',
      '--create',
      '--update',
      '--delete',
      '--type',
      '--verbose',
      '--format'
    ];

    return suggestions.filter(s => s.startsWith(partial));
  }

  /**
   * Handle template listing operation
   * 
   * @param args - Command arguments
   * @param options - Command options
   */
  private async handleList(args: string[], options: TemplateCommandOptions): Promise<void> {
    this.outputService.debug('Fetching templates...');
    
    const response = await this.apiService.getTemplates(options.type);
    
    if (!response.success) {
      this.outputService.error('API_ERROR', response.error?.message || 'Failed to fetch templates');
      return;
    }

    if (response.data.length === 0) {
      this.outputService.info('No templates found');
      return;
    }

    this.outputService.print(response.data, {
      ariaLabel: 'Template list',
      ariaDescription: `Found ${response.data.length} templates`
    });
  }

  /**
   * Handle template creation operation
   * 
   * @param args - Command arguments
   * @param options - Command options
   */
  private async handleCreate(args: string[], options: TemplateCommandOptions): Promise<void> {
    if (!args[0]) {
      this.outputService.error('MISSING_NAME', 'Template name is required');
      return;
    }

    try {
      const templateData = await this.readTemplateData();
      const response = await this.apiService.createTemplate(templateData);

      if (!response.success) {
        this.outputService.error('CREATE_FAILED', response.error?.message || 'Failed to create template');
        return;
      }

      this.outputService.info(`Template '${args[0]}' created successfully`);
      if (options.verbose) {
        this.outputService.print(response.data);
      }

    } catch (error) {
      this.handleError(error as Error);
    }
  }

  /**
   * Handle template update operation
   * 
   * @param args - Command arguments
   * @param options - Command options
   */
  private async handleUpdate(args: string[], options: TemplateCommandOptions): Promise<void> {
    if (!args[0]) {
      this.outputService.error('MISSING_NAME', 'Template name is required');
      return;
    }

    try {
      const templateData = await this.readTemplateData();
      const response = await this.apiService.updateTemplate(args[0], templateData);

      if (!response.success) {
        this.outputService.error('UPDATE_FAILED', response.error?.message || 'Failed to update template');
        return;
      }

      this.outputService.info(`Template '${args[0]}' updated successfully`);
      if (options.verbose) {
        this.outputService.print(response.data);
      }

    } catch (error) {
      this.handleError(error as Error);
    }
  }

  /**
   * Handle template deletion operation
   * 
   * @param args - Command arguments
   * @param options - Command options
   */
  private async handleDelete(args: string[], options: TemplateCommandOptions): Promise<void> {
    if (!args[0]) {
      this.outputService.error('MISSING_NAME', 'Template name is required');
      return;
    }

    try {
      const response = await this.apiService.deleteTemplate(args[0]);

      if (!response.success) {
        this.outputService.error('DELETE_FAILED', response.error?.message || 'Failed to delete template');
        return;
      }

      this.outputService.info(`Template '${args[0]}' deleted successfully`);

    } catch (error) {
      this.handleError(error as Error);
    }
  }

  /**
   * Determine which operation to execute based on options
   * 
   * @param options - Command options
   * @returns Operation name or undefined
   */
  private determineOperation(options: TemplateCommandOptions): string | undefined {
    if (options.list) return 'list';
    if (options.create) return 'create';
    if (options.update) return 'update';
    if (options.delete) return 'delete';
    return 'list'; // Default operation
  }

  /**
   * Read template data from stdin
   * 
   * @returns Promise resolving to template payload
   */
  private async readTemplateData(): Promise<TemplatePayload> {
    return new Promise((resolve, reject) => {
      let data = '';
      
      process.stdin.on('data', chunk => {
        data += chunk;
      });

      process.stdin.on('end', () => {
        try {
          const templateData = JSON.parse(data);
          if (this.validateTemplateData(templateData)) {
            resolve(templateData);
          } else {
            reject(new Error('Invalid template data format'));
          }
        } catch (error) {
          reject(error);
        }
      });

      process.stdin.on('error', reject);
    });
  }

  /**
   * Validate template data structure
   * 
   * @param data - Template data to validate
   * @returns boolean indicating if data is valid
   */
  private validateTemplateData(data: any): data is TemplatePayload {
    return (
      typeof data === 'object' &&
      typeof data.name === 'string' &&
      Object.values(NotificationChannel).includes(data.channel) &&
      typeof data.content === 'object'
    );
  }

  /**
   * Handle command errors with proper formatting
   * 
   * @param error - Error to handle
   */
  private handleError(error: Error): void {
    this.outputService.error(
      'COMMAND_ERROR',
      `Template command failed: ${error.message}`
    );
    
    if (this.options.verbose) {
      this.outputService.debug(error.stack || 'No stack trace available');
    }
  }

  /**
   * Cleanup resources on command termination
   */
  private cleanup(): void {
    this.outputService.debug('Cleaning up template command resources');
    // Additional cleanup if needed
  }
}

export default TemplateCommand;