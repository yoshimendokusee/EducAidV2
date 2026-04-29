import React, { useEffect, useState } from 'react';
import { fetchCompatPageHtml } from '../services/compatClient';

export default function CompatHtmlFrame({ pagePath }) {
  const [html, setHtml] = useState('');
  const [status, setStatus] = useState('loading');

  useEffect(() => {
    let active = true;

    fetchCompatPageHtml(pagePath)
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
  }, [pagePath]);

  if (status === 'loading') {
    return <div>Loading...</div>;
  }

  if (status === 'error') {
    return <div>Unable to load page.</div>;
  }

  return <div dangerouslySetInnerHTML={{ __html: html }} />;
}
