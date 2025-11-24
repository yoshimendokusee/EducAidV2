# OCR Bypass Mode - Instructions

## 🎯 Purpose
This bypass mode allows students to register at CVSCU Gentri without strict OCR verification requirements during testing events. It temporarily disables document verification checks to ensure smooth registration during high-volume events.

## ⚠️ IMPORTANT WARNING
**This bypass is for TEMPORARY TESTING PURPOSES ONLY!**
- Only use during scheduled testing events
- MUST be disabled immediately after the event
- All registrations during bypass mode will still be recorded but with mock verification scores

---

## 🔧 How to Enable Bypass

### Step 1: Edit Configuration File
1. Navigate to: `c:\xampp\htdocs\EducAid\config\ocr_bypass_config.php`
2. Find this line:
   ```php
   define('OCR_BYPASS_ENABLED', true);  // ⚠️ TEMPORARY BYPASS ENABLED
   ```
3. Ensure it's set to `true` to enable bypass

### Step 2: Verify Bypass Status
1. Open your browser and go to: `http://localhost/EducAid/check_ocr_bypass_status.php`
2. You should see: **⚠️ BYPASS ENABLED** in red
3. If you see **✅ BYPASS DISABLED** in green, the bypass is not active

### Step 3: Test Registration
1. Go to the student registration page
2. Upload documents (any documents will be accepted)
3. Click "Process Document" buttons
4. All verifications should pass automatically with high confidence scores

---

## 🔒 How to Disable Bypass (AFTER TESTING)

### Step 1: Edit Configuration File
1. Navigate to: `c:\xampp\htdocs\EducAid\config\ocr_bypass_config.php`
2. Change this line:
   ```php
   define('OCR_BYPASS_ENABLED', false);  // ⚠️ BYPASS DISABLED
   ```
3. Set it to `false` to disable bypass

### Step 2: Verify Bypass is Disabled
1. Refresh: `http://localhost/EducAid/check_ocr_bypass_status.php`
2. You should see: **✅ BYPASS DISABLED** in green
3. If still enabled, clear your browser cache and check the config file again

---

## 📋 What Gets Bypassed?

When bypass mode is ENABLED, the following checks are skipped:

### ✅ Enrollment Form (EAF - Document Type 00)
- ✅ Student name matching
- ✅ University name verification
- ✅ Course information validation
- ✅ Year level verification
- ✅ Document type identification
- ✅ All confidence score requirements

### ✅ ID Picture/Valid ID
- ✅ OCR text extraction
- ✅ Confidence score calculations

### ✅ Grade Documents
- ✅ Subject extraction
- ✅ Grade parsing
- ✅ GPA calculations

### ✅ Letter of Acceptance
- ✅ Text verification
- ✅ Content validation

### ✅ Certificate of Grades
- ✅ Text verification
- ✅ Content validation

---

## 📊 Mock Data Used During Bypass

When bypass is enabled, the system returns:
- **OCR Confidence**: 95.0%
- **Verification Score**: 98.0%
- **Verification Status**: `passed`
- **All validation checks**: Automatically passed

---

## 🚨 Important Notes

### For Testing Day (November 24, 2025)
1. ✅ Enable bypass BEFORE students arrive
2. ✅ Verify bypass status using the status page
3. ✅ Monitor logs for bypass messages (should see `⚠️ OCR BYPASS ACTIVE` in logs)
4. ✅ Keep bypass enabled throughout the event

### After Testing Day
1. ⚠️ **IMMEDIATELY** disable bypass
2. ⚠️ Verify it's disabled using status page
3. ⚠️ Test with a real registration to ensure normal verification works
4. ⚠️ Review all registrations that occurred during bypass mode

---

## 🔍 How to Check Logs

To verify bypass is working, check error logs for messages like:
```
⚠️ OCR BYPASS ENABLED: CVSCU Gentri Testing Event - November 24, 2025
⚠️ ALL OCR VERIFICATIONS WILL BE AUTOMATICALLY PASSED
⚠️ OCR BYPASS ACTIVE - Skipping verification for: enrollment_form.pdf
⚠️ OCR BYPASS ACTIVE - Returning mock data for: id_picture.jpg
🔓 CREATING BYPASS RESPONSE - All verifications passed (bypass mode)
```

---

## 📁 Files Modified for Bypass

The following files were updated to support bypass mode:

1. **Config File** (Controls bypass on/off)
   - `/config/ocr_bypass_config.php` ⭐ MAIN CONTROL FILE

2. **OCR Services** (Check bypass before processing)
   - `/services/EnrollmentFormOCRService.php`
   - `/services/OCRProcessingService.php`
   - `/services/OCRProcessingService_Safe.php`

3. **Status Page** (Monitor bypass status)
   - `/check_ocr_bypass_status.php`

4. **Documentation**
   - `/OCR_BYPASS_INSTRUCTIONS.md` (this file)

---

## 🆘 Troubleshooting

### Problem: Bypass not working (still requiring verification)
**Solutions:**
1. Check `ocr_bypass_config.php` is set to `true`
2. Clear PHP opcache: `php -r "opcache_reset();"`
3. Restart Apache/web server
4. Check file permissions on config file

### Problem: Can't access status page
**Solutions:**
1. Make sure you're using correct URL: `http://localhost/EducAid/check_ocr_bypass_status.php`
2. Check if file exists at root of EducAid folder
3. Verify Apache is running

### Problem: Documents still being rejected
**Solutions:**
1. Check browser console for errors
2. Verify bypass is truly enabled via status page
3. Check error logs for bypass messages
4. Try clearing browser cache and cookies

---

## 📞 Support Contact

If you encounter any issues during the testing event:
1. Check the status page first
2. Review error logs in: `c:\xampp\apache\logs\error.log`
3. Verify configuration file settings
4. Contact system administrator

---

## ✅ Quick Checklist

### Before Testing Event:
- [ ] Edit `config/ocr_bypass_config.php`
- [ ] Set `OCR_BYPASS_ENABLED` to `true`
- [ ] Open status page and verify BYPASS ENABLED
- [ ] Test with one registration to confirm
- [ ] Inform testing team bypass is active

### After Testing Event:
- [ ] Edit `config/ocr_bypass_config.php`
- [ ] Set `OCR_BYPASS_ENABLED` to `false`
- [ ] Open status page and verify BYPASS DISABLED
- [ ] Test with one registration to confirm normal verification
- [ ] Review all bypass-mode registrations for validation

---

**Last Updated**: November 24, 2025  
**Created for**: CVSCU Gentri Testing Event  
**Version**: 1.0
