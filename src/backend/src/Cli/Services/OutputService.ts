/**
 * CLI Output Service
 * Provides enterprise-grade output management with accessibility and internationalization support
 * @packageDocumentation
 */

import chalk from 'chalk'; // v4.1.2
import { formatOutput, formatStatus, formatError } from '../Utils/formatter';
import { OutputFormat } from '../Types';

/**
 * Interface for print options with accessibility and internationalization support
 */
interface PrintOptions {
  /** Screen reader description */
  ariaLabel?: string;
  /** Indicates if content should be announced immediately */
  ariaLive?: 'polite' | 'assertive';
  /** Additional context for screen readers */
  ariaDescription?: string;
}

/**
 * Service class responsible for managing CLI output with accessibility
 * and internationalization support
 */
export class OutputService {
  private readonly outputFormat: OutputFormat;
  private readonly verbose: boolean;
  private readonly isRTL: boolean;
  private readonly chalkInstance: typeof chalk;
  private readonly outputBuffer: string[];
  private readonly supportsColor: boolean;
  private readonly terminalWidth: number;

  /**
   * Initialize output service with format, verbosity, and accessibility settings
   * 
   * @param format - Desired output format
   * @param verbose - Enable verbose output
   * @param isRTL - Enable RTL text direction
   */
  constructor(
    format: OutputFormat = OutputFormat.PLAIN,
    verbose: boolean = false,
    isRTL: boolean = false
  ) {
    this.outputFormat = format;
    this.verbose = verbose;
    this.isRTL = isRTL;
    this.chalkInstance = new chalk.Instance({ level: 3 }); // Force ANSI colors for WCAG compliance
    this.outputBuffer = [];
    this.supportsColor = process.stdout.isTTY && chalk.supportsColor.hasBasic;
    this.terminalWidth = process.stdout.columns || 80;

    // Set up screen reader support
    if (process.env.TERM_PROGRAM === 'NVDA' || process.env.TERM_PROGRAM === 'JAWS') {
      process.stdout.write('\u001B[?12l'); // Disable cursor blink for screen readers
    }
  }

  /**
   * Print formatted output to console with accessibility support
   * 
   * @param data - Data to be printed
   * @param options - Accessibility and formatting options
   */
  public print(data: unknown, options: PrintOptions = {}): void {
    try {
      // Format data according to selected output format
      let output = formatOutput(data, this.outputFormat);

      // Apply RTL formatting if needed
      if (this.isRTL) {
        output = this.applyRTLFormatting(output);
      }

      // Add screen reader metadata
      if (options.ariaLabel) {
        output = `${this.chalkInstance.hidden(`[${options.ariaLabel}] `)}${output}`;
      }

      // Handle large datasets with buffering
      if (output.length > 1000) {
        this.bufferOutput(output);
      } else {
        process.stdout.write(output + '\n');
      }

      // Announce to screen readers if needed
      if (options.ariaLive === 'assertive') {
        process.stdout.write(`\u0007`); // Bell character for immediate attention
      }
    } catch (error) {
      this.error('OUTPUT_ERROR', (error as Error).message);
    }
  }

  /**
   * Print formatted notification status with WCAG compliance
   * 
   * @param status - Notification status to display
   */
  public printStatus(status: string): void {
    const formattedStatus = formatStatus(status);
    const output = this.isRTL ? this.applyRTLFormatting(formattedStatus) : formattedStatus;
    process.stdout.write(output + '\n');
  }

  /**
   * Print info level message with accessibility support
   * 
   * @param message - Information message
   */
  public info(message: string): void {
    const output = this.chalkInstance.blue(`INFO: ${message}`);
    const formattedOutput = this.isRTL ? this.applyRTLFormatting(output) : output;
    process.stdout.write(formattedOutput + '\n');
  }

  /**
   * Print warning level message with accessibility support
   * 
   * @param message - Warning message
   */
  public warn(message: string): void {
    const output = this.chalkInstance.hex('#FFA000')(`WARN: ${message}`); // WCAG AA compliant yellow
    const formattedOutput = this.isRTL ? this.applyRTLFormatting(output) : output;
    process.stdout.write(formattedOutput + '\n');
    process.stdout.write(`\u0007`); // Alert screen readers
  }

  /**
   * Print error level message with accessibility support
   * 
   * @param code - Error code
   * @param message - Error message
   */
  public error(code: string, message: string): void {
    const formattedError = formatError(code, message);
    const output = this.isRTL ? this.applyRTLFormatting(formattedError) : formattedError;
    process.stdout.write(output + '\n');
    process.stdout.write(`\u0007\u0007`); // Double bell for critical errors
  }

  /**
   * Print debug level message when verbose mode is enabled
   * 
   * @param message - Debug message
   */
  public debug(message: string): void {
    if (this.verbose) {
      const output = this.chalkInstance.gray(`DEBUG: ${message}`);
      const formattedOutput = this.isRTL ? this.applyRTLFormatting(output) : output;
      process.stdout.write(formattedOutput + '\n');
    }
  }

  /**
   * Apply RTL text direction formatting
   * 
   * @param text - Text to format
   * @returns Formatted RTL text
   */
  private applyRTLFormatting(text: string): string {
    // Add RTL mark and handle bidirectional text
    return `\u200F${text.split('\n').map(line => line.split('').reverse().join('')).join('\n')}\u200F`;
  }

  /**
   * Buffer large output for chunked display
   * 
   * @param output - Output to buffer
   */
  private bufferOutput(output: string): void {
    const chunks = output.match(new RegExp(`.{1,${this.terminalWidth}}`, 'g')) || [];
    this.outputBuffer.push(...chunks);

    while (this.outputBuffer.length > 0) {
      const chunk = this.outputBuffer.shift();
      if (chunk) {
        process.stdout.write(chunk + '\n');
      }
    }
  }

  /**
   * Clear the output buffer
   */
  private clearBuffer(): void {
    this.outputBuffer.length = 0;
  }
}