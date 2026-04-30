import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { workflowApi, adminApi } from '../services/apiClient';

export default function AdminDashboard() {
  const { user } = useAuth();
  const [stats, setStats] = useState(null);
  const [applicants, setApplicants] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const loadData = async () => {
      try {
        setLoading(true);
        setError(null);

        // Load workflow status
        try {
          const workflowResult = await workflowApi.getStatus();
          if (workflowResult.ok) {
            setStats(workflowResult.data);
          } else {
            console.warn('Workflow status failed:', workflowResult.status);
            // Set default stats if API fails
            setStats({
              status: 'unknown',
              active_students: 0,
              approved_count: 0,
              pending_review: 0,
            });
          }
        } catch (err) {
          console.error('Error loading workflow status:', err);
          setStats({
            status: 'unknown',
            active_students: 0,
            approved_count: 0,
            pending_review: 0,
          });
        }

        // Load applicant badge count
        try {
          const applicantResult = await adminApi.getApplicantBadgeCount();
          if (applicantResult.ok) {
            setApplicants(applicantResult.data.count || 0);
          }
        } catch (err) {
          console.error('Error loading applicant count:', err);
          setApplicants(0);
        }

        setLoading(false);
      } catch (err) {
        setError(err.message || 'Failed to load dashboard data');
        setLoading(false);
      }
    };

    loadData();
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-gray-50">
        <div className="text-center">
          <div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
          <p className="mt-4 text-gray-600">Loading admin dashboard...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50 py-6">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-gray-900">Admin Dashboard</h1>
          <p className="mt-2 text-gray-600">
            Welcome, <span className="font-semibold">{user?.email || 'Administrator'}</span>
          </p>
        </div>

        {/* Error Message */}
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p className="text-red-700">⚠️ {error}</p>
          </div>
        )}

        {/* Stats Cards */}
        {stats && (
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div className="bg-white rounded-lg shadow p-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-gray-600 text-sm">Active Students</p>
                  <p className="text-3xl font-bold text-blue-600 mt-2">{stats.active_students || 0}</p>
                </div>
                <div className="text-4xl text-blue-200">👥</div>
              </div>
            </div>

            <div className="bg-white rounded-lg shadow p-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-gray-600 text-sm">Pending Applicants</p>
                  <p className="text-3xl font-bold text-yellow-600 mt-2">{applicants}</p>
                </div>
                <div className="text-4xl text-yellow-200">📋</div>
              </div>
            </div>

            <div className="bg-white rounded-lg shadow p-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-gray-600 text-sm">Approved</p>
                  <p className="text-3xl font-bold text-green-600 mt-2">{stats.approved_count || 0}</p>
                </div>
                <div className="text-4xl text-green-200">✅</div>
              </div>
            </div>

            <div className="bg-white rounded-lg shadow p-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-gray-600 text-sm">Pending Review</p>
                  <p className="text-3xl font-bold text-red-600 mt-2">{stats.pending_review || 0}</p>
                </div>
                <div className="text-4xl text-red-200">⏳</div>
              </div>
            </div>
          </div>
        )}

        {/* Quick Actions */}
        <div className="bg-white rounded-lg shadow p-6 mb-8">
          <h2 className="text-2xl font-bold text-gray-900 mb-4">Quick Actions</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Link
              to="/admin/applicants"
              className="inline-flex items-center justify-between bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition"
            >
              <span>Review Applicants ({applicants})</span>
              <span>→</span>
            </Link>
            <a
              href="/modules/admin/distribution_control.php"
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center justify-between bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg transition"
            >
              <span>Manage Distributions</span>
              <span>→</span>
            </a>
            <Link
              to="/admin/settings"
              className="inline-flex items-center justify-between bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg transition"
            >
              <span>Settings</span>
              <span>→</span>
            </Link>
          </div>
        </div>

        {/* Admin Tools Grid */}
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-2xl font-bold text-gray-900 mb-4">Admin Tools</h2>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {/* Applicants */}
            <Link
              to="/admin/applicants"
              className="p-4 border border-gray-200 rounded-lg hover:shadow-md hover:border-blue-300 transition text-center"
            >
              <div className="text-3xl mb-2">📋</div>
              <div className="font-semibold text-gray-900">Applicants</div>
              <div className="text-sm text-gray-600 mt-1">{applicants} pending</div>
            </Link>

            {/* Distributions */}
            <Link
              to="/admin/distributions"
              className="p-4 border border-gray-200 rounded-lg hover:shadow-md hover:border-green-300 transition text-center"
            >
              <div className="text-3xl mb-2">📦</div>
              <div className="font-semibold text-gray-900">Distributions</div>
              <div className="text-sm text-gray-600 mt-1">Manage</div>
            </Link>

            {/* Reports */}
            <Link
              to="/admin/reports"
              className="p-4 border border-gray-200 rounded-lg hover:shadow-md hover:border-purple-300 transition text-center"
            >
              <div className="text-3xl mb-2">📊</div>
              <div className="font-semibold text-gray-900">Reports</div>
              <div className="text-sm text-gray-600 mt-1">Analytics</div>
            </Link>

            {/* Settings */}
            <Link
              to="/admin/settings"
              className="p-4 border border-gray-200 rounded-lg hover:shadow-md hover:border-gray-300 transition text-center"
            >
              <div className="text-3xl mb-2">⚙️</div>
              <div className="font-semibold text-gray-900">Settings</div>
              <div className="text-sm text-gray-600 mt-1">Configuration</div>
            </Link>

            {/* Verify Students */}
            <a
              href="/modules/admin/verify_students.php"
              target="_blank"
              rel="noopener noreferrer"
              className="p-4 border border-gray-200 rounded-lg hover:shadow-md hover:border-orange-300 transition text-center cursor-pointer"
            >
              <div className="text-3xl mb-2">✓</div>
              <div className="font-semibold text-gray-900">Verify</div>
              <div className="text-sm text-gray-600 mt-1">Students</div>
            </a>

            {/* View Documents */}
            <a
              href="/modules/admin/view_documents.php"
              target="_blank"
              rel="noopener noreferrer"
              className="p-4 border border-gray-200 rounded-lg hover:shadow-md hover:border-indigo-300 transition text-center cursor-pointer"
            >
              <div className="text-3xl mb-2">📄</div>
              <div className="font-semibold text-gray-900">Documents</div>
              <div className="text-sm text-gray-600 mt-1">View All</div>
            </a>

            {/* Notifications */}
            <a
              href="/modules/admin/admin_notifications.php"
              target="_blank"
              rel="noopener noreferrer"
              className="p-4 border border-gray-200 rounded-lg hover:shadow-md hover:border-pink-300 transition text-center cursor-pointer"
            >
              <div className="text-3xl mb-2">🔔</div>
              <div className="font-semibold text-gray-900">Notifications</div>
              <div className="text-sm text-gray-600 mt-1">Messages</div>
            </a>

            {/* Export Data */}
            <a
              href="/modules/admin/export_student_data.php"
              target="_blank"
              rel="noopener noreferrer"
              className="p-4 border border-gray-200 rounded-lg hover:shadow-md hover:border-cyan-300 transition text-center cursor-pointer"
            >
              <div className="text-3xl mb-2">💾</div>
              <div className="font-semibold text-gray-900">Export</div>
              <div className="text-sm text-gray-600 mt-1">Data</div>
            </a>
          </div>
        </div>
      </div>
    </div>
  );
}
