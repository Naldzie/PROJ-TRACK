<!-- Manage Activities Modal -->
<div class="modal fade" id="manageActivitiesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: 12px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                <h5 class="modal-title" style="font-weight: 700;">
                    <i class="bi bi-list-check" style="margin-right: 8px;"></i>Manage Activities for <span id="activityMonthLabel">--</span>(shared)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <input type="hidden" id="currentMonthLabel" value="">

                <!-- Existing Activities List -->
                <div style="margin-bottom: 24px;">
                    <h6 style="margin-bottom: 12px; font-weight: 600; color: #111827;">
                        <i class="bi bi-list-check" style="margin-right: 6px; color: #667eea;"></i>Current Activities
                    </h6>
                    <div id="activitiesList" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; min-height: 80px;">
                        <div style="text-align: center; padding: 20px; color: #9ca3af;">
                            Loading activities...
                        </div>
                    </div>
                </div>

                <!-- Add New Activity Form -->
                <div style="border-top: 2px solid #e5e7eb; padding-top: 20px;">
                    <h6 style="margin-bottom: 12px; font-weight: 600; color: #111827;">
                        <i class="bi bi-plus-circle" style="margin-right: 6px; color: #667eea;"></i>Add New Activity
                    </h6>
                    <form id="addActivityForm" style="display: flex; flex-direction: column; gap: 12px;">
                        <input type="hidden" name="project_id" value="{{ $project->id }}">
                        <input type="hidden" name="month_label" id="activityMonthLabelInput" value="">

                        <div>
                            <input type="text" name="activity_name" placeholder="Activity name (e.g., Safety inspection)" required
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;">
                        </div>

                        <div>
                            <textarea name="description" placeholder="Description (optional)" rows="2"
                                      style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;"></textarea>
                        </div>

                        <button type="button" onclick="addNewActivity()" 
                                style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                            <i class="bi bi-plus-circle" style="margin-right: 6px;"></i>Add Activity
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>