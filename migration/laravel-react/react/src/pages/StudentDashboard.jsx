import React, { useEffect, useState } from 'react';
import { studentApi, documentApi, workflowApi } from '../services/apiClient';

export default function StudentDashboard() {
  const [student, setStudent] = useState(null);
  const [documents, setDocuments] = useState([]);
  const [notifications, setNotifications] = useState(0);
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);

  useEffect(() => {
    const loadData = async () => {
      try {
        // Load notification count
        const notifResult = await studentApi.getNotificationCount();
        if (notifResult.ok) {
          setNotifications(notifResult.data.count || 0);
        }

        // Load student documents
        const docsResult = await documentApi.getStudentDocuments(
          sessionStorage.getItem('student_id')
        );
        if (docsResult.ok) {
          setDocuments(docsResult.data.documents || []);
        }

        // Load workflow status for context
        const workflowResult = await workflowApi.getStatus();
        if (workflowResult.ok) {
          setStudent({
            name: sessionStorage.getItem('student_name'),
            status: workflowResult.data.status,
          });
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
    return <div className="p-8">Loading dashboard...</div>;
  }

  if (status === 'error') {
    return <div className="p-8 text-red-600">Error: {error}</div>;
  }

  return (
    <div className="container mx-auto p-6">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {/* Welcome Card */}
        <div className="col-span-1 md:col-span-3 bg-white rounded-lg shadow p-6">
          <h1 className="text-3xl font-bold">Welcome, {student?.name}</h1>
          <p className="text-gray-600 mt-2">
            Current Status: <span className="font-semibold">{student?.status}</span>
          </p>
        </div>

        {/* Quick Stats */}
        <div className="bg-blue-50 rounded-lg shadow p-6">
          <h2 className="text-xl font-semibold text-blue-900">Documents Uploaded</h2>
          <p className="text-4xl font-bold text-blue-600 mt-2">{documents.length}</p>
        </div>

        <div className="bg-green-50 rounded-lg shadow p-6">
          <h2 className="text-xl font-semibold text-green-900">Notifications</h2>
          <p className="text-4xl font-bold text-green-600 mt-2">{notifications}</p>
        </div>

        <div className="bg-purple-50 rounded-lg shadow p-6">
          <h2 className="text-xl font-semibold text-purple-900">Account Status</h2>
          <p className="text-lg text-purple-600 mt-2">Active</p>
        </div>

        {/* Documents Section */}
        <div className="col-span-1 md:col-span-3 bg-white rounded-lg shadow p-6">
          <h2 className="text-2xl font-bold mb-4">Your Documents</h2>
          {documents.length > 0 ? (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {documents.map((doc) => (
                <div key={doc.id} className="border rounded p-4 hover:bg-gray-50">
                  <h3 className="font-semibold">{doc.type}</h3>
                  <p className="text-sm text-gray-600">Status: {doc.status}</p>
                  {doc.uploaded_at && (
                    <p className="text-xs text-gray-500 mt-2">
                      Uploaded: {new Date(doc.uploaded_at).toLocaleDateString()}
                    </p>
                  )}
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-600">No documents uploaded yet.</p>
          )}
        </div>

        {/* Action Buttons */}
        <div className="col-span-1 md:col-span-3 flex gap-4">
          <a
            href="/student/upload"
            className="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700"
          >
            Upload Document
          </a>
          <a
            href="/student/notifications"
            className="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700"
          >
            View Notifications ({notifications})
          </a>
          <a
            href="/student/settings"
            className="bg-gray-600 text-white px-6 py-2 rounded hover:bg-gray-700"
          >
            Settings
          </a>
        </div>
      </div>
    </div>
  );
}
