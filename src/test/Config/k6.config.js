// k6 load testing configuration for notification service
// Version: 1.0.0

import http from 'k6/http';
import { check, sleep } from 'k6';
import { NotificationLoadTest } from '../Performance/LoadTest/NotificationLoadTest';
import { TemplateLoadTest } from '../Performance/LoadTest/TemplateLoadTest';
import { VendorLoadTest } from '../Performance/LoadTest/VendorLoadTest';

// Target performance metrics
const TARGET_THROUGHPUT = 100000; // Target messages per minute
const MAX_LATENCY_SECONDS = 30; // Maximum acceptable 95th percentile latency
const MIN_SUCCESS_RATE = 0.999; // Minimum required delivery success rate
const RAMP_UP_TIME = 30; // Seconds to ramp up load
const STEADY_STATE_TIME = 300; // Seconds to maintain peak load
const RAMP_DOWN_TIME = 30; // Seconds to ramp down load

// Test configuration
export const options = {
  // Three-stage load profile
  stages: [
    { duration: `${RAMP_UP_TIME}s`, target: 100 }, // Ramp up to 100 VUs
    { duration: `${STEADY_STATE_TIME}s`, target: 100 }, // Maintain 100 VUs
    { duration: `${RAMP_DOWN_TIME}s`, target: 0 } // Ramp down to 0 VUs
  ],

  // Performance thresholds
  thresholds: {
    // Throughput requirements
    'notification_rate': [{
      threshold: `rate>=${TARGET_THROUGHPUT/60}`, // Convert to per-second rate
      abortOnFail: true
    }],
    
    // Latency requirements
    'http_req_duration': [{
      threshold: `p(95)<${MAX_LATENCY_SECONDS * 1000}`, // Convert to milliseconds
      abortOnFail: true
    }],
    
    // Success rate requirements
    'checks': [{
      threshold: `rate>=${MIN_SUCCESS_RATE}`,
      abortOnFail: true
    }]
  },

  // Batch configuration
  batch: {
    batchSize: 1000, // Process messages in batches of 1000
    batchTimeout: '10s', // Maximum batch wait time
    maxBatchSize: 10000 // Maximum batch size under load
  },

  // Custom metrics
  metrics: {
    notification_rate: new Rate('notifications_sent'),
    vendor_failover_time: new Trend('vendor_failover_time'),
    template_render_time: new Trend('template_render_time')
  }
};

// Test setup
export function setup() {
  // Validate test environment
  const envCheck = http.get('http://notification-service/health');
  check(envCheck, {
    'test environment is ready': (r) => r.status === 200
  });

  // Initialize test templates
  const templates = {
    email: {
      id: 'test_email_template',
      content: {
        subject: 'Test Email {{subject}}',
        body: 'Hello {{name}}, this is a test email.'
      }
    },
    sms: {
      id: 'test_sms_template',
      content: 'Test SMS for {{name}}: {{message}}'
    },
    push: {
      id: 'test_push_template',
      content: {
        title: 'Test Push {{title}}',
        body: '{{message}}'
      }
    }
  };

  // Configure vendor simulators
  const vendorConfig = {
    email: ['iterable', 'sendgrid', 'ses'],
    sms: ['telnyx', 'twilio'],
    push: ['sns']
  };

  return {
    templates,
    vendorConfig,
    testData: generateTestData()
  };
}

// Main test scenario
export default function(data) {
  // High-throughput notification test
  group('Notification Throughput', function() {
    const payload = {
      recipient: 'test@example.com',
      template_id: data.templates.email.id,
      context: {
        subject: 'Load Test',
        name: 'Test User'
      }
    };

    const response = http.post('http://notification-service/v1/notifications', JSON.stringify(payload), {
      headers: { 'Content-Type': 'application/json' }
    });

    check(response, {
      'notification accepted': (r) => r.status === 202,
      'has tracking id': (r) => r.json('id') !== undefined
    });

    // Record notification rate
    options.metrics.notification_rate.add(1);
  });

  // Template rendering performance
  group('Template Performance', function() {
    const startTime = new Date();
    const response = http.post('http://notification-service/v1/templates/render', {
      template_id: data.templates.email.id,
      context: data.testData.templateContext
    });

    check(response, {
      'template rendered successfully': (r) => r.status === 200
    });

    options.metrics.template_render_time.add(new Date() - startTime);
  });

  // Vendor failover testing
  group('Vendor Failover', function() {
    const startTime = new Date();
    const response = http.post('http://notification-service/v1/notifications', {
      recipient: 'test@example.com',
      channel: 'email',
      force_failover: true // Trigger vendor failover
    });

    check(response, {
      'failover successful': (r) => r.status === 202
    });

    options.metrics.vendor_failover_time.add(new Date() - startTime);
  });

  // Rate limiting and throttling
  sleep(1); // Prevent overwhelming the service
}

// Test cleanup
export function teardown(data) {
  // Clean up test templates
  for (const channel in data.templates) {
    http.delete(`http://notification-service/v1/templates/${data.templates[channel].id}`);
  }

  // Reset vendor simulators
  http.post('http://notification-service/test/reset-vendors');
}

// Helper function to generate test data
function generateTestData() {
  return {
    templateContext: {
      subject: 'Performance Test',
      name: 'Load Test User',
      message: 'This is a test notification',
      title: 'Test Notification'
    }
  };
}