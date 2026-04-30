import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function LoginForm() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [userType, setUserType] = useState('student');
  const [localError, setLocalError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  
  const { login, isLoading } = useAuth();
  const navigate = useNavigate();

  const validateForm = () => {
    if (!email) {
      setLocalError('Email is required');
      return false;
    }
    if (!email.includes('@')) {
      setLocalError('Please enter a valid email');
      return false;
    }
    if (!password) {
      setLocalError('Password is required');
      return false;
    }
    if (password.length < 6) {
      setLocalError('Password must be at least 6 characters');
      return false;
    }
    return true;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLocalError('');

    if (!validateForm()) {
      return;
    }

    setIsSubmitting(true);
    const success = await login(email, password, userType);
    setIsSubmitting(false);

    if (success) {
      // Redirect based on user type
      if (userType === 'admin') {
        navigate('/admin/home');
      } else {
        navigate('/student/home');
      }
    } else {
      setLocalError('Login failed. Please check your credentials.');
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center px-4">
      <div className="w-full max-w-md">
        <div className="bg-white rounded-lg shadow-lg p-8">
          {/* Header */}
          <div className="text-center mb-8">
            <h1 className="text-3xl font-bold text-gray-900 mb-2">EducAid</h1>
            <p className="text-gray-600">Education Assistance Distribution System</p>
          </div>

          {/* Error Message */}
          {localError && (
            <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
              <p className="text-red-700 text-sm">{localError}</p>
            </div>
          )}

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-4">
            {/* User Type Selection */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Login As
              </label>
              <div className="flex gap-4">
                <label className="flex items-center cursor-pointer">
                  <input
                    type="radio"
                    name="userType"
                    value="student"
                    checked={userType === 'student'}
                    onChange={(e) => setUserType(e.target.value)}
                    className="w-4 h-4 text-indigo-600"
                  />
                  <span className="ml-2 text-sm text-gray-700">Student</span>
                </label>
                <label className="flex items-center cursor-pointer">
                  <input
                    type="radio"
                    name="userType"
                    value="admin"
                    checked={userType === 'admin'}
                    onChange={(e) => setUserType(e.target.value)}
                    className="w-4 h-4 text-indigo-600"
                  />
                  <span className="ml-2 text-sm text-gray-700">Admin</span>
                </label>
              </div>
            </div>

            {/* Email Field */}
            <div>
              <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
                Email Address
              </label>
              <input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@example.com"
                disabled={isSubmitting || isLoading}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none transition disabled:bg-gray-50 disabled:text-gray-500"
              />
            </div>

            {/* Password Field */}
            <div>
              <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
                Password
              </label>
              <input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                disabled={isSubmitting || isLoading}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none transition disabled:bg-gray-50 disabled:text-gray-500"
              />
            </div>

            {/* Submit Button */}
            <button
              type="submit"
              disabled={isSubmitting || isLoading}
              className="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              {isSubmitting || isLoading ? (
                <>
                  <div className="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full" />
                  Logging in...
                </>
              ) : (
                'Sign In'
              )}
            </button>
          </form>

          {/* Footer Info */}
          <div className="mt-6 pt-6 border-t border-gray-200">
            <p className="text-xs text-gray-600 text-center">
              Protected by secure authentication
            </p>
          </div>
        </div>

        {/* Demo Credentials Info */}
        <div className="mt-6 bg-white rounded-lg shadow p-4 text-sm text-gray-600">
          <p className="font-medium text-gray-700 mb-2">Demo Credentials:</p>
          <ul className="space-y-1 text-xs">
            <li><strong>Student:</strong> student@example.com / password</li>
            <li><strong>Admin:</strong> admin@example.com / password</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
