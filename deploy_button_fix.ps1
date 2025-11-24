# Deploy Button Fix for OCR Bypass System
# This script commits and pushes the button enablement fix

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  DEPLOYING BUTTON FIX FOR BYPASS MODE" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Navigate to repository
Set-Location "c:\xampp\htdocs\EducAid"

# Check git status
Write-Host "📋 Current git status:" -ForegroundColor Yellow
git status --short

Write-Host ""
Write-Host "➕ Adding modified files..." -ForegroundColor Yellow
git add modules/student/student_register.php

Write-Host ""
Write-Host "💾 Creating commit..." -ForegroundColor Yellow
git commit -m "Fix: Enable document Next buttons on page load during bypass mode

- Added JavaScript to enable all document step buttons (steps 4-8) when OCR_BYPASS_ENABLED is true
- Buttons now show 'Continue - Bypass Mode (Optional)' text with warning styling
- Students can now skip document uploads entirely during bypass mode
- Fixes issue where buttons remained locked despite bypass being active
- Bypass status logged to console for debugging"

Write-Host ""
Write-Host "🚀 Pushing to Railway..." -ForegroundColor Yellow
git push origin main

Write-Host ""
Write-Host "✅ DEPLOYMENT COMPLETE!" -ForegroundColor Green
Write-Host ""
Write-Host "📝 Next steps:" -ForegroundColor Cyan
Write-Host "   1. Wait 2-3 minutes for Railway to deploy" -ForegroundColor White
Write-Host "   2. Check: https://educaid-production.up.railway.app/check_ocr_bypass_status.php" -ForegroundColor White
Write-Host "   3. Test registration - document buttons should be enabled" -ForegroundColor White
Write-Host ""
Write-Host "⚠️  IMPORTANT: Disable bypass after testing event!" -ForegroundColor Red
Write-Host "    Edit config/ocr_bypass_config.php and set to false" -ForegroundColor Red
Write-Host ""
