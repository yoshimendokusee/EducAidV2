import React, { useEffect, useState } from 'react';
import { fetchWorkflowStatus } from '../services/workflowApi';

// Behavior-preserving gate for routes/actions driven by legacy workflow flags.
export default function WorkflowStatusGate({ flag, fallback = null, children }) {
  const [status, setStatus] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    fetchWorkflowStatus()
      .then((payload) => {
        if (!active) return;
        setStatus(payload?.data || null);
      })
      .catch(() => {
        if (!active) return;
        setStatus(null);
      })
      .finally(() => {
        if (!active) return;
        setLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  if (loading) return null;

  const allowed = Boolean(status && status[flag]);
  return allowed ? children : fallback;
}
