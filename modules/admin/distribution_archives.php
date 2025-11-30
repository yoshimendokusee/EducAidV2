<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';

// Get filter from query string
$filter = $_GET['filter'] ?? 'all';

// Scan filesystem for compressed distribution archives
$distributionsPath = __DIR__ . '/../../assets/uploads/distributions';
$allDistributions = [];

if (is_dir($distributionsPath)) {
    $zipFiles = glob($distributionsPath . '/*.zip');
    
    foreach ($zipFiles as $zipFile) {
        $filename = basename($zipFile);
        $filesize = filesize($zipFile);
        $filetime = filemtime($zipFile);
        
        // Parse distribution ID from filename (format: #MUNICIPALITY-DISTR-YYYY-MM-DD-HHMMSS.zip)
        $distribution_id = str_replace('.zip', '', $filename);
        
        // Try to open ZIP and count files AND student folders
        $fileCount = 0;
        $studentFolderCount = 0;
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            $fileCount = $zip->numFiles;
            
            // Count unique student folders (top-level directories in ZIP)
            // Format: "LastName, FirstName M. - STUDENT-ID/"
            $studentFolders = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $path = $stat['name'];
                
                // Extract first folder name (student folder)
                $parts = explode('/', $path);
                if (count($parts) > 1 && !empty($parts[0])) {
                    $studentFolders[$parts[0]] = true;
                }
            }
            $studentFolderCount = count($studentFolders);
            
            $zip->close();
        }
        
        // Check if there's a matching snapshot in database
        $snapshotQuery = pg_query_params($connection,
            "SELECT ds.*, 
                    (SELECT COUNT(DISTINCT student_id) FROM distribution_student_records WHERE snapshot_id = ds.snapshot_id) as actual_student_count
             FROM distribution_snapshots ds 
             WHERE archive_filename = $1 OR distribution_id = $2 
             LIMIT 1",
            [$filename, $distribution_id]
        );
        $snapshot = $snapshotQuery ? pg_fetch_assoc($snapshotQuery) : null;
        
        // Use actual count from database or ZIP folder count
        $actualStudentCount = $snapshot['actual_student_count'] ?? $studentFolderCount;
        
        // Build distribution entry
        $dist = [
            'distribution_id' => $distribution_id,
            'created_at' => date('Y-m-d H:i:s', $filetime),
            'year_level' => $snapshot['academic_year'] ?? 'N/A',
            'semester' => $snapshot['semester'] ?? 'N/A',
            'student_count' => $actualStudentCount, // Use accurate count from database or ZIP folders
            'file_count' => $fileCount,
            'original_size' => $filesize * 2, // Estimate: assume 50% compression
            'current_size' => $filesize,
            'status' => 'ended',
            'files_compressed' => true,
            'archived_files_count' => $fileCount,
            'location' => $snapshot['location'] ?? 'Unknown',
            'notes' => $snapshot['notes'] ?? ''
        ];
        
        $allDistributions[] = $dist;
    }
}

// Sort by date descending
usort($allDistributions, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Get compression statistics
$totalDistributions = count($allDistributions);
$compressedDistributions = count(array_filter($allDistributions, fn($d) => $d['files_compressed']));
$totalSpaceSaved = array_sum(array_map(fn($d) => $d['original_size'] - $d['current_size'], $allDistributions));
$avgCompressionRatio = $compressedDistributions > 0 
    ? (array_sum(array_map(fn($d) => 
        $d['original_size'] > 0 ? (($d['original_size'] - $d['current_size']) / $d['original_size']) * 100 : 0
      , $allDistributions)) / $compressedDistributions)
    : 0;

$compressionStats = [
    'total_distributions' => $totalDistributions,
    'compressed_distributions' => $compressedDistributions,
    'total_space_saved' => $totalSpaceSaved,
    'avg_compression_ratio' => $avgCompressionRatio
];

// Filter distributions based on tab
$filteredDistributions = array_filter($allDistributions, function($dist) use ($filter) {
    if ($filter === 'all') return true;
    // All ZIP files are already archived, so 'archived' filter shows same as 'all'
    if ($filter === 'archived') return $dist['archived_files_count'] > 0;
    return true;
});

$pageTitle = "Distribution Archives";
?>
<?php $page_title='Distribution Archives'; $extra_css=['../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
    <style>
        .stat-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .stat-banner .stat-item {
            text-align: center;
        }
        .stat-banner .stat-value {
            font-size: 2rem;
            font-weight: bold;
        }
        .stat-banner .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .badge-active {
            background-color: #28a745;
        }
        .badge-ended {
            background-color: #6c757d;
        }
        .badge-compressed {
            background-color: #17a2b8;
        }
        .badge-archived {
            background-color: #ffc107;
            color: #000;
        }
        .nav-tabs .nav-link {
            color: #495057;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
        }
        .table-actions .btn {
            margin-right: 3px;
        }
        .distribution-details .table-sm {
            font-size: 0.875rem;
        }
        .distribution-details h6 {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 8px;
            margin-bottom: 15px;
            color: #495057;
        }
        .sticky-top {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        #detailsModal .modal-dialog {
            max-width: 1200px;
        }
    </style>
</head>
<body>
    
    <div class="main-content">
        <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="fw-bold mb-1">Distribution Archives</h1>
            </div>

            <!-- Compression Statistics Banner -->
            <div class="stat-banner">
                <div class="row">
                    <div class="col-md-3 stat-item">
                        <div class="stat-value"><?php echo $compressionStats['total_distributions']; ?></div>
                        <div class="stat-label">Total Distributions</div>
                    </div>
                    <div class="col-md-3 stat-item">
                        <div class="stat-value"><?php echo $compressionStats['compressed_distributions']; ?></div>
                        <div class="stat-label">Compressed</div>
                    </div>
                    <div class="col-md-3 stat-item">
                        <div class="stat-value">
                            <?php echo number_format($compressionStats['total_space_saved'] / 1024 / 1024, 2); ?> MB
                        </div>
                        <div class="stat-label">Space Saved</div>
                    </div>
                    <div class="col-md-3 stat-item">
                        <div class="stat-value">
                            <?php echo number_format($compressionStats['avg_compression_ratio'], 1); ?>%
                        </div>
                        <div class="stat-label">Avg. Compression Ratio</div>
                    </div>
                </div>
            </div>

            <!-- Tabbed Interface -->
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" 
                               href="?filter=all">
                                <i class="bi bi-archive-fill"></i> All Archives (<?php echo count($allDistributions); ?>)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'archived' ? 'active' : ''; ?>" 
                               href="?filter=archived">
                                <i class="bi bi-file-zip-fill"></i> With Files (<?php echo count(array_filter($allDistributions, fn($d) => $d['archived_files_count'] > 0)); ?>)
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <?php if (empty($filteredDistributions)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No distributions found for this filter.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Created</th>
                                        <th>Year/Sem</th>
                                        <th>Students</th>
                                        <th>Files</th>
                                        <th>Original Size</th>
                                        <th>Current Size</th>
                                        <th>Space Saved</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filteredDistributions as $dist): ?>
                                        <?php 
                                            $spaceSaved = $dist['original_size'] - $dist['current_size'];
                                            $compressionPct = $dist['original_size'] > 0 
                                                ? round(($spaceSaved / $dist['original_size']) * 100, 1) 
                                                : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($dist['distribution_id']); ?></strong>
                                            </td>
                                            <td><?php echo date('M d, Y g:i A', strtotime($dist['created_at'])); ?></td>
                                            <td>
                                                <strong>AY <?php echo htmlspecialchars($dist['year_level']); ?></strong><br>
                                                <small class="text-muted">Semester <?php echo htmlspecialchars($dist['semester']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $dist['student_count']; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $dist['file_count']; ?></span>
                                            </td>
                                            <td><?php echo number_format($dist['original_size'] / 1024 / 1024, 2); ?> MB</td>
                                            <td><?php echo number_format($dist['current_size'] / 1024 / 1024, 2); ?> MB</td>
                                            <td>
                                                <?php if ($spaceSaved > 0): ?>
                                                    <span class="badge bg-success">
                                                        <?php echo number_format($spaceSaved / 1024 / 1024, 2); ?> MB
                                                        (<?php echo $compressionPct; ?>%)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($dist['files_compressed']): ?>
                                                    <span class="badge badge-compressed">
                                                        <i class="bi bi-file-zip"></i> Compressed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-ended">Ended</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="table-actions">
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewDetails('<?php echo htmlspecialchars($dist['distribution_id']); ?>')">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                                <?php if ($dist['files_compressed']): ?>
                                                    <a href="download_distribution.php?id=<?php echo urlencode($dist['distribution_id']); ?>" 
                                                       class="btn btn-sm btn-success">
                                                        <i class="bi bi-download"></i> Download
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    </section>
</div>

<!-- Details Modal - Moved outside wrapper for proper z-index -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Distribution Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Load Bootstrap JS first, BEFORE our custom scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Wait for DOM and Bootstrap to be ready
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize modal only after Bootstrap is loaded
            window.detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
        });
        
        function viewDetails(distId) {
            // Ensure modal is initialized
            if (!window.detailsModal) {
                window.detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
            }
            
            window.detailsModal.show();
            document.getElementById('detailsContent').innerHTML = 
                '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading distribution details...</p></div>';
            
            // Fetch distribution details via AJAX
            fetch(`get_distribution_details.php?id=${encodeURIComponent(distId)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Failed to load distribution details');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        displayDistributionDetails(data);
                    } else {
                        throw new Error(data.error || 'Unknown error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('detailsContent').innerHTML = 
                        `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> ${error.message}</div>`;
                });
        }
        
        function displayDistributionDetails(data) {
            const dist = data.distribution;
            const comp = data.compression;
            const students = data.students || [];
            const files = data.files || [];
            const zipFile = data.zip_file;
            
            let html = `
                <div class="distribution-details">
                    <!-- Header Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6><i class="bi bi-calendar-event"></i> Distribution Information</h6>
                            <table class="table table-sm table-borderless">
                                <tr><td class="text-muted">ID:</td><td><code>${dist.id}</code></td></tr>
                                <tr><td class="text-muted">Academic Year:</td><td><strong>${dist.academic_year}</strong></td></tr>
                                <tr><td class="text-muted">Semester:</td><td><strong>${dist.semester}</strong></td></tr>
                                <tr><td class="text-muted">Date:</td><td>${dist.date}</td></tr>
                                <tr><td class="text-muted">Location:</td><td>${dist.location || 'N/A'}</td></tr>
                                <tr><td class="text-muted">Finalized By:</td><td>${dist.finalized_by}</td></tr>
                                <tr><td class="text-muted">Finalized At:</td><td>${dist.finalized_at || 'N/A'}</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="bi bi-file-zip"></i> Compression Statistics</h6>
                            <table class="table table-sm table-borderless">
                                <tr><td class="text-muted">Status:</td><td><span class="badge ${comp.compressed ? 'bg-success' : 'bg-secondary'}">${comp.compressed ? 'Compressed' : 'Not Compressed'}</span></td></tr>
                                <tr><td class="text-muted">Original Size:</td><td>${formatBytes(comp.original_size)}</td></tr>
                                <tr><td class="text-muted">Compressed Size:</td><td>${formatBytes(comp.compressed_size)}</td></tr>
                                <tr><td class="text-muted">Space Saved:</td><td><span class="text-success">${formatBytes(comp.space_saved)} (${comp.compression_ratio}%)</span></td></tr>
                                <tr><td class="text-muted">Total Files:</td><td><span class="badge bg-info">${dist.file_count}</span></td></tr>
                                <tr><td class="text-muted">Total Students:</td><td><span class="badge bg-primary">${dist.student_count}</span></td></tr>
                            </table>
                        </div>
                    </div>
                    
                    ${dist.notes ? `<div class="alert alert-info"><strong>Notes:</strong> ${dist.notes}</div>` : ''}
                    
                    <!-- Students List -->
                    <h6><i class="bi bi-people"></i> Students (${students.length})</h6>
                    <div class="table-responsive mb-4" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Year Level</th>
                                    <th>University</th>
                                    <th>Barangay</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            if (students.length > 0) {
                students.forEach(student => {
                    html += `
                        <tr>
                            <td><small>${student.last_name}, ${student.first_name} ${student.middle_name || ''}</small></td>
                            <td><small>${student.email || 'N/A'}</small></td>
                            <td><small>${student.year_level_name || 'N/A'}</small></td>
                            <td><small>${student.university_name || 'N/A'}</small></td>
                            <td><small>${student.barangay_name || 'N/A'}</small></td>
                            <td><small>₱${parseFloat(student.amount_received || 0).toLocaleString()}</small></td>
                        </tr>
                    `;
                });
            } else {
                html += '<tr><td colspan="6" class="text-center text-muted">No student records found</td></tr>';
            }
            
            html += `
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- File Manifest -->
                    <h6><i class="bi bi-files"></i> Archived Files (${files.length})</h6>
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Document Type</th>
                                    <th>File Size</th>
                                    <th>Archived Path</th>
                                    <th>Hash</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            if (files.length > 0) {
                files.forEach(file => {
                    html += `
                        <tr>
                            <td><small><code>${file.student_id}</code></small></td>
                            <td><small>${file.document_type_code || 'N/A'}</small></td>
                            <td><small>${formatBytes(file.file_size)}</small></td>
                            <td><small class="text-muted">${file.archived_path || 'N/A'}</small></td>
                            <td><small><code>${file.file_hash ? file.file_hash.substring(0, 8) + '...' : 'N/A'}</code></small></td>
                        </tr>
                    `;
                });
            } else {
                html += '<tr><td colspan="5" class="text-center text-muted">No file records found</td></tr>';
            }
            
            html += `
                            </tbody>
                        </table>
                    </div>
            `;
            
            // Add download button if ZIP exists
            if (zipFile && zipFile.exists) {
                html += `
                    <div class="mt-4 text-center">
                        <a href="download_distribution.php?id=${encodeURIComponent(dist.id)}" 
                           class="btn btn-success btn-lg">
                            <i class="bi bi-download"></i> Download ZIP Archive (${formatBytes(zipFile.size)})
                        </a>
                    </div>
                `;
            }
            
            html += '</div>';
            
            document.getElementById('detailsContent').innerHTML = html;
        }
        
        function formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }
    </script>
</body>
</html>
