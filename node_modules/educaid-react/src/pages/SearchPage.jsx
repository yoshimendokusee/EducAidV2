import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import { adminApi } from '../services/apiClient';
import { AdminPageShell } from '../components/AdminPageShell';
import SearchForm from '../components/SearchForm';
import SearchResults from '../components/SearchResults';

export default function SearchPage() {
  const { user } = useAuth();
  const [entityType, setEntityType] = useState('applicants');
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalResults, setTotalResults] = useState(0);
  const [perPage, setPerPage] = useState(20);
  const [hasSearched, setHasSearched] = useState(false);

  const handleSearch = async (filters) => {
    try {
      setLoading(true);
      setError(null);
      setCurrentPage(1);

      let response;
      switch (entityType) {
        case 'applicants':
          response = await adminApi.searchApplicants(filters);
          break;
        case 'distributions':
          response = await adminApi.searchDistributions(filters);
          break;
        case 'documents':
          response = await adminApi.searchDocuments(filters);
          break;
        default:
          response = await adminApi.searchApplicants(filters);
      }

      if (response.ok) {
        setResults(response.data || []);
        setTotalResults(response.total || 0);
        setPerPage(response.per_page || 20);
        setHasSearched(true);
      } else {
        setError(response.data?.message || 'Search failed');
      }
    } catch (err) {
      setError('An error occurred during search: ' + err.message);
      console.error('Search error:', err);
    } finally {
      setLoading(false);
    }
  };

  const handlePageChange = async (page, filters) => {
    setCurrentPage(page);
    // Refetch with new page number
    const updatedFilters = { ...filters, page };
    handleSearch(updatedFilters);
  };

  const handleReset = () => {
    setResults([]);
    setTotalResults(0);
    setError(null);
    setHasSearched(false);
    setCurrentPage(1);
  };

  const handleSelectItem = (item) => {
    console.log('Selected item:', item);
    // Could open a modal or navigate to detail view
  };

  return (
    <AdminPageShell>
      <div className="max-w-7xl mx-auto px-4 py-8">
        <div className="mb-8">
          <h1 className="text-4xl font-bold text-gray-900 mb-2">Advanced Search</h1>
          <p className="text-gray-600">Search and filter applicants, distributions, and documents</p>
        </div>

        {/* Entity Type Selector */}
        <div className="mb-6 flex gap-2">
          {['applicants', 'distributions', 'documents'].map((type) => (
            <button
              key={type}
              onClick={() => {
                setEntityType(type);
                handleReset();
              }}
              className={`px-4 py-2 rounded-lg font-medium transition ${
                entityType === type
                  ? 'bg-blue-600 text-white'
                  : 'bg-gray-200 text-gray-800 hover:bg-gray-300'
              }`}
            >
              {type.charAt(0).toUpperCase() + type.slice(1)}
            </button>
          ))}
        </div>

        {/* Search Form */}
        <SearchForm
          entityType={entityType}
          onSearch={handleSearch}
          onReset={handleReset}
        />

        {/* Search Results */}
        {hasSearched && (
          <div>
            <div className="mb-4 text-sm text-gray-600">
              {totalResults > 0 && (
                <p>Found <strong>{totalResults}</strong> {entityType}</p>
              )}
            </div>
            <SearchResults
              results={results}
              loading={loading}
              error={error}
              entityType={entityType}
              currentPage={currentPage}
              totalResults={totalResults}
              perPage={perPage}
              onPageChange={(page) => handlePageChange(page, {})}
              onSelectItem={handleSelectItem}
            />
          </div>
        )}

        {/* No search yet */}
        {!hasSearched && !loading && (
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-8 text-center">
            <p className="text-gray-600">Use the search form above to find {entityType}</p>
          </div>
        )}
      </div>
    </AdminPageShell>
  );
}
