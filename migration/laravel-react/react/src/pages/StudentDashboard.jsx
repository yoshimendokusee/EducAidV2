import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function StudentDashboard() {
  const { user } = useAuth();
  const [documents, setDocuments] = useState([]);
  const [notifications, setNotifications] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const loadDashboardData = async () => {
      try {
        setIsLoading(true);
        setError(null);

        // Fetch documents
        const docsRes = await fetch('/api/documents/student-documents', {
          credentials: 'include',
        });
        const docsData = await docsRes.json();

        // Fetch notification count
        const notifRes = await fetch('/api/student/get_notification_count.php', {
          credentials: 'include',
        });
        const notifData = await notifRes.json();

        setDocuments(docsData?.documents || []);
        setNotifications(notifData?.count || 0);
      } catch (err) {
        console.error('Failed to load dashboard data:', err);
        setError('Failed to load dashboard data. Please refresh the page.');
      } finally {
        setIsLoading(false);
      }
    };

    loadDashboardData();
  }, []);

  if (isLoading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="flex flex-col items-center gap-4">
          <div className="animate-spin h-12 w-12 border-4 border-indigo-600 border-t-transparent rounded-full" />
          <p className="text-gray-600">Loading your dashboard...</p>
        </div>
      </div>
    );
  }


  return (
    <div className="min-h-screen bg-gray-50">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Error Message */}
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p className="text-red-700">{error}</p>
          </div>
        )}

        {/* Welcome Header */}
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-gray-900">
            Welcome, {user?.name || 'Student'}!
          </h1>
          <p className="text-gray-600 mt-2">
            Here's your EducAid dashboard for assistance distribution tracking.
          </p>
        </div>

        {/* Quick Stats */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <div className="flex items-center justify-center h-12 w-12 rounded-md bg-indigo-500">
                  <svg
                    className="h-6 w-6 text-white"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                  </svg>
                </div>
              </div>
              <div className="ml-4">
                <p className="text-sm text-gray-600">Total Documents</p>
                <p className="text-2xl font-semibold text-gray-900">
                  {documents.length}
                </p>
              </div>
            </div>
          </div>

          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <div className="flex items-center justify-center h-12 w-12 rounded-md bg-green-500">
                  <svg
                    className="h-6 w-6 text-white"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M5 13l4 4L19 7"
                    />
                  </svg>
                </div>
              </div>
              <div className="ml-4">
                <p className="text-sm text-gray-600">Notifications</p>
                <p className="text-2xl font-semibold text-gray-900">
                  {notifications}
                </p>
              </div>
            </div>
          </div>

          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <div className="flex items-center justify-center h-12 w-12 rounded-md bg-yellow-500">
                  <svg
                    className="h-6 w-6 text-white"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                  </svg>
                </div>
              </div>
              <div className="ml-4">
                <p className="text-sm text-gray-600">Status</p>
                <p className="text-2xl font-semibold text-gray-900">Active</p>
              </div>
            </div>
          </div>

          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex items-center">
              <div className="flex-shrink-0">
                <div className="flex items-center justify-center h-12 w-12 rounded-md bg-blue-500">
                  <svg
                    className="h-6 w-6 text-white"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                  </svg>
                </div>
              </div>
              <div className="ml-4">
                <p className="text-sm text-gray-600">Help</p>
                <p className="text-2xl font-semibold text-gray-900">Available</p>
              </div>
            </div>
          </div>
        </div>

        {/* Quick Actions */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <Link
            to="/student/upload"
            className="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer"
          >
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-lg font-semibold text-gray-900">Upload Documents</h3>
                <p className="text-sm text-gray-600 mt-1">
                  Upload required documents for verification
                </p>
              </div>
              <svg
                className="h-8 w-8 text-indigo-600"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M12 4v16m8-8H4"
                />
              </svg>
            </div>
          </Link>

          <Link
            to="/student/notifications"
            className="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer relative"
          >
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-lg font-semibold text-gray-900">Notifications</h3>
                <p className="text-sm text-gray-600 mt-1">
                  Manage your notification preferences
                </p>
              </div>
              <div className="relative">
                <svg
                  className="h-8 w-8 text-indigo-600"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                  />
                </svg>
                {notifications > 0 && (
                  <span className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-600 rounded-full">
                    {notifications}
                  </span>
                )}
              </div>
            </div>
          </Link>

          <Link
            to="/student/profile"
            className="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer"
          >
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-lg font-semibold text-gray-900">My Profile</h3>
                <p className="text-sm text-gray-600 mt-1">
                  View and update your profile information
                </p>
              </div>
              <svg
                className="h-8 w-8 text-indigo-600"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                />
              </svg>
            </div>
          </Link>
        </div>

        {/* Recent Documents */}
        <div className="bg-white rounded-lg shadow">
          <div className="px-6 py-4 border-b border-gray-200">
            <h2 className="text-lg font-semibold text-gray-900">Recent Documents</h2>
          </div>
          <div className="divide-y divide-gray-200">
            {documents.length === 0 ? (
              <div className="px-6 py-12 text-center">
                <svg
                  className="mx-auto h-12 w-12 text-gray-400"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                  />
                </svg>
                <p className="mt-4 text-gray-600">No documents uploaded yet</p>
                <Link
                  to="/student/upload"
                  className="mt-4 inline-block px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition"
                >
                  Upload Now
                </Link>
              </div>
            ) : (
              documents.slice(0, 5).map((doc) => (
                <div key={doc.id} className="px-6 py-4 flex items-center justify-between">
                  <div className="flex items-center">
                    <svg
                      className="h-8 w-8 text-gray-400"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                      />
                    </svg>
                    <div className="ml-4">
                      <p className="font-medium text-gray-900">{doc.name}</p>
                      <p className="text-sm text-gray-600">
                        Uploaded {new Date(doc.created_at).toLocaleDateString()}
                      </p>
                    </div>
                  </div>
                  <div>
                    <span className={`px-3 py-1 rounded-full text-sm font-medium ${
                      doc.status === 'verified'
                        ? 'bg-green-100 text-green-800'
                        : doc.status === 'pending'
                        ? 'bg-yellow-100 text-yellow-800'
                        : 'bg-red-100 text-red-800'
                    }`}>
                      {doc.status || 'pending'}
                    </span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
