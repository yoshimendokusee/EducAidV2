import React, { createContext, useContext, useState, useEffect } from 'react';

const AuthContext = createContext();

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  // Check if user is already logged in (on app load)
  useEffect(() => {
    const checkAuthStatus = async () => {
      try {
        const response = await fetch('/api/auth/status', {
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
          },
        });
        
        if (response.ok) {
          const data = await response.json();
          if (data.authenticated) {
            setUser({
              id: data.user?.id,
              email: data.user?.email,
              name: data.user?.name,
              type: data.user?.type, // 'student' or 'admin'
            });
          }
        }
      } catch (err) {
        console.error('Auth status check failed:', err);
      } finally {
        setIsLoading(false);
      }
    };

    checkAuthStatus();
  }, []);

  const login = async (email, password, userType = 'student') => {
    setIsLoading(true);
    setError(null);

    try {
      const endpoint = userType === 'admin' 
        ? '/api/auth/admin-login'
        : '/api/auth/student-login';

      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ email, password }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Login failed');
      }

      setUser({
        id: data.user.id,
        email: data.user.email,
        name: data.user.name,
        type: userType,
      });

      return true;
    } catch (err) {
      setError(err.message);
      return false;
    } finally {
      setIsLoading(false);
    }
  };

  const logout = async () => {
    try {
      await fetch('/api/auth/logout', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
        },
      });
    } catch (err) {
      console.error('Logout failed:', err);
    } finally {
      setUser(null);
      setError(null);
    }
  };

  const value = {
    user,
    isLoading,
    error,
    login,
    logout,
    isAuthenticated: !!user,
    isStudent: user?.type === 'student',
    isAdmin: user?.type === 'admin',
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
