<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Services/DistributionManager.php';

$distManager = new \App\Services\DistributionManager();

// Get storage statistics
$storageStats = $distManager->getStorageStatistics();
$compressionStats = $distManager->getCompressionStatistics();

// Get recent logs without distribution_id join (since file_archive_log might not have it)
$recentLogsQuery = "SELECT 
                        fal.*,
                        TRIM(CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))) as admin_name,
                        s.student_id as lrn,
                        s.first_name || ' ' || s.last_name as student_name
                     FROM file_archive_log fal
                     LEFT JOIN admins a ON fal.performed_by = a.admin_id
                     LEFT JOIN students s ON fal.student_id = s.student_id
                     ORDER BY fal.performed_at DESC
                     LIMIT 15";
$recentLogsResult = pg_query($connection, $recentLogsQuery);
$recentLogs = $recentLogsResult ? pg_fetch_all($recentLogsResult) ?: [] : [];

// Calculate total storage and percentages
$totalStorage = 0;
$storageByCategory = [];
foreach ($storageStats as $stat) {
    $totalStorage += $stat['total_size'];
    $storageByCategory[$stat['category']] = $stat;
}

// Get max storage from settings
$settingsFile = __DIR__ . '/../../data/municipal_settings.json';
$maxStorageGB = 100; // default
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $maxStorageGB = $settings['max_storage_gb'] ?? 100;
}
$maxStorageBytes = $maxStorageGB * 1024 * 1024 * 1024;
$storagePercent = ($totalStorage / $maxStorageBytes) * 100;

$pageTitle = "Storage Dashboard";
?>
<?php $page_title='Storage Dashboard'; $extra_css=['../../assets/css/admin/table_core.css']; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="mb-0">Storage Dashboard</h1>
                </div>

                <!-- Quick Stats Section -->
                <div class="quick-actions mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-3 fw-bold">Storage Capacity Overview</h5>
                            <div class="d-flex justify-content-between mb-2">
                                <span style="font-size: 1.1rem;">
                                    <strong><?php echo number_format($totalStorage / (1024*1024*1024), 2); ?> GB</strong> 
                                    of <?php echo $maxStorageGB; ?> GB used
                                </span>
                                <span style="font-size: 1.2rem;"><strong><?php echo number_format($storagePercent, 1); ?>%</strong></span>
                            </div>
                            <?php
                            $progressColor = 'bg-success';
                            if ($storagePercent > 80) {
                                $progressColor = 'bg-danger';
                            } elseif ($storagePercent > 60) {
                                $progressColor = 'bg-warning';
                            }
                            ?>
                            <div class="progress" style="height: 35px; background-color: rgba(255,255,255,0.2);">
                                <div class="progress-bar <?php echo $progressColor; ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo min($storagePercent, 100); ?>%; font-size: 1rem; font-weight: 600;">
                                    <?php echo number_format($storagePercent, 1); ?>%
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <i class="bi bi-hdd-stack" style="font-size: 5rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                    <?php if ($storagePercent > 80): ?>
                    <div class="alert alert-danger mt-3 mb-0" style="background-color: rgba(255,255,255,0.95); color: #dc3545; border: none;">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Warning:</strong> Storage capacity is critically high (<?php echo number_format($storagePercent, 1); ?>%). Consider archiving or compressing old distributions.
                    </div>
                    <?php elseif ($storagePercent > 60): ?>
                    <div class="alert alert-warning mt-3 mb-0" style="background-color: rgba(255,255,255,0.95); color: #856404; border: none;">
                        <i class="bi bi-exclamation-circle"></i>
                        <strong>Notice:</strong> Storage usage is above 60%. Monitor capacity and plan for archiving.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Metric Cards -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card shadow-sm h-100" style="border-left: 4px solid #10b981;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="text-muted small text-uppercase mb-1" style="font-weight: 600; letter-spacing: 0.5px;">Total Storage</div>
                                        <div class="h3 mb-0 fw-bold text-dark"><?php echo number_format($totalStorage / (1024*1024*1024), 2); ?> GB</div>
                                    </div>
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                         style="width: 50px; height: 50px; background-color: rgba(16, 185, 129, 0.1);">
                                        <i class="bi bi-hdd fs-4" style="color: #10b981;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card shadow-sm h-100" style="border-left: 4px solid #3b82f6;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="text-muted small text-uppercase mb-1" style="font-weight: 600; letter-spacing: 0.5px;">Active Students</div>
                                        <div class="h3 mb-0 fw-bold text-dark">
                                            <?php 
                                            $activeStudents = isset($storageByCategory['active']) ? $storageByCategory['active']['student_count'] : 0;
                                            echo number_format($activeStudents); 
                                            ?>
                                        </div>
                                    </div>
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                         style="width: 50px; height: 50px; background-color: rgba(59, 130, 246, 0.1);">
                                        <i class="bi bi-people fs-4" style="color: #3b82f6;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card shadow-sm h-100" style="border-left: 4px solid #06b6d4;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="text-muted small text-uppercase mb-1" style="font-weight: 600; letter-spacing: 0.5px;">Archived Students</div>
                                        <div class="h3 mb-0 fw-bold text-dark">
                                            <?php 
                                            $archivedStudents = isset($storageByCategory['archived']) ? $storageByCategory['archived']['student_count'] : 0;
                                            echo number_format($archivedStudents); 
                                            ?>
                                        </div>
                                    </div>
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                         style="width: 50px; height: 50px; background-color: rgba(6, 182, 212, 0.1);">
                                        <i class="bi bi-archive fs-4" style="color: #06b6d4;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card shadow-sm h-100" style="border-left: 4px solid #f59e0b;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="text-muted small text-uppercase mb-1" style="font-weight: 600; letter-spacing: 0.5px;">Space Saved</div>
                                        <div class="h3 mb-0 fw-bold text-dark">
                                            <?php echo number_format(($compressionStats['total_space_saved'] ?? 0) / (1024*1024), 2); ?> MB
                                        </div>
                                    </div>
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                         style="width: 50px; height: 50px; background-color: rgba(245, 158, 11, 0.1);">
                                        <i class="bi bi-file-earmark-zip fs-4" style="color: #f59e0b;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row mb-4">
                    <div class="col-lg-6 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-header text-white" style="background-color: #495057;">
                                <h6 class="m-0 fw-bold">Storage Distribution</h6>
                            </div>
                            <div class="card-body">
                                <div style="position: relative; height: 300px;">
                                    <canvas id="storageChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6 mb-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-header text-white" style="background-color: #495057;">
                                <h6 class="m-0 fw-bold">Files by Category</h6>
                            </div>
                            <div class="card-body">
                                <div style="position: relative; height: 300px;">
                                    <canvas id="filesChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Storage Breakdown Table -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header text-white" style="background-color: #495057;">
                        <h6 class="m-0 fw-bold">Storage Breakdown</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 compact-cards">
                                <thead style="background-color: #f8f9fa;">
                                    <tr>
                                        <th class="fw-semibold px-4 py-3">Category</th>
                                        <th class="text-end fw-semibold py-3">Students</th>
                                        <th class="text-end fw-semibold py-3">Files</th>
                                        <th class="text-end fw-semibold py-3">Storage Size</th>
                                        <th class="text-end fw-semibold px-4 py-3">% of Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($storageStats as $stat): 
                                        $percentage = $totalStorage > 0 ? ($stat['total_size'] / $totalStorage) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td class="px-4 py-3" data-label="Category">
                                            <span class="badge" style="background-color: <?php 
                                                echo $stat['category'] === 'active' ? 'rgba(34, 197, 94, 0.15)' : 
                                                    ($stat['category'] === 'distributions' ? 'rgba(59, 130, 246, 0.15)' :
                                                    ($stat['category'] === 'archived' ? 'rgba(6, 182, 212, 0.15)' : 'rgba(107, 114, 128, 0.15)')); 
                                            ?>; color: <?php 
                                                echo $stat['category'] === 'active' ? '#15803d' : 
                                                    ($stat['category'] === 'distributions' ? '#1e40af' :
                                                    ($stat['category'] === 'archived' ? '#0e7490' : '#374151')); 
                                            ?>; font-weight: 500; padding: 6px 12px;">
                                                <?php 
                                                    if ($stat['category'] === 'distributions') {
                                                        echo 'Past Distributions';
                                                    } else {
                                                        echo ucfirst($stat['category']);
                                                    }
                                                ?>
                                            </span>
                                        </td>
                                        <td class="text-end py-3" data-label="Students">
                                            <?php 
                                                if ($stat['category'] === 'distributions') {
                                                    echo '<span class="text-muted">' . number_format($stat['student_count']) . ' unique</span>';
                                                } else {
                                                    echo number_format($stat['student_count']);
                                                }
                                            ?>
                                        </td>
                                        <td class="text-end py-3" data-label="Files"><?php echo number_format($stat['file_count']); ?></td>
                                        <td class="text-end py-3" data-label="Storage Size">
                                            <strong><?php echo number_format($stat['total_size'] / (1024*1024), 2); ?> MB</strong>
                                        </td>
                                        <td class="text-end px-4 py-3" data-label="% of Total">
                                            <span class="badge bg-light text-dark border"><?php echo number_format($percentage, 1); ?>%</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($storageStats)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-5">
                                            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                            No storage data available
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Archive Operations -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header text-white d-flex justify-content-between align-items-center" style="background-color: #495057;">
                        <h6 class="m-0 fw-bold">Recent Archive Operations</h6>
                        <span class="badge" style="background-color: rgba(255,255,255,0.2); color: white;"><?php echo count($recentLogs); ?> records</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 compact-cards">
                                <thead style="background-color: #f8f9fa;">
                                    <tr>
                                        <th class="fw-semibold px-4 py-3">Timestamp</th>
                                        <th class="fw-semibold py-3">Operation</th>
                                        <th class="fw-semibold py-3">Student</th>
                                        <th class="fw-semibold py-3">Performed By</th>
                                        <th class="fw-semibold py-3">Status</th>
                                        <th class="text-end fw-semibold px-4 py-3">Size</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td class="px-4 py-3" data-label="Timestamp">
                                            <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($log['performed_at'] ?? 'now')); ?></small>
                                        </td>
                                        <td class="py-3" data-label="Operation">
                                            <span class="badge" style="background-color: rgba(6, 182, 212, 0.15); color: #0e7490; font-weight: 500;">
                                                <?php echo strtoupper($log['operation'] ?? 'UNKNOWN'); ?>
                                            </span>
                                        </td>
                                        <td class="py-3" data-label="Student">
                                            <?php if (!empty($log['student_name'])): ?>
                                                <div class="fw-medium"><?php echo htmlspecialchars($log['student_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($log['lrn'] ?? ''); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">—</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3" data-label="Performed By">
                                            <small><?php echo htmlspecialchars($log['admin_name'] ?? 'System'); ?></small>
                                        </td>
                                        <td class="py-3" data-label="Status">
                                            <span class="badge" style="background-color: <?php 
                                                $status = strtoupper($log['operation_status'] ?? 'UNKNOWN');
                                                echo $status === 'SUCCESS' ? 'rgba(34, 197, 94, 0.15)' : 
                                                    ($status === 'FAILED' ? 'rgba(239, 68, 68, 0.15)' : 'rgba(107, 114, 128, 0.15)');
                                            ?>; color: <?php 
                                                echo $status === 'SUCCESS' ? '#15803d' : 
                                                    ($status === 'FAILED' ? '#991b1b' : '#374151');
                                            ?>; font-weight: 500;">
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                        <td class="text-end px-4 py-3" data-label="Size">
                                            <small class="text-muted"><?php echo number_format(($log['total_size_before'] ?? 0) / (1024*1024), 2); ?> MB</small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recentLogs)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-5" style="display: table-cell !important;">
                                            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                                <i class="bi bi-inbox fs-1 mb-2 opacity-50"></i>
                                                <span>No archive operations recorded</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        // Storage Distribution Pie Chart
        const storageCtx = document.getElementById('storageChart');
        if (storageCtx) {
            new Chart(storageCtx.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: [
                        <?php foreach ($storageStats as $stat): ?>
                            '<?php echo ucfirst($stat['category']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        data: [
                            <?php foreach ($storageStats as $stat): ?>
                                <?php echo round($stat['total_size'] / (1024*1024), 2); ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: ['#3BB54A', '#1A70F0', '#3BC5DC', '#8DD672', '#6c757d']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed.toFixed(2) + ' MB';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Files by Category Bar Chart
        const filesCtx = document.getElementById('filesChart');
        if (filesCtx) {
            new Chart(filesCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($storageStats as $stat): ?>
                            '<?php echo ucfirst($stat['category']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [
                        {
                            label: 'Students',
                            data: [
                                <?php foreach ($storageStats as $stat): ?>
                                    <?php echo $stat['student_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#1A70F0'
                        },
                        {
                            label: 'Files',
                            data: [
                                <?php foreach ($storageStats as $stat): ?>
                                    <?php echo $stat['file_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#3BB54A'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>