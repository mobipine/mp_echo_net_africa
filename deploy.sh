#!/bin/bash

# TrustFund Laravel Application Deployment Script
# This script helps deploy the Laravel application with Docker

set -e

echo "🚀 Starting TrustFund Application Deployment..."

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Please install Docker first."
    exit 1
fi

# Generate application key if not exists
if [ ! -f .env ]; then
    echo "📝 Creating .env file..."
    cp .env.example .env
    echo "⚠️  Please update the .env file with your production settings before continuing."
    echo "   Important: Set APP_KEY, database credentials, and other production values."
    read -p "Press Enter when you've updated the .env file..."
fi

# Generate APP_KEY if not set
if ! grep -q "APP_KEY=base64:" .env; then
    echo "🔑 Generating application key..."
    docker run --rm -v $(pwd):/app -w /app php:8.3-cli php -r "echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;" >> .env
fi

# Build Docker image
echo "🏗️  Building Docker image..."
docker build -t trustfund-app .

# Stop and remove existing container if it exists
echo "🛑 Stopping existing container..."
docker stop trustfund-app 2>/dev/null || true
docker rm trustfund-app 2>/dev/null || true

# Run the container
echo "🚀 Starting TrustFund application..."
docker run -d \
    --name trustfund-app \
    -p 8089:80 \
    -v $(pwd)/storage:/production/echonetafrica/storage \
    -v $(pwd)/bootstrap/cache:/production/echonetafrica/bootstrap/cache \
    trustfund-app

# Wait for application to start
echo "⏳ Waiting for application to start..."
sleep 10

# Check if container is running
if ! docker ps | grep -q trustfund-app; then
    echo "❌ Container failed to start. Checking logs..."
    docker logs trustfund-app
    exit 1
fi

echo "✅ Deployment completed successfully!"
echo ""
echo "🌐 Application is running at: http://localhost"
echo ""
echo "📋 Useful commands:"
echo "   View logs: docker logs -f trustfund-app"
echo "   Stop application: docker stop trustfund-app"
echo "   Restart application: docker restart trustfund-app"
echo "   Access container shell: docker exec -it trustfund-app bash"
echo ""
echo "🔧 Background processes running inside container:"
echo "   - Laravel Scheduler (runs scheduled commands)"
echo "   - Queue Workers (process background jobs)"
echo "   - Web Server (nginx + php-fpm)"
echo ""
echo "📅 Scheduled Commands:"
echo "   - SMS sending: Every 5 seconds"
echo "   - Survey dispatch: Every minute"
echo "   - Survey progress: Every minute"
echo "   - Loan interest accrual: Daily at 9 AM"
echo ""
echo "💡 Note: Make sure your database is accessible from the container"
echo "   Set environment variables when running the container if needed:"
echo "   docker run -d --name trustfund-app -e DB_HOST=your-db-host -e DB_PASSWORD=your-password -p 80:80 trustfund-app"
