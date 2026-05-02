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
    <nav className="border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
      <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
        <div className="flex items-center gap-6">
          <Link to="/" className="text-2xl font-bold tracking-tight text-blue-600">
            EducAid
          </Link>

          {userType === 'student' && (
            <div className="hidden gap-2 md:flex">
              <Link to="/student/home" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                Dashboard
              </Link>
              <Link to="/student/upload" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                Upload
              </Link>
              <Link to="/student/notifications" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                Notifications
                {notificationCount > 0 && (
                  <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-xs font-semibold text-white">
                    {notificationCount}
                  </span>
                )}
              </Link>
            </div>
          )}

          {userType === 'admin' && (
            <div className="hidden gap-2 md:flex">
              <Link to="/admin/home" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                Dashboard
              </Link>
              <Link to="/admin/applicants" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                Applicants
              </Link>
              <Link to="/admin/distributions" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                Distributions
              </Link>
              <Link to="/admin/search" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                🔍 Search
              </Link>
              <Link to="/admin/reports" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                Reports
              </Link>
              <Link to="/admin/settings" className="rounded-full px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-blue-600">
                Settings
              </Link>
            </div>
          )}
        </div>

        <div className="flex items-center gap-3">
          <span className="hidden text-sm text-slate-600 sm:block">
            {userName || 'Signed in'}
          </span>
          <button
            onClick={handleLogout}
            className="rounded-full bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700"
          >
            Logout
          </button>
        </div>
      </div>
    </nav>
  );
}
