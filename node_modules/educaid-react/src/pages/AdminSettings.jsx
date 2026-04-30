import React, { useState } from 'react';
import { useAuth } from '../context/AuthContext';

export default function AdminSettings() {
  const { user } = useAuth();
  const [settings, setSettings] = useState({
    email_notifications: true,
    sms_notifications: false,
    maintenance_mode: false,
    auto_approve: false,
  });
  const [saved, setSaved] = useState(false);

  const handleChange = (key) => {
    setSettings(prev => ({
      ...prev,
      [key]: !prev[key],
    }));
    setSaved(false);
  };

  const handleSave = async () => {
    // In production, this would call an API endpoint
    try {
      // Simulate API call
      await new Promise(resolve => setTimeout(resolve, 500));
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
    } catch (err) {
      console.error('Failed to save settings:', err);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 py-6">
      <div className="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-gray-900">Admin Settings</h1>
          <p className="mt-2 text-gray-600">Manage your admin account settings and system preferences</p>
        </div>

        {/* Account Information */}
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-xl font-bold text-gray-900 mb-4">Account Information</h2>
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Email</label>
              <div className="mt-1 p-3 bg-gray-50 rounded border border-gray-200">
                {user?.email || 'Not provided'}
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Role</label>
              <div className="mt-1 p-3 bg-gray-50 rounded border border-gray-200">
                Administrator
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Last Login</label>
              <div className="mt-1 p-3 bg-gray-50 rounded border border-gray-200">
                Today at {new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              </div>
            </div>
          </div>
        </div>

        {/* Notification Settings */}
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-xl font-bold text-gray-900 mb-4">Notification Settings</h2>
          <div className="space-y-4">
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={settings.email_notifications}
                onChange={() => handleChange('email_notifications')}
                className="rounded border-gray-300 text-blue-600"
              />
              <span className="ml-3 text-gray-700">
                <span className="font-medium">Email Notifications</span>
                <p className="text-sm text-gray-500">Receive email alerts for pending applicants</p>
              </span>
            </label>

            <label className="flex items-center">
              <input
                type="checkbox"
                checked={settings.sms_notifications}
                onChange={() => handleChange('sms_notifications')}
                className="rounded border-gray-300 text-blue-600"
              />
              <span className="ml-3 text-gray-700">
                <span className="font-medium">SMS Notifications</span>
                <p className="text-sm text-gray-500">Receive SMS alerts for urgent issues</p>
              </span>
            </label>
          </div>
        </div>

        {/* System Settings */}
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-xl font-bold text-gray-900 mb-4">System Settings</h2>
          <div className="space-y-4">
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={settings.maintenance_mode}
                onChange={() => handleChange('maintenance_mode')}
                className="rounded border-gray-300 text-blue-600"
              />
              <span className="ml-3 text-gray-700">
                <span className="font-medium">Maintenance Mode</span>
                <p className="text-sm text-gray-500">Disable student access for maintenance (admins only)</p>
              </span>
            </label>

            <label className="flex items-center">
              <input
                type="checkbox"
                checked={settings.auto_approve}
                onChange={() => handleChange('auto_approve')}
                className="rounded border-gray-300 text-blue-600"
              />
              <span className="ml-3 text-gray-700">
                <span className="font-medium">Auto-Approve Applications</span>
                <p className="text-sm text-gray-500">Automatically approve all applications (use with caution)</p>
              </span>
            </label>
          </div>
        </div>

        {/* Danger Zone */}
        <div className="bg-white rounded-lg shadow p-6 mb-6 border border-red-200">
          <h2 className="text-xl font-bold text-red-600 mb-4">Danger Zone</h2>
          <div className="space-y-4">
            <button className="w-full text-left p-4 border border-red-200 rounded hover:bg-red-50 transition">
              <div className="font-semibold text-red-600">Reset System Data</div>
              <div className="text-sm text-gray-600 mt-1">Clear all temporary files and cache</div>
            </button>
            <button className="w-full text-left p-4 border border-red-200 rounded hover:bg-red-50 transition">
              <div className="font-semibold text-red-600">Backup System</div>
              <div className="text-sm text-gray-600 mt-1">Download a complete system backup</div>
            </button>
          </div>
        </div>

        {/* Success Message */}
        {saved && (
          <div className="fixed bottom-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg">
            ✓ Settings saved successfully
          </div>
        )}

        {/* Save Button */}
        <div className="flex gap-4">
          <button
            onClick={handleSave}
            className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition"
          >
            Save Settings
          </button>
          <a
            href="/admin/home"
            className="bg-gray-200 hover:bg-gray-300 text-gray-900 px-6 py-2 rounded-lg transition"
          >
            Cancel
          </a>
        </div>
      </div>
    </div>
  );
}
