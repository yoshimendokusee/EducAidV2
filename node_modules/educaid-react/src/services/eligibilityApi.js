export async function subjectCheckWithJson(payload) {
  const response = await fetch('/api/eligibility/subject-check.php', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify(payload),
  });

  const data = await response.json().catch(() => ({}));
  return { ok: response.ok, status: response.status, data };
}

export async function subjectCheckWithFile(file, universityKey) {
  const form = new FormData();
  form.append('gradeDocument', file);
  form.append('universityKey', universityKey);

  const response = await fetch('/api/eligibility/subject-check.php', {
    method: 'POST',
    credentials: 'include',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: form,
  });

  const data = await response.json().catch(() => ({}));
  return { ok: response.ok, status: response.status, data };
}
