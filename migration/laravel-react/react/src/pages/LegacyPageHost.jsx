import React from 'react';
import LegacyHtmlFrame from '../components/LegacyHtmlFrame';

export default function LegacyPageHost({ legacyPath }) {
  return <LegacyHtmlFrame legacyPath={legacyPath} />;
}
