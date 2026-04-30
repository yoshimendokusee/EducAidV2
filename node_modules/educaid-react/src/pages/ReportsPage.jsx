import React, { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';

export default function ReportsPage() {
  const { user } = useAuth();
  const [reportType, setReportType] = useState('overview');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [reportData, setReportData] = useState(null);

  // Sample data for demonstration
  const sampleReports = {
    overview: {
      title: 'System Overview Report',
      totalStudents: 1243,
      activeApplications: 287,
      approvedApplications: 956,
      pendingReview: 342,
      documentsPending: 89,
      averageProcessTime: '2.4 days',
    },
    students: {
      title: 'Student Statistics',
      totalEnrolled: 1243,
      newThisMonth: 142,
      byGrade: {
        'Grade 7': 312,
        'Grade 8': 287,
        'Grade 9': 298,
        'Grade 10': 223,
        'Grade 11': 89,
        'Grade 12': 34,
      },
      bySchool: [
        { school: 'Central High School', count: 287 },
        { school: 'North Valley Academy', count: 234 },
        { school: 'South Ridge High', count: 198 },
        { school: 'East Park University', count: 156 },
        { school: 'Others', count: 368 },
      ],
    },
    distributions: {
      title: 'Distribution Report',
      totalDistributions: 12,
      active: 3,
      completed: 8,
      cancelled: 1,
      totalItemsDistributed: 3847,
      averageItemsPerDistribution: 320,
      successRate: '98.5%',
    },
    documents: {
      title: 'Document Processing Report',
      totalProcessed: 3456,
      pendingOCR: 89,
      ocrSuccessRate: '94.2%',
      uploadsPast24h: 156,
      averageUploadTime: '2.3 minutes',
      documentsByType: {
        'Grade Certificate': 1287,
        'Transcript': 987,
        'ID Document': 856,
        'Medical Certificate': 234,
        'Other': 92,
      },
    },
  };

  useEffect(() => {
    loadReport(reportType);
  }, [reportType]);

  const loadReport = async (type) => {
    try {
      setLoading(true);
      setError(null);
      // Simulate API call
      await new Promise(resolve => setTimeout(resolve, 500));
      setReportData(sampleReports[type] || sampleReports.overview);
    } catch (err) {
      setError('Failed to load report');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 py-6">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-gray-900">Reports & Analytics</h1>
          <p className="mt-2 text-gray-600">Generate and view system reports</p>
        </div>

        {/* Report Type Selection */}
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Report Type</h2>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {['overview', 'students', 'distributions', 'documents'].map(type => (
              <button
                key={type}
                onClick={() => setReportType(type)}
                className={`p-4 rounded-lg border-2 transition font-medium capitalize ${
                  reportType === type
                    ? 'border-blue-600 bg-blue-50 text-blue-600'
                    : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300'
                }`}
              >
                {type}
              </button>
            ))}
          </div>
        </div>

        {/* Error Message */}
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p className="text-red-700">⚠️ {error}</p>
          </div>
        )}

        {/* Loading State */}
        {loading && (
          <div className="flex items-center justify-center p-12 bg-white rounded-lg shadow">
            <div className="text-center">
              <div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
              <p className="mt-4 text-gray-600">Loading report...</p>
            </div>
          </div>
        )}

        {/* Report Content */}
        {!loading && reportData && (
          <>
            {/* Report Title */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
              <h2 className="text-2xl font-bold text-gray-900 mb-6">{reportData.title}</h2>

              {/* Overview Report */}
              {reportType === 'overview' && (
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                  <div className="p-4 bg-blue-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Total Students</p>
                    <p className="text-2xl font-bold text-blue-600">{reportData.totalStudents}</p>
                  </div>
                  <div className="p-4 bg-green-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Approved Applications</p>
                    <p className="text-2xl font-bold text-green-600">{reportData.approvedApplications}</p>
                  </div>
                  <div className="p-4 bg-yellow-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Pending Review</p>
                    <p className="text-2xl font-bold text-yellow-600">{reportData.pendingReview}</p>
                  </div>
                  <div className="p-4 bg-red-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Documents Pending</p>
                    <p className="text-2xl font-bold text-red-600">{reportData.documentsPending}</p>
                  </div>
                  <div className="p-4 bg-purple-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Active Applications</p>
                    <p className="text-2xl font-bold text-purple-600">{reportData.activeApplications}</p>
                  </div>
                  <div className="p-4 bg-indigo-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Avg Process Time</p>
                    <p className="text-2xl font-bold text-indigo-600">{reportData.averageProcessTime}</p>
                  </div>
                </div>
              )}

              {/* Students Report */}
              {reportType === 'students' && (
                <div className="space-y-6">
                  <div className="grid grid-cols-3 gap-4">
                    <div className="p-4 bg-blue-50 rounded-lg">
                      <p className="text-gray-600 text-sm">Total Enrolled</p>
                      <p className="text-2xl font-bold text-blue-600">{reportData.totalEnrolled}</p>
                    </div>
                    <div className="p-4 bg-green-50 rounded-lg">
                      <p className="text-gray-600 text-sm">New This Month</p>
                      <p className="text-2xl font-bold text-green-600">{reportData.newThisMonth}</p>
                    </div>
                  </div>
                  <div>
                    <h3 className="font-semibold text-gray-900 mb-3">Students by Grade</h3>
                    <div className="space-y-2">
                      {Object.entries(reportData.byGrade).map(([grade, count]) => (
                        <div key={grade} className="flex items-center">
                          <div className="w-24 text-sm font-medium text-gray-600">{grade}</div>
                          <div className="flex-1 bg-gray-200 rounded h-6 overflow-hidden">
                            <div
                              className="bg-blue-600 h-full"
                              style={{
                                width: `${(count / Math.max(...Object.values(reportData.byGrade))) * 100}%`,
                              }}
                            ></div>
                          </div>
                          <div className="w-12 text-right text-sm font-medium text-gray-600">{count}</div>
                        </div>
                      ))}
                    </div>
                  </div>
                  <div>
                    <h3 className="font-semibold text-gray-900 mb-3">Students by School</h3>
                    <table className="w-full">
                      <tbody>
                        {reportData.bySchool.map((row, idx) => (
                          <tr key={idx} className="border-b border-gray-200">
                            <td className="py-2 text-gray-600">{row.school}</td>
                            <td className="py-2 text-right font-semibold text-gray-900">{row.count}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {/* Distributions Report */}
              {reportType === 'distributions' && (
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                  <div className="p-4 bg-blue-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Total Distributions</p>
                    <p className="text-2xl font-bold text-blue-600">{reportData.totalDistributions}</p>
                  </div>
                  <div className="p-4 bg-green-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Completed</p>
                    <p className="text-2xl font-bold text-green-600">{reportData.completed}</p>
                  </div>
                  <div className="p-4 bg-yellow-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Active</p>
                    <p className="text-2xl font-bold text-yellow-600">{reportData.active}</p>
                  </div>
                  <div className="p-4 bg-purple-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Total Items</p>
                    <p className="text-2xl font-bold text-purple-600">{reportData.totalItemsDistributed}</p>
                  </div>
                  <div className="p-4 bg-indigo-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Avg Items/Dist</p>
                    <p className="text-2xl font-bold text-indigo-600">{reportData.averageItemsPerDistribution}</p>
                  </div>
                  <div className="p-4 bg-emerald-50 rounded-lg">
                    <p className="text-gray-600 text-sm">Success Rate</p>
                    <p className="text-2xl font-bold text-emerald-600">{reportData.successRate}</p>
                  </div>
                </div>
              )}

              {/* Documents Report */}
              {reportType === 'documents' && (
                <div className="space-y-6">
                  <div className="grid grid-cols-3 gap-4">
                    <div className="p-4 bg-blue-50 rounded-lg">
                      <p className="text-gray-600 text-sm">Total Processed</p>
                      <p className="text-2xl font-bold text-blue-600">{reportData.totalProcessed}</p>
                    </div>
                    <div className="p-4 bg-yellow-50 rounded-lg">
                      <p className="text-gray-600 text-sm">Pending OCR</p>
                      <p className="text-2xl font-bold text-yellow-600">{reportData.pendingOCR}</p>
                    </div>
                    <div className="p-4 bg-green-50 rounded-lg">
                      <p className="text-gray-600 text-sm">OCR Success Rate</p>
                      <p className="text-2xl font-bold text-green-600">{reportData.ocrSuccessRate}</p>
                    </div>
                  </div>
                  <div>
                    <h3 className="font-semibold text-gray-900 mb-3">Documents by Type</h3>
                    <div className="space-y-2">
                      {Object.entries(reportData.documentsByType).map(([type, count]) => (
                        <div key={type} className="flex items-center">
                          <div className="w-40 text-sm font-medium text-gray-600">{type}</div>
                          <div className="flex-1 bg-gray-200 rounded h-6 overflow-hidden">
                            <div
                              className="bg-blue-600 h-full"
                              style={{
                                width: `${(count / Math.max(...Object.values(reportData.documentsByType))) * 100}%`,
                              }}
                            ></div>
                          </div>
                          <div className="w-16 text-right text-sm font-medium text-gray-600">{count}</div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Export Options */}
            <div className="bg-white rounded-lg shadow p-6">
              <h3 className="text-lg font-semibold text-gray-900 mb-4">Export Report</h3>
              <div className="flex gap-4">
                <button className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                  📊 Download as PDF
                </button>
                <button className="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg transition">
                  📋 Download as CSV
                </button>
                <button className="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded-lg transition">
                  🖨️ Print Report
                </button>
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
