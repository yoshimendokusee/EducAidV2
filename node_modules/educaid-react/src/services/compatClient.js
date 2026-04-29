export async function fetchCompatPageHtml(pagePath) {
  const encodedPath = encodeURIComponent(pagePath);
  const response = await fetch(`/compat/render?path=${encodedPath}`, {
    method: 'GET',
    credentials: 'include',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
    },
  });

  if (!response.ok) {
    throw new Error(`Compat page load failed: ${response.status}`);
  }

  return response.text();
}
