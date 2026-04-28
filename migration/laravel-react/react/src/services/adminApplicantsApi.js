async function requestJson(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'include',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      ...(options.headers || {}),
    },
    ...options,
  });

  const data = await response.json().catch(() => ({}));
  return { ok: response.ok, status: response.status, data };
}

export const adminApplicantsApi = {
  getBadgeCount() {
    return requestJson('/api/admin/applicants/badge-count');
  },

  getApplicantDetails(studentId) {
    const query = new URLSearchParams({ student_id: String(studentId) });
    return requestJson(`/api/admin/applicants/details?${query.toString()}`);
  },

  postApplicantAction(formDataObj) {
    const form = new URLSearchParams();
    Object.entries(formDataObj || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null) form.append(k, String(v));
    });

    return requestJson('/api/admin/applicants/actions', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: form.toString(),
    });
  },
};
