import React from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import CompatPageHost from './pages/CompatPageHost';
import StudentDashboard from './pages/StudentDashboard';
import AdminDashboard from './pages/AdminDashboard';
import DocumentUpload from './pages/DocumentUpload';
import StudentNotifications from './pages/StudentNotifications';

export default function App() {
  return (
    <Routes>
      {/* Legacy compatibility routes */}
      <Route path="/" element={<CompatPageHost pagePath="website/index.php" />} />
      <Route path="/login" element={<LoginPage />} />

      {/* Native React Components - New Migration */}
      <Route path="/student/home" element={<StudentDashboard />} />
      <Route path="/student/upload" element={<DocumentUpload />} />
      <Route path="/student/notifications" element={<StudentNotifications />} />
      <Route path="/admin/home" element={<AdminDashboard />} />

      {/* Fallback routes to legacy pages (for pages not yet migrated) */}
      <Route path="/admin/*" element={<CompatPageHost pagePath="modules/admin/homepage.php" />} />
      <Route path="/student/*" element={<CompatPageHost pagePath="modules/student/student_homepage.php" />} />

      {/* Keep behavior-safe fallback until full React-native conversion is complete */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
