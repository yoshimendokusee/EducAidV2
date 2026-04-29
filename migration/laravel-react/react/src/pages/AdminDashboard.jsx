import React, { useEffect, useState } from 'react';
import { workflowApi, adminApi } from '../services/apiClient';

export default function AdminDashboard() {
  const [stats, setStats] = useState(null);
  const [applicants, setApplicants] = useState(0);
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);

  useEffect(() => {
    const loadData = async () => {
      try {
        // Load workflow status
        const workflowResult = await workflowApi.getStatus();
        if (workflowResult.ok) {
          setStats(workflowResult.data);
        }

        // Load applicant badge count
        const applicantResult = await adminApi.getApplicantBadgeCount();
        if (applicantResult.ok) {
          setApplicants(applicantResult.data.count || 0);
        }

        setStatus('ready');
      } catch (err) {
        setError(err.message);
        setStatus('error');
      }
    };

    loadData();
  }, []);

  if (status === 'loading') {
    return <div className="p-8">Loading admin dashboard...</div>;
  }

  if (status === 'error') {
    return <div className="p-8 text-red-600">Error: {error}</div>;
  }

  return (
    <div className="container mx-auto p-6">
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        {/* Welcome Card */}
        <div className="col-span-1 md:col-span-4 bg-white rounded-lg shadow p-6">
          <h1 className="text-3xl font-bold">Admin Dashboard</h1>
          <p className="text-gray-600 mt-2">
            Current Status: <span className="font-semibold">{stats?.status}</span>
          </p>
        </div>

        {/* Key Metrics */}
        {stats && (
          <>
            <div className="bg-blue-50 rounded-lg shadow p-6">
              <h2 className="text-lg font-semibold text-blue-900">Active Students</h2>
              <p className="text-3xl font-bold text-blue-600 mt-2">{stats.active_students || 0}</p>
            </div>

            <div className="bg-yellow-50 rounded-lg shadow p-6">
              <h2 className="text-lg font-semibold text-yellow-900">Applicants</h2>
              <p className="text-3xl font-bold text-yellow-600 mt-2">{applicants}</p>
            </div>

            <div className="bg-green-50 rounded-lg shadow p-6">
              <h2 className="text-lg font-semibold text-green-900">Approved</h2>
              <p className="text-3xl font-bold text-green-600 mt-2">{stats.approved_count || 0}</p>
            </div>

            <div className="bg-red-50 rounded-lg shadow p-6">
              <h2 className="text-lg font-semibold text-red-900">Pending Review</h2>
              <p className="text-3xl font-bold text-red-600 mt-2">{stats.pending_review || 0}</p>
            </div>
          </>
        )}

        {/* Admin Tools */}
        <div className="col-span-1 md:col-span-4 bg-white rounded-lg shadow p-6">
          <h2 className="text-2xl font-bold mb-4">Admin Tools</h2>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <a
              href="/admin/applicants"
              className="text-center p-4 border rounded hover:bg-gray-50"
            >
              <div className="text-2xl font-bold text-blue-600">{applicants}</div>
              <div className="text-sm text-gray-600">Applicants</div>
            </a>
            <a
              href="/admin/distributions"
              className="text-center p-4 border rounded hover:bg-gray-50"
            >
              <div className="text-2xl font-bold text-green-600">📊</div>
              <div className="text-sm text-gray-600">Distributions</div>
            </a>
            <a
              href="/admin/reports"
              className="text-center p-4 border rounded hover:bg-gray-50"
            >
              <div className="text-2xl font-bold text-purple-600">📈</div>
              <div className="text-sm text-gray-600">Reports</div>
            </a>
            <a
              href="/admin/settings"
              className="text-center p-4 border rounded hover:bg-gray-50"
            >
              <div className="text-2xl font-bold text-gray-600">⚙️</div>
              <div className="text-sm text-gray-600">Settings</div>
            </a>
          </div>
        </div>

        {/* Quick Actions */}
        <div className="col-span-1 md:col-span-4 flex gap-4">
          <a
            href="/admin/applicants"
            className="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700"
          >
            Review Applicants ({applicants})
          </a>
          <a
            href="/admin/distributions"
            className="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700"
          >
            Manage Distributions
          </a>
          <a
            href="/admin/reports"
            className="bg-purple-600 text-white px-6 py-2 rounded hover:bg-purple-700"
          >
            Generate Reports
          </a>
        </div>
      </div>
    </div>
  );
}
