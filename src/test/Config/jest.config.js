/** 
 * Jest configuration for Notification Service CLI Testing
 * @requires ts-jest ^29.0.5 - TypeScript preprocessor for Jest
 * @requires @types/jest ^29.5.0 - TypeScript definitions for Jest
 */

/** @type {import('@jest/types').Config.InitialOptions} */
const config = {
  // Use ts-jest preset for TypeScript support
  preset: 'ts-jest',

  // Set Node.js as the test environment
  testEnvironment: 'node',

  // Define root directories for test discovery
  roots: [
    '<rootDir>/../../backend/src/Cli'
  ],

  // Supported file extensions
  moduleFileExtensions: [
    'ts',
    'js',
    'json'
  ],

  // TypeScript file transformation configuration
  transform: {
    '^.+\\.tsx?$': 'ts-jest'
  },

  // Test file pattern matching
  testRegex: '(/__tests__/.*|(\\.|/)(test|spec))\\.(jsx?|tsx?)$',

  // Module path aliases for clean imports
  moduleNameMapper: {
    '@cli/(.*)': '<rootDir>/../../backend/src/Cli/$1',
    '@types/(.*)': '<rootDir>/../../backend/src/Cli/Types/$1',
    '@services/(.*)': '<rootDir>/../../backend/src/Cli/Services/$1',
    '@commands/(.*)': '<rootDir>/../../backend/src/Cli/Commands/$1',
    '@utils/(.*)': '<rootDir>/../../backend/src/Cli/Utils/$1'
  },

  // Strict coverage thresholds (100% coverage requirement)
  coverageThreshold: {
    global: {
      branches: 100,
      functions: 100,
      lines: 100,
      statements: 100
    }
  },

  // Environment setup files
  setupFiles: [
    '<rootDir>/test.env'
  ],

  // Test timeout configuration (30 seconds)
  testTimeout: 30000,

  // Additional Jest configuration
  verbose: true,
  collectCoverage: true,
  coverageDirectory: '<rootDir>/coverage',
  coverageReporters: ['text', 'lcov'],
  
  // Clear mocks between tests
  clearMocks: true,
  
  // Fail tests on any error or warning
  errorOnDeprecated: true,
  
  // Maximum number of concurrent workers
  maxWorkers: '50%',
  
  // Detect open handles (async operations) that keep the process running
  detectOpenHandles: true,
  
  // Force exit after test completion
  forceExit: true
};

module.exports = config;