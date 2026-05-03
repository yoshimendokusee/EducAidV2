/**
 * Reports Module JavaScript
 * Handles interactive filtering, previews, and exports
 */

$(document).ready(function() {
    // Initialize Select2 for multi-select dropdowns
    $('.multi-select').select2({
        placeholder: 'Select...',
        allowClear: true,
        width: '100%'
    });
    
    // Filter barangays based on municipality (from hidden input)
    function filterBarangaysByMunicipality() {
        const selectedMunicipality = $('#municipalityFilterValue').val();
        const $barangayFilter = $('#barangayFilter');
        
        // Show/hide barangay options based on municipality
        $barangayFilter.find('option').each(function() {
            const optionMunicipality = $(this).data('municipality');
            
            if (!selectedMunicipality || selectedMunicipality === '' || optionMunicipality == selectedMunicipality) {
                $(this).prop('disabled', false).show();
            } else {
                $(this).prop('disabled', true).hide();
            }
        });
        
        // Refresh Select2
        $barangayFilter.trigger('change.select2');
    }
    
    // Apply filter on page load
    filterBarangaysByMunicipality();
    
    // Update filter badge count on change
    $('#reportFiltersForm').on('change', 'select, input', function() {
        updateFilterBadge();
    });
    
    // Initialize filter count - municipality is pre-selected
    updateFilterBadge();
});

/**
 * Update the filter badge count
 */
function updateFilterBadge() {
    const form = $('#reportFiltersForm')[0];
    const formData = new FormData(form);
    let filterCount = 0;
    
    for (let [key, value] of formData.entries()) {
        if (key !== 'csrf_token' && key !== 'include_archived' && value && value !== '') {
            filterCount++;
        }
    }
    
    $('#filterBadge').text(filterCount + ' filter' + (filterCount !== 1 ? 's' : '') + ' applied');
    
    if (filterCount > 0) {
        $('#filterBadge').removeClass('bg-light text-dark').addClass('bg-warning text-dark');
    } else {
        $('#filterBadge').removeClass('bg-warning text-dark').addClass('bg-light text-dark');
    }
}

/**
 * Preview report with current filters
 */
function previewReport() {
    const form = $('#reportFiltersForm')[0];
    const formData = new FormData(form);
    formData.append('action', 'preview');
    
    // Show loading
    showLoading('Generating preview...');
    
    $.ajax({
        url: '../../api/reports/generate_report.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            hideLoading();
            
            if (response.success) {
                displayPreview(response.data);
                displayStatistics(response.data.stats);
                updateFilterSummary(response.data.filter_summary);
            } else {
                showError('Failed to generate preview: ' + (response.error || 'Unknown error'));
            }
        },
        error: function(xhr, status, error) {
            hideLoading();
            showError('Error generating preview: ' + error);
            console.error('Preview error:', xhr.responseText);
        }
    });
}

/**
 * Display preview data in table
 */
function displayPreview(data) {
    const tbody = $('#previewTableBody');
    tbody.empty();
    
    if (!data.students || data.students.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                    <p class="mt-2">No students found matching the selected filters.</p>
                </td>
            </tr>
        `);
        $('#previewCount').text('0 records');
        $('#previewPanel').fadeIn();
        return;
    }
    
    let html = '';
    data.students.forEach((student, index) => {
        const fullName = [
            student.first_name,
            student.middle_name,
            student.last_name,
            student.extension_name
        ].filter(Boolean).join(' ');
        
        html += `
            <tr>
                <td class="text-center" data-label="No.">${index + 1}</td>
                <td data-label="Student ID"><code>${escapeHtml(student.student_id)}</code></td>
                <td data-label="Name"><strong>${escapeHtml(fullName)}</strong></td>
                <td class="text-center" data-label="Gender">
                    <span class="badge ${student.sex === 'Male' ? 'bg-primary' : 'bg-info'}">
                        ${escapeHtml(student.sex || '-')}
                    </span>
                </td>
                <td data-label="Barangay">${escapeHtml(student.barangay || '-')}</td>
                <td data-label="University"><small>${escapeHtml(student.university || '-')}</small></td>
                <td class="text-center" data-label="Year Level">${escapeHtml(student.year_level || '-')}</td>
                <td class="text-center" data-label="Status">
                    <span class="badge ${getStatusBadgeClass(student.status_display)}">
                        ${escapeHtml(student.status_display)}
                    </span>
                </td>
            </tr>
        `;
    });
    
    tbody.html(html);
    $('#previewCount').text(data.preview_count + ' of ' + data.total + ' records');
    $('#previewPanel').fadeIn();
    
    // Scroll to preview
    $('html, body').animate({
        scrollTop: $('#previewPanel').offset().top - 20
    }, 500);
}

/**
 * Display statistics cards
 */
function displayStatistics(stats) {
    $('#statTotalStudents').text(Number(stats.total_students).toLocaleString());
    $('#statMale').text(Number(stats.male_count).toLocaleString());
    $('#statFemale').text(Number(stats.female_count).toLocaleString());
    $('#statConfidence').text(Number(stats.avg_confidence).toFixed(1) + '%');
    
    // Calculate percentages
    const total = parseInt(stats.total_students);
    if (total > 0) {
        const malePercent = ((parseInt(stats.male_count) / total) * 100).toFixed(1);
        const femalePercent = ((parseInt(stats.female_count) / total) * 100).toFixed(1);
        $('#statMalePercent').text(malePercent + '%');
        $('#statFemalePercent').text(femalePercent + '%');
    }
    
    $('#statisticsPanel').fadeIn();
}

/**
 * Update filter summary text
 */
function updateFilterSummary(summary) {
    if (Array.isArray(summary) && summary.length > 0) {
        $('#filterSummary').html('<i class="bi bi-filter-circle"></i> ' + summary.join(' • '));
    } else {
        $('#filterSummary').text('No filters applied - showing all records');
    }
}

/**
 * Export report as PDF
 */
function exportPDF() {
    const form = $('#reportFiltersForm')[0];
    const formData = new FormData(form);
    formData.append('action', 'export_pdf');
    formData.append('report_type', 'student_list');
    
    // Show loading
    showLoading('Generating PDF report...');
    
    // Create a temporary form to submit
    const tempForm = document.createElement('form');
    tempForm.method = 'POST';
    tempForm.action = '../../api/reports/generate_report.php';
    tempForm.target = '_blank';
    
    for (let [key, value] of formData.entries()) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        tempForm.appendChild(input);
    }
    
    document.body.appendChild(tempForm);
    tempForm.submit();
    document.body.removeChild(tempForm);
    
    // Hide loading after a short delay
    setTimeout(hideLoading, 2000);
    
    showSuccess('PDF report is being generated and will download shortly.');
}

/**
 * Export report as Excel
 */
function exportExcel() {
    const form = $('#reportFiltersForm')[0];
    const formData = new FormData(form);
    formData.append('action', 'export_excel');
    
    // Show loading
    showLoading('Generating Excel report...');
    
    // Create a temporary form to submit
    const tempForm = document.createElement('form');
    tempForm.method = 'POST';
    tempForm.action = '../../api/reports/generate_report.php';
    tempForm.target = '_blank';
    
    for (let [key, value] of formData.entries()) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = value;
        tempForm.appendChild(input);
    }
    
    document.body.appendChild(tempForm);
    tempForm.submit();
    document.body.removeChild(tempForm);
    
    // Hide loading after a short delay
    setTimeout(hideLoading, 2000);
    
    showSuccess('Excel report is being generated and will download shortly.');
}

/**
 * Reset all filters
 */
function resetFilters() {
    $('#reportFiltersForm')[0].reset();
    $('.multi-select').val(null).trigger('change');
    $('#previewPanel').fadeOut();
    $('#statisticsPanel').fadeOut();
    updateFilterBadge();
    $('#filterSummary').text('Select filters and click Preview');
}

/**
 * Get Bootstrap badge class for status
 */
function getStatusBadgeClass(status) {
    const statusLower = (status || '').toLowerCase();
    
    if (statusLower.includes('active')) return 'bg-success';
    if (statusLower.includes('applicant')) return 'bg-warning';
    if (statusLower.includes('archived')) return 'bg-secondary';
    if (statusLower.includes('disabled')) return 'bg-danger';
    if (statusLower.includes('blacklisted')) return 'bg-dark';
    
    return 'bg-info';
}

/**
 * Show loading overlay
 */
function showLoading(message = 'Loading...') {
    const overlay = $(`
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-spinner">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-3 mb-0 fw-bold">${message}</p>
            </div>
        </div>
    `);
    
    $('body').append(overlay);
}

/**
 * Hide loading overlay
 */
function hideLoading() {
    $('#loadingOverlay').fadeOut(300, function() {
        $(this).remove();
    });
}

/**
 * Show success toast
 */
function showSuccess(message) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: message,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    } else {
        alert(message);
    }
}

/**
 * Show error toast
 */
function showError(message) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: message,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 5000,
            timerProgressBar: true
        });
    } else {
        alert('Error: ' + message);
    }
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return (text || '').toString().replace(/[&<>"']/g, m => map[m]);
}

/**
 * Export current preview table to CSV (bonus feature)
 */
function exportTableToCSV() {
    const table = $('#previewTable')[0];
    let csv = [];
    
    // Headers
    const headers = [];
    $(table).find('thead th').each(function() {
        headers.push('"' + $(this).text().trim() + '"');
    });
    csv.push(headers.join(','));
    
    // Data
    $(table).find('tbody tr').each(function() {
        const row = [];
        $(this).find('td').each(function() {
            row.push('"' + $(this).text().trim().replace(/"/g, '""') + '"');
        });
        csv.push(row.join(','));
    });
    
    // Download
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'report_preview_' + new Date().toISOString().slice(0, 10) + '.csv');
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
