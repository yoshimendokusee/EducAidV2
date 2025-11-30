<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRFProtection.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Check if current admin is super_admin
$current_admin_role = 'super_admin'; // Default for backward compatibility
if (isset($_SESSION['admin_id'])) {
    $roleQuery = pg_query_params($connection, "SELECT role FROM admins WHERE admin_id = $1", [$_SESSION['admin_id']]);
    $roleData = pg_fetch_assoc($roleQuery);
    $current_admin_role = $roleData['role'] ?? 'super_admin';
}

// Only super_admin can access this page
if ($current_admin_role !== 'super_admin') {
    header("Location: homepage.php");
    exit;
}

// Generate CSRF tokens for forms
$csrfTokenCreateAdmin = CSRFProtection::generateToken('create_admin');
$csrfTokenToggleStatus = CSRFProtection::generateToken('toggle_admin_status');

// Super Admin limit constant
define('MAX_SUPER_ADMINS', 3);

// Count current super admins
$superAdminCountQuery = pg_query($connection, "SELECT COUNT(*) as count FROM admins WHERE role = 'super_admin'");
$superAdminCount = (int) pg_fetch_result($superAdminCountQuery, 0, 'count');
$superAdminLimitReached = $superAdminCount >= MAX_SUPER_ADMINS;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_admin'])) {
        // CSRF validation
        $token = $_POST['csrf_token'] ?? '';
        if (!CSRFProtection::validateToken('create_admin', $token)) {
            $error = "Security validation failed. Please refresh the page.";
        } else {
            $first_name = trim($_POST['first_name']);
            $middle_name = trim($_POST['middle_name']);
            $last_name = trim($_POST['last_name']);
            $email = trim($_POST['email']);
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $role = $_POST['role'];
            
            // Check super admin limit before creating
            if ($role === 'super_admin' && $superAdminLimitReached) {
                $error = "Cannot create Super Admin. Maximum limit of " . MAX_SUPER_ADMINS . " Super Admins has been reached.";
            } elseif (strlen($password) < 6) {
                $error = "Password must be at least 6 characters.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $municipality_id = 1; // Default municipality
                
                $insertQuery = "INSERT INTO admins (municipality_id, first_name, middle_name, last_name, email, username, password, role) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)";
                $result = pg_query_params($connection, $insertQuery, [$municipality_id, $first_name, $middle_name, $last_name, $email, $username, $hashed_password, $role]);
                
                if ($result) {
                    // Update super admin count if we just created one
                    if ($role === 'super_admin') {
                        $superAdminCount++;
                        $superAdminLimitReached = $superAdminCount >= MAX_SUPER_ADMINS;
                    }
                    
                    // Add admin notification
                    $notification_msg = "New " . ($role === 'super_admin' ? 'Super Admin' : 'Sub Admin') . " created: " . $first_name . " " . $last_name . " (" . $username . ")";
                    pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
                    
                    $success = "Admin created successfully!";
                } else {
                    $error = "Failed to create admin. Username or email may already exist.";
                }
            }
        }
    }
    
    if (isset($_POST['toggle_status'])) {
        // CSRF validation
        $token = $_POST['csrf_token'] ?? '';
        if (!CSRFProtection::validateToken('toggle_admin_status', $token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }
        
        $admin_id = intval($_POST['admin_id']);
        $new_status = $_POST['new_status'] === 'true';
        
        $updateQuery = "UPDATE admins SET is_active = $1 WHERE admin_id = $2";
        $result = pg_query_params($connection, $updateQuery, [$new_status ? 'true' : 'false', $admin_id]);
        
        if ($result) {
            // Add admin notification
            $statusText = $new_status ? 'activated' : 'deactivated';
            $notification_msg = "Admin account " . $statusText . " (ID: " . $admin_id . ")";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            $success = "Admin status updated successfully!";
        }
    }
}

// Fetch all admins
$adminsQuery = "SELECT admin_id, first_name, middle_name, last_name, email, username, role, is_active, created_at, last_login FROM admins ORDER BY created_at DESC";
$adminsResult = pg_query($connection, $adminsQuery);
$admins = pg_fetch_all($adminsResult) ?: [];
?>

<?php $page_title='Admin Management'; include __DIR__ . '/../../includes/admin/admin_head.php'; ?>
<link rel="stylesheet" href="../../assets/css/admin/table_core.css">
<style>
/* Tablet optimization (768px-991px) */
@media (min-width: 768px) and (max-width: 991.98px) {
    .modal-mobile-compact .modal-dialog { 
        max-width: 700px; 
        margin: 2rem auto; 
    }
    .modal-mobile-compact .modal-content {
        border-radius: 1rem;
    }
    .modal-mobile-compact .modal-header {
        padding: 1.25rem;
    }
    .modal-mobile-compact .modal-body { 
        max-height: 70vh; 
        overflow-y: auto;
        padding: 1rem 1.25rem;
    }
    .modal-mobile-compact .modal-footer {
        padding: 1rem 1.25rem;
    }
    .modal-mobile-compact .modal-title {
        font-size: 1.1rem;
    }
    .modal-mobile-compact .form-control {
        font-size: 0.95rem;
    }
    .modal-mobile-compact .btn {
        font-size: 0.9rem;
        padding: 0.65rem 1.25rem;
    }
}

/* Mobile-only compact modal size (consistent with Manage Applicants) */
@media (max-width: 576px) {
    .modal-mobile-compact .modal-dialog { max-width: 420px; width: 88%; margin: 1rem auto; }
    .modal-mobile-compact .modal-body { max-height: 60vh; overflow-y: auto; }
}

/* Header actions alignment */
.section-header .actions { display: flex; align-items: center; gap: .5rem; }

/* Role permissions styling */
.role-permissions .role-col { border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 16px; border-left: 4px solid transparent; border: 1px solid #eef2f4; }
.role-permissions .role-super { border-left-color: var(--bs-success); background: #f6fffb; border-color: rgba(25,135,84,.25); }
.role-permissions .role-sub { border-left-color: var(--bs-info); background: #f5fbff; border-color: rgba(13,202,240,.25); }
.role-permissions .role-title { font-weight: 600; margin-bottom: .5rem; }
.role-permissions ul li { margin-bottom: .35rem; }
</style>
</head>
<body>
<?php include __DIR__ . '/../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include __DIR__ . '/../../includes/admin/admin_header.php'; ?>
    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4 px-4">
            <div class="section-header mb-4 d-flex justify-content-between align-items-center">
                <h2 class="fw-bold text-dark mb-0">Admin Management</h2>
                <div class="actions">
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createAdminModal">Create New Admin</button>
                </div>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <!-- Create New Admin -->
            
            
            <!-- Admin List -->
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0 fw-bold">Existing Admins</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="adminsTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admins as $admin): ?>
                                    <tr>
                                        <td data-label="Name"><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['middle_name'] . ' ' . $admin['last_name']) ?></td>
                                        <td data-label="Username"><?= htmlspecialchars($admin['username']) ?></td>
                                        <td data-label="Email"><?= htmlspecialchars($admin['email']) ?></td>
                                        <td data-label="Role">
                                            <span class="badge <?= $admin['role'] === 'super_admin' ? 'bg-danger' : 'bg-info' ?>">
                                                <?= $admin['role'] === 'super_admin' ? 'Super Admin' : 'Sub Admin' ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge <?= $admin['is_active'] === 't' ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $admin['is_active'] === 't' ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td data-label="Created"><?= date('M d, Y', strtotime($admin['created_at'])) ?></td>
                                        <td data-label="Last Login"><?= $admin['last_login'] ? date('M d, Y H:i', strtotime($admin['last_login'])) : 'Never' ?></td>
                                        <td data-label="Actions">
                                            <?php if ($admin['admin_id'] != ($_SESSION['admin_id'] ?? 0)): ?>
                                                <button type="button" class="btn btn-sm <?= $admin['is_active'] === 't' ? 'btn-outline-danger' : 'btn-outline-success' ?>" onclick="showToggleStatusModal(<?= $admin['admin_id'] ?>, '<?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name'], ENT_QUOTES) ?>', '<?= $admin['is_active'] === 't' ? 'deactivate' : 'activate' ?>')">
                                                    <?= $admin['is_active'] === 't' ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">Current User</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                </div>
            </div>
            
            <!-- Role Permissions Info -->
            <div class="card mt-4 role-permissions border-0 shadow-sm" style="border-radius: 12px;">
                <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 1rem;">
                    <h5 class="mb-0 text-white fw-bold" style="font-size: 1.1rem;">Role Permissions</h5>
                </div>
                <div class="card-body" style="background: white; padding: 1.5rem;">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="role-col role-super">
                                <div class="role-title text-success">Super Admin</div>
                                <ul class="list-unstyled mb-0">
                                    <li><i class="bi bi-check-circle text-success me-1"></i> Full system access</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> Manage all students</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> Slot management</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> Schedule publishing</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> Admin management</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> System settings</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> Data management</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="role-col role-sub">
                                <div class="role-title text-info">Sub Admin</div>
                                <ul class="list-unstyled mb-0">
                                    <li><i class="bi bi-check-circle text-success me-1"></i> View dashboard</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> Review registrations</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> Manage applicants</li>
                                    <li><i class="bi bi-check-circle text-success me-1"></i> View notifications</li>
                                    <li><i class="bi bi-x-circle text-danger me-1"></i> Slot management</li>
                                    <li><i class="bi bi-x-circle text-danger me-1"></i> Schedule publishing</li>
                                    <li><i class="bi bi-x-circle text-danger me-1"></i> Admin management</li>
                                    <li><i class="bi bi-x-circle text-danger me-1"></i> System settings</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Create Admin Modal -->
<div class="modal fade modal-mobile-compact" id="createAdminModal" tabindex="-1" aria-labelledby="createAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createAdminModalLabel">Create New Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="createAdminForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenCreateAdmin) ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="middle_name" class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="middle_name" name="middle_name">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('password', 'passwordIcon')">
                                        <i class="bi bi-eye" id="passwordIcon"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Minimum 6 characters</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirm_password" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('confirm_password', 'confirmPasswordIcon')">
                                        <i class="bi bi-eye" id="confirmPasswordIcon"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="sub_admin">Sub Admin (Limited Access)</option>
                                    <option value="super_admin" <?php echo $superAdminLimitReached ? 'disabled' : ''; ?>>Super Admin (Full Access) <?php echo $superAdminLimitReached ? '- Limit Reached' : ''; ?></option>
                                </select>
                                <small class="text-muted">
                                    Choose carefully - this determines what features the admin can access.
                                    <?php if ($superAdminLimitReached): ?>
                                    <br><span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Super Admin limit reached (<?php echo $superAdminCount; ?>/<?php echo MAX_SUPER_ADMINS; ?>)</span>
                                    <?php else: ?>
                                    <br><span class="text-info"><i class="bi bi-info-circle"></i> Super Admins: <?php echo $superAdminCount; ?>/<?php echo MAX_SUPER_ADMINS; ?></span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_admin" class="btn btn-success">
                        Create Admin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Status Modal -->
<div class="modal fade modal-mobile-compact" id="toggleStatusModal" tabindex="-1" aria-labelledby="toggleStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="toggleStatusModalLabel">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="toggleStatusForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfTokenToggleStatus) ?>">
                <div class="modal-body">
                    <div class="alert alert-info"><span id="statusMessage"></span></div>
                    <p id="confirmationText"></p>
                    <input type="hidden" id="toggleAdminId" name="admin_id">
                    <input type="hidden" id="toggleNewStatus" name="new_status">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="toggle_status" class="btn" id="confirmActionBtn">
                        <span id="confirmActionText"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>

<script>
// Password visibility toggle function
function togglePasswordVisibility(fieldId, iconId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(iconId);
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}

// Function for toggle status modal
function showToggleStatusModal(adminId, adminName, action) {
    const isDeactivate = action === 'deactivate';
    
    document.getElementById('toggleAdminId').value = adminId;
    document.getElementById('toggleNewStatus').value = isDeactivate ? 'false' : 'true';
    
    const statusMessage = document.getElementById('statusMessage');
    const confirmationText = document.getElementById('confirmationText');
    const confirmBtn = document.getElementById('confirmActionBtn');
    const confirmText = document.getElementById('confirmActionText');
    
    if (isDeactivate) {
        statusMessage.textContent = 'This will prevent the admin from logging in and accessing the system.';
        confirmationText.innerHTML = `Are you sure you want to <strong>deactivate</strong> ${adminName}?`;
        confirmBtn.className = 'btn btn-danger';
        confirmText.textContent = 'Deactivate';
    } else {
        statusMessage.textContent = 'This will allow the admin to log in and access the system again.';
        confirmationText.innerHTML = `Are you sure you want to <strong>activate</strong> ${adminName}?`;
        confirmBtn.className = 'btn btn-success';
        confirmText.textContent = 'Activate';
    }
    
    new bootstrap.Modal(document.getElementById('toggleStatusModal')).show();
}

// Form validation for create admin
document.getElementById('createAdminForm').addEventListener('submit', function(e) {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (password !== confirmPassword) {
        e.preventDefault();
        alert('Passwords do not match. Please try again.');
        return false;
    }
    
    if (password.length < 6) {
        e.preventDefault();
        alert('Password must be at least 6 characters long.');
        return false;
    }
    
    // Check required fields
    const requiredFields = ['first_name', 'last_name', 'email', 'username'];
    for (let field of requiredFields) {
        if (!document.getElementById(field).value.trim()) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }
    }
});

// Clear form when modal is closed
document.getElementById('createAdminModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('createAdminForm').reset();
});

// Real-time password confirmation check
document.getElementById('confirm_password').addEventListener('input', function() {
    const password = document.getElementById('password').value;
    const confirmPassword = this.value;
    const submitBtn = document.querySelector('#createAdminForm button[type="submit"]');
    
    if (password && confirmPassword) {
        if (password === confirmPassword) {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
            submitBtn.disabled = false;
        } else {
            this.classList.remove('is-valid');
            this.classList.add('is-invalid');
            submitBtn.disabled = true;
        }
    } else {
        this.classList.remove('is-valid', 'is-invalid');
        submitBtn.disabled = false;
    }
});
</script>
</body>
</html>

<?php pg_close($connection); ?>
