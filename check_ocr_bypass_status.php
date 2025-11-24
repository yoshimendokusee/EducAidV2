<?php
/**
 * OCR Bypass Status Check
 * Quick page to verify if OCR bypass is enabled or disabled
 * Access this page to see current bypass status
 */

require_once __DIR__ . '/../config/ocr_bypass_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR Bypass Status</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
        }
        .status-badge {
            display: inline-block;
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 24px;
            font-weight: bold;
            margin: 20px 0;
            text-transform: uppercase;
        }
        .status-enabled {
            background: #ff4757;
            color: white;
            animation: pulse 2s infinite;
        }
        .status-disabled {
            background: #2ed573;
            color: white;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
        }
        .info-value {
            color: #333;
            font-weight: 500;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-top: 20px;
            border-radius: 5px;
            color: #856404;
        }
        .success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-top: 20px;
            border-radius: 5px;
            color: #155724;
        }
        .timestamp {
            text-align: center;
            margin-top: 30px;
            color: #6c757d;
            font-size: 14px;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .back-link:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 OCR Bypass Status Check</h1>
        
        <?php if (defined('OCR_BYPASS_ENABLED') && OCR_BYPASS_ENABLED === true): ?>
            <div class="status-badge status-enabled">⚠️ BYPASS ENABLED</div>
            
            <div class="warning">
                <strong>⚠️ WARNING:</strong> OCR verification bypass is currently ACTIVE. 
                All document verifications will be automatically passed without actual OCR processing.
            </div>
            
            <div class="info">
                <div class="info-row">
                    <span class="info-label">Bypass Status:</span>
                    <span class="info-value" style="color: #dc3545;">ENABLED ⚠️</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Reason:</span>
                    <span class="info-value"><?php echo defined('OCR_BYPASS_REASON') ? OCR_BYPASS_REASON : 'Not specified'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Mock Confidence:</span>
                    <span class="info-value"><?php echo defined('OCR_BYPASS_CONFIDENCE') ? OCR_BYPASS_CONFIDENCE : 'N/A'; ?>%</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Mock Verification:</span>
                    <span class="info-value"><?php echo defined('OCR_BYPASS_VERIFICATION_SCORE') ? OCR_BYPASS_VERIFICATION_SCORE : 'N/A'; ?>%</span>
                </div>
            </div>
            
            <div class="warning" style="margin-top: 20px;">
                <strong>📝 Remember:</strong> Disable this bypass after the testing event by setting 
                <code>OCR_BYPASS_ENABLED = false</code> in <code>/config/ocr_bypass_config.php</code>
            </div>
            
        <?php else: ?>
            <div class="status-badge status-disabled">✅ BYPASS DISABLED</div>
            
            <div class="success">
                <strong>✅ Normal Operation:</strong> OCR verification is functioning normally. 
                All documents are being verified according to standard requirements.
            </div>
            
            <div class="info">
                <div class="info-row">
                    <span class="info-label">Bypass Status:</span>
                    <span class="info-value" style="color: #28a745;">DISABLED ✅</span>
                </div>
                <div class="info-row">
                    <span class="info-label">OCR Processing:</span>
                    <span class="info-value">Standard verification active</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Document Checks:</span>
                    <span class="info-value">All validation rules enforced</span>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="timestamp">
            Status checked: <?php echo date('F j, Y g:i:s A'); ?>
        </div>
        
        <center>
            <a href="../modules/student/student_register.php" class="back-link">← Back to Registration</a>
        </center>
    </div>
</body>
</html>
