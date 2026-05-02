import React, { useState, useEffect } from 'react';
import { adminApi } from '../services/apiClient';

export default function SearchForm({ 
  entityType = 'applicants', 
  onSearch, 
  onReset,
  filterOptions = {} 
}) {
  const [filters, setFilters] = useState({
    search: '',
    status: '',
    municipality: '',
    year_level: '',
    date_from: '',
    date_to: '',
    sort_by: 'created_at',
    sort_order: 'desc',
    page: 1,
    per_page: 20,
  });

  const [options, setOptions] = useState({
    statuses: [],
    municipalities: [],
    year_levels: [],
    document_types: [],
  });

  useEffect(() => {
    loadFilterOptions();
  }, [entityType]);

  const loadFilterOptions = async () => {
    try {
      const response = await adminApi.getSearchFilterOptions(entityType);
      if (response.ok && response.data) {
        setOptions(response.data);
      }
    } catch (err) {
      console.error('Failed to load filter options:', err);
    }
  };

  const handleFilterChange = (e) => {
    const { name, value } = e.target;
    setFilters(prev => ({
      ...prev,
      [name]: value,
      page: 1, // Reset to page 1 on filter change
    }));
  };

  const handleSearch = (e) => {
    e.preventDefault();
    onSearch(filters);
  };

  const handleReset = () => {
    const resetFilters = {
      search: '',
      status: '',
      municipality: '',
      year_level: '',
      date_from: '',
      date_to: '',
      document_type: '',
      sort_by: 'created_at',
      sort_order: 'desc',
      page: 1,
      per_page: 20,
    };
    setFilters(resetFilters);
    if (onReset) onReset();
  };

  return (
    <div className="bg-white rounded-lg shadow-md p-6 mb-6">
      <h2 className="text-2xl font-bold mb-4 text-gray-800">
        Search {entityType.charAt(0).toUpperCase() + entityType.slice(1)}
      </h2>
      
      <form onSubmit={handleSearch} className="space-y-4">
        {/* Search Text */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            Search
          </label>
          <input
            type="text"
            name="search"
            value={filters.search}
            onChange={handleFilterChange}
            placeholder={`Search ${entityType}...`}
            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          />
        </div>

        {/* Status Filter */}
        {options.statuses && options.statuses.length > 0 && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Status
            </label>
            <select
              name="status"
              value={filters.status}
              onChange={handleFilterChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="">All Statuses</option>
              {options.statuses.map(status => (
                <option key={status} value={status}>
                  {status.charAt(0).toUpperCase() + status.slice(1)}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Municipality Filter */}
        {(entityType === 'applicants' || entityType === 'all') && 
         options.municipalities && options.municipalities.length > 0 && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Municipality
            </label>
            <select
              name="municipality"
              value={filters.municipality}
              onChange={handleFilterChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="">All Municipalities</option>
              {options.municipalities.map(mun => (
                <option key={mun.id} value={mun.id}>
                  {mun.name}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Year Level Filter */}
        {(entityType === 'applicants' || entityType === 'all') && 
         options.year_levels && options.year_levels.length > 0 && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Year Level
            </label>
            <select
              name="year_level"
              value={filters.year_level}
              onChange={handleFilterChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="">All Year Levels</option>
              {options.year_levels.map(level => (
                <option key={level} value={level}>
                  Year {level}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Document Type Filter */}
        {(entityType === 'documents' || entityType === 'all') && 
         options.document_types && options.document_types.length > 0 && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Document Type
            </label>
            <select
              name="document_type"
              value={filters.document_type || ''}
              onChange={handleFilterChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="">All Document Types</option>
              {options.document_types.map(type => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Date Range */}
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              From Date
            </label>
            <input
              type="date"
              name="date_from"
              value={filters.date_from}
              onChange={handleFilterChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              To Date
            </label>
            <input
              type="date"
              name="date_to"
              value={filters.date_to}
              onChange={handleFilterChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
          </div>
        </div>

        {/* Sorting */}
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Sort By
            </label>
            <select
              name="sort_by"
              value={filters.sort_by}
              onChange={handleFilterChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="created_at">Created Date</option>
              <option value="updated_at">Updated Date</option>
              <option value="name">Name</option>
              {entityType === 'distributions' && (
                <>
                  <option value="amount">Amount</option>
                  <option value="status">Status</option>
                </>
              )}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Order
            </label>
            <select
              name="sort_order"
              value={filters.sort_order}
              onChange={handleFilterChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="asc">Ascending</option>
              <option value="desc">Descending</option>
            </select>
          </div>
        </div>

        {/* Results Per Page */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            Results Per Page
          </label>
          <select
            name="per_page"
            value={filters.per_page}
            onChange={handleFilterChange}
            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          >
            <option value={10}>10</option>
            <option value={20}>20</option>
            <option value={50}>50</option>
            <option value={100}>100</option>
          </select>
        </div>

        {/* Action Buttons */}
        <div className="flex gap-4 pt-4">
          <button
            type="submit"
            className="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition"
          >
            Search
          </button>
          <button
            type="button"
            onClick={handleReset}
            className="flex-1 bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-4 rounded-lg transition"
          >
            Reset
          </button>
        </div>
      </form>
    </div>
  );
}
