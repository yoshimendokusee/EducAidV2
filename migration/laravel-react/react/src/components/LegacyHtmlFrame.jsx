import React, { useEffect, useState } from 'react';
import { fetchLegacyPageHtml } from '../services/legacyClient';

export default function LegacyHtmlFrame({ legacyPath }) {
  const [html, setHtml] = useState('');
  const [status, setStatus] = useState('loading');

  useEffect(() => {
    let active = true;

    fetchLegacyPageHtml(legacyPath)
      .then((data) => {
        if (!active) return;
        setHtml(data);
        setStatus('ready');
      })
      .catch(() => {
        if (!active) return;
        setStatus('error');
      });

    return () => {
      active = false;
    };
  }, [legacyPath]);

  if (status === 'loading') {
    return <div>Loading...</div>;
  }

  if (status === 'error') {
    return <div>Unable to load page.</div>;
  }

  // Rendering exact legacy HTML preserves existing structure/styling/JS behavior.
  return <div dangerouslySetInnerHTML={{ __html: html }} />;
}
