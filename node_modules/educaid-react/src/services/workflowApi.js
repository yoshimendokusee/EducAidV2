export async function fetchWorkflowStatus() {
  const response = await fetch('/api/workflow/status', {
    credentials: 'include',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });

  if (!response.ok) {
    throw new Error(`Workflow status failed: ${response.status}`);
  }

  return response.json();
}

export async function fetchWorkflowStudentCounts() {
  const response = await fetch('/api/workflow/student-counts', {
    credentials: 'include',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });

  if (!response.ok) {
    throw new Error(`Workflow counts failed: ${response.status}`);
  }

  return response.json();
}
