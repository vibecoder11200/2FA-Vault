########################################################################
#                                                                      #
#          2FA-Vault Development Environment Setup (Windows)           #
#                                                                      #
########################################################################

Write-Host "==================================" -ForegroundColor Cyan
Write-Host "  2FA-Vault Dev Environment Setup" -ForegroundColor Cyan
Write-Host "==================================" -ForegroundColor Cyan
Write-Host ""

function Print-Success {
    param([string]$Message)
    Write-Host "✓ $Message" -ForegroundColor Green
}

function Print-Error {
    param([string]$Message)
    Write-Host "✗ $Message" -ForegroundColor Red
}

function Print-Info {
    param([string]$Message)
    Write-Host "→ $Message" -ForegroundColor Yellow
}

# Check if Docker is installed
try {
    docker --version | Out-Null
    Print-Success "Docker is installed"
} catch {
    Print-Error "Docker is not installed. Please install Docker Desktop first."
    exit 1
}

# Check if Docker Compose is installed
try {
    docker-compose --version | Out-Null
    Print-Success "Docker Compose is installed"
} catch {
    Print-Error "Docker Compose is not installed. Please install Docker Compose first."
    exit 1
}

# Step 1: Copy environment file
Print-Info "Step 1: Setting up environment file..."
if (-not (Test-Path ".env")) {
    if (Test-Path ".env.dev") {
        Copy-Item ".env.dev" ".env"
        Print-Success "Copied .env.dev to .env"
    } elseif (Test-Path ".env.example") {
        Copy-Item ".env.example" ".env"
        Print-Success "Copied .env.example to .env"
    } else {
        Print-Error ".env.example not found!"
        exit 1
    }
} else {
    Print-Info ".env already exists, skipping"
}

# Step 2: Create required directories
Print-Info "Step 2: Creating required directories..."
$directories = @(
    "database",
    "storage\app",
    "storage\framework\cache",
    "storage\framework\sessions",
    "storage\framework\views",
    "storage\logs",
    "bootstrap\cache"
)

foreach ($dir in $directories) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
}
Print-Success "Directories created"

# Step 3: Create SQLite database
Print-Info "Step 3: Creating SQLite database..."
$dbPath = "database\database.sqlite"
if (-not (Test-Path $dbPath)) {
    New-Item -ItemType File -Path $dbPath -Force | Out-Null
}
Print-Success "SQLite database created"

# Step 4: Build Docker images
Print-Info "Step 4: Building Docker images (this may take a few minutes)..."
docker-compose -f docker-compose.dev.yml build --no-cache
if ($LASTEXITCODE -eq 0) {
    Print-Success "Docker images built"
} else {
    Print-Error "Failed to build Docker images"
    exit 1
}

# Step 5: Start containers
Print-Info "Step 5: Starting containers..."
docker-compose -f docker-compose.dev.yml up -d
if ($LASTEXITCODE -eq 0) {
    Print-Success "Containers started"
} else {
    Print-Error "Failed to start containers"
    exit 1
}

# Wait for containers to be ready
Print-Info "Waiting for containers to be ready..."
Start-Sleep -Seconds 15

# Step 6: Install Composer dependencies
Print-Info "Step 6: Installing Composer dependencies..."
docker-compose -f docker-compose.dev.yml exec -T app composer install
if ($LASTEXITCODE -eq 0) {
    Print-Success "Composer dependencies installed"
} else {
    Print-Error "Failed to install Composer dependencies"
}

# Step 7: Install NPM dependencies
Print-Info "Step 7: Installing NPM dependencies..."
docker-compose -f docker-compose.dev.yml exec -T vite npm install
if ($LASTEXITCODE -eq 0) {
    Print-Success "NPM dependencies installed"
} else {
    Print-Error "Failed to install NPM dependencies"
}

# Step 8: Generate application key
Print-Info "Step 8: Generating application key..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan key:generate --force
if ($LASTEXITCODE -eq 0) {
    Print-Success "Application key generated"
} else {
    Print-Error "Failed to generate application key"
}

# Step 9: Run migrations
Print-Info "Step 9: Running database migrations..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan migrate --force
if ($LASTEXITCODE -eq 0) {
    Print-Success "Database migrations completed"
} else {
    Print-Error "Failed to run migrations"
}

# Step 10: Install Laravel Passport
Print-Info "Step 10: Installing Laravel Passport..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan passport:install --force
if ($LASTEXITCODE -eq 0) {
    Print-Success "Laravel Passport installed"
} else {
    Print-Error "Failed to install Passport"
}

# Step 11: Clear caches
Print-Info "Step 11: Clearing caches..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan config:clear
docker-compose -f docker-compose.dev.yml exec -T app php artisan cache:clear
docker-compose -f docker-compose.dev.yml exec -T app php artisan view:clear
Print-Success "Caches cleared"

# Step 12: Create storage link
Print-Info "Step 12: Creating storage symlink..."
docker-compose -f docker-compose.dev.yml exec -T app php artisan storage:link
if ($LASTEXITCODE -eq 0) {
    Print-Success "Storage symlink created"
} else {
    Print-Info "Storage link may already exist"
}

Write-Host ""
Write-Host "==================================" -ForegroundColor Cyan
Write-Host "  ✅ Setup Complete!             " -ForegroundColor Cyan
Write-Host "==================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "🚀 Your development environment is ready!" -ForegroundColor Green
Write-Host ""
Write-Host "📍 Access points:" -ForegroundColor Yellow
Write-Host "   - Backend API: http://localhost:8000"
Write-Host "   - Vite Dev Server: http://localhost:5173"
Write-Host "   - phpMyAdmin: http://localhost:8080 (user: root, pass: root)"
Write-Host "   - MailHog: http://localhost:8025"
Write-Host ""
Write-Host "🔧 Useful commands:" -ForegroundColor Yellow
Write-Host "   - View logs: docker-compose -f docker-compose.dev.yml logs -f"
Write-Host "   - Run tests: docker-compose -f docker-compose.dev.yml exec app composer test"
Write-Host "   - Enter container: docker-compose -f docker-compose.dev.yml exec app sh"
Write-Host "   - Stop environment: docker-compose -f docker-compose.dev.yml down"
Write-Host ""
Write-Host "📚 Documentation: .\DEVELOPMENT.md" -ForegroundColor Cyan
Write-Host "🧪 Testing Guide: .\docs\TESTING-PLAN.md" -ForegroundColor Cyan
Write-Host ""
Write-Host "Happy coding! 🎉" -ForegroundColor Magenta
