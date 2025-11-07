# CI/CD Integration Guide for GUI Modernization Tests

This guide explains how to integrate the GUI modernization test suite into your CI/CD pipeline.

## Table of Contents

1. [GitHub Actions](#github-actions)
2. [GitLab CI](#gitlab-ci)
3. [Jenkins](#jenkins)
4. [Pre-commit Hooks](#pre-commit-hooks)
5. [Docker Integration](#docker-integration)
6. [Troubleshooting](#troubleshooting)

## GitHub Actions

### Complete Workflow

Create `.github/workflows/gui-tests.yml`:

```yaml
name: GUI Modernization Tests

on:
  pull_request:
    paths:
      - 'src/gui/**'
      - 'tests/**'
  push:
    branches:
      - main
      - 'claudeflow-*'

jobs:
  test-suite:
    name: Run Test Suite
    runs-on: ubuntu-latest

    strategy:
      matrix:
        php-version: ['7.4', '8.0', '8.1']

    steps:
      - name: Checkout Code
        uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: pdo, pdo_mysql, curl, json
          coverage: none

      - name: Start Docker Container
        run: |
          docker compose -f docker-compose-single.yml up -d
          sleep 15  # Wait for container initialization

      - name: Verify Docker Container
        run: |
          docker ps
          docker compose -f docker-compose-single.yml logs

      - name: Run API Caching Tests
        id: cache-tests
        run: php tests/api/test-caching.php
        continue-on-error: false

      - name: Run Performance Benchmarks
        id: perf-tests
        run: php tests/performance/benchmark.php --docker
        continue-on-error: false

      - name: Run Integration Tests
        id: integration-tests
        run: ./tests/integration/test-gui-flow.sh
        env:
          SKIP_RESTART_TEST: 1  # Don't restart in CI

      - name: Upload Test Results
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: test-results-php-${{ matrix.php-version }}
          path: |
            tests/test-results.json
            tests/performance/performance-metrics.json
            tests/integration/integration-test-results.json
            tests/test-report.html

      - name: Comment PR with Results
        if: github.event_name == 'pull_request'
        uses: actions/github-script@v6
        with:
          script: |
            const fs = require('fs');
            const results = JSON.parse(fs.readFileSync('tests/test-results.json', 'utf8'));

            const body = `## Test Results (PHP ${{ matrix.php-version }})

            ✅ Passed: ${results.summary.passed}
            ❌ Failed: ${results.summary.failed}
            ⏭️ Skipped: ${results.summary.skipped}

            Duration: ${results.summary.duration.toFixed(2)}s

            <details>
            <summary>View Details</summary>

            ${results.tests.map(t => `- ${t.status}: ${t.name}`).join('\n')}

            </details>`;

            github.rest.issues.createComment({
              issue_number: context.issue.number,
              owner: context.repo.owner,
              repo: context.repo.repo,
              body: body
            });

      - name: Stop Docker Container
        if: always()
        run: docker compose -f docker-compose-single.yml down

  browser-tests:
    name: Browser Compatibility Check
    runs-on: ubuntu-latest

    steps:
      - name: Checkout Code
        uses: actions/checkout@v3

      - name: Setup Node.js
        uses: actions/setup-node@v3
        with:
          node-version: '18'

      - name: Install Playwright
        run: |
          npm install -D @playwright/test
          npx playwright install --with-deps

      - name: Run Browser Tests
        run: |
          # Start a simple HTTP server
          php -S localhost:8000 -t tests &
          sleep 2

          # Run headless browser tests
          npx playwright test tests/browser/compatibility-test.html

      - name: Upload Browser Test Results
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: browser-test-results
          path: playwright-report/

  performance-check:
    name: Performance Regression Check
    runs-on: ubuntu-latest

    steps:
      - name: Checkout Code
        uses: actions/checkout@v3

      - name: Checkout Base Branch
        run: git fetch origin ${{ github.base_ref }}

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.0'

      - name: Start Docker Container
        run: |
          docker compose -f docker-compose-single.yml up -d
          sleep 15

      - name: Run Current Performance Test
        run: php tests/performance/benchmark.php --docker > current-perf.txt

      - name: Checkout Base Branch
        run: |
          git checkout origin/${{ github.base_ref }}
          docker compose -f docker-compose-single.yml restart
          sleep 15

      - name: Run Base Performance Test
        run: php tests/performance/benchmark.php --docker > base-perf.txt

      - name: Compare Performance
        run: |
          echo "## Performance Comparison" >> $GITHUB_STEP_SUMMARY
          echo "" >> $GITHUB_STEP_SUMMARY
          echo "### Current Branch" >> $GITHUB_STEP_SUMMARY
          cat current-perf.txt >> $GITHUB_STEP_SUMMARY
          echo "" >> $GITHUB_STEP_SUMMARY
          echo "### Base Branch" >> $GITHUB_STEP_SUMMARY
          cat base-perf.txt >> $GITHUB_STEP_SUMMARY

      - name: Stop Docker Container
        if: always()
        run: docker compose -f docker-compose-single.yml down
```

### Fast Workflow (Quick Checks)

Create `.github/workflows/gui-quick.yml` for fast PR checks:

```yaml
name: Quick GUI Tests

on:
  pull_request:
    paths:
      - 'src/gui/**'

jobs:
  quick-test:
    name: Quick Test Suite
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.0'

      - name: Run API Caching Tests (Fast)
        run: php tests/api/test-caching.php

      - name: Check JavaScript Syntax
        run: |
          for file in src/gui/assets/js/*.js; do
            node --check "$file"
          done

      - name: Check PHP Syntax
        run: |
          find src/gui -name "*.php" -exec php -l {} \;
```

## GitLab CI

Create `.gitlab-ci.yml`:

```yaml
stages:
  - test
  - report

variables:
  DOCKER_DRIVER: overlay2
  DOCKER_TLS_CERTDIR: ""

before_script:
  - docker info

test:api-caching:
  stage: test
  image: php:8.0-cli
  script:
    - php tests/api/test-caching.php
  artifacts:
    reports:
      junit: tests/test-results.json
    paths:
      - tests/test-results.json
    expire_in: 1 week

test:performance:
  stage: test
  image: php:8.0-cli
  services:
    - docker:dind
  script:
    - docker compose -f docker-compose-single.yml up -d
    - sleep 15
    - php tests/performance/benchmark.php --docker
  artifacts:
    paths:
      - tests/performance/performance-metrics.json
    expire_in: 1 week
  after_script:
    - docker compose -f docker-compose-single.yml down

test:integration:
  stage: test
  image: ubuntu:latest
  services:
    - docker:dind
  before_script:
    - apt-get update && apt-get install -y docker-compose curl jq bc
  script:
    - docker compose -f docker-compose-single.yml up -d
    - sleep 15
    - ./tests/integration/test-gui-flow.sh
  artifacts:
    paths:
      - tests/integration/integration-test-results.json
    expire_in: 1 week

report:generate:
  stage: report
  image: ubuntu:latest
  dependencies:
    - test:api-caching
    - test:performance
    - test:integration
  script:
    - apt-get update && apt-get install -y jq
    - ./tests/run-all-tests.sh --skip-docker
  artifacts:
    paths:
      - tests/test-report.html
    expire_in: 1 month
  only:
    - merge_requests
    - main
```

## Jenkins

Create `Jenkinsfile`:

```groovy
pipeline {
    agent any

    environment {
        COMPOSE_FILE = 'docker-compose-single.yml'
        SKIP_RESTART_TEST = '1'
    }

    stages {
        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Start Docker') {
            steps {
                sh '''
                    docker compose -f ${COMPOSE_FILE} up -d
                    sleep 15
                    docker ps
                '''
            }
        }

        stage('API Caching Tests') {
            steps {
                sh 'php tests/api/test-caching.php'
            }
        }

        stage('Performance Benchmarks') {
            steps {
                sh 'php tests/performance/benchmark.php --docker'
            }
        }

        stage('Integration Tests') {
            steps {
                sh './tests/integration/test-gui-flow.sh'
            }
        }

        stage('Generate Report') {
            steps {
                sh './tests/run-all-tests.sh'
                publishHTML([
                    reportDir: 'tests',
                    reportFiles: 'test-report.html',
                    reportName: 'Test Report'
                ])
            }
        }
    }

    post {
        always {
            sh 'docker compose -f ${COMPOSE_FILE} down || true'

            archiveArtifacts artifacts: 'tests/**/*.json', fingerprint: true
            archiveArtifacts artifacts: 'tests/test-report.html', fingerprint: true

            // Parse and publish test results
            junit 'tests/test-results.json'
        }

        success {
            echo 'All tests passed!'
        }

        failure {
            echo 'Tests failed!'
            emailext (
                subject: "Test Failure: ${env.JOB_NAME} - Build #${env.BUILD_NUMBER}",
                body: "Check console output at ${env.BUILD_URL}",
                to: "dev-team@example.com"
            )
        }
    }
}
```

## Pre-commit Hooks

### Local Git Hook

Create `.git/hooks/pre-commit`:

```bash
#!/bin/bash
#
# Pre-commit hook for GUI changes
#

# Check if GUI files are being committed
GUI_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep "^src/gui/")

if [ -z "$GUI_FILES" ]; then
    echo "No GUI files changed, skipping tests"
    exit 0
fi

echo "GUI files changed, running quick tests..."

# Run API caching tests (fast)
echo "Running API caching tests..."
if ! php tests/api/test-caching.php; then
    echo "❌ API caching tests failed"
    exit 1
fi

# Check JavaScript syntax
echo "Checking JavaScript syntax..."
for file in $GUI_FILES; do
    if [[ $file == *.js ]]; then
        if ! node --check "$file" 2>/dev/null; then
            echo "❌ JavaScript syntax error in $file"
            exit 1
        fi
    fi
done

# Check PHP syntax
echo "Checking PHP syntax..."
for file in $GUI_FILES; do
    if [[ $file == *.php ]]; then
        if ! php -l "$file" >/dev/null 2>&1; then
            echo "❌ PHP syntax error in $file"
            exit 1
        fi
    fi
done

echo "✅ Pre-commit checks passed"
exit 0
```

### Husky Integration (for Node.js projects)

```bash
npm install --save-dev husky

# Initialize husky
npx husky install

# Add pre-commit hook
npx husky add .husky/pre-commit "bash tests/run-all-tests.sh --quick"
```

## Docker Integration

### Docker Compose for Testing

Create `docker-compose.test.yml`:

```yaml
version: '3.8'

services:
  test-runner:
    build:
      context: .
      dockerfile: Dockerfile.test
    volumes:
      - .:/app
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      - DOCKER_COMPOSE_FILE=docker-compose-single.yml
      - DOCKER_SERVICE_NAME=alice
    command: /app/tests/run-all-tests.sh
    depends_on:
      - alice

  alice:
    extends:
      file: docker-compose-single.yml
      service: alice
```

Create `Dockerfile.test`:

```dockerfile
FROM php:8.0-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    docker.io \
    docker-compose \
    curl \
    jq \
    bc \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app

CMD ["bash", "tests/run-all-tests.sh"]
```

### Run Tests in Docker

```bash
# Build test image
docker compose -f docker-compose.test.yml build

# Run all tests
docker compose -f docker-compose.test.yml up --abort-on-container-exit

# View results
docker compose -f docker-compose.test.yml logs test-runner
```

## Troubleshooting

### Common Issues

#### 1. Docker Container Not Starting

```bash
# Check logs
docker compose -f docker-compose-single.yml logs

# Verify ports available
lsof -i :8080

# Increase wait time
sleep 30  # Instead of sleep 15
```

#### 2. Tests Failing in CI but Passing Locally

```bash
# Check PHP version
php --version

# Check available memory
free -h

# Check Docker resources
docker stats --no-stream
```

#### 3. Permission Errors

```bash
# Fix test script permissions
chmod +x tests/**/*.sh
chmod +x tests/**/*.php

# Fix Docker socket permissions (CI)
sudo chmod 666 /var/run/docker.sock
```

#### 4. Timeout Issues

```yaml
# Increase timeouts in GitHub Actions
- name: Run Tests
  run: ./tests/run-all-tests.sh
  timeout-minutes: 30  # Default is 360 (6 hours)
```

### Debug Mode

Enable verbose output:

```bash
# In GitHub Actions
- name: Run Tests
  run: bash -x ./tests/run-all-tests.sh

# In GitLab CI
script:
  - set -x
  - ./tests/run-all-tests.sh
```

### Performance Optimization for CI

```bash
# Use Docker layer caching
- name: Set up Docker Buildx
  uses: docker/setup-buildx-action@v2

- name: Cache Docker layers
  uses: actions/cache@v3
  with:
    path: /tmp/.buildx-cache
    key: ${{ runner.os }}-buildx-${{ github.sha }}
    restore-keys: |
      ${{ runner.os }}-buildx-
```

## Best Practices

1. **Run Quick Tests First**: API caching tests are fast, run them before slow integration tests
2. **Parallel Execution**: Run independent test suites in parallel (GitHub Actions matrix)
3. **Cache Dependencies**: Cache Docker layers and PHP dependencies
4. **Fail Fast**: Use `continue-on-error: false` to stop on first failure
5. **Artifact Retention**: Keep test results for 1 week minimum
6. **PR Comments**: Auto-comment test results on PRs
7. **Performance Tracking**: Compare performance against base branch
8. **Manual Tests**: Document browser tests that can't be automated

## Metrics to Track

Monitor these metrics in your CI/CD pipeline:

- **Test Success Rate**: Target 95%+
- **Test Duration**: Keep under 5 minutes for quick feedback
- **Code Coverage**: Track over time (optional for GUI)
- **Performance Benchmarks**: Alert on regressions >10%
- **Flaky Tests**: Identify and fix tests that fail intermittently

## Next Steps

1. Add code coverage reporting (optional)
2. Set up performance regression alerts
3. Integrate with Slack/Discord for notifications
4. Create custom badges for README
5. Add visual regression testing (Percy, Chromatic)

## Resources

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [GitLab CI Documentation](https://docs.gitlab.com/ee/ci/)
- [Jenkins Pipeline Documentation](https://www.jenkins.io/doc/book/pipeline/)
- [Issue #137](https://github.com/eiou-org/eiou/issues/137)
