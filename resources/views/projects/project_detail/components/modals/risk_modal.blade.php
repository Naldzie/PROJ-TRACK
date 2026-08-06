<!-- ADD RISK MODAL -->
<div class="modal fade" id="riskModal" tabindex="-1" aria-labelledby="riskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header risk-modal-header">
                <h5 class="modal-title" id="riskModalLabel">
                    <i class="bi bi-shield-plus" style="margin-right: 8px;"></i>
                    Add Risk
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addRiskForm">
                    @csrf

                    <div class="risk-form-section">
                        <div class="risk-form-section-label">
                            <i class="bi bi-shield-exclamation"></i> Risk Details
                        </div>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="riskName" class="form-label">Risk Name *</label>
                                <input type="text" class="form-control" id="riskName" name="risk_name"
                                       placeholder="e.g., Cement price hike may stall Phase 2" required>
                                <small class="text-muted">What could go wrong with this project</small>
                            </div>
                            <div class="col-md-4">
                                <label for="riskStatus" class="form-label">Status *</label>
                                <select class="form-control" id="riskStatus" name="status" required>
                                    <option value="Open">Open</option>
                                    <option value="Mitigated">Mitigated</option>
                                    <option value="Closed">Closed</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label for="riskDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="riskDescription" name="description"
                                      rows="3" placeholder="Detailed description of the risk..."></textarea>
                        </div>
                    </div>

                    <div class="risk-form-section">
                        <div class="risk-form-section-label">
                            <i class="bi bi-sliders"></i> Assessment
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="riskProbability" class="form-label">Probability *</label>
                                <select class="form-control" id="riskProbability" name="probability" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="riskImpact" class="form-label">Impact *</label>
                                <select class="form-control" id="riskImpact" name="impact" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Auto Score</label>
                                <div id="riskScorePreview" class="risk-score-preview">
                                    <span class="risk-score-badge risk-level-medium">Score 4 · Medium</span>
                                </div>
                                <small class="text-muted">Score = probability × impact (1–9), computed automatically</small>
                            </div>
                        </div>
                    </div>

                    <div class="risk-form-section">
                        <div class="risk-form-section-label">
                            <i class="bi bi-clipboard-check"></i> Response
                        </div>
                        <div class="mb-3">
                            <label for="riskMitigation" class="form-label">Mitigation Plan</label>
                            <textarea class="form-control" id="riskMitigation" name="mitigation_plan" rows="2"
                                      placeholder="What should be done to reduce this risk..."></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="riskOwner" class="form-label">Owner</label>
                                <input type="text" class="form-control" id="riskOwner" name="owner"
                                       placeholder="Who is responsible for this risk">
                            </div>
                            <div class="col-md-6">
                                <label for="riskIdentifiedAt" class="form-label">Date Identified</label>
                                <input type="date" class="form-control" id="riskIdentifiedAt" name="identified_at">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" style="background: #d97706; border-color: #d97706;">
                        <i class="bi bi-plus-circle" style="margin-right: 6px;"></i>
                        Add Risk
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- EDIT RISK MODAL -->
<div class="modal fade" id="editRiskModal" tabindex="-1" aria-labelledby="editRiskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header risk-modal-header">
                <h5 class="modal-title" id="editRiskModalLabel">
                    <i class="bi bi-pencil-square" style="margin-right: 8px;"></i>
                    Edit Risk
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editRiskForm">
                    @csrf
                    @method('PUT')
                    <input type="hidden" id="editRiskId" name="risk_id">

                    <div class="risk-form-section">
                        <div class="risk-form-section-label">
                            <i class="bi bi-shield-exclamation"></i> Risk Details
                        </div>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label for="editRiskName" class="form-label">Risk Name *</label>
                                <input type="text" class="form-control" id="editRiskName" name="risk_name" required>
                            </div>
                            <div class="col-md-4">
                                <label for="editRiskStatus" class="form-label">Status *</label>
                                <select class="form-control" id="editRiskStatus" name="status" required>
                                    <option value="Open">Open</option>
                                    <option value="Mitigated">Mitigated</option>
                                    <option value="Closed">Closed</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label for="editRiskDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editRiskDescription" name="description" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="risk-form-section">
                        <div class="risk-form-section-label">
                            <i class="bi bi-sliders"></i> Assessment
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="editRiskProbability" class="form-label">Probability *</label>
                                <select class="form-control" id="editRiskProbability" name="probability" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="editRiskImpact" class="form-label">Impact *</label>
                                <select class="form-control" id="editRiskImpact" name="impact" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Auto Score</label>
                                <div id="editRiskScorePreview" class="risk-score-preview">
                                    <span class="risk-score-badge risk-level-medium">Score 4 · Medium</span>
                                </div>
                                <small class="text-muted">Score = probability × impact (1–9), computed automatically</small>
                            </div>
                        </div>
                    </div>

                    <div class="risk-form-section">
                        <div class="risk-form-section-label">
                            <i class="bi bi-clipboard-check"></i> Response
                        </div>
                        <div class="mb-3">
                            <label for="editRiskMitigation" class="form-label">Mitigation Plan</label>
                            <textarea class="form-control" id="editRiskMitigation" name="mitigation_plan" rows="2"></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="editRiskOwner" class="form-label">Owner</label>
                                <input type="text" class="form-control" id="editRiskOwner" name="owner">
                            </div>
                            <div class="col-md-6">
                                <label for="editRiskIdentifiedAt" class="form-label">Date Identified</label>
                                <input type="date" class="form-control" id="editRiskIdentifiedAt" name="identified_at">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="background: #d97706; border-color: #d97706;">
                            <i class="bi bi-check-circle" style="margin-right: 6px;"></i>
                            Update Risk
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
