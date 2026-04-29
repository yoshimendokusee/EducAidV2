<?php

return [
    // Tesseract OCR executable path
    'tesseract_path' => env('TESSERACT_PATH', 'tesseract'),

    // Maximum file size for OCR processing (bytes)
    'max_file_size' => env('OCR_MAX_FILE_SIZE', 10 * 1024 * 1024), // 10MB

    // Allowed file extensions for OCR
    'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'bmp'],

    // Bypass OCR processing (for testing)
    'bypass_enabled' => env('OCR_BYPASS_ENABLED', false),

    // OCR language
    'language' => env('OCR_LANGUAGE', 'eng'),

    // Tesseract OCR engine mode (0-3)
    // 0 = Legacy only
    // 1 = Neural nets LSTM only
    // 2 = Legacy + LSTM
    // 3 = Default
    'engine_mode' => env('OCR_ENGINE_MODE', 1),

    // Page segmentation mode (0-13)
    // 6 = Assume single uniform block of text
    'page_seg_mode' => env('OCR_PAGE_SEG_MODE', 6),

    // Enable image preprocessing (contrast enhancement, etc.)
    'enable_preprocessing' => env('OCR_ENABLE_PREPROCESSING', true),

    // Confidence threshold for extracting text
    'confidence_threshold' => env('OCR_CONFIDENCE_THRESHOLD', 30),
];
