# Quick Deploy Script for Windows PowerShell
# Deploy OCR Bypass to Railway Production

Write-Host "==================================================" -ForegroundColor Cyan
Write-Host "🚀 OCR BYPASS DEPLOYMENT TO RAILWAY" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Current Status: Bypass is ENABLED in code" -ForegroundColor Yellow
Write-Host "Target: educaid-production.up.railway.app" -ForegroundColor Yellow
Write-Host ""

# Check if we're in the right directory
if (-Not (Test-Path "config\ocr_bypass_config.php")) {
    Write-Host "❌ Error: Not in EducAid directory or config file not found" -ForegroundColor Red
    Write-Host "Please run this script from the EducAid root directory" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Config file found" -ForegroundColor Green
Write-Host ""

# Check Git status
Write-Host "📋 Checking Git status..." -ForegroundColor Cyan
git status
Write-Host ""

# Prompt user
$response = Read-Host "Do you want to deploy these changes to Railway? (y/n)"

if ($response -ne "y" -and $response -ne "Y") {
    Write-Host "❌ Deployment cancelled" -ForegroundColor Red
    exit 0
}

Write-Host ""
Write-Host "🔄 Adding files to Git..." -ForegroundColor Cyan
git add config/ocr_bypass_config.php
git add services/EnrollmentFormOCRService.php
git add services/OCRProcessingService.php
git add services/OCRProcessingService_Safe.php
git add check_ocr_bypass_status.php
git add test_ocr_bypass.php
git add *.md

Write-Host "✅ Files added" -ForegroundColor Green
Write-Host ""

Write-Host "💾 Committing changes..." -ForegroundColor Cyan
git commit -m "Deploy OCR bypass for CVSCU Gentri testing event - Nov 24, 2025

- OCR bypass currently ENABLED for testing event
- All document verifications will auto-pass with high scores
- Status page: /check_ocr_bypass_status.php
- Will disable after event completion"

Write-Host "✅ Changes committed" -ForegroundColor Green
Write-Host ""

Write-Host "📤 Pushing to GitHub..." -ForegroundColor Cyan
git push origin main

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Push successful!" -ForegroundColor Green
    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host "🎉 DEPLOYMENT INITIATED" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Railway will now auto-deploy your changes." -ForegroundColor Yellow
    Write-Host "This usually takes 2-5 minutes." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "📊 Next Steps:" -ForegroundColor Cyan
    Write-Host "1. Monitor Railway dashboard for deployment status"
    Write-Host "2. Wait for 'Deployment successful' message"
    Write-Host "3. Verify status page:"
    Write-Host "   https://educaid-production.up.railway.app/check_ocr_bypass_status.php" -ForegroundColor Magenta
    Write-Host "4. Test registration if desired"
    Write-Host ""
    Write-Host "⚠️  REMEMBER: Disable bypass after the event!" -ForegroundColor Red
    Write-Host ""
} else {
    Write-Host "❌ Push failed!" -ForegroundColor Red
    Write-Host "Please check your Git configuration and try again" -ForegroundColor Red
    exit 1
}
