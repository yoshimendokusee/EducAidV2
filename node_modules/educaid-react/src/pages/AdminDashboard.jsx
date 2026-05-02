import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { workflowApi, adminApi } from '../services/apiClient';
import {
  AdminCard,
  AdminErrorState,
  AdminLoadingState,
  AdminPageShell,
  AdminStatCard,
} from '../components/AdminPageShell';

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
    return <AdminLoadingState label="Loading admin dashboard..." />;
  }

  return (
    <AdminPageShell
      title="Admin Dashboard"
      subtitle="Monitor applications, distributions, reports, and system activity from one consistent workspace."
      userLabel={user?.email || 'Administrator'}
      actions={(
        <>
          <Link to="/admin/applicants" className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700">
            Review Applicants ({applicants})
          </Link>
          <Link to="/admin/distributions" className="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
            Manage Distributions
          </Link>
        </>
      )}
    >
      {error && <AdminErrorState error={error} />}

      {stats && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          <AdminStatCard label="Active Students" value={stats.active_students || 0} accent="blue" icon="👥" />
          <AdminStatCard label="Pending Applicants" value={applicants} accent="yellow" icon="📋" />
          <AdminStatCard label="Approved" value={stats.approved_count || 0} accent="green" icon="✅" />
          <AdminStatCard label="Pending Review" value={stats.pending_review || 0} accent="red" icon="⏳" />
        </div>
      )}

      <AdminCard title="Quick Actions">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
          <Link to="/admin/applicants" className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-5 py-4 text-slate-900 transition hover:border-blue-300 hover:bg-blue-50">
            <span className="font-semibold">Review Applicants</span>
            <span className="text-slate-500">→</span>
          </Link>
          <Link to="/admin/distributions" className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-5 py-4 text-slate-900 transition hover:border-emerald-300 hover:bg-emerald-50">
            <span className="font-semibold">Manage Distributions</span>
            <span className="text-slate-500">→</span>
          </Link>
          <Link to="/admin/settings" className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-5 py-4 text-slate-900 transition hover:border-violet-300 hover:bg-violet-50">
            <span className="font-semibold">Settings</span>
            <span className="text-slate-500">→</span>
          </Link>
        </div>
      </AdminCard>

      <AdminCard title="Admin Tools">
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          <Link to="/admin/applicants" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-md">
            <div className="text-3xl">📋</div>
            <div className="mt-3 font-semibold text-slate-900">Applicants</div>
            <div className="mt-1 text-sm text-slate-500">{applicants} pending</div>
          </Link>
          <Link to="/admin/distributions" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md">
            <div className="text-3xl">📦</div>
            <div className="mt-3 font-semibold text-slate-900">Distributions</div>
            <div className="mt-1 text-sm text-slate-500">Manage</div>
          </Link>
          <Link to="/admin/reports" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-violet-300 hover:shadow-md">
            <div className="text-3xl">📊</div>
            <div className="mt-3 font-semibold text-slate-900">Reports</div>
            <div className="mt-1 text-sm text-slate-500">Analytics</div>
          </Link>
          <Link to="/admin/analytics" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md">
            <div className="text-3xl">📈</div>
            <div className="mt-3 font-semibold text-slate-900">Analytics</div>
            <div className="mt-1 text-sm text-slate-500">Dashboard</div>
          </Link>
          <Link to="/admin/settings" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md">
            <div className="text-3xl">⚙️</div>
            <div className="mt-3 font-semibold text-slate-900">Settings</div>
            <div className="mt-1 text-sm text-slate-500">Configuration</div>
          </Link>
          <a href="/modules/admin/verify_students.php" target="_blank" rel="noopener noreferrer" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-orange-300 hover:shadow-md">
            <div className="text-3xl">✓</div>
            <div className="mt-3 font-semibold text-slate-900">Verify</div>
            <div className="mt-1 text-sm text-slate-500">Students</div>
          </a>
          <a href="/modules/admin/view_documents.php" target="_blank" rel="noopener noreferrer" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md">
            <div className="text-3xl">📄</div>
            <div className="mt-3 font-semibold text-slate-900">Documents</div>
            <div className="mt-1 text-sm text-slate-500">View All</div>
          </a>
          <a href="/modules/admin/admin_notifications.php" target="_blank" rel="noopener noreferrer" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-pink-300 hover:shadow-md">
            <div className="text-3xl">🔔</div>
            <div className="mt-3 font-semibold text-slate-900">Notifications</div>
            <div className="mt-1 text-sm text-slate-500">Messages</div>
          </a>
          <a href="/modules/admin/export_student_data.php" target="_blank" rel="noopener noreferrer" className="rounded-2xl border border-slate-200 bg-white p-4 text-center transition hover:-translate-y-0.5 hover:border-cyan-300 hover:shadow-md">
            <div className="text-3xl">💾</div>
            <div className="mt-3 font-semibold text-slate-900">Export</div>
            <div className="mt-1 text-sm text-slate-500">Data</div>
          </a>
        </div>
      </AdminCard>
    </AdminPageShell>
  );
}
