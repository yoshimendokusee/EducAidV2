# Open Graph Images

This directory contains social media preview images (Open Graph images) for each page.

## Image Specifications

### Size Requirements
- **Recommended**: 1200 x 630 pixels
- **Minimum**: 600 x 314 pixels
- **Aspect Ratio**: 1.91:1

### File Format
- **Preferred**: JPG (smaller file size)
- **Alternative**: PNG (better quality, larger size)
- **Max File Size**: Under 5MB (ideally under 300KB)

## Images Needed

Create the following images:

1. **og-landing.jpg** - Landing page preview
   - Current path: `/assets/images/og/og-landing.jpg`
   - Shows: General Trias City Hall or EducAid branding

2. **og-about.jpg** - About page preview
   - Current path: `/assets/images/og/og-about.jpg`
   - Shows: Team or mission statement visual

3. **og-howitworks.jpg** - How It Works page preview
   - Current path: `/assets/images/og/og-howitworks.jpg`
   - Shows: Step-by-step process infographic

4. **og-requirements.jpg** - Requirements page preview
   - Current path: `/assets/images/og/og-requirements.jpg`
   - Shows: Document checklist or requirements overview

5. **og-contact.jpg** - Contact page preview
   - Current path: `/assets/images/og/og-contact.jpg`
   - Shows: Contact information or office location

6. **og-announcements.jpg** - Announcements page preview
   - Current path: `/assets/images/og/og-announcements.jpg`
   - Shows: Latest news or announcement board

7. **og-login.jpg** - Login page preview (optional)
   - Current path: `/assets/images/og/og-login.jpg`
   - Shows: Secure login visual

8. **og-register.jpg** - Registration page preview (optional)
   - Current path: `/assets/images/og/og-register.jpg`
   - Shows: Registration form or new student welcome

## Design Guidelines

### Required Elements
- **EducAid Logo**: Top-left or center
- **Page Title**: Large, readable text
- **City Seal**: General Trias seal/logo
- **Background**: Clean, professional design

### Branding
- **Primary Color**: #0051f8 (EducAid Blue)
- **Secondary Color**: #18a54a (Green)
- **Font**: Manrope or Poppins (similar to website)

### Example Layout
```
┌─────────────────────────────────────┐
│ [Logo]                              │
│                                     │
│        Page Title Here              │
│        Short Description            │
│                                     │
│              [Visual Element]       │
│                                     │
│         www.educ-aid.site           │
└─────────────────────────────────────┘
```

## How to Create Images

### Option 1: Canva (Recommended)
1. Go to https://www.canva.com
2. Create new design → Custom size → 1200 x 630
3. Use template or create from scratch
4. Add EducAid branding, logo, text
5. Download as JPG (quality: 90%)

### Option 2: Photoshop/GIMP
1. New file: 1200 x 630 pixels, 72 DPI
2. Add background color/gradient
3. Import EducAid logo and General Trias seal
4. Add page title and description text
5. Save as JPG (quality: 85-90%)

### Option 3: Figma
1. Create frame: 1200 x 630
2. Design with components
3. Export as JPG @ 2x

## Testing Images

After creating images, test them:

### Facebook Debugger
- URL: https://developers.facebook.com/tools/debug/
- Enter page URL: https://www.educ-aid.site/website/landingpage.php
- Click "Scrape Again" to refresh cache
- Verify image displays correctly

### Twitter Card Validator
- URL: https://cards-dev.twitter.com/validator
- Enter page URL
- Check preview

### LinkedIn Post Inspector
- URL: https://www.linkedin.com/post-inspector/
- Enter page URL
- Verify preview

## Temporary Placeholder

Until custom images are created, a default placeholder will be used:
- Path: `/assets/images/educaid-default-og.jpg`
- Shows: EducAid logo with General Trias branding

## Updating Images

To update an image:
1. Replace the file in this directory
2. Clear social media cache using debugger tools above
3. Test by sharing the page URL

## File Naming Convention

Use lowercase with hyphens:
- ✅ `og-landing.jpg`
- ✅ `og-about.jpg`
- ❌ `OG_Landing.jpg`
- ❌ `ogLanding.JPG`

## Best Practices

1. **Keep Text Minimal**: 5-10 words maximum
2. **High Contrast**: Ensure text is readable
3. **Avoid Small Text**: Will be hard to read when scaled
4. **Safe Zones**: Keep important content away from edges
5. **Test on Mobile**: Preview how it looks on small screens
6. **Optimize File Size**: Compress without losing quality

## Tools for Optimization

- **TinyPNG**: https://tinypng.com/ (compress images)
- **Squoosh**: https://squoosh.app/ (advanced compression)
- **ImageOptim**: https://imageoptim.com/ (Mac only)

## Current Status

- [ ] og-landing.jpg
- [ ] og-about.jpg
- [ ] og-howitworks.jpg
- [ ] og-requirements.jpg
- [ ] og-contact.jpg
- [ ] og-announcements.jpg
- [ ] og-login.jpg (optional)
- [ ] og-register.jpg (optional)

**Note**: Until images are created, the SEO system will use default fallback images defined in `config/seo_config.php`.
