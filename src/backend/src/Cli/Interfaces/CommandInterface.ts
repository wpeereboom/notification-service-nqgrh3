/**
 * Core interface definition for CLI commands in the Notification Service
 * @packageDocumentation
 */

import { CommandOptions } from '../Types';

/**
 * Core interface that all CLI commands must implement
 * Provides standardized structure for command execution, validation, and documentation
 * 
 * @interface CommandInterface
 * @version 1.0.0
 */
export interface CommandInterface {
  /**
   * Unique identifier for the command
   * Used for command registration and lookup
   * @example 'notify', 'status', 'template'
   */
  readonly name: string;

  /**
   * Detailed description of command functionality
   * Used in help documentation and command discovery
   */
  readonly description: string;

  /**
   * Command-specific options extending base CommandOptions
   * Defines the configuration and behavior parameters
   */
  readonly options: CommandOptions;

  /**
   * Optional alternative command names
   * Allows for command aliases and shortcuts
   * @example ['n'] for 'notify'
   */
  readonly aliases: string[];

  /**
   * Executes the command with provided arguments and options
   * Implements the core command functionality
   * 
   * @param args - Command arguments array
   * @param options - Command execution options
   * @returns Promise resolving when command execution completes
   * @throws Error if command execution fails
   */
  execute(args: string[], options: CommandOptions): Promise<void>;

  /**
   * Validates command arguments before execution
   * Ensures all required parameters are present and valid
   * 
   * @param args - Command arguments to validate
   * @returns boolean indicating if arguments are valid
   */
  validate(args: string[]): boolean;

  /**
   * Generates formatted help documentation for the command
   * Provides usage instructions, examples, and option descriptions
   * 
   * @returns Formatted help text string
   */
  getHelp(): string;

  /**
   * Provides command completion suggestions
   * Enables CLI auto-completion functionality
   * 
   * @param partial - Partial command input to generate suggestions for
   * @returns Array of completion suggestions
   */
  getSuggestions(partial: string): string[];
}