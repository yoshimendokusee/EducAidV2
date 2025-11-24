# 🚀 RAILWAY DEPLOYMENT - QUICK REFERENCE CARD

## ⚡ FASTEST WAY TO DEPLOY

### Option 1: Use PowerShell Script (Recommended for Windows)
```powershell
cd c:\xampp\htdocs\EducAid
.\deploy_ocr_bypass.ps1
```

### Option 2: Manual Git Commands
```powershell
cd c:\xampp\htdocs\EducAid
git add .
git commit -m "Deploy OCR bypass for CVSCU Gentri - ENABLED"
git push origin main
```

---

## 📊 VERIFY DEPLOYMENT

**After Railway finishes deploying (2-5 minutes):**

### Check Status Page:
```
https://educaid-production.up.railway.app/check_ocr_bypass_status.php
```
Should show: **⚠️ BYPASS ENABLED** (red)

### Test Registration:
```
https://educaid-production.up.railway.app/modules/student/student_register.php
```

---

## ⏱️ DEPLOYMENT TIMELINE

1. **Push to GitHub**: ~30 seconds
2. **Railway detects push**: ~10 seconds  
3. **Railway builds**: ~2-3 minutes
4. **Railway deploys**: ~30 seconds
5. **Total**: ~3-5 minutes

---

## 🔒 AFTER EVENT - DISABLE BYPASS

```powershell
# Edit config file first:
# config/ocr_bypass_config.php
# Change: define('OCR_BYPASS_ENABLED', true);
# To:     define('OCR_BYPASS_ENABLED', false);

# Then deploy:
cd c:\xampp\htdocs\EducAid
git add config/ocr_bypass_config.php
git commit -m "DISABLE OCR bypass after event"
git push origin main
```

---

## 🚨 EMERGENCY PROCEDURES

### If Deployment Fails:
1. Check Railway dashboard for errors
2. Review GitHub push succeeded
3. Manually trigger deploy in Railway
4. Check Railway logs

### If Need to Rollback:
```powershell
git revert HEAD
git push origin main
```

### If Bypass Not Working:
1. Check status page
2. Review Railway logs
3. Restart Railway service
4. SSH into Railway and verify files

---

## 📱 IMPORTANT URLS

**Production Site:**
- Main: https://educaid-production.up.railway.app/
- Status: https://educaid-production.up.railway.app/check_ocr_bypass_status.php
- Register: https://educaid-production.up.railway.app/modules/student/student_register.php

**Railway Dashboard:**
- https://railway.app/

---

## ✅ CHECKLIST

### Before Deployment:
- [ ] All changes committed locally
- [ ] Tested locally
- [ ] Bypass is ENABLED in code

### Deploy:
- [ ] Run deploy script OR push manually
- [ ] Monitor Railway dashboard
- [ ] Wait for "Deployment successful"

### Verify:
- [ ] Check status page (should show ENABLED)
- [ ] Test one registration
- [ ] Inform team site is ready

### After Event:
- [ ] Edit config to DISABLE
- [ ] Push changes
- [ ] Verify status page shows DISABLED
- [ ] Test normal operation

---

## 🎯 ONE-LINER DEPLOYMENT

```powershell
cd c:\xampp\htdocs\EducAid; git add .; git commit -m "Deploy OCR bypass - ENABLED"; git push origin main
```

---

**Current Date**: November 24, 2025  
**Event**: CVSCU Gentri Testing  
**Status**: Ready to Deploy 🚀
