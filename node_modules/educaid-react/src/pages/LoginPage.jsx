import React from 'react';
import { Navigate } from 'react-router-dom';
import LoginForm from '../components/LoginForm';
import { useAuth } from '../context/AuthContext';

export default function LoginPage() {
  const { isAuthenticated } = useAuth();

  // If already logged in, redirect to appropriate dashboard
  if (isAuthenticated) {
    return <Navigate to="/admin/home" replace />;
  }

  return <LoginForm />;
}
