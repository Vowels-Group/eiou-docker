#!/bin/bash
# Deployment script for eIOU application
# Usage: ./deploy.sh [environment] [version]
# Example: ./deploy.sh production v1.0.0

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
ENVIRONMENT=${1:-production}
VERSION=${2:-latest}
DEPLOY_DIR="/var/www/eiou"
BACKUP_DIR="/var/backups/eiou"
ROLLBACK_LIMIT=5

# Functions
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Pre-deployment checks
pre_deploy_checks() {
    log_info "Running pre-deployment checks..."

    # Check if Docker is installed
    if ! command -v docker &> /dev/null; then
        log_error "Docker is not installed"
        exit 1
    fi

    # Check if docker-compose is installed
    if ! command -v docker-compose &> /dev/null; then
        log_error "Docker Compose is not installed"
        exit 1
    fi

    # Check disk space
    available_space=$(df -h / | awk 'NR==2 {print $4}' | sed 's/G//')
    if (( $(echo "$available_space < 2" | bc -l) )); then
        log_warning "Low disk space: ${available_space}GB available"
    fi

    # Check if deployment directory exists
    if [ ! -d "$DEPLOY_DIR" ]; then
        log_info "Creating deployment directory: $DEPLOY_DIR"
        sudo mkdir -p "$DEPLOY_DIR"
    fi

    # Check if backup directory exists
    if [ ! -d "$BACKUP_DIR" ]; then
        log_info "Creating backup directory: $BACKUP_DIR"
        sudo mkdir -p "$BACKUP_DIR"
    fi
}

# Backup current deployment
backup_current() {
    log_info "Backing up current deployment..."

    if [ -d "$DEPLOY_DIR/data" ]; then
        backup_name="backup-$(date +%Y%m%d-%H%M%S).tar.gz"
        sudo tar -czf "$BACKUP_DIR/$backup_name" -C "$DEPLOY_DIR" data/
        log_info "Backup created: $backup_name"

        # Clean old backups
        backup_count=$(ls -1 "$BACKUP_DIR" | wc -l)
        if [ "$backup_count" -gt "$ROLLBACK_LIMIT" ]; then
            oldest_backup=$(ls -1t "$BACKUP_DIR" | tail -1)
            sudo rm "$BACKUP_DIR/$oldest_backup"
            log_info "Removed old backup: $oldest_backup"
        fi
    fi
}

# Deploy application
deploy() {
    log_info "Deploying version: $VERSION to $ENVIRONMENT"

    cd "$DEPLOY_DIR"

    # Pull latest code
    if [ -d .git ]; then
        log_info "Pulling latest changes..."
        git fetch --all
        git checkout "$VERSION"
    else
        log_info "Cloning repository..."
        git clone https://github.com/eiou-org/eiou.git .
        git checkout "$VERSION"
    fi

    # Copy environment file
    if [ -f ".env.$ENVIRONMENT" ]; then
        cp ".env.$ENVIRONMENT" .env
        log_info "Environment file configured for: $ENVIRONMENT"
    else
        log_warning "No environment file found for: $ENVIRONMENT"
    fi

    # Build and start containers
    log_info "Building Docker containers..."
    docker-compose -f docker-compose.yml build --no-cache

    log_info "Starting containers..."
    docker-compose -f docker-compose.yml up -d

    # Wait for application to be ready
    log_info "Waiting for application to be ready..."
    max_attempts=30
    attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if curl -f http://localhost:8080/health &> /dev/null; then
            log_info "Application is ready!"
            break
        fi

        attempt=$((attempt + 1))
        if [ $attempt -eq $max_attempts ]; then
            log_error "Application failed to start"
            rollback
            exit 1
        fi

        sleep 2
    done
}

# Run migrations
run_migrations() {
    log_info "Running database migrations..."

    # Execute migrations inside container
    docker-compose exec -T app php artisan migrate --force 2>/dev/null || true

    # Seed database if needed
    if [ "$ENVIRONMENT" = "development" ]; then
        docker-compose exec -T app php artisan db:seed 2>/dev/null || true
    fi
}

# Health checks
health_check() {
    log_info "Running health checks..."

    # Check HTTP response
    http_status=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/health)
    if [ "$http_status" != "200" ]; then
        log_error "Health check failed: HTTP $http_status"
        return 1
    fi

    # Check container status
    unhealthy=$(docker-compose ps | grep -c "unhealthy" || true)
    if [ "$unhealthy" -gt 0 ]; then
        log_error "Unhealthy containers detected"
        return 1
    fi

    log_info "All health checks passed!"
    return 0
}

# Rollback deployment
rollback() {
    log_warning "Rolling back deployment..."

    # Stop current containers
    docker-compose down

    # Find latest backup
    latest_backup=$(ls -1t "$BACKUP_DIR" | head -1)

    if [ -n "$latest_backup" ]; then
        log_info "Restoring from backup: $latest_backup"
        sudo tar -xzf "$BACKUP_DIR/$latest_backup" -C "$DEPLOY_DIR"

        # Restart with previous version
        docker-compose up -d

        log_info "Rollback completed"
    else
        log_error "No backup found for rollback"
        exit 1
    fi
}

# Post-deployment tasks
post_deploy() {
    log_info "Running post-deployment tasks..."

    # Clear caches
    docker-compose exec -T app php artisan cache:clear 2>/dev/null || true

    # Optimize autoloader
    docker-compose exec -T app composer dump-autoload --optimize 2>/dev/null || true

    # Set permissions
    sudo chown -R www-data:www-data "$DEPLOY_DIR/data" 2>/dev/null || true
    sudo chmod -R 775 "$DEPLOY_DIR/data" 2>/dev/null || true

    # Update monitoring
    if [ "$ENVIRONMENT" = "production" ]; then
        curl -X POST http://monitoring.example.com/deploy \
            -H "Content-Type: application/json" \
            -d "{\"version\":\"$VERSION\",\"status\":\"success\"}" \
            2>/dev/null || true
    fi
}

# Notification
notify() {
    status=$1
    message=$2

    log_info "Sending notification: $message"

    # Send to Slack/Discord/Email
    if [ "$ENVIRONMENT" = "production" ]; then
        # Example Slack notification
        curl -X POST https://hooks.slack.com/services/xxx/yyy/zzz \
            -H "Content-Type: application/json" \
            -d "{\"text\":\"Deployment $status: $message\"}" \
            2>/dev/null || true
    fi
}

# Main deployment flow
main() {
    log_info "Starting deployment process..."

    # Pre-deployment
    pre_deploy_checks
    backup_current

    # Deploy
    if deploy; then
        run_migrations

        if health_check; then
            post_deploy
            notify "SUCCESS" "Version $VERSION deployed to $ENVIRONMENT"
            log_info "Deployment completed successfully!"
            exit 0
        else
            rollback
            notify "FAILED" "Health checks failed for $VERSION"
            log_error "Deployment failed - rolled back"
            exit 1
        fi
    else
        notify "FAILED" "Deployment of $VERSION failed"
        log_error "Deployment failed"
        exit 1
    fi
}

# Run main function
main