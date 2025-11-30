<?php
require_once __DIR__ . '/../../includes/CSRFProtection.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_username'])) {
    header('Location: ../../modules/admin/admin_login.php');
    exit;
}

include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/report_filters.php';
require_once __DIR__ . '/../../includes/report_generator.php';

// Resolve admin context
$adminId = $_SESSION['admin_id'] ?? null;
$adminUsername = $_SESSION['admin_username'] ?? null;
$adminMunicipalityId = null;
$adminRole = 'sub_admin';

if ($adminId) {
    $admRes = pg_query_params($connection, "SELECT municipality_id, role FROM admins WHERE admin_id = $1", [$adminId]);
} elseif ($adminUsername) {
    $admRes = pg_query_params($connection, "SELECT municipality_id, role FROM admins WHERE username = $1", [$adminUsername]);
}

if ($admRes && pg_num_rows($admRes)) {
    $admRow = pg_fetch_assoc($admRes);
    $adminMunicipalityId = $admRow['municipality_id'];
    $adminRole = $admRow['role'];
}

// Generate CSRF token
$csrfToken = CSRFProtection::generateToken('generate_report');

// Get filter options from database
// For super admins: show all options but default to their municipality
// For sub admins: only show their municipality's data
$barangays = pg_query($connection, "SELECT barangay_id, name, municipality_id FROM barangays ORDER BY name");
$municipalities = pg_query($connection, "SELECT municipality_id, name FROM municipalities ORDER BY name");
$yearLevels = pg_query($connection, "SELECT year_level_id, name FROM year_levels ORDER BY name");

if ($adminRole === 'super_admin') {
    // Even for super admins, scope to their municipality for reporting
    $universities = pg_query_params($connection,
        "SELECT DISTINCT u.university_id, u.name
         FROM universities u
         INNER JOIN students s ON u.university_id = s.university_id
         WHERE s.municipality_id = $1
         ORDER BY u.name", [$adminMunicipalityId]);
    $distributions = pg_query_params($connection,
        "SELECT DISTINCT ds.snapshot_id, ds.distribution_id, ds.academic_year, ds.semester, ds.finalized_at
         FROM distribution_snapshots ds
         INNER JOIN distribution_student_records r ON r.snapshot_id = ds.snapshot_id
         INNER JOIN students s ON s.student_id = r.student_id
         WHERE s.municipality_id = $1 AND ds.finalized_at IS NOT NULL
         ORDER BY ds.finalized_at DESC",
        [$adminMunicipalityId]
    );
    $academicYears = pg_query_params($connection,
        "SELECT DISTINCT current_academic_year
         FROM students WHERE municipality_id = $1 AND current_academic_year IS NOT NULL
         ORDER BY current_academic_year DESC",
        [$adminMunicipalityId]
    );
} else {
    // Sub-admins only see data from their municipality
    $universities = pg_query_params($connection, "SELECT DISTINCT u.university_id, u.name FROM universities u INNER JOIN students s ON u.university_id = s.university_id WHERE s.municipality_id = $1 ORDER BY u.name", [$adminMunicipalityId]);
    $distributions = pg_query_params($connection, "SELECT DISTINCT ds.snapshot_id, ds.distribution_id, ds.academic_year, ds.semester, ds.finalized_at FROM distribution_snapshots ds INNER JOIN distribution_lists dl ON ds.snapshot_id = dl.snapshot_id INNER JOIN students s ON dl.student_id = s.student_id WHERE s.municipality_id = $1 AND ds.finalized_at IS NOT NULL ORDER BY ds.finalized_at DESC", [$adminMunicipalityId]);
    $academicYears = pg_query_params($connection, "SELECT DISTINCT current_academic_year FROM students WHERE municipality_id = $1 AND current_academic_year IS NOT NULL ORDER BY current_academic_year DESC", [$adminMunicipalityId]);
}

$pageTitle = "Reports & Analytics";
include __DIR__ . '/../../includes/admin/admin_head.php';
?>
<link rel="stylesheet" href="../../assets/css/admin/table_core.css">
<link rel="stylesheet" href="../../assets/css/admin/reports.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    .page-title { font-weight: 700; color: #111; }
    .page-subtitle { color: #6c757d; }
    .card-gradient {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        border: 1px solid #e2e8f0;
    }
    .card-gradient .card-header {
        background: #f8f9fa;
        color: #111;
        border-bottom: 1px solid #e2e8f0;
        border-radius: 16px 16px 0 0;
        padding: 1rem 1.25rem;
    }
    .card-gradient .card-header h5 {
        font-weight: 600;
    }
    .card-gradient .card-body {
        padding: 1.5rem;
    }
    #filterBadge {
        background: #10b981 !important;
        color: #fff !important;
        font-weight: 500;
        padding: 0.35rem 0.75rem;
        border-radius: 20px;
    }
</style>
</head>
<body>
  <?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
  
  <div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
      <div class="container-fluid py-4">
    <!-- Header -->
    <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h4 class="page-title mb-1">Reports & Analytics</h4>
            <div class="page-subtitle">Generate comprehensive reports with advanced filtering options</div>
        </div>
        <div class="mt-3 mt-md-0">
            <button class="btn btn-outline-secondary" onclick="resetFilters()">Clear</button>
        </div>
    </div>

    <!-- Filter Panel -->
    <div class="card card-gradient mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Report Filters</h5>
            <span id="filterBadge" class="badge bg-light text-dark">0 filters applied</span>
        </div>
        <div class="card-body p-4">
            <form id="reportFiltersForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="row g-3">
                    <!-- Report Type -->
                    <div class="col-md-3">
                        <label class="form-label">Report Type</label>
                        <select name="report_type" class="form-select" id="reportType">
                            <option value="student_list" selected>Standard Student List</option>
                            <option value="applicants_master">Applicants Master List (Municipality)</option>
                            <option value="statistics">Statistics Summary</option>
                            <option value="comprehensive">Comprehensive Workbook (Multi-sheet)</option>
                        </select>
                        <small class="text-muted">Comprehensive includes applicants, active, and breakdowns.</small>
                    </div>
                    <!-- Student Status -->
                    <div class="col-md-3">
                        <label class="form-label">Student Status</label>
                        <select name="status[]" class="form-select multi-select" multiple>
                            <option value="active">Active</option>
                            <option value="applicant">Applicant</option>
                            <option value="under_registration">Under Registration</option>
                            <option value="disabled">Disabled</option>
                        </select>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="include_archived" id="includeArchived">
                            <label class="form-check-label small" for="includeArchived">
                                Include Archived Students
                            </label>
                        </div>
                    </div>

                    <!-- Gender -->
                    <div class="col-md-3">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">All</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>

                    <!-- Municipality -->
                    <div class="col-md-3">
                        <label class="form-label">Municipality</label>
                        <?php 
                        // Get municipality name for display
                        pg_result_seek($municipalities, 0);
                        $municipalityName = '';
                        while ($m = pg_fetch_assoc($municipalities)) {
                            if ($m['municipality_id'] == $adminMunicipalityId) {
                                $municipalityName = $m['name'];
                                break;
                            }
                        }
                        ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($municipalityName); ?>" readonly>
                        <input type="hidden" name="municipality_id" value="<?php echo $adminMunicipalityId; ?>" id="municipalityFilterValue">
                        <small class="text-muted">Your assigned municipality</small>
                    </div>

                    <!-- Barangay -->
                    <div class="col-md-3">
                        <label class="form-label">Barangay</label>
                        <select name="barangay_id[]" class="form-select multi-select" multiple id="barangayFilter">
                            <?php 
                            // Reset pointer to read barangays
                            pg_result_seek($barangays, 0);
                            while ($b = pg_fetch_assoc($barangays)): 
                            ?>
                                <option value="<?php echo $b['barangay_id']; ?>" data-municipality="<?php echo $b['municipality_id']; ?>">
                                    <?php echo htmlspecialchars($b['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- University -->
                    <div class="col-md-3">
                        <label class="form-label">University</label>
                        <select name="university_id[]" class="form-select multi-select" multiple>
                            <?php while ($u = pg_fetch_assoc($universities)): ?>
                                <option value="<?php echo $u['university_id']; ?>">
                                    <?php echo htmlspecialchars($u['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Year Level -->
                    <div class="col-md-3">
                        <label class="form-label">Year Level</label>
                        <select name="year_level_id[]" class="form-select multi-select" multiple>
                            <?php while ($yl = pg_fetch_assoc($yearLevels)): ?>
                                <option value="<?php echo $yl['year_level_id']; ?>">
                                    <?php echo htmlspecialchars($yl['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Academic Year -->
                    <div class="col-md-3">
                        <label class="form-label">Academic Year</label>
                        <select name="academic_year" class="form-select">
                            <option value="">All Years</option>
                            <?php while ($ay = pg_fetch_assoc($academicYears)): ?>
                                <option value="<?php echo htmlspecialchars($ay['current_academic_year']); ?>">
                                    <?php echo htmlspecialchars($ay['current_academic_year']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Distribution -->
                    <div class="col-md-3">
                        <label class="form-label">Distribution</label>
                        <select name="distribution_id" class="form-select">
                            <option value="">All Distributions</option>
                            <?php while ($d = pg_fetch_assoc($distributions)): ?>
                                <option value="<?php echo $d['snapshot_id']; ?>">
                                    <?php 
                                    echo htmlspecialchars($d['distribution_id']) . ' - ' . 
                                         htmlspecialchars($d['academic_year']) . ' ' . 
                                         htmlspecialchars($d['semester']); 
                                    ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="col-md-3">
                        <label class="form-label">Registration Date From</label>
                        <input type="date" name="date_from" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Registration Date To</label>
                        <input type="date" name="date_to" class="form-control">
                    </div>

                    <!-- Confidence Score Range -->
                    <div class="col-md-3">
                        <label class="form-label">Min Confidence Score</label>
                        <input type="number" name="confidence_min" class="form-control" min="0" max="100" placeholder="0-100">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Max Confidence Score</label>
                        <input type="number" name="confidence_max" class="form-control" min="0" max="100" placeholder="0-100">
                    </div>
                </div>

                <hr class="my-4">

                <!-- Action Buttons -->
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary btn-lg" onclick="previewReport()">Preview Report</button>
                        <button type="button" class="btn btn-danger" onclick="exportPDF()">
                            Export PDF
                        </button>
                        <button type="button" class="btn btn-success" onclick="exportExcel()">Export Excel</button>
                    </div>
                    <div>
                        <small class="text-muted" id="filterSummary">Select filters and click Preview</small>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Statistics Overview -->
    <div id="statisticsPanel" class="row g-3 mb-4" style="display: none;">
        <div class="col-md-3">
            <div class="card stat-card bg-gradient-primary text-white">
                <div class="card-body">
                    <i class="bi bi-people-fill watermark-icon"></i>
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title mb-1">Total Students</h6>
                            <h2 class="mb-0" id="statTotalStudents">0</h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card bg-gradient-success text-white">
                <div class="card-body">
                    <i class="bi bi-gender-male watermark-icon"></i>
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title mb-1">Male</h6>
                            <h2 class="mb-0" id="statMale">0</h2>
                            <small id="statMalePercent">0%</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card bg-gradient-info text-white">
                <div class="card-body">
                    <i class="bi bi-gender-female watermark-icon"></i>
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title mb-1">Female</h6>
                            <h2 class="mb-0" id="statFemale">0</h2>
                            <small id="statFemalePercent">0%</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card bg-gradient-warning text-white">
                <div class="card-body">
                    <i class="bi bi-graph-up watermark-icon"></i>
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title mb-1">Avg Confidence</h6>
                            <h2 class="mb-0" id="statConfidence">0%</h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Results -->
    <div id="previewPanel" class="card" style="display: none; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 2px 12px rgba(0,0,0,0.06);">
        <div class="card-header d-flex justify-content-between align-items-center" style="background: #fff; border-bottom: 1px solid #e2e8f0; border-radius: 16px 16px 0 0; padding: 1rem 1.25rem;">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-table me-2 text-muted"></i>Report Preview
                <span class="badge ms-2" style="background: #3b82f6; font-weight: 500;" id="previewCount">0 records</span>
            </h5>
            <button class="btn btn-sm btn-outline-secondary" onclick="$('#previewPanel').fadeOut()" style="border-radius: 8px;">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
        <div class="card-body">
            <div class="alert" style="background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; border-radius: 10px;">
                <strong>Preview Mode:</strong> Showing up to 50 records. Export to PDF/Excel for complete dataset.
            </div>
            <div class="table-responsive">
                <table class="table table-hover compact-cards" id="previewTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 5%;">No.</th>
                            <th style="width: 12%;">Student ID</th>
                            <th style="width: 18%;">Name</th>
                            <th style="width: 8%;">Gender</th>
                            <th style="width: 15%;">Barangay</th>
                            <th style="width: 18%;">University</th>
                            <th style="width: 12%;">Year Level</th>
                            <th style="width: 12%;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="previewTableBody">
                        <tr>
                            <td colspan="8" class="text-center text-muted">
                                <i class="bi bi-inbox"></i> No data to display
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

    </section>
  </div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="../../assets/js/admin/reports.js"></script>

</body>
</html>

<?php
// Close database connection
if (isset($connection)) {
    pg_close($connection);
}
?>
