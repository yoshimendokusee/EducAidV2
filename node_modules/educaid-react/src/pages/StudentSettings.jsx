import React, { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { studentApi } from '../services/apiClient';

export default function StudentSettings() {
  const { user } = useAuth();
  const [activeTab, setActiveTab] = useState('profile');
  const [loading, setLoading] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(null);

  // Profile settings
  const [profile, setProfile] = useState({
    first_name: '',
    last_name: '',
    phone: '',
  });

  // Password settings
  const [password, setPassword] = useState({
    current: '',
    new: '',
    confirm: '',
  });

  // Notification preferences
  const [notifications, setNotifications] = useState({
    email_notifications: true,
    document_reminders: true,
    status_updates: true,
    system_announcements: true,
  });

  // Privacy settings
  const [privacy, setPrivacy] = useState({
    profile_visible: false,
    data_collection: true,
    analytics: true,
  });

  useEffect(() => {
    loadPreferences();
  }, []);

  const loadPreferences = async () => {
    try {
      const result = await studentApi.getNotificationPreferences();
      if (result.ok) {
        setNotifications(result.data);
      }

      const privacyResult = await studentApi.getPrivacySettings();
      if (privacyResult.ok) {
        setPrivacy(privacyResult.data);
      }
    } catch (err) {
      console.error('Error loading preferences:', err);
    }
  };

  const handleSaveProfile = async () => {
    setLoading(true);
    setError(null);
    try {
      // In production, this would call an API endpoint
      await new Promise(resolve => setTimeout(resolve, 500));
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
    } catch (err) {
      setError('Failed to save profile');
    }
    setLoading(false);
  };

  const handleSavePassword = async () => {
    if (password.new !== password.confirm) {
      setError('Passwords do not match');
      return;
    }
    if (password.new.length < 8) {
      setError('Password must be at least 8 characters');
      return;
    }

    setLoading(true);
    setError(null);
    try {
      // In production, this would call an API endpoint
      await new Promise(resolve => setTimeout(resolve, 500));
      setPassword({ current: '', new: '', confirm: '' });
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
    } catch (err) {
      setError('Failed to update password');
    }
    setLoading(false);
  };

  const handleSaveNotifications = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await studentApi.saveNotificationPreferences(notifications);
      if (result.ok) {
        setSaved(true);
        setTimeout(() => setSaved(false), 3000);
      } else {
        setError('Failed to save notification preferences');
      }
    } catch (err) {
      setError('Failed to save notification preferences');
    }
    setLoading(false);
  };

  const handleSavePrivacy = async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await studentApi.savePrivacySettings(privacy);
      if (result.ok) {
        setSaved(true);
        setTimeout(() => setSaved(false), 3000);
      } else {
        setError('Failed to save privacy settings');
      }
    } catch (err) {
      setError('Failed to save privacy settings');
    }
    setLoading(false);
  };

  return (
    <div className="min-h-screen bg-gray-50 py-6">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-gray-900">Account Settings</h1>
          <p className="mt-2 text-gray-600">Manage your account, privacy, and notification preferences</p>
        </div>

        {/* Tabs */}
        <div className="bg-white rounded-lg shadow mb-6">
          <div className="flex border-b border-gray-200">
            {['profile', 'password', 'notifications', 'privacy'].map(tab => (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`flex-1 px-4 py-3 font-medium text-center capitalize transition ${
                  activeTab === tab
                    ? 'text-blue-600 border-b-2 border-blue-600'
                    : 'text-gray-600 hover:text-gray-900'
                }`}
              >
                {tab}
              </button>
            ))}
          </div>

          <div className="p-6">
            {error && (
              <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                <p className="text-red-700">{error}</p>
              </div>
            )}

            {/* Profile Tab */}
            {activeTab === 'profile' && (
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Email</label>
                  <input
                    type="email"
                    value={user?.email || ''}
                    disabled
                    className="mt-1 w-full p-2 border border-gray-300 rounded-lg bg-gray-50"
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">First Name</label>
                    <input
                      type="text"
                      value={profile.first_name}
                      onChange={e => setProfile({ ...profile, first_name: e.target.value })}
                      className="mt-1 w-full p-2 border border-gray-300 rounded-lg"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Last Name</label>
                    <input
                      type="text"
                      value={profile.last_name}
                      onChange={e => setProfile({ ...profile, last_name: e.target.value })}
                      className="mt-1 w-full p-2 border border-gray-300 rounded-lg"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Phone Number</label>
                  <input
                    type="tel"
                    value={profile.phone}
                    onChange={e => setProfile({ ...profile, phone: e.target.value })}
                    className="mt-1 w-full p-2 border border-gray-300 rounded-lg"
                  />
                </div>
                <button
                  onClick={handleSaveProfile}
                  disabled={loading}
                  className="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-6 py-2 rounded-lg transition"
                >
                  {loading ? 'Saving...' : 'Save Profile'}
                </button>
              </div>
            )}

            {/* Password Tab */}
            {activeTab === 'password' && (
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Current Password</label>
                  <input
                    type="password"
                    value={password.current}
                    onChange={e => setPassword({ ...password, current: e.target.value })}
                    className="mt-1 w-full p-2 border border-gray-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">New Password</label>
                  <input
                    type="password"
                    value={password.new}
                    onChange={e => setPassword({ ...password, new: e.target.value })}
                    className="mt-1 w-full p-2 border border-gray-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Confirm Password</label>
                  <input
                    type="password"
                    value={password.confirm}
                    onChange={e => setPassword({ ...password, confirm: e.target.value })}
                    className="mt-1 w-full p-2 border border-gray-300 rounded-lg"
                  />
                </div>
                <p className="text-sm text-gray-500">Password must be at least 8 characters long</p>
                <button
                  onClick={handleSavePassword}
                  disabled={loading}
                  className="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-6 py-2 rounded-lg transition"
                >
                  {loading ? 'Updating...' : 'Update Password'}
                </button>
              </div>
            )}

            {/* Notifications Tab */}
            {activeTab === 'notifications' && (
              <div className="space-y-4">
                <label className="flex items-center">
                  <input
                    type="checkbox"
                    checked={notifications.email_notifications}
                    onChange={e =>
                      setNotifications({ ...notifications, email_notifications: e.target.checked })
                    }
                    className="rounded border-gray-300 text-blue-600"
                  />
                  <span className="ml-3 text-gray-700">
                    <span className="font-medium">Email Notifications</span>
                    <p className="text-sm text-gray-500">Receive email updates about your applications</p>
                  </span>
                </label>

                <label className="flex items-center">
                  <input
                    type="checkbox"
                    checked={notifications.document_reminders}
                    onChange={e =>
                      setNotifications({ ...notifications, document_reminders: e.target.checked })
                    }
                    className="rounded border-gray-300 text-blue-600"
                  />
                  <span className="ml-3 text-gray-700">
                    <span className="font-medium">Document Reminders</span>
                    <p className="text-sm text-gray-500">Get reminders to upload missing documents</p>
                  </span>
                </label>

                <label className="flex items-center">
                  <input
                    type="checkbox"
                    checked={notifications.status_updates}
                    onChange={e =>
                      setNotifications({ ...notifications, status_updates: e.target.checked })
                    }
                    className="rounded border-gray-300 text-blue-600"
                  />
                  <span className="ml-3 text-gray-700">
                    <span className="font-medium">Status Updates</span>
                    <p className="text-sm text-gray-500">Notifications when your application status changes</p>
                  </span>
                </label>

                <label className="flex items-center">
                  <input
                    type="checkbox"
                    checked={notifications.system_announcements}
                    onChange={e =>
                      setNotifications({ ...notifications, system_announcements: e.target.checked })
                    }
                    className="rounded border-gray-300 text-blue-600"
                  />
                  <span className="ml-3 text-gray-700">
                    <span className="font-medium">System Announcements</span>
                    <p className="text-sm text-gray-500">Important system-wide announcements</p>
                  </span>
                </label>

                <button
                  onClick={handleSaveNotifications}
                  disabled={loading}
                  className="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-6 py-2 rounded-lg transition"
                >
                  {loading ? 'Saving...' : 'Save Preferences'}
                </button>
              </div>
            )}

            {/* Privacy Tab */}
            {activeTab === 'privacy' && (
              <div className="space-y-4">
                <label className="flex items-center">
                  <input
                    type="checkbox"
                    checked={privacy.profile_visible}
                    onChange={e => setPrivacy({ ...privacy, profile_visible: e.target.checked })}
                    className="rounded border-gray-300 text-blue-600"
                  />
                  <span className="ml-3 text-gray-700">
                    <span className="font-medium">Public Profile</span>
                    <p className="text-sm text-gray-500">Allow others to view your profile information</p>
                  </span>
                </label>

                <label className="flex items-center">
                  <input
                    type="checkbox"
                    checked={privacy.data_collection}
                    onChange={e => setPrivacy({ ...privacy, data_collection: e.target.checked })}
                    className="rounded border-gray-300 text-blue-600"
                  />
                  <span className="ml-3 text-gray-700">
                    <span className="font-medium">Allow Data Collection</span>
                    <p className="text-sm text-gray-500">Help improve the platform by sharing usage data</p>
                  </span>
                </label>

                <label className="flex items-center">
                  <input
                    type="checkbox"
                    checked={privacy.analytics}
                    onChange={e => setPrivacy({ ...privacy, analytics: e.target.checked })}
                    className="rounded border-gray-300 text-blue-600"
                  />
                  <span className="ml-3 text-gray-700">
                    <span className="font-medium">Analytics</span>
                    <p className="text-sm text-gray-500">Allow analytics to track your activity</p>
                  </span>
                </label>

                <button
                  onClick={handleSavePrivacy}
                  disabled={loading}
                  className="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-6 py-2 rounded-lg transition"
                >
                  {loading ? 'Saving...' : 'Save Privacy Settings'}
                </button>
              </div>
            )}
          </div>
        </div>

        {/* Success Message */}
        {saved && (
          <div className="fixed bottom-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg">
            ✓ Settings saved successfully
          </div>
        )}

        {/* Back Button */}
        <a href="/student/home" className="text-blue-600 hover:text-blue-700">
          ← Back to Dashboard
        </a>
      </div>
    </div>
  );
}
