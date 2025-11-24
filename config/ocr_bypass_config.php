<?php
/**
 * OCR VERIFICATION BYPASS CONFIGURATION
 * 
 * TEMPORARY BYPASS for testing at CVSCU Gentri (November 24, 2025)
 * 
 * WARNING: This bypass is for TESTING PURPOSES ONLY
 * Set OCR_BYPASS_ENABLED to false after the testing event
 * 
 * When enabled:
 * - All OCR verifications will be automatically passed
 * - All documents will be marked with high confidence scores
 * - Students can register without strict document verification
 * 
 * REMEMBER TO DISABLE THIS AFTER TESTING!
 */

// ====================================================================
// SET THIS TO true TO ENABLE BYPASS (false TO DISABLE)
// ====================================================================
define('OCR_BYPASS_ENABLED', true);  // ⚠️ TEMPORARY BYPASS ENABLED

// Bypass reason (for logging)
define('OCR_BYPASS_REASON', 'CVSCU Gentri Testing Event - November 24, 2025');

// Default values when bypass is enabled
define('OCR_BYPASS_CONFIDENCE', 95.0);  // Mock high confidence score
define('OCR_BYPASS_VERIFICATION_SCORE', 98.0);  // Mock high verification score

// Log bypass status on load
if (OCR_BYPASS_ENABLED) {
    error_log("⚠️ OCR BYPASS ENABLED: " . OCR_BYPASS_REASON);
    error_log("⚠️ ALL OCR VERIFICATIONS WILL BE AUTOMATICALLY PASSED");
}

?>
