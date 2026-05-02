import React, { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { adminApi } from '../services/apiClient';
import { AdminLoadingState, AdminPageShell } from '../components/AdminPageShell';

export default function ApplicantsPage() {
  const { user } = useAuth();
  const [applicants, setApplicants] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [filter, setFilter] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedApplicants, setSelectedApplicants] = useState(new Set());

  // Sample applicants data for demonstration
  const sampleApplicants = [
    {
      id: 1,
      name: 'Juan Dela Cruz',
      email: 'juan.delacruz@example.com',
      school: 'Central High School',
      grade: 12,
      status: 'pending',
      submittedDate: '2026-04-28',
      documentsComplete: 8,
      documentsTotal: 10,
    },
    {
      id: 2,
      name: 'Maria Santos',
      email: 'maria.santos@example.com',
      school: 'North Valley Academy',
      grade: 11,
      status: 'approved',
      submittedDate: '2026-04-15',
      documentsComplete: 10,
      documentsTotal: 10,
    },
    {
      id: 3,
      name: 'Carlos Garcia',
      email: 'carlos.garcia@example.com',
      school: 'South Ridge High',
      grade: 10,
      status: 'rejected',
      submittedDate: '2026-04-20',
      documentsComplete: 6,
      documentsTotal: 10,
    },
    {
      id: 4,
      name: 'Ana Reyes',
      email: 'ana.reyes@example.com',
      school: 'East Park University',
      grade: 12,
      status: 'pending',
      submittedDate: '2026-04-27',
      documentsComplete: 9,
      documentsTotal: 10,
    },
    {
      id: 5,
      name: 'Pedro Lim',
      email: 'pedro.lim@example.com',
      school: 'Central High School',
      grade: 11,
      status: 'pending',
      submittedDate: '2026-04-29',
      documentsComplete: 7,
      documentsTotal: 10,
    },
  ];

  useEffect(() => {
    loadApplicants();
  }, []);

  const loadApplicants = async () => {
    try {
      setLoading(true);
      setError(null);
      
      // Try to fetch from API
      const response = await adminApi.getApplicantDetails();
      
      if (response.ok && response.data) {
        // If API returns an array, use it; otherwise use as wrapper
        const data = Array.isArray(response.data) ? response.data : response.data.applicants || [];
        setApplicants(data.length > 0 ? data : sampleApplicants);
      } else {
        // Fall back to sample data if API not implemented
        setApplicants(sampleApplicants);
      }
    } catch (err) {
      console.error('Failed to load applicants:', err);
      setApplicants(sampleApplicants);
    } finally {
      setLoading(false);
    }
  };

  const filteredApplicants = applicants.filter(app => {
    const matchesFilter = filter === 'all' || app.status === filter;
    const matchesSearch = searchTerm === '' ||
      app.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      app.email.toLowerCase().includes(searchTerm.toLowerCase());
    return matchesFilter && matchesSearch;
  });

  const handleApprove = async (id) => {
    try {
      const response = await adminApi.performApplicantAction({
        applicant_id: id,
        action: 'approve',
      });
      
      if (response.ok) {
        setApplicants(prev =>
          prev.map(app =>
            app.id === id ? { ...app, status: 'approved' } : app
          )
        );
      } else {
        setError('Failed to approve applicant');
      }
    } catch (err) {
      console.error('Approve error:', err);
      setError('Failed to approve applicant');
    }
  };

  const handleReject = async (id) => {
    try {
      const response = await adminApi.performApplicantAction({
        applicant_id: id,
        action: 'reject',
      });
      
      if (response.ok) {
        setApplicants(prev =>
          prev.map(app =>
            app.id === id ? { ...app, status: 'rejected' } : app
          )
        );
      } else {
        setError('Failed to reject applicant');
      }
    } catch (err) {
      console.error('Reject error:', err);
      setError('Failed to reject applicant');
    }
  };

  const toggleSelectApplicant = (id) => {
    const newSelected = new Set(selectedApplicants);
    if (newSelected.has(id)) {
      newSelected.delete(id);
    } else {
      newSelected.add(id);
    }
    setSelectedApplicants(newSelected);
  };

  const statusBadge = (status) => {
    const styles = {
      pending: 'bg-yellow-100 text-yellow-800',
      approved: 'bg-green-100 text-green-800',
      rejected: 'bg-red-100 text-red-800',
    };
    return styles[status] || styles.pending;
  };

  if (loading) {
    return <AdminLoadingState label="Loading applicants..." />;
  }

  return (
    <AdminPageShell
      title="Applicants Management"
      subtitle="Review and manage student applications in a single, consistent workspace."
      userLabel={user?.email || 'Administrator'}
    >
      <div className="space-y-6">
        {/* Header */}
        <div>
          <h1 className="text-3xl font-bold text-slate-900">Applicants Management</h1>
          <p className="mt-2 text-slate-600">Review and manage student applications</p>
        </div>

        {/* Error Message */}
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p className="text-red-700">⚠️ {error}</p>
          </div>
        )}

        {/* Controls */}
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Search</label>
              <input
                type="text"
                placeholder="Name or email..."
                value={searchTerm}
                onChange={e => setSearchTerm(e.target.value)}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Filter by Status</label>
              <select
                value={filter}
                onChange={e => setFilter(e.target.value)}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="all">All Applicants</option>
                <option value="pending">Pending Review</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Stats</label>
              <div className="flex gap-2">
                <div className="flex-1 p-2 bg-yellow-50 rounded">
                  <p className="text-xs text-gray-600">Pending</p>
                  <p className="text-lg font-bold text-yellow-600">
                    {applicants.filter(a => a.status === 'pending').length}
                  </p>
                </div>
                <div className="flex-1 p-2 bg-green-50 rounded">
                  <p className="text-xs text-gray-600">Approved</p>
                  <p className="text-lg font-bold text-green-600">
                    {applicants.filter(a => a.status === 'approved').length}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Applicants Table */}
        <div className="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 overflow-hidden">
            {filteredApplicants.length === 0 ? (
              <div className="p-8 text-center text-gray-600">
                <p>No applicants found</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="bg-gray-50 border-b border-gray-200">
                    <tr>
                      <th className="px-6 py-3 text-left">
                        <input
                          type="checkbox"
                          onChange={e =>
                            setSelectedApplicants(
                              e.target.checked
                                ? new Set(filteredApplicants.map(a => a.id))
                                : new Set()
                            )
                          }
                          className="rounded border-gray-300"
                        />
                      </th>
                      <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">Name</th>
                      <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">School</th>
                      <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">Grade</th>
                      <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">Documents</th>
                      <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">Status</th>
                      <th className="px-6 py-3 text-left text-sm font-semibold text-gray-900">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredApplicants.map((app, idx) => (
                      <tr key={app.id} className={idx % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                        <td className="px-6 py-4">
                          <input
                            type="checkbox"
                            checked={selectedApplicants.has(app.id)}
                            onChange={() => toggleSelectApplicant(app.id)}
                            className="rounded border-gray-300"
                          />
                        </td>
                        <td className="px-6 py-4">
                          <div>
                            <p className="font-medium text-gray-900">{app.name}</p>
                            <p className="text-sm text-gray-600">{app.email}</p>
                          </div>
                        </td>
                        <td className="px-6 py-4 text-sm text-gray-700">{app.school}</td>
                        <td className="px-6 py-4 text-sm text-gray-700">{app.grade}</td>
                        <td className="px-6 py-4">
                          <div className="w-20 bg-gray-200 rounded h-2 overflow-hidden">
                            <div
                              className="bg-blue-600 h-full"
                              style={{
                                width: `${(app.documentsComplete / app.documentsTotal) * 100}%`,
                              }}
                            ></div>
                          </div>
                          <p className="text-xs text-gray-600 mt-1">
                            {app.documentsComplete}/{app.documentsTotal}
                          </p>
                        </td>
                        <td className="px-6 py-4">
                          <span className={`px-3 py-1 rounded-full text-xs font-semibold capitalize ${statusBadge(app.status)}`}>
                            {app.status}
                          </span>
                        </td>
                        <td className="px-6 py-4 text-sm space-y-1">
                          {app.status === 'pending' && (
                            <div className="flex gap-2">
                              <button
                                onClick={() => handleApprove(app.id)}
                                className="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded text-xs transition"
                              >
                                ✓ Approve
                              </button>
                              <button
                                onClick={() => handleReject(app.id)}
                                className="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs transition"
                              >
                                ✕ Reject
                              </button>
                            </div>
                          )}
                          {app.status !== 'pending' && (
                            <button className="px-3 py-1 bg-gray-400 text-white rounded text-xs cursor-not-allowed">
                              View
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

        {/* Bulk Actions */}
        {selectedApplicants.size > 0 && (
          <div className="mt-6 bg-white rounded-lg shadow p-4 flex items-center justify-between">
            <p className="text-gray-700">
              <span className="font-semibold">{selectedApplicants.size}</span> applicant(s) selected
            </p>
            <div className="flex gap-2">
              <button className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded transition">
                Approve Selected
              </button>
              <button className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded transition">
                Reject Selected
              </button>
            </div>
          </div>
        )}
      </div>
    </AdminPageShell>
  );
}
