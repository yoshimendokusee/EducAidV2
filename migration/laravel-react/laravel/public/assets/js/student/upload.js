/* filepath: c:\xampp\htdocs\EducAid\assets\js\student\upload.js */
class EnhancedUploadManager {
    constructor() {
        this.triasColors = {
            primary: '#0068DA',
            secondary: '#00B1C6',
            accent: '#0088C8',
            success: '#3DAD10',
            light: '#67D6C6',
            fresh: '#6CC748'
        };
    this.defaultMaxFileSizeMb = 5;
        this.allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        this.init();
    }
    
    init() {
        this.bindFileInputs();
        this.bindDragAndDrop();
        this.bindFormSubmission();
        this.animateProgress();
        this.addTriasThemeEffects();
    }
    
    addTriasThemeEffects() {
        // Add floating particles effect to header
        this.createFloatingParticles();
        
        // Add ripple effect to buttons
        this.addRippleEffect();
    }
    
    createFloatingParticles() {
        const header = document.querySelector('.upload-header');
        if (!header) return;
        
        for (let i = 0; i < 5; i++) {
            const particle = document.createElement('div');
            particle.style.cssText = `
                position: absolute;
                width: 6px;
                height: 6px;
                background: rgba(255,255,255,0.3);
                border-radius: 50%;
                animation: float-particle ${5 + Math.random() * 5}s linear infinite;
                top: ${Math.random() * 100}%;
                left: ${Math.random() * 100}%;
            `;
            header.appendChild(particle);
        }
        
        // Add keyframes for particle animation
        if (!document.querySelector('#particle-styles')) {
            const style = document.createElement('style');
            style.id = 'particle-styles';
            style.textContent = `
                @keyframes float-particle {
                    0% { transform: translateY(0px) rotate(0deg); opacity: 1; }
                    100% { transform: translateY(-100px) rotate(360deg); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
    }
    
    addRippleEffect() {
        const buttons = document.querySelectorAll('.submit-btn, .upload-item-icon');
        buttons.forEach(button => {
            button.addEventListener('click', (e) => {
                const ripple = document.createElement('span');
                const rect = button.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.cssText = `
                    position: absolute;
                    width: ${size}px;
                    height: ${size}px;
                    left: ${x}px;
                    top: ${y}px;
                    background: rgba(255,255,255,0.5);
                    border-radius: 50%;
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    pointer-events: none;
                `;
                
                button.style.position = 'relative';
                button.appendChild(ripple);
                
                setTimeout(() => ripple.remove(), 600);
            });
        });
        
        // Add ripple animation
        if (!document.querySelector('#ripple-styles')) {
            const style = document.createElement('style');
            style.id = 'ripple-styles';
            style.textContent = `
                @keyframes ripple {
                    to { transform: scale(2); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
    }
    
    bindFileInputs() {
        const fileInputs = document.querySelectorAll('input[type="file"]');
        fileInputs.forEach(input => {
            input.addEventListener('change', (e) => this.handleFileSelection(e));
        });
    }
    
    bindDragAndDrop() {
        const uploadItems = document.querySelectorAll('.upload-form-item');
        uploadItems.forEach(item => {
            item.addEventListener('dragover', this.handleDragOver);
            item.addEventListener('dragleave', this.handleDragLeave);
            item.addEventListener('drop', (e) => this.handleDrop(e, item));
        });
    }
    
    handleDragOver(e) {
        e.preventDefault();
        e.currentTarget.style.borderColor = '#0088C8';
        e.currentTarget.style.background = 'linear-gradient(135deg, #ffffff 0%, #f8fbff 100%)';
        e.currentTarget.style.transform = 'scale(1.02)';
    }
    
    handleDragLeave(e) {
        e.preventDefault();
        e.currentTarget.style.borderColor = '#67D6C6';
        e.currentTarget.style.background = 'linear-gradient(135deg, #f8fbff 0%, #f0f8ff 100%)';
        e.currentTarget.style.transform = 'scale(1)';
    }
    
    handleDrop(e, uploadItem) {
        e.preventDefault();
        this.handleDragLeave(e);
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const input = uploadItem.querySelector('input[type="file"]');
            input.files = files;
            this.handleFileSelection({ target: input });
        }
    }
    
    handleFileSelection(event) {
        const input = event.target;
        const file = input.files[0];
        const uploadItem = input.closest('.upload-form-item');
        
        if (file) {
            if (this.validateFile(file, input)) {
                this.showFilePreview(file, uploadItem);
                this.updateUploadItemState(uploadItem, 'success');
            } else {
                input.value = '';
                this.updateUploadItemState(uploadItem, 'error');
            }
        } else {
            this.clearFilePreview(uploadItem);
            this.updateUploadItemState(uploadItem, 'default');
        }
    }
    
    validateFile(file, input) {
        let maxMb = (input && input.dataset && input.dataset.maxMb)
            ? parseInt(input.dataset.maxMb, 10)
            : this.defaultMaxFileSizeMb;
        if (!Number.isFinite(maxMb) || maxMb <= 0) {
            maxMb = this.defaultMaxFileSizeMb;
        }
        const maxBytes = maxMb * 1024 * 1024;

        if (file.size > maxBytes) {
            this.showToast(`File size exceeds ${maxMb}MB limit`, 'error');
            return false;
        }
        
        if (!this.allowedTypes.includes(file.type)) {
            this.showToast('Only JPG, PNG, and PDF files are allowed', 'error');
            return false;
        }
        
        return true;
    }
    
    showFilePreview(file, uploadItem) {
        const previewContainer = uploadItem.querySelector('.file-preview');
        
        if (file.type.startsWith('image/')) {
            this.showImagePreview(file, previewContainer);
        } else {
            this.showDocumentPreview(file, previewContainer);
        }
        
        previewContainer.classList.add('show');
    }
    
    showImagePreview(file, container) {
        const reader = new FileReader();
        reader.onload = (e) => {
            container.innerHTML = `
                <div class="file-preview-content">
                    <img src="${e.target.result}" alt="Preview">
                    <div class="file-info">
                        <div class="file-name">${file.name}</div>
                        <div class="file-size">${this.formatFileSize(file.size)}</div>
                        <div class="mt-2">
                            <span class="badge" style="background: ${this.triasColors.success}; color: white;">
                                <i class="bi bi-check-circle me-1"></i>
                                Ready to upload
                            </span>
                        </div>
                    </div>
                    <button type="button" class="file-remove" onclick="this.closest('.upload-form-item').querySelector('input[type=file]').value=''; this.closest('.upload-form-item').querySelector('.file-preview').classList.remove('show'); this.closest('.upload-form-item').classList.remove('has-file');">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            `;
        };
        reader.readAsDataURL(file);
    }
    
    showDocumentPreview(file, container) {
        container.innerHTML = `
            <div class="file-preview-content">
                <div class="d-flex align-items-center justify-content-center" style="width: 80px; height: 80px; background: ${this.triasColors.accent}; border-radius: 8px;">
                    <i class="bi bi-file-pdf-fill text-white" style="font-size: 2rem;"></i>
                </div>
                <div class="file-info">
                    <div class="file-name">${file.name}</div>
                    <div class="file-size">${this.formatFileSize(file.size)}</div>
                    <div class="mt-2">
                        <span class="badge" style="background: ${this.triasColors.success}; color: white;">
                            <i class="bi bi-check-circle me-1"></i>
                            Ready to upload
                        </span>
                    </div>
                </div>
                <button type="button" class="file-remove" onclick="this.closest('.upload-form-item').querySelector('input[type=file]').value=''; this.closest('.upload-form-item').querySelector('.file-preview').classList.remove('show'); this.closest('.upload-form-item').classList.remove('has-file');">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        `;
    }
    
    clearFilePreview(uploadItem) {
        const previewContainer = uploadItem.querySelector('.file-preview');
        previewContainer.classList.remove('show');
        previewContainer.innerHTML = '';
    }
    
    updateUploadItemState(uploadItem, state) {
        uploadItem.classList.remove('has-file', 'has-error');
        
        switch (state) {
            case 'success':
                uploadItem.classList.add('has-file');
                uploadItem.style.borderColor = this.triasColors.success;
                break;
            case 'error':
                uploadItem.classList.add('has-error');
                uploadItem.style.borderColor = '#dc3545';
                setTimeout(() => {
                    uploadItem.classList.remove('has-error');
                    uploadItem.style.borderColor = this.triasColors.light;
                }, 3000);
                break;
        }
    }
    
    bindFormSubmission() {
        const form = document.getElementById('uploadForm');
        if (form) {
            form.addEventListener('submit', (e) => this.handleSubmit(e));
        }
    }
    
    handleSubmit(event) {
        const form = event.target;
        // Support both legacy and current submit button ids
        let submitBtn = document.getElementById('submitBtn') || document.getElementById('submit-documents');
        try {
            if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = `
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Uploading Documents...
            `;
            submitBtn.style.background = `linear-gradient(135deg, ${this.triasColors.secondary} 0%, ${this.triasColors.light} 100%)`;
            }
        } catch (e) { /* No-op guard */ }
        
        form.classList.add('loading');
    }
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    showToast(message, type = 'info') {
        const bgColor = type === 'error' ? '#dc3545' : this.triasColors.success;
        const toastHtml = `
            <div class="toast align-items-center text-white border-0" role="alert" style="background: ${bgColor};">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-${type === 'error' ? 'exclamation-triangle' : 'check-circle'}-fill me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(toastContainer);
        }
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toast = new bootstrap.Toast(toastContainer.lastElementChild);
        toast.show();
    }
    
    animateProgress() {
        const progressItems = document.querySelectorAll('.progress-item');
        progressItems.forEach((item, index) => {
            setTimeout(() => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';
                item.style.transition = 'all 0.5s ease';
                
                setTimeout(() => {
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                }, 100);
            }, index * 200);
        });
    }
}

// Initialize when DOM loads
document.addEventListener('DOMContentLoaded', () => {
    new EnhancedUploadManager();
    
    const uploadFormSection = document.querySelector('.upload-form-section');
    const scrollIndicator = document.querySelector('.scroll-indicator');
    
    if (uploadFormSection) {
        // Check if content is scrollable
        function checkScrollable() {
            const isScrollable = uploadFormSection.scrollHeight > uploadFormSection.clientHeight;
            
            if (isScrollable && scrollIndicator) {
                scrollIndicator.classList.add('show');
            }
        }
        
        // Hide scroll indicator when user scrolls near bottom
        uploadFormSection.addEventListener('scroll', function() {
            const scrollPercent = (this.scrollTop / (this.scrollHeight - this.clientHeight)) * 100;
            
            if (scrollPercent > 80 && scrollIndicator) {
                scrollIndicator.classList.remove('show');
            } else if (scrollPercent < 80 && scrollIndicator) {
                scrollIndicator.classList.add('show');
            }
        });
        
        checkScrollable();
        window.addEventListener('resize', checkScrollable);
    }
});