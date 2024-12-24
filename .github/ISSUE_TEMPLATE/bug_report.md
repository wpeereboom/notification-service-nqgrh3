---
name: Bug Report
about: Create a detailed bug report to help us improve the notification service
title: '[BUG] '
labels: bug
assignees: ''
---

## Bug Description
### Summary
<!-- Provide a clear and concise description of the bug -->

### Severity
<!-- Select the severity of this bug -->
- [ ] Critical - Service Down
- [ ] High - Major Feature Broken
- [ ] Medium - Feature Partially Working
- [ ] Low - Minor Issue

## System Context
### Component
<!-- Select the affected component -->
- [ ] API Gateway
- [ ] Lambda Functions
- [ ] Database
- [ ] Queue System
- [ ] Cache Layer
- [ ] Email Service
- [ ] SMS Service
- [ ] Push Notification
- [ ] Template Engine
- [ ] CLI Tool

### Environment
<!-- Select the environment where the bug was found -->
- [ ] Production
- [ ] Staging
- [ ] Development

### Version
<!-- Specify the version or commit hash where the bug was found -->
Version/Commit: 

## Reproduction Steps
### Prerequisites
<!-- List any required setup or conditions needed to reproduce the bug -->

### Steps to Reproduce
<!-- Provide detailed step-by-step instructions to reproduce the bug -->
1. 
2. 
3. 

### Expected Behavior
<!-- Describe what should happen -->

### Actual Behavior
<!-- Describe what actually happens -->

## Technical Details
### Logs
<!-- Include relevant CloudWatch logs or error messages -->
```
[Insert logs here]
```

### Request ID
<!-- If applicable, provide the API request ID -->
Request ID: 

### Notification ID
<!-- If applicable, provide the notification ID -->
Notification ID: 

## Impact
### Affected Users
<!-- Describe the number or type of users affected -->

### Affected Channels
<!-- Check all channels affected by this bug -->
- [ ] Email
- [ ] SMS
- [ ] Push Notifications

### Affected Vendors
<!-- Check all vendors affected by this bug -->
- [ ] Iterable
- [ ] SendGrid
- [ ] Amazon SES
- [ ] Telnyx
- [ ] Twilio
- [ ] AWS SNS

## Initial Verification Checklist
<!-- Please complete the following checks before submitting -->
- [ ] I have checked if this bug is already reported
- [ ] I have verified this bug exists in the latest version
- [ ] I have checked the logs for relevant errors
- [ ] I have tested in a clean environment

## Information Completeness Checklist
<!-- Ensure all necessary information is provided -->
- [ ] Clear reproduction steps provided
- [ ] System context details included
- [ ] Error messages/logs attached
- [ ] Impact assessment completed

## Performance Impact Assessment
<!-- Check all performance metrics affected -->
- [ ] Message throughput affected
- [ ] Delivery success rate impact
- [ ] System availability impact
- [ ] Vendor failover affected

<!-- 
Note: This bug report will be automatically validated by the CI pipeline.
Please ensure all required fields are completed to expedite the review process.
-->