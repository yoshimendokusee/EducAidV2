# React Development Quick Start Guide

**Status:** ✅ Ready for Development  
**Last Updated:** 2026-04-29

---

## Prerequisites

- Node.js 18+
- npm or yarn
- Git
- Running Laravel backend on `http://localhost:8090`

---

## Quick Start

### 1. Navigate to React Folder
```bash
cd migration/laravel-react/react
```

### 2. Install Dependencies
```bash
npm install
```

### 3. Start Development Server
```bash
npm run dev
```

The React app will be available at `http://localhost:5173` (or the port shown in terminal)

### 4. Build for Production
```bash
npm run build
```

Output goes to `dist/` folder

---

## Project Structure

```
react/
├── src/
│   ├── App.jsx                 # Main app component with routes
│   ├── main.jsx                # Entry point
│   ├── pages/
│   │   ├── StudentDashboard.jsx
│   │   ├── AdminDashboard.jsx
│   │   ├── DocumentUpload.jsx
│   │   ├── StudentNotifications.jsx
│   │   ├── LoginPage.jsx
│   │   └── CompatPageHost.jsx  # Fallback for legacy PHP
│   ├── components/
│   │   ├── Navbar.jsx
│   │   ├── CompatHtmlFrame.jsx
│   │   └── WorkflowStatusGate.jsx
│   ├── services/
│   │   ├── apiClient.js        # ALL API calls (use this!)
│   │   ├── compatClient.js
│   │   ├── studentApi.js
│   │   └── workflowApi.js
│   ├── css/
│   └── index.css
├── public/
├── package.json
├── vite.config.js
├── tailwind.config.js
└── index.html

```

---

## Available Routes

### Student Pages
- `/student/home` - Student Dashboard (React)
- `/student/upload` - Document Upload (React)
- `/student/notifications` - Notification Preferences (React)

### Admin Pages
- `/admin/home` - Admin Dashboard (React)

### Legacy Fallback
- `/` - Home page (legacy PHP)
- `/login` - Login (legacy PHP)
- Any other route falls back to legacy

---

## Using the API Client

### Import
```javascript
import { 
  studentApi, 
  documentApi, 
  adminApi,
  workflowApi 
} from '../services/apiClient';
```

### Make API Calls
```javascript
// Get notification count
const result = await studentApi.getNotificationCount();
if (result.ok) {
  console.log('Count:', result.data.count);
} else {
  console.error('Error:', result.data);
}

// Upload document
const uploadResult = await documentApi.reuploadDocument({
  student_id: '123',
  document_type: 'id_picture',
  file_data: base64Data,
  file_name: 'my-id.jpg',
  mime_type: 'image/jpeg'
});

// Get workflow status
const status = await workflowApi.getStatus();
```

### Error Handling
```javascript
try {
  const result = await studentApi.getNotificationCount();
  if (!result.ok) {
    // HTTP error
    console.error('Error:', result.status, result.data);
  } else {
    // Success
    console.log(result.data);
  }
} catch (error) {
  // Network error
  console.error('Network error:', error);
}
```

---

## Common Development Tasks

### Add a New API Endpoint

In `src/services/apiClient.js`:

```javascript
export const newApi = {
  getSomething(id) {
    return jsonRequest(`${API_BASE}/endpoint/path/${id}`);
  },
  
  postSomething(payload) {
    return jsonRequest(`${API_BASE}/endpoint/path`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};
```

### Add a New Page Component

1. Create file: `src/pages/MyNewPage.jsx`
2. Add route in `App.jsx`:
```javascript
import MyNewPage from './pages/MyNewPage';

// In Routes:
<Route path="/my-path" element={<MyNewPage />} />
```

### Add a Reusable Component

1. Create file: `src/components/MyComponent.jsx`
2. Export component:
```javascript
export default function MyComponent({ prop1, prop2 }) {
  return <div>...</div>;
}
```
3. Import and use in pages

### Format with Prettier
```bash
npm run format
```

### Lint Code
```bash
npm run lint
```

---

## Browser DevTools

### React DevTools
1. Install: [React Developer Tools extension](https://chrome.google.com/webstore/detail/react-developer-tools/)
2. Inspect components in DevTools > Components tab

### Network Debugging
1. Open DevTools > Network tab
2. Watch API calls to `/api/*` routes
3. Check request/response bodies

---

## Common Issues & Solutions

### Issue: "Cannot find module" error
**Solution:** Make sure path is correct and imports use `./` for relative paths
```javascript
import Component from './components/MyComponent';  // ✅ Correct
import Component from 'components/MyComponent';    // ❌ Wrong
```

### Issue: API returns 404
**Solution:** Check that Laravel backend is running on port 8090:
```bash
# Terminal 1: React
npm run dev

# Terminal 2: Laravel
php artisan serve --port=8090
```

### Issue: CORS error
**Solution:** Ensure Laravel `cors.php` config allows requests from React origin
(Usually handled by `compat.session.bridge` middleware)

### Issue: Session lost after refresh
**Solution:** Session data stored in `sessionStorage` will persist across page loads
```javascript
// Set session data (do this after login)
sessionStorage.setItem('student_id', '123');
sessionStorage.setItem('user_type', 'student');

// Retrieve session data
const id = sessionStorage.getItem('student_id');
```

---

## Testing

### Run Tests
```bash
npm test
```

### Watch Mode
```bash
npm test -- --watch
```

### Coverage Report
```bash
npm test -- --coverage
```

---

## Performance Tips

1. **Use React DevTools Profiler** to identify slow components
2. **Lazy load routes** using `React.lazy()` for large components
3. **Memoize components** using `React.memo()` to prevent unnecessary re-renders
4. **Debounce API calls** in search/filter inputs

---

## Troubleshooting

### Clear Cache
```bash
# Clear npm cache
npm cache clean --force

# Delete node_modules and reinstall
rm -rf node_modules package-lock.json
npm install
```

### Reset Development Environment
```bash
# Kill any running dev servers
pkill -f "vite"

# Start fresh
npm run dev
```

### Check Environment
```bash
# Verify Node version (should be 18+)
node --version

# Verify npm version
npm --version
```

---

## Deployment to Production

### 1. Build React App
```bash
cd migration/laravel-react/react
npm run build
```

### 2. Output in Laravel Public Folder
```bash
# The build output automatically goes to laravel/public/dist/
```

### 3. Deploy Laravel App
```bash
cd migration/laravel-react/laravel
# Deploy to your server
```

### 4. Serve from Laravel
Laravel will serve the React app through:
```php
// routes/web.php
Route::get('/{path?}', function () {
    return file_get_contents(public_path('dist/index.html'));
})->where('path', '.*');
```

---

## Useful Commands Reference

```bash
# Development
npm run dev              # Start dev server

# Building
npm run build            # Build for production
npm run preview          # Preview production build locally

# Utilities
npm install              # Install dependencies
npm update               # Update packages
npm run format           # Format code with Prettier
npm run lint             # Check code with ESLint
npm test                 # Run tests

# Maintenance
npm cache clean --force  # Clear npm cache
npm dedupe               # Remove duplicate packages
```

---

## Documentation Links

- **Vite:** https://vitejs.dev/
- **React:** https://react.dev/
- **React Router:** https://reactrouter.com/
- **Tailwind CSS:** https://tailwindcss.com/
- **JavaScript Fetch API:** https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API

---

## Need Help?

1. Check `REACT_MIGRATION_PHASE7_STATUS.md` for architecture overview
2. Look at existing components for patterns
3. Check browser console for errors
4. Review Laravel logs at `storage/logs/laravel.log`

---

**Happy Coding! 🚀**
