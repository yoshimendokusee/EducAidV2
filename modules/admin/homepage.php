<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Demo mode handling: support a persistent toggle via session + robust query parsing
// 1) Toggle: ?toggle_demo=1 -> flips the current mode and redirects (PRG)
if (isset($_GET['toggle_demo'])) {
  $_SESSION['DEMO_MODE'] = !($_SESSION['DEMO_MODE'] ?? false);
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

// 2) Explicit set: ?demo=1|true|yes|on or ?demo=0|false|no|off -> sets and redirects (PRG)
if (isset($_GET['demo'])) {
  $val = strtolower((string)$_GET['demo']);
  $truthy = ['1','true','yes','on'];
  $falsy  = ['0','false','no','off',''];
  if (in_array($val, $truthy, true)) {
    $_SESSION['DEMO_MODE'] = true;
  } elseif (in_array($val, $falsy, true)) {
    $_SESSION['DEMO_MODE'] = false;
  }
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

// Effective mode comes from session (defaults to off)
$DEMO_MODE = $_SESSION['DEMO_MODE'] ?? false;

// Only include DB when not in demo mode
if (!$DEMO_MODE) {
  include __DIR__ . '/../../config/database.php';
}
if (!isset($_SESSION['admin_username'])) {
  header("Location: ../../unified_login.php");
  exit;
}

$municipality_id = 1; // Default municipality

// Fetch municipality max capacity (or use demo value)
$maxCapacity = null;
if ($DEMO_MODE) {
  $maxCapacity = 1200;
} else {
  $capacityResult = pg_query_params($connection, "SELECT max_capacity FROM municipalities WHERE municipality_id = $1", [$municipality_id]);
  if ($capacityResult && pg_num_rows($capacityResult) > 0) {
    $capacityRow = pg_fetch_assoc($capacityResult);
    $maxCapacity = $capacityRow['max_capacity'];
  }
}

// Fetch barangay distribution (or use demo data)
$barangayLabels = $barangayVerified = $barangayApplicant = [];
if ($DEMO_MODE) {
    $barangayLabels = ['Santiago','San Roque','Poblacion','Mataas na Bayan','Bucal','Tamacan'];
    $barangayVerified = [84, 67, 112, 93, 45, 61];
    $barangayApplicant = [40, 25, 58, 32, 18, 27];
} else {
    $barangayRes = pg_query($connection, "
      SELECT b.name AS barangay,
             SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN status='applicant' THEN 1 ELSE 0 END) AS applicant
      FROM students st
      JOIN barangays b ON st.barangay_id = b.barangay_id
      GROUP BY b.name
      HAVING COUNT(*) > 0
      ORDER BY b.name");
    while ($row = pg_fetch_assoc($barangayRes)) {
        $barangayLabels[] = $row['barangay'];
        $barangayVerified[] = (int)$row['verified'];
        $barangayApplicant[] = (int)$row['applicant'];
    }
}

// Fetch gender distribution (or use demo data)
$genderLabels = $genderVerified = $genderApplicant = [];
if ($DEMO_MODE) {
    $genderLabels = ['Male','Female'];
    $genderVerified = [260, 224];
    $genderApplicant = [130, 228];
} else {
    $genderRes = pg_query($connection, "
      SELECT sex AS gender,
             SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN status='applicant' THEN 1 ELSE 0 END) AS applicant
      FROM students
      WHERE sex IS NOT NULL
      GROUP BY sex
      HAVING COUNT(*) > 0
      ORDER BY sex");
    while ($row = pg_fetch_assoc($genderRes)) {
        $genderLabels[] = $row['gender'];
        $genderVerified[] = (int)$row['verified'];
        $genderApplicant[] = (int)$row['applicant'];
    }
}

// Fetch university distribution (or use demo data)
$universityLabels = $universityVerified = $universityApplicant = [];
if ($DEMO_MODE) {
    $universityLabels = [
      'Lyceum of the Philippines University - Cavite',
      'Cavite State University',
      'De La Salle University - Dasmariñas',
      'Far Eastern University',
      'Polytechnic University of the Philippines'
    ];
    $universityVerified = [95, 132, 78, 54, 60];
    $universityApplicant = [42, 66, 40, 22, 30];
} else {
    $universityRes = pg_query($connection, "
      SELECT u.name AS university,
             SUM(CASE WHEN st.status='active' THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN st.status='applicant' THEN 1 ELSE 0 END) AS applicant
      FROM students st
      JOIN universities u ON st.university_id = u.university_id
      GROUP BY u.name
      HAVING COUNT(*) > 0
      ORDER BY u.name");
    while ($row = pg_fetch_assoc($universityRes)) {
        $universityLabels[] = $row['university'];
        $universityVerified[] = (int)$row['verified'];
        $universityApplicant[] = (int)$row['applicant'];
    }
}

// Fetch year level distribution (or use demo data)
$yearLevelLabels = $yearLevelVerified = $yearLevelApplicant = [];
if ($DEMO_MODE) {
    $yearLevelLabels = ['1st Year','2nd Year','3rd Year','4th Year'];
    $yearLevelVerified = [140, 160, 100, 84];
    $yearLevelApplicant = [90, 72, 56, 40];
} else {
    $yearLevelRes = pg_query($connection, "
      SELECT yl.name AS year_level,
             SUM(CASE WHEN st.status='active' THEN 1 ELSE 0 END) AS verified,
             SUM(CASE WHEN st.status='applicant' THEN 1 ELSE 0 END) AS applicant
      FROM students st
      JOIN year_levels yl ON st.year_level_id = yl.year_level_id
      GROUP BY yl.name, yl.sort_order
      HAVING COUNT(*) > 0
      ORDER BY yl.sort_order");
    while ($row = pg_fetch_assoc($yearLevelRes)) {
        $yearLevelLabels[] = $row['year_level'];
        $yearLevelVerified[] = (int)$row['verified'];
        $yearLevelApplicant[] = (int)$row['applicant'];
    }
}

// Fetch past distributions (or use demo data)
$pastDistributions = [];
if ($DEMO_MODE) {
    $pastDistributions = [
      [
        'snapshot_id' => 1,
        'distribution_date' => date('Y-m-d', strtotime('-20 days')),
        'location' => 'Town Plaza',
        'total_students_count' => 520,
        'academic_year' => '2025-2026',
        'semester' => '1st Sem',
        'finalized_at' => date('Y-m-d H:i:s', strtotime('-19 days 15:00')),
        'notes' => 'Smooth distribution; minor queue delays.',
        'finalized_by_name' => 'Admin User'
      ],
      [
        'snapshot_id' => 2,
        'distribution_date' => date('Y-m-d', strtotime('-90 days')),
        'location' => 'Municipal Gym',
        'total_students_count' => 480,
        'academic_year' => '2024-2025',
        'semester' => '2nd Sem',
        'finalized_at' => date('Y-m-d H:i:s', strtotime('-89 days 14:30')),
        'notes' => '',
        'finalized_by_name' => 'Jane Doe'
      ],
      [
        'snapshot_id' => 3,
        'distribution_date' => date('Y-m-d', strtotime('-200 days')),
        'location' => 'Barangay Hall',
        'total_students_count' => 450,
        'academic_year' => '2024-2025',
        'semester' => '1st Sem',
        'finalized_at' => date('Y-m-d H:i:s', strtotime('-199 days 10:15')),
        'notes' => 'Special priority lanes tested successfully.',
        'finalized_by_name' => 'John Smith'
      ]
    ];
} else {
    $pastDistributionsRes = pg_query($connection, "
      SELECT 
        ds.snapshot_id,
        ds.distribution_date,
        ds.location,
        ds.total_students_count,
        ds.academic_year,
        ds.semester,
        ds.finalized_at,
        ds.notes,
        CONCAT(a.first_name, ' ', a.last_name) as finalized_by_name
      FROM distribution_snapshots ds
      LEFT JOIN admins a ON ds.finalized_by = a.admin_id
      ORDER BY ds.finalized_at DESC
      LIMIT 5
    ");
    if ($pastDistributionsRes) {
        while ($row = pg_fetch_assoc($pastDistributionsRes)) {
            $pastDistributions[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard</title>
  
  <!-- Critical CSS for FOUC prevention -->
  <style>
    body { opacity: 0; transition: opacity 0.3s ease; }
    body.ready { opacity: 1; }
    .admin-wrapper { min-height: 100vh; }
    .home-section { background: #f5f5f5; }
  </style>
  
  <link rel="stylesheet" href="../../assets/css/bootstrap.min.css" />
  <link rel="stylesheet" href="../../assets/css/bootstrap-icons.css" />
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css" />
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css" />
  <link rel="stylesheet" href="../../assets/css/admin/table_core.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
</head>
<body>
  <?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
  
  <div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">

      <div class="container-fluid py-4 px-4">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <h1 class="fw-bold mb-1 mb-2 mb-sm-0">Welcome, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></h1>
          <a class="btn btn-sm <?php echo $DEMO_MODE ? 'btn-warning' : 'btn-outline-warning'; ?> ms-sm-2" href="?toggle_demo=1" title="Toggle demo mode">
            <i class="bi bi-camera-video me-1"></i><?php echo $DEMO_MODE ? 'Exit Demo' : 'Enter Demo'; ?>
          </a>
          <?php if ($DEMO_MODE): ?>
            <span class="badge bg-warning text-dark ms-2"><i class="bi bi-lightning-charge me-1"></i>Demo Mode</span>
          <?php endif; ?>
        </div>
        <p class="text-muted mb-0">Here you can manage student registrations, verify applicants, and more.</p>

        <!-- Dashboard Stats Cards -->
        <div class="stats-grid">
          <div class="stat-card stat-blue">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div class="stat-content">
              <span class="stat-value">
                <?php
                  if ($DEMO_MODE) {
                    $totalStudents = array_sum($genderVerified ?? []) + array_sum($genderApplicant ?? []);
                  } else {
                    $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status IN ('applicant', 'active', 'given')");
                    $row = pg_fetch_assoc($result);
                    $totalStudents = (int)$row['total'];
                  }
                  echo $maxCapacity !== null ? ($totalStudents . '/' . $maxCapacity) : $totalStudents;
                ?>
              </span>
              <span class="stat-label"><?php echo $maxCapacity !== null ? 'Students / Capacity' : 'Total Students'; ?></span>
            </div>
          </div>

          <div class="stat-card stat-orange">
            <div class="stat-icon"><i class="bi bi-pencil-square"></i></div>
            <div class="stat-content">
              <span class="stat-value">
                <?php
                  if ($DEMO_MODE) {
                    echo 37;
                  } else {
                    $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'under_registration'");
                    $row = pg_fetch_assoc($result);
                    echo $row['total'];
                  }
                ?>
              </span>
              <span class="stat-label">Still on Registration</span>
            </div>
          </div>

          <div class="stat-card stat-amber">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-content">
              <span class="stat-value">
                <?php
                  if ($DEMO_MODE) {
                    echo array_sum($genderApplicant ?? []);
                  } else {
                    $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'applicant'");
                    $row = pg_fetch_assoc($result);
                    echo $row['total'];
                  }
                ?>
              </span>
              <span class="stat-label">Pending Applications</span>
            </div>
          </div>

          <div class="stat-card stat-green">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-content">
              <span class="stat-value">
                <?php
                  if ($DEMO_MODE) {
                    echo array_sum($genderVerified ?? []);
                  } else {
                    $result = pg_query($connection, "SELECT COUNT(*) AS total FROM students WHERE status = 'active'");
                    $row = pg_fetch_assoc($result);
                    echo $row['total'];
                  }
                ?>
              </span>
              <span class="stat-label">Verified Students</span>
            </div>
          </div>
          
          <div class="stat-card stat-purple">
            <div class="stat-icon"><i class="bi bi-shield-check"></i></div>
            <div class="stat-content">
              <span class="stat-value">
                <?php
                  if ($DEMO_MODE) {
                    echo '47';
                  } else {
                    $blockedQuery = "SELECT COUNT(*) AS total FROM household_block_attempts WHERE blocked_at >= CURRENT_DATE - INTERVAL '30 days'";
                    $blockedResult = @pg_query($connection, $blockedQuery);
                    if ($blockedResult) {
                      $blockedRow = pg_fetch_assoc($blockedResult);
                      echo $blockedRow['total'] ?? 0;
                    } else {
                      echo '0';
                    }
                  }
                ?>
              </span>
              <span class="stat-label">Household Blocks (30d)</span>
            </div>
          </div>
          
          <div class="stat-card stat-red">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-content">
              <span class="stat-value">
                <?php
                  if ($DEMO_MODE) {
                    echo '3';
                  } else {
                    $reviewQuery = "SELECT COUNT(*) AS total FROM students WHERE admin_review_required = TRUE AND is_archived = FALSE";
                    $reviewResult = @pg_query($connection, $reviewQuery);
                    if ($reviewResult) {
                      $reviewRow = pg_fetch_assoc($reviewResult);
                      echo $reviewRow['total'] ?? 0;
                    } else {
                      echo '0';
                    }
                  }
                ?>
              </span>
              <span class="stat-label">Requires Review</span>
            </div>
          </div>
        </div>

        <style>
          .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
          }
          .stat-card {
            position: relative;
            padding: 1.5rem;
            border-radius: 16px;
            color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 120px;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
          }
          .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, transparent 50%);
            pointer-events: none;
          }
          .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
          }
          .stat-icon {
            position: absolute;
            top: 50%;
            right: 1rem;
            transform: translateY(-50%);
            font-size: 3rem;
            opacity: 0.2;
          }
          .stat-content {
            position: relative;
            z-index: 1;
          }
          .stat-value {
            display: block;
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 0.25rem;
          }
          .stat-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            opacity: 0.9;
          }
          /* Modern gradient backgrounds */
          .stat-blue {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            box-shadow: 0 4px 14px rgba(59, 130, 246, 0.35);
          }
          .stat-orange {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            box-shadow: 0 4px 14px rgba(249, 115, 22, 0.35);
          }
          .stat-amber {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
          }
          .stat-green {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            box-shadow: 0 4px 14px rgba(34, 197, 94, 0.35);
          }
          .stat-purple {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            box-shadow: 0 4px 14px rgba(139, 92, 246, 0.35);
          }
          .stat-red {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
          }
          @media (max-width: 768px) {
            .stats-grid {
              grid-template-columns: repeat(2, 1fr);
              gap: 0.75rem;
            }
            .stat-card {
              padding: 1.25rem;
              min-height: 100px;
              border-radius: 12px;
            }
            .stat-value {
              font-size: 1.5rem;
            }
            .stat-label {
              font-size: 0.8rem;
            }
            .stat-icon {
              font-size: 2.5rem;
              right: 0.75rem;
            }
          }
          @media (max-width: 480px) {
            .stat-card {
              padding: 1rem;
            }
            .stat-value {
              font-size: 1.35rem;
            }
          }
        </style>

        <!-- Website Content Management (Super Admin Only) -->
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
        <div class="row g-4 mt-4">
          <div class="col-12">
            <div class="custom-card">
              <div class="custom-card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-pencil-square me-2"></i>Website Content Management</h5>
                <span class="badge bg-white text-info">Super Admin Access</span>
              </div>
              <div class="custom-card-body">
                <p class="text-muted mb-3">
                  <i class="bi bi-info-circle me-1"></i>
                  Edit static content on public website pages. Click "Edit Page" to modify text, colors, and layout.
                </p>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead class="table-light">
                      <tr>
                        <th style="width: 30%;"><i class="bi bi-file-earmark-text me-1"></i>Page</th>
                        <th style="width: 15%;" class="text-center"><i class="bi bi-puzzle me-1"></i>Blocks</th>
                        <th style="width: 20%;" class="text-center"><i class="bi bi-clock-history me-1"></i>Last Updated</th>
                        <th style="width: 20%;" class="text-center"><i class="bi bi-eye me-1"></i>View</th>
                        <th style="width: 15%;" class="text-center"><i class="bi bi-pencil me-1"></i>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <i class="bi bi-house-door text-primary me-2"></i>
                          <strong>Landing Page</strong>
                          <br><small class="text-muted">Homepage with hero, features, FAQs</small>
                        </td>
                        <td class="text-center"><span class="badge bg-primary">50+ blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $lpUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM landing_content_blocks WHERE municipality_id=1");
                              if ($lpUpdate && $row = pg_fetch_assoc($lpUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/landingpage.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/landingpage.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <i class="bi bi-info-circle text-success me-2"></i>
                          <strong>About Page</strong>
                          <br><small class="text-muted">Program information & history</small>
                        </td>
                        <td class="text-center"><span class="badge bg-success">20+ blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $aboutUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM about_content_blocks WHERE municipality_id=1");
                              if ($aboutUpdate && $row = pg_fetch_assoc($aboutUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/about.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/about.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <i class="bi bi-diagram-3 text-info me-2"></i>
                          <strong>How It Works</strong>
                          <br><small class="text-muted">Application process & steps</small>
                        </td>
                        <td class="text-center"><span class="badge bg-info">13 blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $hiwUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM how_it_works_content_blocks WHERE municipality_id=1");
                              if ($hiwUpdate && $row = pg_fetch_assoc($hiwUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/how-it-works.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/how-it-works.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <i class="bi bi-card-checklist text-warning me-2"></i>
                          <strong>Requirements</strong>
                          <br><small class="text-muted">Document requirements & checklist</small>
                        </td>
                        <td class="text-center"><span class="badge bg-warning text-dark">52 blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $reqUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM requirements_content_blocks WHERE municipality_id=1");
                              if ($reqUpdate && $row = pg_fetch_assoc($reqUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/requirements.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/requirements.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <i class="bi bi-megaphone text-danger me-2"></i>
                          <strong>Announcements</strong>
                          <br><small class="text-muted">Page headers & descriptions</small>
                        </td>
                        <td class="text-center"><span class="badge bg-danger">7 blocks</span></td>
                        <td class="text-center text-muted small">
                          <?php
                            if (!$DEMO_MODE) {
                              $annUpdate = pg_query($connection, "SELECT MAX(updated_at) as last_update FROM announcements_content_blocks WHERE municipality_id=1");
                              if ($annUpdate && $row = pg_fetch_assoc($annUpdate)) {
                                echo $row['last_update'] ? date('M d, Y H:i', strtotime($row['last_update'])) : 'Never';
                              } else { echo 'Never'; }
                            } else { echo 'Demo Mode'; }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/announcements.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/announcements.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center">
                            <div class="page-icon me-2" style="background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);">
                              <i class="bi bi-envelope-fill"></i>
                            </div>
                            <div>
                              <div class="fw-semibold">Contact</div>
                              <small class="text-muted">Contact information & inquiry form</small>
                            </div>
                          </div>
                        </td>
                        <td class="text-center">
                          <?php
                          try {
                            $stmt = $connection->query("SELECT COUNT(*) as count FROM contact_content_blocks WHERE municipality_id = 1");
                            $count = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo '<span class="badge bg-secondary">' . ($count['count'] ?? 0) . ' blocks</span>';
                          } catch (Exception $e) {
                            echo '<span class="badge bg-danger">Error</span>';
                          }
                          ?>
                        </td>
                        <td class="text-center">
                          <?php
                          try {
                            $stmt = $connection->query("SELECT MAX(updated_at) as last_updated FROM contact_content_blocks WHERE municipality_id = 1");
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($result['last_updated']) {
                              $date = new DateTime($result['last_updated']);
                              echo '<small class="text-muted">' . $date->format('M d, Y g:i A') . '</small>';
                            } else {
                              echo '<small class="text-muted">Never edited</small>';
                            }
                          } catch (Exception $e) {
                            echo '<small class="text-muted">N/A</small>';
                          }
                          ?>
                        </td>
                        <td class="text-center">
                          <a href="../../website/contact.php" class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Visit
                          </a>
                        </td>
                        <td class="text-center">
                          <a href="../../website/contact.php?edit=1" class="btn btn-sm btn-warning">
                            <i class="bi bi-pencil-fill me-1"></i>Edit Page
                          </a>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="alert alert-info d-flex align-items-center mt-3 mb-0">
                  <i class="bi bi-lightbulb me-2 flex-shrink-0"></i>
                  <div>
                    <strong>Tip:</strong> Changes are saved per page and can be rolled back via the history feature in each page editor.
                    The "Blocks" count represents editable content sections on each page.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Unified Chart with Filters -->
        <div class="row g-4 mt-4">
          <div class="col-12">
            <div class="chart-card">
              <div class="chart-card-header">
                <div class="chart-title">
                  <span class="chart-icon"><i class="bi bi-bar-chart-line"></i></span>
                  <div>
                    <h5 id="chartTitle">Student Distribution</h5>
                    <p class="chart-subtitle">Overview of student demographics</p>
                  </div>
                </div>
                <select id="chartFilter" class="chart-filter-select">
                  <option value="gender">By Gender</option>
                  <option value="barangay">By Barangay</option>
                  <option value="university">By University</option>
                  <option value="yearLevel">By Year Level</option>
                </select>
              </div>
              <div class="chart-card-body">
                <div id="unifiedChart" style="min-height: 380px;"></div>
              </div>
            </div>
          </div>
        </div>

        <style>
          .chart-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            overflow: hidden;
          }
          .chart-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
          }
          .chart-card-header {
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
            background: #fafbfc;
          }
          .chart-title {
            display: flex;
            align-items: center;
            gap: 0.875rem;
          }
          .chart-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4f7df3 0%, #3b5fc7 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
          }
          .chart-title h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
          }
          .chart-subtitle {
            margin: 0;
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 400;
          }
          .chart-filter-select {
            padding: 0.5rem 2rem 0.5rem 0.875rem;
            font-size: 0.85rem;
            font-weight: 500;
            color: #374151;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.25em 1.25em;
            transition: border-color 0.15s, box-shadow 0.15s;
          }
          .chart-filter-select:hover {
            border-color: #d1d5db;
          }
          .chart-filter-select:focus {
            outline: none;
            border-color: #4f7df3;
            box-shadow: 0 0 0 3px rgba(79,125,243,0.1);
          }
          .chart-card-body {
            padding: 1.25rem 1.5rem 1.5rem;
          }
          @media (max-width: 576px) {
            .chart-card-header {
              flex-direction: column;
              align-items: flex-start;
              gap: 1rem;
            }
            .chart-filter-select {
              width: 100%;
            }
          }
        </style>

        <!-- Past Distributions Section -->
        <?php if (!empty($pastDistributions)): ?>
        <div class="row g-4 mt-4">
          <div class="col-12">
            <div class="distribution-history-section">
              <div class="section-header">
                <div class="header-content">
                  <div class="header-icon">
                    <i class="bi bi-clock-history"></i>
                  </div>
                  <div>
                    <h5 class="mb-1">Recent Distribution History</h5>
                    <p class="text-muted small mb-0">Track and review past distribution events</p>
                  </div>
                </div>
                <a href="distribution_archives.php" class="btn-view-all">
                  <span>View All</span>
                  <i class="bi bi-arrow-right ms-2"></i>
                </a>
              </div>
              
              <div class="distribution-timeline">
                <?php foreach ($pastDistributions as $index => $distribution): ?>
                <div class="timeline-item">
                  <div class="timeline-marker">
                    <div class="marker-dot"></div>
                    <div class="marker-line"></div>
                  </div>
                  
                  <div class="timeline-content">
                    <div class="distribution-card">
                      <div class="card-header-row">
                        <div class="distribution-date">
                          <i class="bi bi-calendar-event"></i>
                          <span><?php echo date('F d, Y', strtotime($distribution['distribution_date'])); ?></span>
                        </div>
                        <div class="student-count">
                          <i class="bi bi-people-fill"></i>
                          <span><?php echo number_format($distribution['total_students_count']); ?></span>
                        </div>
                      </div>
                      
                      <div class="location-info">
                        <i class="bi bi-geo-alt-fill"></i>
                        <h6><?php echo htmlspecialchars($distribution['location']); ?></h6>
                      </div>
                      
                      <?php if ($distribution['academic_year'] && $distribution['semester']): ?>
                      <div class="academic-info">
                        <span class="info-badge">
                          <i class="bi bi-mortarboard-fill"></i>
                          <?php echo htmlspecialchars($distribution['academic_year']); ?>
                        </span>
                        <span class="info-badge">
                          <i class="bi bi-calendar3"></i>
                          <?php echo htmlspecialchars($distribution['semester']); ?>
                        </span>
                      </div>
                      <?php endif; ?>
                      
                      <div class="card-footer-row">
                        <div class="finalized-by">
                          <i class="bi bi-person-check-fill"></i>
                          <span><?php echo htmlspecialchars($distribution['finalized_by_name'] ?: 'Unknown'); ?></span>
                        </div>
                        <div class="finalized-time">
                          <i class="bi bi-clock-fill"></i>
                          <span><?php echo date('M d, Y \a\t H:i', strtotime($distribution['finalized_at'])); ?></span>
                        </div>
                      </div>
                      
                      <?php if (!empty($distribution['notes']) && $index === 0): ?>
                      <div class="distribution-notes">
                        <div class="notes-header">
                          <i class="bi bi-sticky-fill"></i>
                          <span>Notes</span>
                        </div>
                        <p><?php echo nl2br(htmlspecialchars($distribution['notes'])); ?></p>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>

  <!-- Chart.js Data -->
  <script>
    // All chart data
    window.chartData = {
      gender: {
        labels: <?php echo json_encode($genderLabels); ?>,
        verified: <?php echo json_encode($genderVerified); ?>,
        applicant: <?php echo json_encode($genderApplicant); ?>,
        title: "Gender Distribution",
        icon: "bi-gender-ambiguous",
        horizontal: false
      },
      barangay: {
        labels: <?php echo json_encode($barangayLabels); ?>,
        verified: <?php echo json_encode($barangayVerified); ?>,
        applicant: <?php echo json_encode($barangayApplicant); ?>,
        title: "Barangay Distribution",
        icon: "bi-house-door-fill",
        horizontal: true
      },
      university: {
        labels: <?php echo json_encode($universityLabels); ?>,
        verified: <?php echo json_encode($universityVerified); ?>,
        applicant: <?php echo json_encode($universityApplicant); ?>,
        title: "University Distribution",
        icon: "bi-building",
        horizontal: true
      },
      yearLevel: {
        labels: <?php echo json_encode($yearLevelLabels); ?>,
        verified: <?php echo json_encode($yearLevelVerified); ?>,
        applicant: <?php echo json_encode($yearLevelApplicant); ?>,
        title: "Year Level Distribution",
        icon: "bi-mortarboard",
        horizontal: false
      }
    };
  </script>

  <script>
    let unifiedChart = null;

    function isDataEmpty(verified, applicant) {
      const allVerifiedZero = verified.every(val => val === 0);
      const allApplicantZero = applicant.every(val => val === 0);
      return allVerifiedZero && allApplicantZero;
    }

    function showNoDataMessage(containerId, message = "No data available") {
      const container = document.getElementById(containerId);
      if (container) {
        container.innerHTML = `
          <div class="d-flex flex-column align-items-center justify-content-center" style="min-height: 400px;">
            <i class="bi bi-info-circle text-muted" style="font-size: 3rem; opacity: 0.5;"></i>
            <p class="text-muted mt-3">${message}</p>
          </div>
        `;
      }
    }

    function updateChartTitle(filterType) {
      const titleElement = document.getElementById('chartTitle');
      const data = window.chartData[filterType];
      if (titleElement && data) {
        titleElement.textContent = data.title;
      }
    }

    function createUnifiedChart(filterType = 'gender') {
      const container = document.getElementById('unifiedChart');
      if (!container) return;

      const data = window.chartData[filterType];
      if (!data) return;

      // Update chart title
      updateChartTitle(filterType);

      // Check if data is empty
      if (isDataEmpty(data.verified, data.applicant)) {
        if (unifiedChart) {
          unifiedChart.destroy();
          unifiedChart = null;
        }
        showNoDataMessage('unifiedChart', `No ${filterType.toLowerCase()} data available`);
        return;
      }

      // Destroy existing chart
      if (unifiedChart) {
        unifiedChart.destroy();
        unifiedChart = null;
      }

      // Clear container
      container.innerHTML = '';

      // Calculate dynamic height for horizontal charts
      const itemCount = data.labels.length;
      const isHorizontal = data.horizontal;
      const chartHeight = isHorizontal ? Math.max(380, itemCount * 45) : 380;

      // Professional ApexCharts configuration
      const options = {
        series: [
          {
            name: 'Verified',
            data: data.verified
          },
          {
            name: 'Applicant',
            data: data.applicant
          }
        ],
        chart: {
          type: 'bar',
          height: chartHeight,
          fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
          toolbar: {
            show: false
          },
          animations: {
            enabled: true,
            easing: 'easeinout',
            speed: 400,
            animateGradually: {
              enabled: true,
              delay: 50
            }
          },
          background: 'transparent'
        },
        plotOptions: {
          bar: {
            horizontal: isHorizontal,
            borderRadius: 4,
            columnWidth: isHorizontal ? '65%' : '55%',
            barHeight: '65%',
            distributed: false,
            dataLabels: {
              position: 'top'
            }
          }
        },
        colors: ['#4f7df3', '#94a3b8'],
        dataLabels: {
          enabled: !isHorizontal && itemCount <= 6,
          formatter: function(val) {
            return val > 0 ? val : '';
          },
          offsetY: -20,
          style: {
            fontSize: '11px',
            fontWeight: 600,
            colors: ['#64748b']
          }
        },
        stroke: {
          show: false
        },
        xaxis: {
          categories: data.labels,
          labels: {
            style: {
              fontSize: '12px',
              fontWeight: 400,
              colors: '#64748b'
            },
            rotate: isHorizontal ? 0 : (itemCount > 4 ? -45 : 0),
            rotateAlways: !isHorizontal && itemCount > 4,
            trim: true,
            maxHeight: 100
          },
          axisBorder: {
            show: false
          },
          axisTicks: {
            show: false
          }
        },
        yaxis: {
          labels: {
            style: {
              fontSize: '12px',
              fontWeight: 400,
              colors: '#64748b'
            },
            maxWidth: isHorizontal ? 180 : undefined,
            formatter: function(val) {
              if (isHorizontal && typeof val === 'string' && val.length > 28) {
                return val.substring(0, 28) + '...';
              }
              return val;
            }
          }
        },
        fill: {
          opacity: 1,
          type: 'solid'
        },
        tooltip: {
          enabled: true,
          shared: true,
          intersect: false,
          y: {
            formatter: function(val) {
              return val + " students";
            }
          },
          style: {
            fontSize: '12px',
            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
          },
          marker: {
            show: true
          }
        },
        legend: {
          show: true,
          position: 'top',
          horizontalAlign: 'right',
          fontSize: '12px',
          fontWeight: 500,
          labels: {
            colors: '#64748b'
          },
          markers: {
            width: 10,
            height: 10,
            radius: 2
          },
          itemMargin: {
            horizontal: 12,
            vertical: 0
          }
        },
        grid: {
          show: true,
          borderColor: '#e2e8f0',
          strokeDashArray: 0,
          position: 'back',
          xaxis: {
            lines: {
              show: isHorizontal
            }
          },
          yaxis: {
            lines: {
              show: !isHorizontal
            }
          },
          padding: {
            top: 0,
            right: 20,
            bottom: 0,
            left: 15
          }
        },
        states: {
          hover: {
            filter: {
              type: 'darken',
              value: 0.9
            }
          },
          active: {
            filter: {
              type: 'darken',
              value: 0.85
            }
          }
        },
        responsive: [
          {
            breakpoint: 768,
            options: {
              chart: {
                height: isHorizontal ? Math.max(320, itemCount * 40) : 320
              },
              plotOptions: {
                bar: {
                  columnWidth: '70%',
                  barHeight: '75%'
                }
              },
              dataLabels: {
                enabled: false
              },
              legend: {
                position: 'top',
                horizontalAlign: 'center'
              },
              xaxis: {
                labels: {
                  rotate: -45,
                  style: {
                    fontSize: '10px'
                  }
                }
              },
              yaxis: {
                labels: {
                  style: {
                    fontSize: '10px'
                  },
                  maxWidth: 120
                }
              }
            }
          }
        ]
      };

      // Create new chart
      unifiedChart = new ApexCharts(container, options);
      unifiedChart.render();
    }

    document.addEventListener("DOMContentLoaded", () => {
      // Initialize chart with default filter
      createUnifiedChart('gender');

      // Add event listener for filter change
      const filterSelect = document.getElementById('chartFilter');
      if (filterSelect) {
        filterSelect.addEventListener('change', (e) => {
          createUnifiedChart(e.target.value);
        });
      }
    });
  </script>
  
  <!-- Anti-FOUC Script -->
  <script>
    (function() {
      document.body.classList.add('ready');
      
      // Handle bfcache (back/forward cache)
      window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
          document.body.classList.add('ready');
        }
      });
    })();
  </script>
</body>
</html>

<?php if (!$DEMO_MODE && isset($connection)) { pg_close($connection); } ?>
