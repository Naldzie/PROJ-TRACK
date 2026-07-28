<!-- === PROGRESS UPDATE MODAL(for removal no usecase) === -->
<div class="modal fade" id="progressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-graph-up"></i> Update Project Progress</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="progressForm">
                    @csrf
                    <input type="hidden" id="progressProjectId" name="project_id">

                    <!-- === PROGRESS === -->
                    <div class="mb-4">
                        <label class="form-label">Progress Percentage <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="range" id="progressSlider" min="0" max="100" step="5" class="form-range" required style="min-width: 200px; margin-right: 10px;">
                            <input type="number" id="progressValue" name="progress_percentage" min="0" max="100" step="0.01" class="form-control" style="width: 100px;" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted d-block mt-2">Drag the slider or enter value directly (0-100)</small>
                    </div>

                    <!-- === PROGRESS INDICATORS === -->
                    <div class="alert alert-info mt-3">
                        <small>
                            <strong>💡 Tips:</strong> Use this to regularly update project progress. 
                            When progress reaches 100%, the project will automatically be marked as completed.
                        </small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveProgressBtn">
                    <i class="bi bi-check-circle"></i> Save Progress
                </button>
            </div>
        </div>
    </div>
</div>
<!-- === END PROGRESS UPDATE MODAL === -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const progressSlider = document.getElementById('progressSlider');
    const progressValue = document.getElementById('progressValue');
    const saveProgressBtn = document.getElementById('saveProgressBtn');

    // Sync slider and input
    if (progressSlider) {
        progressSlider.addEventListener('input', function() {
            progressValue.value = this.value;
        });
    }

    if (progressValue) {
        progressValue.addEventListener('input', function() {
            if (progressSlider) {
                progressSlider.value = this.value;
            }
        });
    }

    // Save progress
    if (saveProgressBtn) {
        saveProgressBtn.addEventListener('click', function() {
            const projectId = document.getElementById('progressProjectId').value;
            const formData = new FormData(document.getElementById('progressForm'));

            fetch(`/admin/projects/${projectId}/update-progress`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    // Close modal and reload
                    bootstrap.Modal.getInstance(document.getElementById('progressModal')).hide();
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating progress');
            });
        });
    }
});
</script>
