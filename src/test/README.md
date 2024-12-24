# Notification Service Test Suite Documentation

## Overview

This document provides comprehensive documentation for the Notification Service test suite, covering unit tests, integration tests, performance tests, and end-to-end (E2E) tests. The test suite ensures the service meets its critical requirements:

- 100% test coverage (lines, methods, and classes)
- 100,000+ messages per minute throughput
- 99.9% delivery success rate
- 99.95% system availability
- < 2 seconds vendor failover time
- < 30 seconds processing latency (95th percentile)

## Test Environment Setup

### Prerequisites

- Docker 20.x+
- PHP 8.2+
- Composer 2.x+
- k6 (latest)
- LocalStack (latest)

Required configuration files:
- `src/test/Config/phpunit.xml` - PHPUnit configuration
- `src/test/Config/test.env` - Test environment variables
- `src/test/docker/docker-compose.test.yml` - Docker test environment

### Environment Configuration

1. Clone the repository and navigate to the test directory:
```bash
cd src/test
```

2. Create test environment file:
```bash
cp Config/test.env.example Config/test.env
```

3. Start the test environment:
```bash
./scripts/setup_test_env.sh
```

This will initialize:
- LocalStack (AWS service emulation)
- Redis cache
- PostgreSQL database
- Test message queues

### Security Configuration

1. Configure test credentials in `test.env`:
```env
AWS_ACCESS_KEY_ID=test
AWS_SECRET_ACCESS_KEY=test
AWS_DEFAULT_REGION=us-east-1
AWS_ENDPOINT_URL=http://localhost:4566
```

2. Set up test vendor credentials:
```env
TEST_ITERABLE_API_KEY=test_key
TEST_SENDGRID_API_KEY=test_key
TEST_TELNYX_API_KEY=test_key
TEST_TWILIO_API_KEY=test_key
```

## Test Categories

### Unit Tests

Unit tests ensure individual components function correctly with 100% coverage requirement.

Key test suites:
- `NotificationTest.php` - Core notification processing
- `TemplateTest.php` - Template rendering
- `VendorTest.php` - Vendor integration
- `QueueTest.php` - Message queue handling

Run unit tests:
```bash
./scripts/run_unit_tests.sh
```

### Integration Tests

Integration tests verify component interactions using LocalStack services.

Test scenarios:
- Database operations
- Cache interactions
- Queue processing
- Vendor API communication

Run integration tests:
```bash
./scripts/run_integration_tests.sh
```

### Performance Tests

Performance tests validate system throughput and latency requirements using k6.

Test scenarios:
- High-volume message processing
- Concurrent request handling
- Vendor failover timing
- End-to-end latency

Run performance tests:
```bash
./scripts/run_performance_tests.sh
```

### E2E Tests

End-to-end tests verify complete notification workflows across all channels.

Test scenarios:
- Email notification delivery
- SMS message processing
- Push notification handling
- Multi-channel delivery

Run E2E tests:
```bash
./scripts/run_e2e_tests.sh
```

## Running Tests

### Test Scripts

The following scripts automate test execution:

| Script | Purpose |
|--------|---------|
| `setup_test_env.sh` | Initialize test environment |
| `run_unit_tests.sh` | Execute unit tests |
| `run_integration_tests.sh` | Execute integration tests |
| `run_performance_tests.sh` | Execute performance tests |
| `run_e2e_tests.sh` | Execute E2E tests |
| `cleanup_test_env.sh` | Clean up test environment |

### Coverage Reports

Generate coverage reports:
```bash
./scripts/run_unit_tests.sh --coverage-html coverage/
```

Coverage requirements:
- Lines: 100%
- Methods: 100%
- Classes: 100%

Reports are generated in HTML and Clover XML formats.

### Performance Monitoring

Monitor performance metrics:
```bash
k6 run --out influxdb=http://localhost:8086/k6 performance/scenarios/
```

Key metrics:
- Request throughput
- Response latency
- Error rates
- Resource utilization

## CI/CD Integration

### Workflow Configuration

GitHub Actions workflow:
```yaml
name: Test Suite
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup test environment
        run: ./scripts/setup_test_env.sh
      - name: Run tests
        run: |
          ./scripts/run_unit_tests.sh
          ./scripts/run_integration_tests.sh
          ./scripts/run_performance_tests.sh
          ./scripts/run_e2e_tests.sh
```

### Pipeline Integration

Tests are integrated into the deployment pipeline:
1. Unit tests must pass with 100% coverage
2. Integration tests must pass
3. Performance tests must meet throughput requirements
4. E2E tests must verify all channels

## Maintenance

### Test Data Management

- Test data is stored in `src/test/fixtures/`
- Update test data using `update_test_data.sh`
- Validate test data using `validate_test_data.sh`

### Troubleshooting

Common issues:
1. LocalStack connection errors
   - Verify LocalStack is running
   - Check endpoint configuration

2. Performance test failures
   - Review system resources
   - Check for network bottlenecks

3. Coverage gaps
   - Run coverage report
   - Review uncovered code paths

### Contributing

1. Create feature branch
2. Add or update tests
3. Verify coverage requirements
4. Submit pull request

Test contribution guidelines:
- Follow PSR-12 coding standards
- Include detailed test descriptions
- Maintain existing coverage levels
- Add performance test scenarios when relevant

## Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [k6 Documentation](https://k6.io/docs/)
- [LocalStack Documentation](https://docs.localstack.cloud/overview/)
- [Docker Documentation](https://docs.docker.com/)