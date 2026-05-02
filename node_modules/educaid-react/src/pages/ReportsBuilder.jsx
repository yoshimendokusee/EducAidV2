import React, { useState } from 'react';
import { AdminPageShell } from '../components/AdminPageShell';
import { reportApi } from '../services/apiClient';

export default function ReportsBuilder() {
  const [filters, setFilters] = useState({ date_from: '', date_to: '', municipality: '' });
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);

  const handleChange = (e) => setFilters(prev => ({ ...prev, [e.target.name]: e.target.value }));

  const handleGenerate = async () => {
    try {
      setLoading(true);
      setError(null);
      const resp = await reportApi.generateReport(filters);
      if (resp.ok) {
        setResult(resp.data);
      } else {
        setError(resp.data?.message || 'Failed to generate report');
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleExportCsv = async () => {
    try {
      setLoading(true);
      setError(null);
      const resp = await reportApi.exportCsv(filters);
      if (!resp.ok) {
        setError('Export CSV failed');
        return;
      }

      const url = URL.createObjectURL(resp.blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = resp.filename || `report_${new Date().toISOString()}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleExportPdf = async () => {
    try {
      setLoading(true);
      setError(null);
      const resp = await reportApi.exportPdf(filters);
      if (!resp.ok) {
        setError('Export PDF failed');
        return;
      }

      const url = URL.createObjectURL(resp.blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = resp.filename || `report_${new Date().toISOString()}.pdf`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <AdminPageShell>
      <div className="max-w-4xl mx-auto p-6">
        <h1 className="text-2xl font-bold mb-4">Reports Builder</h1>

        <div className="bg-white p-6 rounded shadow mb-6">
          <div className="grid grid-cols-2 gap-4 mb-4">
            <div>
              <label className="block text-sm text-gray-700">Date From</label>
              <input type="date" name="date_from" value={filters.date_from} onChange={handleChange} className="w-full border rounded px-3 py-2" />
            </div>
            <div>
              <label className="block text-sm text-gray-700">Date To</label>
              <input type="date" name="date_to" value={filters.date_to} onChange={handleChange} className="w-full border rounded px-3 py-2" />
            </div>
          </div>

          <div className="flex gap-2">
            <button onClick={handleGenerate} className="bg-blue-600 text-white px-4 py-2 rounded">Generate</button>
            <button onClick={handleExportCsv} className="bg-green-600 text-white px-4 py-2 rounded">Export CSV</button>
            <button onClick={handleExportPdf} className="bg-rose-600 text-white px-4 py-2 rounded">Export PDF</button>
          </div>
        </div>

        {loading && <div className="text-gray-600">Working...</div>}
        {error && <div className="text-red-600">{error}</div>}
        {result && (
          <div className="bg-white p-6 rounded shadow">
            <pre className="whitespace-pre-wrap text-sm">{JSON.stringify(result, null, 2)}</pre>
          </div>
        )}
      </div>
    </AdminPageShell>
  );
}
