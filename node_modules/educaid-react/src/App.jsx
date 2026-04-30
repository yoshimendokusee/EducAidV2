import React from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import ErrorBoundary from './components/ErrorBoundary';
import ProtectedRoute from './components/ProtectedRoute';
import LoginPage from './pages/LoginPage';
import CompatPageHost from './pages/CompatPageHost';
import StudentDashboard from './pages/StudentDashboard';
import AdminDashboard from './pages/AdminDashboard';
import AdminSettings from './pages/AdminSettings';
import ApplicantsPage from './pages/ApplicantsPage';
import ReportsPage from './pages/ReportsPage';
import DistributionControlPage from './pages/DistributionControlPage';
import DocumentUpload from './pages/DocumentUpload';
import StudentNotifications from './pages/StudentNotifications';
import StudentSettings from './pages/StudentSettings';
import Navbar from './components/Navbar';

function AppRoutes() {
  return (
    <Routes>
      {/* Public Routes */}
      <Route path="/login" element={<LoginPage />} />

      {/* Protected Student Routes */}
      <Route
        path="/student/home"
        element={
          <ProtectedRoute requiredType="student">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <StudentDashboard />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />
      <Route
        path="/student/upload"
        element={
          <ProtectedRoute requiredType="student">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <DocumentUpload />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />
      <Route
        path="/student/notifications"
        element={
          <ProtectedRoute requiredType="student">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <StudentNotifications />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />
      <Route
        path="/student/settings"
        element={
          <ProtectedRoute requiredType="student">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <StudentSettings />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />

      {/* Protected Admin Routes */}
      <Route
        path="/admin/home"
        element={
          <ProtectedRoute requiredType="admin">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <AdminDashboard />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/settings"
        element={
          <ProtectedRoute requiredType="admin">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <AdminSettings />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/applicants"
        element={
          <ProtectedRoute requiredType="admin">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <ApplicantsPage />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/reports"
        element={
          <ProtectedRoute requiredType="admin">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <ReportsPage />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />
      <Route
        path="/admin/distributions"
        element={
          <ProtectedRoute requiredType="admin">
            <ErrorBoundary>
              <div className="flex flex-col min-h-screen">
                <Navbar />
                <DistributionControlPage />
              </div>
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />

      {/* Fallback routes to legacy pages (for unmigrated pages) */}
      <Route
        path="/admin/*"
        element={
          <ProtectedRoute requiredType="admin">
            <ErrorBoundary>
              <CompatPageHost pagePath="modules/admin/homepage.php" />
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />
      <Route
        path="/student/*"
        element={
          <ProtectedRoute requiredType="student">
            <ErrorBoundary>
              <CompatPageHost pagePath="modules/student/student_homepage.php" />
            </ErrorBoundary>
          </ProtectedRoute>
        }
      />

      {/* Home redirect */}
      <Route path="/" element={<Navigate to="/login" replace />} />

      {/* Catch-all fallback */}
      <Route path="*" element={<Navigate to="/login" replace />} />
    </Routes>
  );
}

export default function App() {
  return (
    <ErrorBoundary>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </ErrorBoundary>
  );
}
