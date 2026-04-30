import React, { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';

export default function DistributionControlPage() {
  const { user } = useAuth();
  const [distributions, setDistributions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [filter, setFilter] = useState('active');
  const [showNewForm, setShowNewForm] = useState(false);
  const [newDist, setNewDist] = useState({
    name: '',
    description: '',
    itemsCount: '',
  });

  // Sample distributions data
  const sampleDistributions = [
    {
      id: 1,
      name: 'April 2026 Scholarship Distribution',
      description: 'Monthly scholarship distribution for eligible students',
      status: 'active',
      itemsCount: 450,
      itemsDistributed: 312,
      percentage: 69,
      startDate: '2026-04-01',
      endDate: '2026-04-30',
      createdBy: 'admin@educaid.gov.ph',
    },
    {
      id: 2,
      name: 'Grade 12 Graduation Assistance',
      description: 'Financial aid for graduating students',
      status: 'active',
      itemsCount: 280,
      itemsDistributed: 280,
      percentage: 100,
      startDate: '2026-03-15',
      endDate: '2026-04-15',
      createdBy: 'admin@educaid.gov.ph',
    },
    {
      id: 3,
      name: 'March 2026 Distribution',
      description: 'Regular monthly distribution',
      status: 'completed',
      itemsCount: 420,
      itemsDistributed: 420,
      percentage: 100,
      startDate: '2026-03-01',
      endDate: '2026-03-31',
      createdBy: 'admin@educaid.gov.ph',
    },
    {
      id: 4,
      name: 'Medical Aid Distribution',
      description: 'Emergency medical assistance for students',
      status: 'pending',
      itemsCount: 150,
      itemsDistributed: 0,
      percentage: 0,
      startDate: '2026-05-01',
      endDate: '2026-05-31',
      createdBy: 'admin@educaid.gov.ph',
    },
  ];

  useEffect(() => {
    loadDistributions();
  }, []);

  const loadDistributions = async () => {
    try {
      setLoading(true);
      setError(null);
      // Simulate API call
      await new Promise(resolve => setTimeout(resolve, 500));
      setDistributions(sampleDistributions);
    } catch (err) {
      setError('Failed to load distributions');
    } finally {
      setLoading(false);
    }
  };

  const handleCreateDistribution = async () => {
    if (!newDist.name || !newDist.itemsCount) {
      setError('Please fill in all required fields');
      return;
    }

    try {
      // Simulate API call
      await new Promise(resolve => setTimeout(resolve, 300));
      const today = new Date().toISOString().split('T')[0];
      const newDistribution = {
        id: Math.max(...distributions.map(d => d.id), 0) + 1,
        name: newDist.name,
        description: newDist.description,
        status: 'pending',
        itemsCount: parseInt(newDist.itemsCount),
        itemsDistributed: 0,
        percentage: 0,
        startDate: today,
        endDate: '',
        createdBy: user?.email || 'admin',
      };
      setDistributions([newDistribution, ...distributions]);
      setNewDist({ name: '', description: '', itemsCount: '' });
      setShowNewForm(false);
    } catch (err) {
      setError('Failed to create distribution');
    }
  };

  const handleStatusChange = async (id, newStatus) => {
    try {
      await new Promise(resolve => setTimeout(resolve, 300));
      setDistributions(prev =>
        prev.map(dist =>
          dist.id === id ? { ...dist, status: newStatus } : dist
        )
      );
    } catch (err) {
      setError('Failed to update distribution');
    }
  };

  const filteredDistributions = distributions.filter(dist =>
    filter === 'all' || dist.status === filter
  );

  const statusBadge = (status) => {
    const styles = {
      pending: 'bg-gray-100 text-gray-800',
      active: 'bg-blue-100 text-blue-800',
      completed: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
    };
    return styles[status] || styles.pending;
  };

  const statusColor = (status) => {
    const colors = {
      pending: 'bg-gray-500',
      active: 'bg-blue-500',
      completed: 'bg-green-500',
      cancelled: 'bg-red-500',
    };
    return colors[status] || colors.pending;
  };

  return (
    <div className="min-h-screen bg-gray-50 py-6">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="mb-8 flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Distribution Control</h1>
            <p className="mt-2 text-gray-600">Manage financial aid distributions</p>
          </div>
          <button
            onClick={() => setShowNewForm(!showNewForm)}
            className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition"
          >
            + New Distribution
          </button>
        </div>

        {/* Error Message */}
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p className="text-red-700">⚠️ {error}</p>
          </div>
        )}

        {/* New Distribution Form */}
        {showNewForm && (
          <div className="bg-white rounded-lg shadow p-6 mb-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Create New Distribution</h2>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Distribution Name *</label>
                <input
                  type="text"
                  value={newDist.name}
                  onChange={e => setNewDist({ ...newDist, name: e.target.value })}
                  placeholder="e.g., May 2026 Scholarship"
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Total Items *</label>
                <input
                  type="number"
                  value={newDist.itemsCount}
                  onChange={e => setNewDist({ ...newDist, itemsCount: e.target.value })}
                  placeholder="e.g., 500"
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Description</label>
                <input
                  type="text"
                  value={newDist.description}
                  onChange={e => setNewDist({ ...newDist, description: e.target.value })}
                  placeholder="Brief description"
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
            </div>
            <div className="flex gap-2">
              <button
                onClick={handleCreateDistribution}
                className="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition"
              >
                Create Distribution
              </button>
              <button
                onClick={() => setShowNewForm(false)}
                className="bg-gray-300 hover:bg-gray-400 text-gray-900 px-6 py-2 rounded-lg transition"
              >
                Cancel
              </button>
            </div>
          </div>
        )}

        {/* Filter */}
        <div className="bg-white rounded-lg shadow p-4 mb-6">
          <div className="flex gap-4">
            {['all', 'pending', 'active', 'completed'].map(status => (
              <button
                key={status}
                onClick={() => setFilter(status)}
                className={`px-4 py-2 rounded-lg font-medium capitalize transition ${
                  filter === status
                    ? 'bg-blue-600 text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                {status}
              </button>
            ))}
          </div>
        </div>

        {/* Loading State */}
        {loading && (
          <div className="flex items-center justify-center p-12 bg-white rounded-lg shadow">
            <div className="text-center">
              <div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
              <p className="mt-4 text-gray-600">Loading distributions...</p>
            </div>
          </div>
        )}

        {/* Distributions List */}
        {!loading && (
          <div className="space-y-6">
            {filteredDistributions.length === 0 ? (
              <div className="bg-white rounded-lg shadow p-8 text-center">
                <p className="text-gray-600">No distributions found</p>
              </div>
            ) : (
              filteredDistributions.map(dist => (
                <div key={dist.id} className="bg-white rounded-lg shadow p-6">
                  <div className="flex items-start justify-between mb-4">
                    <div>
                      <h3 className="text-lg font-semibold text-gray-900">{dist.name}</h3>
                      <p className="text-sm text-gray-600">{dist.description}</p>
                    </div>
                    <span className={`px-3 py-1 rounded-full text-xs font-semibold capitalize ${statusBadge(dist.status)}`}>
                      {dist.status}
                    </span>
                  </div>

                  <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    <div>
                      <p className="text-xs text-gray-600">Total Items</p>
                      <p className="text-xl font-bold text-gray-900">{dist.itemsCount}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-600">Distributed</p>
                      <p className="text-xl font-bold text-blue-600">{dist.itemsDistributed}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-600">Completion</p>
                      <div className="flex items-center gap-2 mt-1">
                        <div className="flex-1 bg-gray-200 rounded h-2 overflow-hidden">
                          <div
                            className={`h-full ${statusColor(dist.status)}`}
                            style={{ width: `${dist.percentage}%` }}
                          ></div>
                        </div>
                        <p className="text-sm font-semibold text-gray-900">{dist.percentage}%</p>
                      </div>
                    </div>
                    <div>
                      <p className="text-xs text-gray-600">Period</p>
                      <p className="text-sm font-medium text-gray-900">
                        {dist.startDate} to {dist.endDate || 'ongoing'}
                      </p>
                    </div>
                  </div>

                  <div className="border-t border-gray-200 pt-4 flex gap-2">
                    {dist.status === 'pending' && (
                      <>
                        <button
                          onClick={() => handleStatusChange(dist.id, 'active')}
                          className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded transition text-sm"
                        >
                          Start Distribution
                        </button>
                        <button
                          onClick={() => handleStatusChange(dist.id, 'cancelled')}
                          className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded transition text-sm"
                        >
                          Cancel
                        </button>
                      </>
                    )}
                    {dist.status === 'active' && (
                      <>
                        <button className="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded transition text-sm">
                          Complete Distribution
                        </button>
                        <button className="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded transition text-sm">
                          View Recipients
                        </button>
                      </>
                    )}
                    {dist.status !== 'pending' && dist.status !== 'active' && (
                      <button className="px-4 py-2 bg-gray-400 text-white rounded cursor-not-allowed text-sm">
                        View Details
                      </button>
                    )}
                  </div>
                </div>
              ))
            )}
          </div>
        )}
      </div>
    </div>
  );
}
