This folder stores an optional local copy of html5-qrcode for offline/blocked-CDN fallback.

Expected files (version 2.3.8):
- html5-qrcode.min.js

Ways to populate:
1) Use the fetch script: scripts/fetch_html5_qrcode.ps1
2) Manually download from jsDelivr/unpkg and place the minified file in this folder.

CSP: Ensure script-src allows 'self' to load this local file.
