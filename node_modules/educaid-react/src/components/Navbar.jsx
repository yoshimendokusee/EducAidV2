import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { studentApi } from '../services/apiClient';

export default function Navbar() {
  const [notificationCount, setNotificationCount] = useState(0);
  const [userType] = useState(sessionStorage.getItem('user_type') || 'student');
  const userName = sessionStorage.getItem('user_name');

  useEffect(() => {
    // Refresh notification count every 30 seconds
    const interval = setInterval(async () => {
      if (userType === 'student') {
        const result = await studentApi.getNotificationCount();
        if (result.ok) {
          setNotificationCount(result.data.count || 0);
        }
      }
    }, 30000);

    return () => clearInterval(interval);
  }, [userType]);

  const handleLogout = () => {
    sessionStorage.clear();
    window.location.href = '/login';
  };

  return (
    <nav className="bg-white shadow-md">
      <div className="container mx-auto px-4 py-4 flex justify-between items-center">
        <div className="flex items-center gap-6">
          <Link to="/" className="text-2xl font-bold text-blue-600">
            EducAid
          </Link>

          {userType === 'student' && (
            <div className="hidden md:flex gap-4">
              <Link to="/student/home" className="text-gray-600 hover:text-blue-600">
                Dashboard
              </Link>
              <Link to="/student/upload" className="text-gray-600 hover:text-blue-600">
                Upload
              </Link>
              <Link to="/student/notifications" className="text-gray-600 hover:text-blue-600">
                Notifications
                {notificationCount > 0 && (
                  <span className="ml-1 bg-red-600 text-white text-xs rounded-full w-5 h-5 inline-flex items-center justify-center">
                    {notificationCount}
                  </span>
                )}
              </Link>
            </div>
          )}

          {userType === 'admin' && (
            <div className="hidden md:flex gap-4">
              <Link to="/admin/home" className="text-gray-600 hover:text-blue-600">
                Dashboard
              </Link>
              <Link to="/admin/applicants" className="text-gray-600 hover:text-blue-600">
                Applicants
              </Link>
              <Link to="/admin/distributions" className="text-gray-600 hover:text-blue-600">
                Distributions
              </Link>
            </div>
          )}
        </div>

        {/* Right side: User menu */}
        <div className="flex items-center gap-4">
          <span className="text-gray-600 text-sm">
            {userName}
          </span>
          <button
            onClick={handleLogout}
            className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
          >
            Logout
          </button>
        </div>
      </div>
    </nav>
  );
}
