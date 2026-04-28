import React from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import CompatPageHost from './pages/CompatPageHost';

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<CompatPageHost pagePath="website/index.php" />} />
      <Route path="/login" element={<LoginPage />} />

      {/* High-priority migrated pages hosted from existing legacy PHP */}
      <Route path="/admin/home" element={<CompatPageHost pagePath="modules/admin/homepage.php" />} />
      <Route path="/student/home" element={<CompatPageHost pagePath="modules/student/student_homepage.php" />} />
      <Route path="/student/upload" element={<CompatPageHost pagePath="modules/student/upload_document.php" />} />

      {/* Keep behavior-safe fallback until full React-native conversion is complete */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
