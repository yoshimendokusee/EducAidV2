#!/bin/bash
# Quick Deploy Script for OCR Bypass to Railway Production
# Run this script to deploy the OCR bypass changes

echo "=================================================="
echo "🚀 OCR BYPASS DEPLOYMENT TO RAILWAY"
echo "=================================================="
echo ""
echo "Current Status: Bypass is ENABLED in code"
echo "Target: educaid-production.up.railway.app"
echo ""

# Check if we're in the right directory
if [ ! -f "config/ocr_bypass_config.php" ]; then
    echo "❌ Error: Not in EducAid directory or config file not found"
    echo "Please run this script from the EducAid root directory"
    exit 1
fi

echo "✅ Config file found"
echo ""

# Check Git status
echo "📋 Checking Git status..."
git status
echo ""

# Prompt user
read -p "Do you want to deploy these changes to Railway? (y/n) " -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]
then
    echo "❌ Deployment cancelled"
    exit 0
fi

echo ""
echo "🔄 Adding files to Git..."
git add config/ocr_bypass_config.php
git add services/EnrollmentFormOCRService.php
git add services/OCRProcessingService.php
git add services/OCRProcessingService_Safe.php
git add check_ocr_bypass_status.php
git add test_ocr_bypass.php
git add *.md

echo "✅ Files added"
echo ""

echo "💾 Committing changes..."
git commit -m "Deploy OCR bypass for CVSCU Gentri testing event - Nov 24, 2025

- OCR bypass currently ENABLED for testing event
- All document verifications will auto-pass with high scores
- Status page: /check_ocr_bypass_status.php
- Will disable after event completion"

echo "✅ Changes committed"
echo ""

echo "📤 Pushing to GitHub..."
git push origin main

if [ $? -eq 0 ]; then
    echo "✅ Push successful!"
    echo ""
    echo "=================================================="
    echo "🎉 DEPLOYMENT INITIATED"
    echo "=================================================="
    echo ""
    echo "Railway will now auto-deploy your changes."
    echo "This usually takes 2-5 minutes."
    echo ""
    echo "📊 Next Steps:"
    echo "1. Monitor Railway dashboard for deployment status"
    echo "2. Wait for 'Deployment successful' message"
    echo "3. Verify status page:"
    echo "   https://educaid-production.up.railway.app/check_ocr_bypass_status.php"
    echo "4. Test registration if desired"
    echo ""
    echo "⚠️  REMEMBER: Disable bypass after the event!"
    echo ""
else
    echo "❌ Push failed!"
    echo "Please check your Git configuration and try again"
    exit 1
fi
