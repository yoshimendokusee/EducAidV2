export async function fetchLegacyPageHtml(legacyPath) {
  const encodedPath = encodeURIComponent(legacyPath);
  const response = await fetch(`/legacy/render?path=${encodedPath}`, {
    method: 'GET',
    credentials: 'include',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
    },
  });

  if (!response.ok) {
    throw new Error(`Legacy page load failed: ${response.status}`);
  }

  return response.text();
}
