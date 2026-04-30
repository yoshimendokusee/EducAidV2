import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function ProtectedRoute({ 
  children, 
  requiredType = null, // 'student', 'admin', or null for any authenticated user
  fallback = '/login'
}) {
  const { isAuthenticated, isLoading, user } = useAuth();

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="flex flex-col items-center gap-4">
          <div className="animate-spin h-12 w-12 border-4 border-indigo-600 border-t-transparent rounded-full" />
          <p className="text-gray-600">Loading...</p>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to={fallback} replace />;
  }

  if (requiredType && user?.type !== requiredType) {
    return <Navigate to={fallback} replace />;
  }

  return children;
}
