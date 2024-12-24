/**
 * VendorCommand - Enterprise-grade CLI command for managing notification service vendors
 * Provides comprehensive vendor management with health monitoring and failover support
 * @packageDocumentation
 * @version 1.0.0
 */

import { CommandInterface } from '../Interfaces/CommandInterface';
import { CommandOptions, OutputFormat, NotificationChannel, ErrorLevel } from '../Types';
import { ApiService } from '../Services/ApiService';
import { OutputService } from '../Services/OutputService';

// Constants for health monitoring and failover
const HEALTH_CHECK_INTERVAL = 30000; // 30 seconds
const FAILOVER_THRESHOLD = 3; // Failed attempts before failover
const VENDOR_TYPES = ['email', 'sms', 'push'] as const;

/**
 * Extended options for vendor command functionality
 */
interface VendorCommandOptions extends CommandOptions {
  status?: boolean;
  update?: boolean;
  service?: typeof VENDOR_TYPES[number];
  healthCheck?: boolean;
  failover?: boolean;
  metrics?: boolean;
}

/**
 * Vendor health metrics interface
 */
interface VendorHealth {
  status: 'UP' | 'DOWN' | 'DEGRADED';
  lastCheck: Date;
  responseTime: number;
  successRate: number;
  failureCount: number;
}

/**
 * Enterprise-grade command implementation for vendor management
 */
export class VendorCommand implements CommandInterface {
  public readonly name = 'vendor';
  public readonly description = 'Manage notification service vendors with health monitoring and failover';
  public readonly aliases = ['v'];

  private healthCheckInterval?: NodeJS.Timer;
  private vendorHealth: Map<string, VendorHealth>;
  private readonly options: VendorCommandOptions = {
    format: OutputFormat.TABLE,
    verbose: false
  };

  /**
   * Initialize vendor command with required services
   * 
   * @param apiService - Service for API communication
   * @param outputService - Service for CLI output
   */
  constructor(
    private readonly apiService: ApiService,
    private readonly outputService: OutputService
  ) {
    this.vendorHealth = new Map();
  }

  /**
   * Execute vendor command with provided arguments and options
   * 
   * @param args - Command arguments
   * @param options - Command options
   */
  public async execute(args: string[], options: VendorCommandOptions): Promise<void> {
    try {
      if (!this.validate(args)) {
        throw new Error('Invalid command arguments');
      }

      Object.assign(this.options, options);

      if (options.status) {
        await this.displayVendorStatus(options.service);
      }

      if (options.healthCheck) {
        await this.startHealthMonitoring(options.service);
      }

      if (options.failover) {
        await this.configureFailover(options.service);
      }

      if (options.update) {
        await this.updateVendorConfig(args[1], JSON.parse(args[2]));
      }

      if (options.metrics) {
        await this.displayVendorMetrics(options.service);
      }
    } catch (error) {
      this.handleError(error as Error);
    }
  }

  /**
   * Start continuous vendor health monitoring
   * 
   * @param service - Optional service type filter
   */
  private async startHealthMonitoring(service?: string): Promise<void> {
    this.outputService.info(`Starting health monitoring${service ? ` for ${service}` : ''}`);

    // Clear existing interval if running
    if (this.healthCheckInterval) {
      clearInterval(this.healthCheckInterval);
    }

    const monitorVendors = async () => {
      try {
        const vendors = service ? [service] : VENDOR_TYPES;
        for (const vendor of vendors) {
          const health = await this.checkVendorHealth(vendor);
          this.vendorHealth.set(vendor, health);

          if (health.status !== 'UP') {
            this.outputService.warn(
              `Vendor ${vendor} health degraded: ${health.successRate}% success rate`
            );

            if (health.failureCount >= FAILOVER_THRESHOLD) {
              await this.initiateFailover(vendor);
            }
          }
        }
      } catch (error) {
        this.outputService.error('HEALTH_CHECK_ERROR', (error as Error).message);
      }
    };

    // Initial check
    await monitorVendors();

    // Set up interval
    this.healthCheckInterval = setInterval(monitorVendors, HEALTH_CHECK_INTERVAL);
  }

  /**
   * Check health status for a specific vendor
   * 
   * @param vendor - Vendor to check
   * @returns Vendor health metrics
   */
  private async checkVendorHealth(vendor: string): Promise<VendorHealth> {
    const startTime = Date.now();
    const response = await this.apiService.getVendorStatus(vendor);
    const responseTime = Date.now() - startTime;

    return {
      status: response.success ? 'UP' : 'DOWN',
      lastCheck: new Date(),
      responseTime,
      successRate: response.data?.metrics?.successRate ?? 0,
      failureCount: response.data?.metrics?.failures ?? 0
    };
  }

  /**
   * Configure vendor failover settings
   * 
   * @param service - Service type to configure
   */
  private async configureFailover(service?: string): Promise<void> {
    try {
      const config = {
        threshold: FAILOVER_THRESHOLD,
        interval: HEALTH_CHECK_INTERVAL,
        autoFailback: true
      };

      await this.apiService.updateFailoverConfig(service, config);
      this.outputService.info(`Failover configuration updated for ${service || 'all services'}`);
    } catch (error) {
      this.outputService.error('FAILOVER_CONFIG_ERROR', (error as Error).message);
    }
  }

  /**
   * Initiate vendor failover process
   * 
   * @param vendor - Vendor requiring failover
   */
  private async initiateFailover(vendor: string): Promise<void> {
    try {
      this.outputService.warn(`Initiating failover for ${vendor}`);
      await this.apiService.updateVendorConfig(vendor, { status: 'FAILING_OVER' });
      
      // Log critical event
      this.outputService.error(
        'VENDOR_FAILOVER',
        `Vendor ${vendor} failed health check, initiating failover`
      );
    } catch (error) {
      this.outputService.error('FAILOVER_ERROR', (error as Error).message);
    }
  }

  /**
   * Display current vendor status
   * 
   * @param service - Optional service type filter
   */
  private async displayVendorStatus(service?: string): Promise<void> {
    try {
      const status = await this.apiService.getVendorStatus(service);
      this.outputService.print(status.data, {
        ariaLabel: 'Vendor Status Information',
        ariaLive: 'polite'
      });
    } catch (error) {
      this.outputService.error('STATUS_ERROR', (error as Error).message);
    }
  }

  /**
   * Display detailed vendor metrics
   * 
   * @param service - Optional service type filter
   */
  private async displayVendorMetrics(service?: string): Promise<void> {
    const metrics = Array.from(this.vendorHealth.entries())
      .filter(([vendor]) => !service || vendor === service)
      .map(([vendor, health]) => ({
        vendor,
        status: health.status,
        successRate: `${health.successRate}%`,
        responseTime: `${health.responseTime}ms`,
        lastCheck: health.lastCheck.toISOString()
      }));

    this.outputService.print(metrics, {
      ariaLabel: 'Vendor Performance Metrics',
      ariaLive: 'polite'
    });
  }

  /**
   * Update vendor configuration
   * 
   * @param vendor - Vendor to update
   * @param config - New configuration
   */
  private async updateVendorConfig(vendor: string, config: Record<string, unknown>): Promise<void> {
    try {
      await this.apiService.updateVendorConfig(vendor, config);
      this.outputService.info(`Configuration updated for ${vendor}`);
    } catch (error) {
      this.outputService.error('CONFIG_UPDATE_ERROR', (error as Error).message);
    }
  }

  /**
   * Validate command arguments
   * 
   * @param args - Command arguments to validate
   * @returns True if arguments are valid
   */
  public validate(args: string[]): boolean {
    if (args.length === 0) {
      return false;
    }

    if (args[0] === 'update' && args.length < 3) {
      return false;
    }

    if (args[0] === 'status' && args[1] && !VENDOR_TYPES.includes(args[1] as any)) {
      return false;
    }

    return true;
  }

  /**
   * Get command help documentation
   * 
   * @returns Formatted help text
   */
  public getHelp(): string {
    return `
Vendor Management Command

Usage:
  vendor status [service]     Display vendor status
  vendor update <vendor> <config>  Update vendor configuration
  vendor health [service]     Start health monitoring
  vendor failover [service]   Configure failover settings
  vendor metrics [service]    Display detailed metrics

Options:
  --format    Output format (json, table, plain)
  --verbose   Enable verbose output

Services:
  email       Email delivery vendors
  sms         SMS delivery vendors
  push        Push notification vendors

Examples:
  vendor status email
  vendor health --format json
  vendor update email '{"weight": 50}'
    `.trim();
  }

  /**
   * Handle command errors
   * 
   * @param error - Error to handle
   */
  private handleError(error: Error): void {
    this.outputService.error('COMMAND_ERROR', error.message);
    if (this.options.verbose) {
      this.outputService.debug(error.stack || 'No stack trace available');
    }
  }

  /**
   * Clean up resources on command completion
   */
  public destroy(): void {
    if (this.healthCheckInterval) {
      clearInterval(this.healthCheckInterval);
    }
  }
}