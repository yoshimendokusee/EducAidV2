import React, { useEffect, useState } from 'react';
import { studentApi } from '../services/apiClient';

export default function StudentNotifications() {
  const [notifications, setNotifications] = useState([]);
  const [preferences, setPreferences] = useState(null);
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      // Load preferences
      const prefResult = await studentApi.getNotificationPreferences();
      if (prefResult.ok) {
        setPreferences(prefResult.data);
      }

      setStatus('ready');
    } catch (err) {
      setError(err.message);
      setStatus('error');
    }
  };

  const handleTogglePreference = async (key) => {
    setSaving(true);
    try {
      const updated = {
        ...preferences,
        [key]: !preferences[key],
      };

      const result = await studentApi.saveNotificationPreferences(updated);
      if (result.ok) {
        setPreferences(updated);
      } else {
        setError(result.data.message || 'Failed to save preferences');
      }
    } catch (err) {
      setError(err.message);
    }
    setSaving(false);
  };

  if (status === 'loading') {
    return <div className="p-8">Loading notifications...</div>;
  }

  if (status === 'error') {
    return <div className="p-8 text-red-600">Error: {error}</div>;
  }

  const notificationOptions = [
    {
      key: 'email_announcements',
      label: 'Email Announcements',
      description: 'Receive important updates via email',
    },
    {
      key: 'email_documents',
      label: 'Document Updates',
      description: 'Get notified when your document status changes',
    },
    {
      key: 'email_approval',
      label: 'Approval Notifications',
      description: 'Receive notification when your application is approved',
    },
    {
      key: 'sms_alerts',
      label: 'SMS Alerts',
      description: 'Receive critical alerts via SMS',
    },
    {
      key: 'push_notifications',
      label: 'Push Notifications',
      description: 'Browser push notifications for important events',
    },
  ];

  return (
    <div className="container mx-auto p-6 max-w-2xl">
      <div className="bg-white rounded-lg shadow p-6">
        <h1 className="text-3xl font-bold mb-2">Notification Preferences</h1>
        <p className="text-gray-600 mb-6">
          Customize how you receive notifications from EducAid.
        </p>

        {error && (
          <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded">
            <p className="text-red-800">{error}</p>
          </div>
        )}

        <div className="space-y-4">
          {notificationOptions.map((option) => (
            <div key={option.key} className="border rounded-lg p-4 flex items-center justify-between">
              <div>
                <h3 className="font-semibold">{option.label}</h3>
                <p className="text-sm text-gray-600">{option.description}</p>
              </div>

              <label className="relative inline-flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={preferences?.[option.key] || false}
                  onChange={() => handleTogglePreference(option.key)}
                  disabled={saving}
                  className="sr-only peer"
                />
                <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600" />
              </label>
            </div>
          ))}
        </div>

        {/* Contact Information Section */}
        <div className="mt-8 pt-6 border-t">
          <h2 className="text-xl font-bold mb-4">Contact Information</h2>
          <div className="space-y-3">
            <div>
              <p className="text-sm text-gray-600">Email Address</p>
              <p className="font-semibold">{sessionStorage.getItem('student_email')}</p>
            </div>
            <div>
              <p className="text-sm text-gray-600">Phone Number</p>
              <p className="font-semibold">{sessionStorage.getItem('student_phone') || 'Not provided'}</p>
            </div>
          </div>
        </div>

        {/* Save confirmation */}
        <div className="mt-6 p-4 bg-blue-50 border border-blue-200 rounded">
          <p className="text-blue-800 text-sm">
            Your notification preferences are saved automatically.
          </p>
        </div>
      </div>
    </div>
  );
}
