/**
 * CLI Output Formatter Utility
 * Provides comprehensive formatting functions for CLI output with accessibility
 * and internationalization support.
 * @packageDocumentation
 */

import chalk from 'chalk'; // v4.1.2
import Table from 'cli-table3'; // v0.6.3
import { OutputFormat, NotificationStatus } from '../Types/index';

/**
 * Status symbol mapping with accessibility considerations
 * Uses Unicode symbols with high contrast and screen reader support
 */
const STATUS_SYMBOLS = {
  [NotificationStatus.QUEUED]: '⏳',     // Hourglass
  [NotificationStatus.PROCESSING]: '⚙️',  // Gear
  [NotificationStatus.DELIVERED]: '✔️',   // Check mark
  [NotificationStatus.FAILED]: '❌',      // Cross mark
} as const;

/**
 * WCAG AA compliant color mapping for status
 */
const STATUS_COLORS = {
  [NotificationStatus.QUEUED]: chalk.hex('#707070'),      // Gray
  [NotificationStatus.PROCESSING]: chalk.hex('#0066CC'),  // Blue
  [NotificationStatus.DELIVERED]: chalk.hex('#2E7D32'),   // Green
  [NotificationStatus.FAILED]: chalk.hex('#D32F2F'),      // Red
} as const;

/**
 * Formats data according to specified output format with enhanced type safety
 * and proper error handling
 * 
 * @param data - Data to be formatted
 * @param format - Desired output format
 * @returns Formatted string with proper encoding and line endings
 * @throws Error if data cannot be formatted in specified format
 */
export function formatOutput(data: unknown, format: OutputFormat): string {
  try {
    switch (format) {
      case OutputFormat.JSON:
        return formatJson(data);
      case OutputFormat.TABLE:
        return formatTable(data);
      case OutputFormat.PLAIN:
        return formatPlain(data);
      default:
        throw new Error(`Unsupported output format: ${format}`);
    }
  } catch (error) {
    throw new Error(`Formatting error: ${(error as Error).message}`);
  }
}

/**
 * Formats notification status with WCAG compliant colors and symbols
 * 
 * @param status - Notification status to format
 * @returns Accessible colored status string with symbol
 */
export function formatStatus(status: NotificationStatus): string {
  const symbol = STATUS_SYMBOLS[status] || '❔'; // Question mark as fallback
  const colorizer = STATUS_COLORS[status] || chalk.white;
  
  // Add screen reader text
  const screenReaderText = `Status: ${status.toLowerCase()}`;
  
  return colorizer(`${symbol} ${status} `) + chalk.hidden(screenReaderText);
}

/**
 * Formats error messages with standardized structure and debugging info
 * 
 * @param code - Error code
 * @param message - Error message
 * @returns Formatted error message with optional stack trace
 */
export function formatError(code: string, message: string): string {
  const timestamp = new Date().toISOString();
  return chalk.red(`ERROR [${code}] ${timestamp}\n${message}`);
}

/**
 * Internal helper to format data as JSON
 * 
 * @param data - Data to format as JSON
 * @returns Formatted JSON string
 */
function formatJson(data: unknown): string {
  return JSON.stringify(data, (key, value) => {
    // Handle BigInt serialization
    if (typeof value === 'bigint') {
      return value.toString();
    }
    return value;
  }, 2);
}

/**
 * Internal helper to format data as table
 * 
 * @param data - Data to format as table
 * @returns Formatted table string
 */
function formatTable(data: unknown): string {
  if (!Array.isArray(data)) {
    throw new Error('Table format requires array input');
  }

  if (data.length === 0) {
    return 'No data available';
  }

  const headers = Object.keys(data[0]);
  const table = new Table({
    head: headers,
    chars: {
      'top': '─',
      'top-mid': '┬',
      'top-left': '┌',
      'top-right': '┐',
      'bottom': '─',
      'bottom-mid': '┴',
      'bottom-left': '└',
      'bottom-right': '┘',
      'left': '│',
      'left-mid': '├',
      'mid': '─',
      'mid-mid': '┼',
      'right': '│',
      'right-mid': '┤',
      'middle': '│'
    }
  });

  data.forEach((row: Record<string, unknown>) => {
    table.push(headers.map(header => formatPlain(row[header])));
  });

  return table.toString();
}

/**
 * Internal helper to format data as plain text
 * 
 * @param data - Data to format as plain text
 * @returns Formatted plain text string
 */
function formatPlain(data: unknown): string {
  if (data === null || data === undefined) {
    return '';
  }
  
  if (typeof data === 'object') {
    return JSON.stringify(data);
  }
  
  return String(data);
}