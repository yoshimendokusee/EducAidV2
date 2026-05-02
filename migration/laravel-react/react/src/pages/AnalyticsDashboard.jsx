import React, { useEffect, useState } from 'react';
import { adminApi } from '../services/apiClient';

/**
 * StatsCard - Individual metric card
 */
function StatsCard({ label, value, icon, trend, color = 'blue' }) {
  const colorMap = {
    blue: 'bg-blue-50 text-blue-700 border-blue-200',
    green: 'bg-green-50 text-green-700 border-green-200',
    red: 'bg-red-50 text-red-700 border-red-200',
    yellow: 'bg-yellow-50 text-yellow-700 border-yellow-200',
    purple: 'bg-purple-50 text-purple-700 border-purple-200',
    indigo: 'bg-indigo-50 text-indigo-700 border-indigo-200',
  };

  return (
    <div className={`rounded-lg border p-4 ${colorMap[color] || colorMap.blue}`}>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm font-medium opacity-75">{label}</p>
          <p className="mt-2 text-3xl font-bold">{value}</p>
          {trend && (
            <p className={`mt-1 text-xs ${trend > 0 ? 'text-green-600' : 'text-red-600'}`}>
              {trend > 0 ? '↑' : '↓'} {Math.abs(trend)}% from last period
            </p>
          )}
        </div>
        <div className="text-4xl opacity-25">{icon}</div>
      </div>
    </div>
  );
}

/**
 * ChartPreview - Simple line chart preview for time series data
 */
function ChartPreview({ title, data, color = '#3b82f6' }) {
  if (!data || data.length === 0) {
    return (
      <div className="rounded-lg border border-slate-200 bg-white p-4">
        <h3 className="font-semibold text-slate-900">{title}</h3>
        <p className="mt-2 text-sm text-slate-500">No data available</p>
      </div>
    );
  }

  const maxValue = Math.max(...data.map(d => d.value), 1);
  const height = 120;

  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <h3 className="font-semibold text-slate-900">{title}</h3>
      <div className="mt-4 flex items-end justify-between" style={{ height }}>
        {data.map((point, idx) => (
          <div
            key={idx}
            className="flex-1 mx-0.5 rounded-t bg-blue-200 hover:bg-blue-300 transition"
            style={{
              height: `${(point.value / maxValue) * height}px`,
              minHeight: point.value > 0 ? '4px' : '1px',
            }}
            title={`${point.date}: ${point.value}`}
          />
        ))}
      </div>
      <div className="mt-3 text-xs text-slate-500">
        Max: {maxValue} | Last 30 days
      </div>
    </div>
  );
}

/**
 * AnalyticsDashboard - Comprehensive metrics and analytics view
 */
export default function AnalyticsDashboard() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [refreshing, setRefreshing] = useState(false);

  useEffect(() => {
    loadDashboardData();
    // Refresh every 5 minutes
    const interval = setInterval(loadDashboardData, 300000);
    return () => clearInterval(interval);
  }, []);

  const loadDashboardData = async () => {
    try {
      setRefreshing(true);
      const result = await adminApi.getAnalyticsDashboard();
      
      if (result.ok && result.data) {
        setData(result.data);
        setError(null);
      } else {
        setError('Failed to load analytics data');
      }
    } catch (err) {
      console.error('Error loading analytics:', err);
      setError(err.message);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  if (loading) {
    return (
      <div className="space-y-4 p-6">
        <div className="h-8 w-48 animate-pulse rounded bg-slate-200" />
        <div className="grid grid-cols-4 gap-4">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-32 animate-pulse rounded bg-slate-100" />
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-4 p-6">
        <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">
          <p className="font-semibold">Error loading analytics</p>
          <p className="mt-1 text-sm">{error}</p>
          <button
            onClick={loadDashboardData}
            className="mt-3 rounded bg-red-600 px-3 py-1 text-sm text-white hover:bg-red-700"
          >
            Retry
          </button>
        </div>
      </div>
    );
  }

  const metrics = data?.system_metrics || {};
  const apps = data?.application_distribution || {};
  const docs = data?.document_status || {};
  const perfs = data?.performance_metrics || {};
  const activity = data?.activity_summary || {};

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-slate-900">Analytics Dashboard</h1>
          <p className="mt-1 text-slate-600">Real-time system metrics and performance overview</p>
        </div>
        <button
          onClick={loadDashboardData}
          disabled={refreshing}
          className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {refreshing ? 'Refreshing...' : 'Refresh'}
        </button>
      </div>

      {/* System Metrics */}
      <div className="space-y-3">
        <h2 className="text-lg font-semibold text-slate-900">System Overview</h2>
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
          <StatsCard label="Total Students" value={metrics.total_students} icon="👥" color="blue" />
          <StatsCard label="Active Applicants" value={metrics.active_applicants} icon="📋" color="yellow" />
          <StatsCard label="Approved" value={metrics.approved_applicants} icon="✅" color="green" />
          <StatsCard label="Total Documents" value={metrics.total_documents} icon="📄" color="purple" />
          <StatsCard label="Pending Documents" value={metrics.pending_documents} icon="⏳" color="red" />
          <StatsCard label="Active Distributions" value={metrics.active_distributions} icon="📦" color="indigo" />
        </div>
      </div>

      {/* Status Distributions */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        {/* Applications Status */}
        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <h3 className="font-semibold text-slate-900">Application Status</h3>
          <div className="mt-4 space-y-2">
            <div className="flex justify-between text-sm">
              <span className="text-slate-600">Applicant</span>
              <span className="font-semibold">{apps.applicant || 0}</span>
            </div>
            <div className="h-2 w-full rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-yellow-500"
                style={{ width: `${apps.applicant ? (apps.applicant / (Object.values(apps).reduce((a, b) => a + b, 0) || 1)) * 100 : 0}%` }}
              />
            </div>

            <div className="mt-3 flex justify-between text-sm">
              <span className="text-slate-600">Approved</span>
              <span className="font-semibold">{apps.approved || 0}</span>
            </div>
            <div className="h-2 w-full rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-green-500"
                style={{ width: `${apps.approved ? (apps.approved / (Object.values(apps).reduce((a, b) => a + b, 0) || 1)) * 100 : 0}%` }}
              />
            </div>

            <div className="mt-3 flex justify-between text-sm">
              <span className="text-slate-600">Rejected</span>
              <span className="font-semibold">{apps.rejected || 0}</span>
            </div>
            <div className="h-2 w-full rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-red-500"
                style={{ width: `${apps.rejected ? (apps.rejected / (Object.values(apps).reduce((a, b) => a + b, 0) || 1)) * 100 : 0}%` }}
              />
            </div>
          </div>
        </div>

        {/* Document Status */}
        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <h3 className="font-semibold text-slate-900">Document Status</h3>
          <div className="mt-4 space-y-2">
            <div className="flex justify-between text-sm">
              <span className="text-slate-600">Pending</span>
              <span className="font-semibold">{docs.pending || 0}</span>
            </div>
            <div className="h-2 w-full rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-orange-500"
                style={{ width: `${docs.pending ? (docs.pending / (Object.values(docs).reduce((a, b) => a + b, 0) || 1)) * 100 : 0}%` }}
              />
            </div>

            <div className="mt-3 flex justify-between text-sm">
              <span className="text-slate-600">Approved</span>
              <span className="font-semibold">{docs.approved || 0}</span>
            </div>
            <div className="h-2 w-full rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-green-500"
                style={{ width: `${docs.approved ? (docs.approved / (Object.values(docs).reduce((a, b) => a + b, 0) || 1)) * 100 : 0}%` }}
              />
            </div>

            <div className="mt-3 flex justify-between text-sm">
              <span className="text-slate-600">Verified</span>
              <span className="font-semibold">{docs.verified || 0}</span>
            </div>
            <div className="h-2 w-full rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-blue-500"
                style={{ width: `${docs.verified ? (docs.verified / (Object.values(docs).reduce((a, b) => a + b, 0) || 1)) * 100 : 0}%` }}
              />
            </div>
          </div>
        </div>

        {/* Activity Today */}
        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <h3 className="font-semibold text-slate-900">Today's Activity</h3>
          <div className="mt-4 space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-slate-600">Student Logins</span>
              <span className="font-semibold">{activity.student_logins_today || 0}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-slate-600">Documents Uploaded</span>
              <span className="font-semibold">{activity.documents_uploaded_today || 0}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-slate-600">Applications Submitted</span>
              <span className="font-semibold">{activity.applications_submitted_today || 0}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-slate-600">Notifications Sent</span>
              <span className="font-semibold">{activity.notifications_sent_today || 0}</span>
            </div>
            <div className="mt-2 border-t pt-2" />
            <div className="flex justify-between">
              <span className="text-slate-600">Open Support Tickets</span>
              <span className="font-semibold">{activity.support_tickets_open || 0}</span>
            </div>
          </div>
        </div>
      </div>

      {/* Time Series Charts */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <ChartPreview
          title="Applications (Last 30 Days)"
          data={data?.timeseries_applications}
          color="#3b82f6"
        />
        <ChartPreview
          title="Documents (Last 30 Days)"
          data={data?.timeseries_documents}
          color="#8b5cf6"
        />
      </div>

      {/* Performance Metrics */}
      <div className="rounded-lg border border-slate-200 bg-white p-4">
        <h3 className="font-semibold text-slate-900">Performance Metrics</h3>
        <div className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
          <div>
            <p className="text-sm text-slate-600">Avg Processing Time</p>
            <p className="mt-1 text-2xl font-bold">{perfs.avg_processing_days || 0}</p>
            <p className="text-xs text-slate-500">days</p>
          </div>
          <div>
            <p className="text-sm text-slate-600">Avg Docs per Student</p>
            <p className="mt-1 text-2xl font-bold">{perfs.avg_documents_per_student || 0}</p>
            <p className="text-xs text-slate-500">documents</p>
          </div>
          <div>
            <p className="text-sm text-slate-600">System Uptime</p>
            <p className="mt-1 text-2xl font-bold">{perfs.system_uptime_percent || 0}%</p>
            <p className="text-xs text-slate-500">operational</p>
          </div>
          <div>
            <p className="text-sm text-slate-600">API Response</p>
            <p className="mt-1 text-2xl font-bold">{perfs.api_response_time_ms || 0}</p>
            <p className="text-xs text-slate-500">milliseconds</p>
          </div>
          <div>
            <p className="text-sm text-slate-600">DB Query Time</p>
            <p className="mt-1 text-2xl font-bold">{perfs.database_query_time_ms || 0}</p>
            <p className="text-xs text-slate-500">milliseconds</p>
          </div>
          <div>
            <p className="text-sm text-slate-600">Page Load</p>
            <p className="mt-1 text-2xl font-bold">{activity.average_page_load_ms || 0}</p>
            <p className="text-xs text-slate-500">milliseconds</p>
          </div>
        </div>
      </div>

      {/* Last Update */}
      <div className="text-xs text-slate-500">
        Last updated: {data?.timestamp ? new Date(data.timestamp).toLocaleString() : 'N/A'}
      </div>
    </div>
  );
}
