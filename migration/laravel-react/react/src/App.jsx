import React from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import LegacyPageHost from './pages/LegacyPageHost';

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<LegacyPageHost legacyPath="website/index.php" />} />
      <Route path="/login" element={<LoginPage />} />

      {/* High-priority migrated pages hosted from existing legacy PHP */}
      <Route path="/admin/home" element={<LegacyPageHost legacyPath="modules/admin/homepage.php" />} />
      <Route path="/student/home" element={<LegacyPageHost legacyPath="modules/student/student_homepage.php" />} />
      <Route path="/student/upload" element={<LegacyPageHost legacyPath="modules/student/upload_document.php" />} />

      {/* Keep behavior-safe fallback until full React-native conversion is complete */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
