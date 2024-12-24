/**
 * Service responsible for making HTTP requests to the notification service REST API
 * with comprehensive error handling, retry mechanisms, and type safety
 * @packageDocumentation
 */

import axios, { AxiosInstance, AxiosError, AxiosRequestConfig } from 'axios';
import { CommandOptions, NotificationChannel, NotificationStatus } from '../Types/index';
import { ConfigService } from './ConfigService';

// Constants for API configuration
const API_VERSION = 'v1';
const MAX_RETRIES = 3;
const INITIAL_RETRY_DELAY = 1000;
const MAX_RETRY_DELAY = 5000;

/**
 * Generic interface for API responses with type safety
 */
export interface ApiResponse<T> {
  success: boolean;
  data: T;
  error: ApiError | null;
  requestId?: string;
}

/**
 * Enhanced interface for API error responses
 */
export interface ApiError {
  code: string;
  message: string;
  details: any[];
  retryable: boolean;
  timestamp: string;
  requestId?: string;
}

/**
 * Interface for notification response data
 */
interface NotificationResponse {
  id: string;
  status: NotificationStatus;
  channel: NotificationChannel;
  createdAt: string;
  tracking: {
    attempts: number;
    lastAttempt: string | null;
  };
}

/**
 * Enhanced service class for handling API communication with retry mechanisms
 */
export class ApiService {
  private readonly baseUrl: string;
  private readonly apiKey: string;
  private readonly axiosInstance: AxiosInstance;
  private readonly configService: ConfigService;

  /**
   * Initialize API service with configuration and setup axios instance
   * @param configService - Configuration service instance
   */
  constructor(configService: ConfigService) {
    this.configService = configService;
    this.baseUrl = this.configService.getConfig<string>('apiUrl');
    this.apiKey = this.configService.getConfig<string>('apiKey');
    this.axiosInstance = this.createAxiosInstance();
  }

  /**
   * Create and configure axios instance with interceptors and defaults
   * @returns Configured axios instance
   */
  private createAxiosInstance(): AxiosInstance {
    const instance = axios.create({
      baseURL: `${this.baseUrl}/${API_VERSION}`,
      timeout: this.configService.getConfig<number>('timeout'),
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'User-Agent': 'NotificationCLI/1.0'
      }
    });

    // Request interceptor for authentication
    instance.interceptors.request.use((config) => {
      config.headers['Authorization'] = `Bearer ${this.apiKey}`;
      config.headers['X-Request-ID'] = this.generateRequestId();
      return config;
    });

    // Response interceptor for error handling
    instance.interceptors.response.use(
      (response) => response,
      async (error: AxiosError) => {
        const config = error.config as AxiosRequestConfig & { _retry?: number };
        
        if (!config || !this.isRetryableError(error) || (config._retry || 0) >= MAX_RETRIES) {
          throw this.handleApiError(error);
        }

        config._retry = (config._retry || 0) + 1;
        const delay = Math.min(
          INITIAL_RETRY_DELAY * Math.pow(2, config._retry - 1),
          MAX_RETRY_DELAY
        );

        await new Promise(resolve => setTimeout(resolve, delay));
        return instance(config);
      }
    );

    return instance;
  }

  /**
   * Send a new notification with retry capability
   * @param templateId - Template ID to use
   * @param recipient - Notification recipient
   * @param channel - Delivery channel
   * @param context - Template context data
   * @returns Promise with notification response
   */
  public async sendNotification(
    templateId: string,
    recipient: string,
    channel: NotificationChannel,
    context: Record<string, any>
  ): Promise<ApiResponse<NotificationResponse>> {
    try {
      const response = await this.axiosInstance.post<NotificationResponse>('/notifications', {
        templateId,
        recipient,
        channel,
        context
      });

      return {
        success: true,
        data: response.data,
        error: null,
        requestId: response.headers['x-request-id']
      };
    } catch (error) {
      const apiError = this.handleApiError(error as AxiosError);
      return {
        success: false,
        data: null as any,
        error: apiError,
        requestId: apiError.requestId
      };
    }
  }

  /**
   * Get status of a notification with enhanced error handling
   * @param notificationId - ID of notification to check
   * @returns Promise with notification status
   */
  public async getNotificationStatus(
    notificationId: string
  ): Promise<ApiResponse<NotificationResponse>> {
    try {
      const response = await this.axiosInstance.get<NotificationResponse>(
        `/notifications/${notificationId}`
      );

      return {
        success: true,
        data: response.data,
        error: null,
        requestId: response.headers['x-request-id']
      };
    } catch (error) {
      const apiError = this.handleApiError(error as AxiosError);
      return {
        success: false,
        data: null as any,
        error: apiError,
        requestId: apiError.requestId
      };
    }
  }

  /**
   * Get list of available templates with filtering
   * @param channel - Optional channel filter
   * @returns Promise with template list
   */
  public async getTemplates(
    channel?: NotificationChannel
  ): Promise<ApiResponse<Array<Record<string, any>>>> {
    try {
      const params = channel ? { channel } : undefined;
      const response = await this.axiosInstance.get('/templates', { params });

      return {
        success: true,
        data: response.data,
        error: null,
        requestId: response.headers['x-request-id']
      };
    } catch (error) {
      const apiError = this.handleApiError(error as AxiosError);
      return {
        success: false,
        data: [],
        error: apiError,
        requestId: apiError.requestId
      };
    }
  }

  /**
   * Process and standardize API errors
   * @param error - Axios error object
   * @returns Standardized error object
   */
  private handleApiError(error: AxiosError): ApiError {
    const response = error.response;
    const requestId = response?.headers?.['x-request-id'];

    return {
      code: response?.data?.code || 'UNKNOWN_ERROR',
      message: response?.data?.message || 'An unexpected error occurred',
      details: response?.data?.details || [],
      retryable: this.isRetryableError(error),
      timestamp: new Date().toISOString(),
      requestId
    };
  }

  /**
   * Determine if an error is retryable
   * @param error - Axios error object
   * @returns Whether the error is retryable
   */
  private isRetryableError(error: AxiosError): boolean {
    if (!error.response) return true; // Network errors are retryable
    const status = error.response.status;
    return status >= 500 || status === 429; // Server errors and rate limits are retryable
  }

  /**
   * Generate a unique request ID
   * @returns Unique request ID
   */
  private generateRequestId(): string {
    return `cli-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
  }
}

export default ApiService;