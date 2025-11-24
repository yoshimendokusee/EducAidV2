# 🚀 DEPLOYMENT GUIDE - OCR Bypass to Production (educ-site)

## 📋 Overview
This guide will help you deploy the OCR bypass changes to your production Railway site (educaid-production.up.railway.app).

---

## ⚠️ IMPORTANT: Pre-Deployment Considerations

### Option 1: Deploy with Bypass ENABLED (Recommended for Testing Day)
✅ Deploy NOW before the event
✅ Bypass is already enabled in the code
✅ Students can register immediately
⚠️ Must disable after event via code update

### Option 2: Deploy with Bypass DISABLED (Deploy but keep it off)
✅ Deploy the bypass capability
✅ Keep it disabled by default
✅ Enable only when needed via Git commit
⚠️ Requires another deployment to enable

---

## 🎯 RECOMMENDED APPROACH FOR TODAY

Since your event is **TODAY (November 24, 2025)**, I recommend:

**Deploy with bypass ENABLED** so it's ready immediately for your testing event.

---

## 📝 DEPLOYMENT STEPS

### Step 1: Check Current Bypass Status in Code
The bypass is currently **ENABLED** in your local code:
- File: `config/ocr_bypass_config.php`
- Setting: `OCR_BYPASS_ENABLED = true`

### Step 2: Commit Changes to Git

```powershell
# Navigate to your project directory
cd c:\xampp\htdocs\EducAid

# Check status of changes
git status

# Add all new files
git add config/ocr_bypass_config.php
git add services/EnrollmentFormOCRService.php
git add services/OCRProcessingService.php
git add services/OCRProcessingService_Safe.php
git add check_ocr_bypass_status.php
git add test_ocr_bypass.php
git add OCR_BYPASS_INSTRUCTIONS.md
git add OCR_BYPASS_IMPLEMENTATION_SUMMARY.md
git add QUICK_START_GUIDE.md
git add DEPLOYMENT_GUIDE_RAILWAY.md

# Or add all at once:
git add .

# Commit with descriptive message
git commit -m "Add OCR bypass for CVSCU Gentri testing event - Nov 24, 2025

- Created bypass configuration system
- Updated OCR services to check bypass flag
- Added status monitoring page
- Bypass currently ENABLED for testing event
- Will disable after event completion"

# Push to GitHub
git push origin main
```

### Step 3: Railway Auto-Deploy
Railway should automatically detect your push and start deploying:

1. **Monitor Railway Dashboard:**
   - Go to: https://railway.app/
   - Open your EducAid project
   - Watch the deployment logs

2. **Wait for Deployment:**
   - Usually takes 2-5 minutes
   - Watch for "Build successful" message
   - Wait for "Deployment successful" message

### Step 4: Verify Deployment on Production

Once deployed, verify the bypass is working:

```
https://educaid-production.up.railway.app/check_ocr_bypass_status.php
```

You should see:
- **⚠️ BYPASS ENABLED** (red banner)
- Reason: "CVSCU Gentri Testing Event - November 24, 2025"
- Mock Confidence: 95%
- Mock Verification: 98%

### Step 5: Test Registration (Optional but Recommended)

Test with one student registration:
```
https://educaid-production.up.railway.app/modules/student/student_register.php
```

1. Fill in student information
2. Upload any document
3. Click "Process Document"
4. Should pass immediately with high confidence

---

## 🔒 AFTER THE EVENT - DISABLE BYPASS

### Option A: Quick Disable via Git (Recommended)

```powershell
# Navigate to project
cd c:\xampp\htdocs\EducAid

# Edit the config file
# Open: config/ocr_bypass_config.php
# Change: define('OCR_BYPASS_ENABLED', true);
# To:     define('OCR_BYPASS_ENABLED', false);

# Commit the change
git add config/ocr_bypass_config.php
git commit -m "DISABLE OCR bypass after CVSCU Gentri event completion"
git push origin main

# Railway will auto-deploy the disabled version
```

### Option B: Emergency Disable via Railway Console

If you need to disable immediately without waiting for deployment:

1. **SSH into Railway:**
   ```bash
   railway ssh
   ```

2. **Edit config file:**
   ```bash
   cd /app
   nano config/ocr_bypass_config.php
   # Change true to false
   # Save with Ctrl+O, Enter, Ctrl+X
   ```

3. **Note:** This change is temporary and will be overwritten on next deployment

---

## 🔍 MONITORING ON PRODUCTION

### Check Bypass Status Anytime:
```
https://educaid-production.up.railway.app/check_ocr_bypass_status.php
```

### View Railway Logs:
1. Go to Railway Dashboard
2. Click on your EducAid service
3. Click "Logs" tab
4. Look for messages like:
   ```
   ⚠️ OCR BYPASS ENABLED: CVSCU Gentri Testing Event
   ⚠️ OCR BYPASS ACTIVE - Skipping verification
   ```

---

## 📋 DEPLOYMENT CHECKLIST

### Before Event (Do Now):
- [ ] Review changes locally
- [ ] Commit all files to Git
- [ ] Push to GitHub (main branch)
- [ ] Wait for Railway auto-deploy
- [ ] Check status page on production (should show ENABLED)
- [ ] Test one registration on production
- [ ] Inform team that site is ready

### During Event:
- [ ] Monitor Railway logs for errors
- [ ] Check status page periodically
- [ ] Keep an eye on deployment status

### After Event (Critical):
- [ ] Edit config file to disable bypass
- [ ] Commit and push changes
- [ ] Wait for Railway deployment
- [ ] Verify status page shows DISABLED
- [ ] Test normal registration
- [ ] Review all bypass-mode registrations

---

## 🚨 TROUBLESHOOTING

### Problem: Railway Not Auto-Deploying
**Solution:**
1. Check GitHub webhook is connected
2. Manually trigger deploy in Railway dashboard
3. Check Railway logs for errors

### Problem: Changes Not Showing on Production
**Solution:**
1. Clear browser cache
2. Check Railway deployment completed successfully
3. Verify correct branch is deployed (main)
4. SSH into Railway and check files exist

### Problem: Bypass Not Working on Production
**Solution:**
1. Check status page first
2. Review Railway logs for OCR bypass messages
3. Verify config file deployed correctly:
   ```bash
   railway ssh
   cat config/ocr_bypass_config.php
   ```
4. Check PHP error logs in Railway

### Problem: Can't Access Status Page
**Solution:**
1. Verify URL is correct (no typos)
2. Check file was committed to Git
3. Verify Railway deployment included the file
4. Check file permissions

---

## 📁 FILES BEING DEPLOYED

### New Files (will be created on production):
- ✅ `config/ocr_bypass_config.php`
- ✅ `check_ocr_bypass_status.php`
- ✅ `test_ocr_bypass.php`
- ✅ `OCR_BYPASS_INSTRUCTIONS.md`
- ✅ `OCR_BYPASS_IMPLEMENTATION_SUMMARY.md`
- ✅ `QUICK_START_GUIDE.md`
- ✅ `DEPLOYMENT_GUIDE_RAILWAY.md`

### Modified Files (will be updated on production):
- ✅ `services/EnrollmentFormOCRService.php`
- ✅ `services/OCRProcessingService.php`
- ✅ `services/OCRProcessingService_Safe.php`

---

## 🎯 QUICK COMMANDS SUMMARY

```powershell
# Deploy with bypass ENABLED (for today's event)
cd c:\xampp\htdocs\EducAid
git add .
git commit -m "Add OCR bypass for CVSCU Gentri event - ENABLED"
git push origin main
# Wait for Railway deployment (2-5 mins)

# After event - Deploy with bypass DISABLED
# Edit config/ocr_bypass_config.php (change true to false)
git add config/ocr_bypass_config.php
git commit -m "DISABLE OCR bypass after event"
git push origin main
# Wait for Railway deployment
```

---

## 🌐 PRODUCTION URLS

- **Main Site**: https://educaid-production.up.railway.app/
- **Registration**: https://educaid-production.up.railway.app/modules/student/student_register.php
- **Bypass Status**: https://educaid-production.up.railway.app/check_ocr_bypass_status.php

---

## ✅ POST-DEPLOYMENT VERIFICATION

After deployment completes:

1. ✅ Visit status page - should show ENABLED
2. ✅ Test registration - should pass easily
3. ✅ Check Railway logs - should see bypass messages
4. ✅ Inform testing team - site is ready

---

## 📞 SUPPORT

If deployment issues occur:
1. Check Railway dashboard for errors
2. Review deployment logs
3. Test status page URL
4. Verify Git push succeeded
5. Check Railway is connected to correct repo/branch

---

**Ready to deploy?** Follow the steps above to push your changes to production! 🚀

**Remember:** After the event, immediately disable the bypass and redeploy!

---

**Created**: November 24, 2025  
**For**: CVSCU Gentri Testing Event  
**Platform**: Railway (educaid-production.up.railway.app)
