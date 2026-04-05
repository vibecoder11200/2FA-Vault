#!/bin/bash

########################################################################
#                                                                      #
#               2FA-Vault Development Environment Setup                #
#                                                                      #
########################################################################

set -e  # Exit on error

echo "=================================="
echo "  2FA-Vault Dev Environment Setup"
echo "=================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_info() {
    echo -e "${YELLOW}→${NC} $1"
}

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    print_error "Docker is not installed. Please install Docker first."
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null; then
    print_error "Docker Compose is not installed. Please install Docker Compose first."
    exit 1
fi

print_success "Docker and Docker Compose are installed"

# Step 1: Copy environment file
print_info "Step 1: Setting up environment file..."
if [ ! -f ".env" ]; then
    if [ -f ".env.dev" ]; then
        cp .env.dev .env
        print_success "Copied .env.dev to .env"
    elif [ -f ".env.example" ]; then
        cp .env.example .env
        print_success "Copied .env.example to .env"
    else
        print_error ".env.example not found!"
        exit 1
    fi
else
    print_info ".env already exists, skipping"
fi

# Step 2: Create required directories
print_info "Step 2: Creating required directories..."
mkdir -p database
mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
mkdir -p bootstrap/cache
print_success "Directories created"

# Step 3: Create SQLite database
print_info "Step 3: Creating SQLite database..."
touch database/database.sqlite
print_success "SQLite database created"

# Step 4: Set permissions (Unix-like systems)
if [[ "$OSTYPE" != "msys" && "$OSTYPE" != "win32" ]]; then
    print_info "Step 4: Setting permissions..."
    chmod -R 775 storage bootstrap/cache database
    print_success "Permissions set"
else
    print_info "Step 4: Skipping permissions (Windows detected)"
fi

# Step 5: Build Docker images
print_info "Step 5: Building Docker images..."
docker-compose -f docker-compose.dev.yml build --no-cache
print_success "Docker images built"

# Step 6: Start containers
print_info "Step 6: Starting containers..."
docker-compose -f docker-compose.dev.yml up -d
print_success "Containers started"

# Wait for containers to be ready
print_info "Waiting for containers to be ready..."
sleep 10

# Step 7: Install Composer dependencies
print_info "Step 7: Installing Composer dependencies..."
docker-compose -f docker-compose.dev.yml exec -T app composer install
print_success "Composer dependencies installed"

# Step 8: Install NPM dependencies
print_info "Step 8: Installing NPM dependencies..."
docker-compose -f docker-compose.dev.yml exec -T vite npm install
print_success "NPM dependencies installed"

# Step 9: Generate application key
print_info "Step 9: Generating application key..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan key:generate --force
print_success "Application key generated"

# Step 10: Run migrations
print_info "Step 10: Running database migrations..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan migrate --force
print_success "Database migrations completed"

# Step 11: Install Laravel Passport
print_info "Step 11: Installing Laravel Passport..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan passport:install --force
print_success "Laravel Passport installed"

# Step 12: Clear caches
print_info "Step 12: Clearing caches..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan config:clear
docker-compose -f docker-compose.dev.yml exec -T app php artisan cache:clear
docker-compose -f docker-compose.dev.yml exec -T app php artisan view:clear
print_success "Caches cleared"

# Step 13: Create storage link
print_info "Step 13: Creating storage symlink..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan storage:link
print_success "Storage symlink created"

echo ""
echo "=================================="
echo "  ✅ Setup Complete!             "
echo "=================================="
echo ""
echo "🚀 Your development environment is ready!"
echo ""
echo "📍 Access points:"
echo "   - Backend API: http://localhost:8000"
echo "   - Vite Dev Server: http://localhost:5173"
echo "   - phpMyAdmin: http://localhost:8080 (user: root, pass: root)"
echo "   - MailHog: http://localhost:8025"
echo ""
echo "🔧 Useful commands:"
echo "   - View logs: docker-compose -f docker-compose.dev.yml logs -f"
echo "   - Run tests: docker-compose -f docker-compose.dev.yml exec app composer test"
echo "   - Enter container: docker-compose -f docker-compose.dev.yml exec app sh"
echo "   - Stop environment: docker-compose -f docker-compose.dev.yml down"
echo ""
echo "📚 Documentation: ./DEVELOPMENT.md"
echo "🧪 Testing Guide: ./docs/TESTING-PLAN.md"
echo ""
echo "Happy coding! 🎉"
