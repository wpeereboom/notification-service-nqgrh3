/**
 * Configuration management service for the Notification Service CLI
 * Handles secure loading, validation, and persistence of CLI configuration
 * @packageDocumentation
 */

import * as fs from 'fs-extra';
import * as path from 'path';
import * as yaml from 'js-yaml';
import { homedir } from 'os';
import { CommandOptions, OutputFormat } from '../Types';
import { EventEmitter } from 'events';

// Constants for configuration management
const DEFAULT_CONFIG_PATH = path.join(homedir(), '.notify', 'config.yml');
const DEFAULT_OUTPUT_FORMAT = OutputFormat.TABLE;
const CONFIG_FILE_PERMISSIONS = 0o600; // Read/write for owner only
const CONFIG_BACKUP_COUNT = 3;

/**
 * Configuration validation schema
 */
const configSchema = {
  format: {
    type: 'string',
    enum: Object.values(OutputFormat),
    default: DEFAULT_OUTPUT_FORMAT
  },
  apiKey: { type: 'string', pattern: '^[A-Za-z0-9-_]{32,}$' },
  verbose: { type: 'boolean', default: false },
  timeout: { type: 'number', minimum: 1000, maximum: 30000, default: 5000 }
} as const;

/**
 * Type for configuration validation errors
 */
interface ConfigValidationError extends Error {
  code: 'INVALID_CONFIG' | 'PERMISSION_ERROR' | 'CORRUPT_CONFIG';
  field?: string;
}

/**
 * Service for managing CLI configuration settings with enhanced security and validation
 */
export class ConfigService extends EventEmitter {
  private configPath: string;
  private config: Record<string, any>;
  private configBackupPath: string;
  private readonly defaultConfig: Record<string, any>;
  private watcher?: fs.FSWatcher;

  /**
   * Creates a new ConfigService instance
   * @param configPath - Optional custom configuration file path
   * @throws {ConfigValidationError} If config directory cannot be created or secured
   */
  constructor(configPath?: string) {
    super();
    this.configPath = this.normalizePath(configPath || DEFAULT_CONFIG_PATH);
    this.configBackupPath = `${this.configPath}.backup`;
    this.defaultConfig = {
      format: DEFAULT_OUTPUT_FORMAT,
      verbose: false,
      timeout: 5000
    };
    this.config = { ...this.defaultConfig };
    
    this.initializeConfigDirectory();
    this.setupConfigWatcher();
  }

  /**
   * Normalizes and validates the configuration file path
   * @param rawPath - Raw configuration path
   * @returns Normalized absolute path
   */
  private normalizePath(rawPath: string): string {
    const expandedPath = rawPath.replace(/^~/, homedir());
    return path.resolve(expandedPath);
  }

  /**
   * Initializes the configuration directory with secure permissions
   * @throws {ConfigValidationError} If directory cannot be created or secured
   */
  private async initializeConfigDirectory(): Promise<void> {
    const configDir = path.dirname(this.configPath);
    try {
      await fs.ensureDir(configDir, { mode: 0o700 }); // Secure directory permissions
    } catch (error) {
      throw this.createError('Failed to create config directory', 'PERMISSION_ERROR', error);
    }
  }

  /**
   * Sets up a file system watcher for configuration changes
   */
  private setupConfigWatcher(): void {
    const watchDir = path.dirname(this.configPath);
    this.watcher = fs.watch(watchDir, async (eventType, filename) => {
      if (filename === path.basename(this.configPath)) {
        try {
          await this.loadConfig();
          this.emit('configChanged', this.config);
        } catch (error) {
          this.emit('error', error);
        }
      }
    });
  }

  /**
   * Loads and validates configuration from file
   * @throws {ConfigValidationError} If configuration is invalid or corrupted
   */
  public async loadConfig(): Promise<void> {
    try {
      // Check if config file exists
      if (!await fs.pathExists(this.configPath)) {
        await this.saveConfig(); // Create default config
        return;
      }

      // Verify file permissions
      const stats = await fs.stat(this.configPath);
      if ((stats.mode & 0o777) !== CONFIG_FILE_PERMISSIONS) {
        await fs.chmod(this.configPath, CONFIG_FILE_PERMISSIONS);
      }

      // Read and parse configuration
      const configData = await fs.readFile(this.configPath, 'utf8');
      const loadedConfig = yaml.load(configData) as Record<string, any>;

      // Validate configuration
      this.validateConfig(loadedConfig);

      // Merge with defaults
      this.config = {
        ...this.defaultConfig,
        ...loadedConfig
      };

    } catch (error) {
      if (await fs.pathExists(this.configBackupPath)) {
        // Attempt recovery from backup
        try {
          const backupData = await fs.readFile(this.configBackupPath, 'utf8');
          const backupConfig = yaml.load(backupData) as Record<string, any>;
          this.validateConfig(backupConfig);
          this.config = { ...this.defaultConfig, ...backupConfig };
          await this.saveConfig(); // Restore from backup
          return;
        } catch (backupError) {
          throw this.createError('Configuration corrupt and backup recovery failed', 'CORRUPT_CONFIG', backupError);
        }
      }
      throw this.createError('Failed to load configuration', 'INVALID_CONFIG', error);
    }
  }

  /**
   * Saves current configuration to file with backup
   * @throws {ConfigValidationError} If configuration cannot be saved
   */
  public async saveConfig(): Promise<void> {
    try {
      // Validate current configuration
      this.validateConfig(this.config);

      // Create backup of existing config if it exists
      if (await fs.pathExists(this.configPath)) {
        await fs.copy(this.configPath, this.configBackupPath);
      }

      // Write configuration to temporary file first
      const tempPath = `${this.configPath}.tmp`;
      const configYaml = yaml.dump(this.config, { 
        indent: 2,
        lineWidth: -1,
        noRefs: true
      });

      await fs.writeFile(tempPath, configYaml, { mode: CONFIG_FILE_PERMISSIONS });
      await fs.rename(tempPath, this.configPath);

      // Rotate backup files
      await this.rotateBackups();

    } catch (error) {
      throw this.createError('Failed to save configuration', 'INVALID_CONFIG', error);
    }
  }

  /**
   * Retrieves a typed configuration value
   * @param key - Configuration key to retrieve
   * @returns Typed configuration value
   */
  public getConfig<T>(key: string): T {
    const schema = (configSchema as any)[key];
    if (!schema) {
      throw this.createError(`Invalid configuration key: ${key}`, 'INVALID_CONFIG');
    }

    const value = this.config[key];
    if (value === undefined) {
      return schema.default as T;
    }

    if (!this.validateValue(value, schema)) {
      throw this.createError(`Invalid value for ${key}`, 'INVALID_CONFIG', null, key);
    }

    return value as T;
  }

  /**
   * Sets a configuration value with validation
   * @param key - Configuration key to set
   * @param value - Value to set
   * @throws {ConfigValidationError} If value is invalid
   */
  public async setConfig<T>(key: string, value: T): Promise<void> {
    const schema = (configSchema as any)[key];
    if (!schema) {
      throw this.createError(`Invalid configuration key: ${key}`, 'INVALID_CONFIG');
    }

    if (!this.validateValue(value, schema)) {
      throw this.createError(`Invalid value for ${key}`, 'INVALID_CONFIG', null, key);
    }

    this.config[key] = value;
    await this.saveConfig();
    this.emit('configChanged', this.config);
  }

  /**
   * Gets the current output format setting
   * @returns Current OutputFormat
   */
  public getOutputFormat(): OutputFormat {
    return this.getConfig<OutputFormat>('format');
  }

  /**
   * Validates the entire configuration object
   * @param config - Configuration object to validate
   * @throws {ConfigValidationError} If configuration is invalid
   */
  private validateConfig(config: Record<string, any>): void {
    for (const [key, schema] of Object.entries(configSchema)) {
      const value = config[key];
      if (value !== undefined && !this.validateValue(value, schema)) {
        throw this.createError(`Invalid configuration value for ${key}`, 'INVALID_CONFIG', null, key);
      }
    }
  }

  /**
   * Validates a single configuration value against its schema
   * @param value - Value to validate
   * @param schema - Schema to validate against
   * @returns Whether the value is valid
   */
  private validateValue(value: any, schema: any): boolean {
    switch (schema.type) {
      case 'string':
        if (typeof value !== 'string') return false;
        if (schema.pattern && !new RegExp(schema.pattern).test(value)) return false;
        if (schema.enum && !schema.enum.includes(value)) return false;
        return true;
      case 'number':
        if (typeof value !== 'number') return false;
        if (schema.minimum !== undefined && value < schema.minimum) return false;
        if (schema.maximum !== undefined && value > schema.maximum) return false;
        return true;
      case 'boolean':
        return typeof value === 'boolean';
      default:
        return false;
    }
  }

  /**
   * Rotates backup configuration files
   */
  private async rotateBackups(): Promise<void> {
    for (let i = CONFIG_BACKUP_COUNT; i > 0; i--) {
      const oldPath = `${this.configBackupPath}.${i - 1}`;
      const newPath = `${this.configBackupPath}.${i}`;
      if (await fs.pathExists(oldPath)) {
        await fs.rename(oldPath, newPath);
      }
    }
  }

  /**
   * Creates a standardized configuration error
   * @param message - Error message
   * @param code - Error code
   * @param cause - Original error cause
   * @param field - Related configuration field
   * @returns Standardized configuration error
   */
  private createError(
    message: string,
    code: ConfigValidationError['code'],
    cause?: Error | null,
    field?: string
  ): ConfigValidationError {
    const error = new Error(message) as ConfigValidationError;
    error.code = code;
    error.field = field;
    error.cause = cause || undefined;
    return error;
  }

  /**
   * Cleanup resources on service shutdown
   */
  public async destroy(): Promise<void> {
    if (this.watcher) {
      this.watcher.close();
    }
  }
}

export default ConfigService;