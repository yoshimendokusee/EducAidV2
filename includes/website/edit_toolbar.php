<!-- 
  Modular Edit Mode Toolbar v2.0
  Reusable across all editable pages
  Floating sidebar design - Simplified and improved
-->
<?php
// Ensure this is only loaded in edit mode
if (!isset($IS_EDIT_MODE) || !$IS_EDIT_MODE) {
    return;
}

// Default configuration
$default_config = [
    'page_title' => 'Page Editor',
    'show_save' => true,
    'show_reset' => true,
    'show_history' => true,
    'show_exit' => true,
    'show_dashboard' => true,
    'exit_url' => null,
    'dashboard_url' => '../modules/admin/homepage.php'
];

// Merge user config with defaults
$toolbar_config = isset($toolbar_config) ? array_merge($default_config, $toolbar_config) : $default_config;

// Auto-detect exit URL if not provided
if (!$toolbar_config['exit_url']) {
    $current_page = basename($_SERVER['PHP_SELF']);
    $toolbar_config['exit_url'] = $current_page;
}
?>

<style>
/* ===== Edit Toolbar Styles ===== */
.lp-edit-toolbar {
    position: fixed;
    top: 70px;
    right: 12px;
    width: 300px;
    background: #fff;
    border: 3px solid #dc2626;
    border-radius: 16px;
    z-index: 99999;
    padding: 1rem;
    font-family: system-ui, -apple-system, sans-serif;
    box-shadow: 0 4px 24px rgba(0,0,0,0.12), 0 1px 4px rgba(0,0,0,0.08);
    overflow: visible;
    box-sizing: border-box;
    min-width: 280px;
    min-height: 300px;
}

.lp-edit-toolbar.lp-dragging {
    box-shadow: 0 12px 40px rgba(15,23,42,0.25);
    cursor: grabbing;
}

.lp-edit-toolbar.lp-locked {
    box-shadow: 0 4px 16px rgba(15,23,42,0.15);
}

/* Edit Mode Badge */
.lp-edit-badge {
    position: fixed;
    left: 12px;
    top: 70px;
    background: linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%);
    color: #fff;
    padding: 6px 14px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    border-radius: 30px;
    z-index: 99999;
    display: flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 8px rgba(29,78,216,0.4);
}

.lp-edit-badge .dot {
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    box-shadow: 0 0 0 2px rgba(255,255,255,0.3);
    animation: pulse-dot 2s ease-in-out infinite;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.7; transform: scale(1.15); }
}

/* Toolbar Header */
.lp-toolbar-header {
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid #e2e8f0;
    cursor: grab;
}

.lp-toolbar-header:active {
    cursor: grabbing;
}

.lp-toolbar-title {
    font-weight: 700;
    font-size: 0.9rem;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
}

.lp-toolbar-title i {
    color: #3b82f6;
}

/* Lock Toggle */
.lp-lock-toggle {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    cursor: pointer;
}

.lp-lock-toggle:hover {
    background: #3b82f6;
    border-color: #3b82f6;
    color: #fff;
}

.lp-lock-toggle.is-locked {
    background: #3b82f6;
    border-color: #3b82f6;
    color: #fff;
}

/* Form Labels */
.lp-edit-toolbar .form-label {
    font-size: 0.65rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 0.35rem;
}

/* Selected Element Display */
.lp-selected-display {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    font-size: 0.7rem;
    color: #475569;
    min-height: 32px;
    word-break: break-word;
}

.lp-selected-display:empty::before {
    content: 'Click an editable element';
    color: #94a3b8;
    font-style: italic;
}

/* Text Editor */
.lp-edit-toolbar textarea {
    font-size: 0.75rem;
    border-radius: 8px;
    resize: vertical;
    min-height: 70px;
}

/* Color Inputs */
.lp-edit-toolbar .form-control-color {
    border-radius: 8px;
    height: 36px;
    padding: 2px;
}

/* Section Divider */
.lp-toolbar-divider {
    height: 1px;
    background: #e2e8f0;
    margin: 0.75rem 0;
}

/* Section Label */
.lp-toolbar-section {
    font-size: 0.6rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    font-weight: 700;
    color: #94a3b8;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.lp-toolbar-section::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2e8f0;
}

/* Action Buttons */
.lp-toolbar-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.lp-toolbar-actions .btn {
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 500;
    padding: 0.5rem 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.lp-toolbar-actions .btn-row {
    display: flex;
    gap: 0.5rem;
}

.lp-toolbar-actions .btn-row .btn {
    flex: 1;
}

/* Primary Save Button */
.lp-save-primary {
    background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
    border: none;
    color: #fff;
    font-weight: 600;
}

.lp-save-primary:hover:not(:disabled) {
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(22,163,74,0.3);
}

.lp-save-primary:disabled {
    background: #94a3b8;
    cursor: not-allowed;
}

/* Status Display */
.lp-status-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 0.75rem;
    border-top: 1px solid #e2e8f0;
    margin-top: 0.75rem;
}

.lp-status {
    font-size: 0.65rem;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 4px;
}

.lp-status.success { color: #16a34a; }
.lp-status.error { color: #dc2626; }
.lp-status.saving { color: #3b82f6; }

/* Resizer */
.lp-toolbar-resizer {
    position: absolute;
    bottom: 6px;
    right: 6px;
    width: 14px;
    height: 14px;
    cursor: se-resize;
    border-radius: 4px;
    opacity: 0.5;
    transition: opacity 0.2s;
}

.lp-toolbar-resizer::before {
    content: '';
    position: absolute;
    right: 2px;
    bottom: 2px;
    width: 8px;
    height: 8px;
    border-right: 2px solid #94a3b8;
    border-bottom: 2px solid #94a3b8;
}

.lp-toolbar-resizer:hover {
    opacity: 1;
}

.lp-toolbar-resizer.disabled {
    opacity: 0.2;
    pointer-events: none;
}

/* ===== Help Modal Styles ===== */
.lp-help-modal .modal-header {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    border-bottom: none;
    border-radius: 12px 12px 0 0;
}

.lp-help-modal .modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}

.lp-help-modal .help-section {
    background: #f8fafc;
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.lp-help-modal .help-section h6 {
    color: #1e293b;
    font-weight: 600;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.lp-help-modal .help-section h6 i {
    color: #3b82f6;
}

.lp-help-modal .help-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 0.5rem 0;
    font-size: 0.85rem;
}

.lp-help-modal .help-item i {
    color: #22c55e;
    margin-top: 2px;
}

.lp-help-modal .help-tip {
    background: #fef3c7;
    border-left: 3px solid #f59e0b;
    padding: 0.75rem;
    border-radius: 0 8px 8px 0;
    font-size: 0.8rem;
}

/* ===== History Modal Styles ===== */
.lp-history-modal-v2 {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: none;
    align-items: center;
    justify-content: center;
}

.lp-history-modal-v2.show {
    display: flex;
}

.lp-hist-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15,23,42,0.5);
    backdrop-filter: blur(4px);
}

.lp-hist-dialog {
    position: relative;
    background: #fff;
    border-radius: 16px;
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    overflow: hidden;
}

.lp-hist-header {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: #fff;
    padding: 1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.lp-hist-header h5 {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.lp-hist-body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}

.lp-hist-footer {
    padding: 0.75rem 1.25rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    font-size: 0.75rem;
    color: #64748b;
}

/* History Entry Cards */
.lp-hist-entry {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    transition: all 0.2s;
}

.lp-hist-entry:hover {
    border-color: #3b82f6;
    box-shadow: 0 2px 8px rgba(59,130,246,0.1);
}

.lp-hist-entry-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.lp-hist-user {
    display: flex;
    align-items: center;
    gap: 10px;
}

.lp-hist-avatar {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 600;
    font-size: 0.85rem;
}

.lp-hist-user-info strong {
    display: block;
    font-size: 0.85rem;
    color: #1e293b;
}

.lp-hist-user-info small {
    color: #64748b;
    font-size: 0.7rem;
}

.lp-hist-badge {
    background: #dbeafe;
    color: #1d4ed8;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.lp-hist-blocks {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.lp-hist-block-tag {
    background: #e2e8f0;
    color: #475569;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.7rem;
}

.lp-hist-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: #64748b;
}

.lp-hist-empty i {
    font-size: 3rem;
    color: #cbd5e1;
    margin-bottom: 1rem;
    display: block;
}
</style>

<!-- Floating Edit Toolbar -->
<div id="lp-edit-toolbar" class="lp-edit-toolbar">
    <div class="lp-toolbar-header">
        <span class="lp-toolbar-title">
            <i class="bi bi-pencil-square"></i>
            <?php echo htmlspecialchars($toolbar_config['page_title']); ?>
        </span>
        <button id="lp-lock-toggle" type="button" class="lp-lock-toggle" data-locked="0" title="Lock toolbar position">
            <i class="bi bi-lock"></i>
        </button>
    </div>
    
    <!-- Selected Element -->
    <div class="mb-3">
        <label class="form-label">Selected Element</label>
        <div id="lp-current-target" class="lp-selected-display"></div>
    </div>
    
    <!-- Text Editor -->
    <div class="mb-3">
        <label class="form-label">Edit Content</label>
        <textarea id="lp-edit-text" class="form-control" rows="3" 
                  placeholder="Select an element to edit its content..."></textarea>
    </div>
    
    <!-- Color Options -->
    <div class="row g-2 mb-3">
        <div class="col-6">
            <label class="form-label">Text Color</label>
            <input type="color" id="lp-text-color" class="form-control form-control-color w-100" value="#000000" />
        </div>
        <div class="col-6">
            <label class="form-label">Background</label>
            <input type="color" id="lp-bg-color" class="form-control form-control-color w-100" value="#ffffff" />
        </div>
    </div>
    
    <div class="lp-toolbar-divider"></div>
    
    <!-- Actions -->
    <div class="lp-toolbar-section">Actions</div>
    <div class="lp-toolbar-actions">
        <?php if ($toolbar_config['show_save']): ?>
        <button id="lp-save-btn" class="btn lp-save-primary" disabled title="Save changes">
            <i class="bi bi-cloud-check"></i>
            <span>Save Changes</span>
        </button>
        <?php endif; ?>
        
        <div class="btn-row">
            <button id="lp-highlight-toggle" class="btn btn-outline-secondary" data-active="1" title="Toggle edit outlines">
                <i class="bi bi-bounding-box-circles"></i>
                <span class="d-none d-sm-inline">Outlines</span>
            </button>
            
            <?php if ($toolbar_config['show_history']): ?>
            <button id="lp-history-btn" class="btn btn-outline-primary" title="View edit history">
                <i class="bi bi-clock-history"></i>
                <span class="d-none d-sm-inline">History</span>
            </button>
            <?php endif; ?>
        </div>
        
        <div class="btn-row">
            <?php if ($toolbar_config['show_reset']): ?>
            <button id="lp-reset-all" class="btn btn-outline-danger" title="Reset all content to default">
                <i class="bi bi-arrow-counterclockwise"></i>
                <span class="d-none d-sm-inline">Reset</span>
            </button>
            <?php endif; ?>
            
            <?php if ($toolbar_config['show_dashboard']): ?>
            <a href="<?php echo htmlspecialchars($toolbar_config['dashboard_url']); ?>" 
               class="btn btn-outline-secondary" title="Go to Dashboard">
                <i class="bi bi-speedometer2"></i>
            </a>
            <?php endif; ?>
            
            <?php if ($toolbar_config['show_exit']): ?>
            <a href="<?php echo htmlspecialchars($toolbar_config['exit_url']); ?>" 
               class="btn btn-outline-secondary" title="Exit Editor">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </div>
        
        <button id="lp-help-btn" type="button" class="btn btn-outline-info" title="How to use">
            <i class="bi bi-question-circle"></i>
            <span>Help Guide</span>
        </button>
    </div>
    
    <!-- Status Bar -->
    <div class="lp-status-bar">
        <span class="lp-status" id="lp-status">
            <i class="bi bi-circle-fill" style="font-size: 6px;"></i>
            Ready
        </span>
        <small class="text-muted" id="lp-changes-count">0 changes</small>
    </div>

    <span class="lp-toolbar-resizer" title="Resize toolbar"></span>
</div>

<!-- Edit Mode Badge -->
<div class="lp-edit-badge">
    <span class="dot"></span>
    EDIT MODE
</div>

<!-- Help Modal -->
<div class="modal fade lp-help-modal" id="lp-help-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header text-white">
                <h5 class="modal-title">
                    <i class="bi bi-book me-2"></i>Editor Guide
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="help-section">
                    <h6><i class="bi bi-cursor-fill"></i>Getting Started</h6>
                    <div class="help-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <div><strong>Click any blue-outlined element</strong> to select it for editing</div>
                    </div>
                    <div class="help-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <div>Edit the text content in the toolbar's text area</div>
                    </div>
                    <div class="help-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <div>Use the color pickers to change text and background colors</div>
                    </div>
                </div>
                
                <div class="help-section">
                    <h6><i class="bi bi-floppy-fill"></i>Saving Changes</h6>
                    <div class="help-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <div><strong>Save Changes</strong> saves all your edits to the database</div>
                    </div>
                    <div class="help-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <div>Changes are temporary until you click Save</div>
                    </div>
                    <div class="help-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <div>The status shows how many unsaved changes you have</div>
                    </div>
                </div>
                
                <div class="help-section">
                    <h6><i class="bi bi-clock-history"></i>History & Recovery</h6>
                    <div class="help-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <div><strong>History</strong> shows all past edits grouped by editor and date</div>
                    </div>
                    <div class="help-item">
                        <i class="bi bi-check-circle-fill"></i>
                        <div><strong>Reset</strong> restores all content to the original defaults</div>
                    </div>
                </div>
                
                <div class="help-tip">
                    <strong><i class="bi bi-lightbulb me-1"></i>Pro Tip:</strong>
                    You can drag the toolbar by its header to reposition it, or lock it in place using the lock icon.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                    <i class="bi bi-check-lg me-1"></i>Got It
                </button>
            </div>
        </div>
    </div>
</div>

<!-- History Modal (v2) -->
<div class="lp-history-modal-v2" id="lp-history-modal-v2">
    <div class="lp-hist-backdrop"></div>
    <div class="lp-hist-dialog">
        <div class="lp-hist-header">
            <h5><i class="bi bi-clock-history"></i>Edit History</h5>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-light" id="lp-hist-refresh" title="Refresh">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
                <button type="button" class="btn btn-sm btn-light" id="lp-hist-close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <div class="lp-hist-body" id="lp-hist-body">
            <!-- Content loaded dynamically -->
        </div>
        <div class="lp-hist-footer">
            <i class="bi bi-info-circle me-1"></i>
            History shows who edited which sections and when
        </div>
    </div>
</div>

<script>
(function() {
    const toolbar = document.getElementById('lp-edit-toolbar');
    if (!toolbar) return;

    const resizer = toolbar.querySelector('.lp-toolbar-resizer');
    const lockBtn = document.getElementById('lp-lock-toggle');
    const helpBtn = document.getElementById('lp-help-btn');
    const histBtn = document.getElementById('lp-history-btn');
    const histModal = document.getElementById('lp-history-modal-v2');
    const histBody = document.getElementById('lp-hist-body');
    const histClose = document.getElementById('lp-hist-close');
    const histRefresh = document.getElementById('lp-hist-refresh');
    
    const storageKey = 'lp-toolbar-state::' + window.location.pathname;
    const marginX = 12, marginY = 12, minWidth = 280, minHeight = 300;

    let currentState = null;
    let isLocked = false;
    let dragActive = false, dragPointerId = null, offsetX = 0, offsetY = 0;
    let resizeActive = false, resizePointerId = null, initialSize = null, initialPoint = null;

    const readPoint = (evt) => ({
        x: evt.clientX ?? evt.touches?.[0]?.clientX,
        y: evt.clientY ?? evt.touches?.[0]?.clientY
    });

    const clampSize = (width, height) => ({
        width: Math.min(Math.max(minWidth, width), window.innerWidth - marginX * 2),
        height: Math.min(Math.max(minHeight, height), window.innerHeight - marginY * 2)
    });

    const clampPosition = (top, left, width, height) => ({
        top: Math.min(Math.max(marginY, top), Math.max(marginY, window.innerHeight - height - marginY)),
        left: Math.min(Math.max(marginX, left), Math.max(marginX, window.innerWidth - width - marginX))
    });

    function updateLockButton() {
        if (!lockBtn) return;
        lockBtn.innerHTML = isLocked ? '<i class="bi bi-unlock"></i>' : '<i class="bi bi-lock"></i>';
        lockBtn.classList.toggle('is-locked', isLocked);
        lockBtn.title = isLocked ? 'Unlock toolbar' : 'Lock toolbar';
    }

    function setLock(value) {
        isLocked = !!value;
        toolbar.classList.toggle('lp-locked', isLocked);
        resizer?.classList.toggle('disabled', isLocked);
        updateLockButton();
        if (currentState) currentState.locked = isLocked;
        saveState();
    }

    function applyState(state) {
        if (!state) return;
        const rect = toolbar.getBoundingClientRect();
        const merged = {
            top: state.top ?? rect.top,
            left: state.left ?? rect.left,
            width: state.width ?? rect.width,
            height: state.height ?? rect.height,
            locked: state.locked ?? false
        };
        
        const size = clampSize(merged.width, merged.height);
        const pos = clampPosition(merged.top, merged.left, size.width, size.height);
        
        toolbar.style.cssText = `
            top: ${pos.top}px;
            left: ${pos.left}px;
            width: ${size.width}px;
            height: ${size.height}px;
            right: auto;
            bottom: auto;
        `;
        
        currentState = { ...pos, ...size, locked: merged.locked };
        isLocked = merged.locked;
        updateLockButton();
        toolbar.classList.toggle('lp-locked', isLocked);
        resizer?.classList.toggle('disabled', isLocked);
    }

    function defaultState() {
        return {
            top: marginY + 50,
            left: window.innerWidth - 312,
            width: 300,
            height: 520,
            locked: false
        };
    }

    function loadState() {
        try {
            const raw = localStorage.getItem(storageKey);
            applyState(raw ? JSON.parse(raw) : defaultState());
        } catch {
            applyState(defaultState());
        }
    }

    function saveState() {
        if (!currentState) return;
        try {
            localStorage.setItem(storageKey, JSON.stringify(currentState));
        } catch {}
    }

    loadState();

    // Lock toggle
    lockBtn?.addEventListener('click', () => setLock(!isLocked));

    // Drag functionality
    const isInteractive = (el) => el?.closest('a, button, select, textarea, input, .lp-toolbar-resizer');
    
    toolbar.addEventListener('pointerdown', (evt) => {
        if (isLocked || evt.button !== 0 || isInteractive(evt.target)) return;
        const point = readPoint(evt);
        const rect = toolbar.getBoundingClientRect();
        offsetX = point.x - rect.left;
        offsetY = point.y - rect.top;
        dragActive = true;
        dragPointerId = evt.pointerId;
        toolbar.classList.add('lp-dragging');
        toolbar.setPointerCapture?.(evt.pointerId);
        evt.preventDefault();
    });

    toolbar.addEventListener('pointermove', (evt) => {
        if (!dragActive || isLocked || evt.pointerId !== dragPointerId) return;
        const point = readPoint(evt);
        applyState({ top: point.y - offsetY, left: point.x - offsetX });
    });

    toolbar.addEventListener('pointerup', (evt) => {
        if (!dragActive || evt.pointerId !== dragPointerId) return;
        dragActive = false;
        toolbar.classList.remove('lp-dragging');
        toolbar.releasePointerCapture?.(evt.pointerId);
        saveState();
    });

    // Resize functionality
    resizer?.addEventListener('pointerdown', (evt) => {
        if (isLocked || evt.button !== 0) return;
        resizeActive = true;
        resizePointerId = evt.pointerId;
        initialPoint = readPoint(evt);
        initialSize = { width: toolbar.offsetWidth, height: toolbar.offsetHeight };
        resizer.setPointerCapture?.(evt.pointerId);
        evt.preventDefault();
    });

    resizer?.addEventListener('pointermove', (evt) => {
        if (!resizeActive || evt.pointerId !== resizePointerId) return;
        const point = readPoint(evt);
        applyState({
            width: initialSize.width + (point.x - initialPoint.x),
            height: initialSize.height + (point.y - initialPoint.y)
        });
    });

    resizer?.addEventListener('pointerup', (evt) => {
        if (!resizeActive || evt.pointerId !== resizePointerId) return;
        resizeActive = false;
        resizer.releasePointerCapture?.(evt.pointerId);
        saveState();
    });

    // Double-click to reset position
    toolbar.addEventListener('dblclick', (evt) => {
        if (isInteractive(evt.target)) return;
        applyState(defaultState());
        saveState();
    });

    // Help modal
    helpBtn?.addEventListener('click', () => {
        const modal = new bootstrap.Modal(document.getElementById('lp-help-modal'));
        modal.show();
    });

    // History modal functions
    function showHistory() {
        histModal?.classList.add('show');
        loadHistory();
    }

    function hideHistory() {
        histModal?.classList.remove('show');
    }

    async function loadHistory() {
        if (!histBody) return;
        histBody.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Loading history...</p></div>';
        
        try {
            // Get CSRF token
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = csrfMeta?.content || '';
            
            const res = await fetch(window.lpHistoryEndpoint || 'ajax_content_history.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ action: 'fetch_history' })
            });
            
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed to load');
            
            const records = data.records || [];
            if (!records.length) {
                histBody.innerHTML = `
                    <div class="lp-hist-empty">
                        <i class="bi bi-clock"></i>
                        <p>No edit history yet</p>
                        <small>Changes you make will appear here</small>
                    </div>`;
                return;
            }
            
            // Group by user and date
            const groups = {};
            records.forEach(r => {
                const user = r.admin_username || 'System';
                const date = r.created_at?.split(' ')[0] || 'Unknown';
                const key = `${user}|${date}`;
                if (!groups[key]) {
                    groups[key] = { user, date, edits: [], blocks: new Set() };
                }
                groups[key].edits.push(r);
                groups[key].blocks.add(r.block_key);
            });
            
            // Sort by most recent
            const sorted = Object.values(groups).sort((a, b) => 
                (b.edits[0]?.created_at || '').localeCompare(a.edits[0]?.created_at || '')
            );
            
            // Format date nicely
            const formatDate = (dateStr) => {
                const d = new Date(dateStr);
                const today = new Date();
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                if (d.toDateString() === today.toDateString()) return 'Today';
                if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            };
            
            // Build HTML
            let html = '';
            sorted.forEach(group => {
                const initials = group.user.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
                const time = group.edits[0]?.created_at?.split(' ')[1]?.slice(0, 5) || '';
                const blocks = Array.from(group.blocks);
                
                html += `
                    <div class="lp-hist-entry">
                        <div class="lp-hist-entry-header">
                            <div class="lp-hist-user">
                                <div class="lp-hist-avatar">${initials}</div>
                                <div class="lp-hist-user-info">
                                    <strong>${escapeHtml(group.user)}</strong>
                                    <small>${formatDate(group.date)} at ${time}</small>
                                </div>
                            </div>
                            <span class="lp-hist-badge">${group.edits.length} edit${group.edits.length > 1 ? 's' : ''}</span>
                        </div>
                        <div class="lp-hist-blocks">
                            ${blocks.map(b => `<span class="lp-hist-block-tag">${escapeHtml(b)}</span>`).join('')}
                        </div>
                    </div>`;
            });
            
            histBody.innerHTML = html;
            
        } catch (err) {
            histBody.innerHTML = `<div class="alert alert-danger m-3">Failed to load history: ${escapeHtml(err.message)}</div>`;
        }
    }
    
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    // History event listeners
    histBtn?.addEventListener('click', showHistory);
    histClose?.addEventListener('click', hideHistory);
    histRefresh?.addEventListener('click', loadHistory);
    histModal?.querySelector('.lp-hist-backdrop')?.addEventListener('click', hideHistory);

    // Expose for content editor
    window.lpToolbar = {
        showHistory,
        hideHistory,
        loadHistory,
        resetLayout: () => { applyState(defaultState()); saveState(); }
    };
})();
</script>
