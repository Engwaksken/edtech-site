<?php
if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$hasPrograms = !empty($programs);

/*
|--------------------------------------------------------------------------
| Resolve edit entity safely
|--------------------------------------------------------------------------
*/
$edit_entity_type = 'Project';
$edit_project_id  = null;
$edit_program_id  = null;

if (!empty($edit_milestone)) {
    if (!empty($edit_milestone['entity_type'])) {
        $edit_entity_type = (string)$edit_milestone['entity_type'];
    } elseif (!empty($edit_milestone['project_id'])) {
        $edit_entity_type = 'Project';
    } elseif (!empty($edit_milestone['program_id'])) {
        $edit_entity_type = 'Program';
    }

    if ($edit_entity_type === 'Program') {
        $edit_program_id = !empty($edit_milestone['entity_id'])
            ? (int)$edit_milestone['entity_id']
            : (!empty($edit_milestone['program_id']) ? (int)$edit_milestone['program_id'] : null);
    } else {
        $edit_entity_type = 'Project';
        $edit_project_id = !empty($edit_milestone['entity_id'])
            ? (int)$edit_milestone['entity_id']
            : (!empty($edit_milestone['project_id']) ? (int)$edit_milestone['project_id'] : null);
    }
}


function normalizeDeliverables($raw): array
{
    $items = [];

    if (!empty($raw)) {
        $decoded = json_decode((string)$raw, true);

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $items[] = [
                        'title'      => trim((string)($item['title'] ?? $item['name'] ?? $item['deliverable'] ?? '')),
                        'start_date' => trim((string)($item['start_date'] ?? $item['from_date'] ?? '')),
                        'end_date'   => trim((string)($item['end_date'] ?? $item['due_date'] ?? $item['to_date'] ?? '')),
                    ];
                } else {
                    $items[] = [
                        'title'      => trim((string)$item),
                        'start_date' => '',
                        'end_date'   => '',
                    ];
                }
            }
        } else {
            foreach (explode(',', (string)$raw) as $item) {
                $items[] = [
                    'title'      => trim($item),
                    'start_date' => '',
                    'end_date'   => '',
                ];
            }
        }
    }

    $items = array_values(array_filter($items, static function ($item) {
        return !empty($item['title']) || !empty($item['start_date']) || !empty($item['end_date']);
    }));

    if (empty($items)) {
        $items[] = [
            'title'      => '',
            'start_date' => '',
            'end_date'   => '',
        ];
    }

    return $items;
}

function normalizeListField($raw): array
{
    $items = [];

    if (!empty($raw)) {
        $decoded = json_decode((string)$raw, true);

        if (is_array($decoded)) {
            $items = $decoded;
        } else {
            $items = explode(',', (string)$raw);
        }
    }

    $items = array_map(static fn($item) => trim((string)$item), $items);
    $items = array_values(array_filter($items, static fn($item) => $item !== ''));

    if (empty($items)) {
        $items[] = '';
    }

    return $items;
}

$editDeliverables = normalizeDeliverables($edit_milestone['deliverables'] ?? '');
$editDependencies = normalizeListField($edit_milestone['dependencies'] ?? '');
?>

<!-- Add Milestone Modal -->
<div id="addMilestoneModal" class="modal">
    <div class="modal-content" style="max-width: 980px;">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Add New Milestone</h3>
            <span class="close" onclick="closeModal('addMilestoneModal')">&times;</span>
        </div>

        <div class="modal-body">
            <form method="POST" action="includes/milestones-process.php" id="addMilestoneForm">
                <div class="form-group">
                    <label for="entity_type" class="required">Entity Type</label>
                    <select id="entity_type" name="entity_type" class="form-control" required onchange="toggleEntitySelection()">
                        <option value="">Select Type</option>
                        <option value="Project" selected>Project</option>
                        <?php if ($hasPrograms): ?>
                            <option value="Program">Program</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group" id="projectSelection">
                    <label for="project_id" class="required">Project</label>
                    <select id="project_id" name="project_id" class="form-control">
                        <option value="">Select Project</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo (int)$project['project_id']; ?>">
                                <?php echo h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($hasPrograms): ?>
                    <div class="form-group" id="programSelection" style="display:none;">
                        <label for="program_id" class="required">Program</label>
                        <select id="program_id" name="program_id" class="form-control">
                            <option value="">Select Program</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo (int)$program['id']; ?>">
                                    <?php echo h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="milestone_name" class="required">Milestone Name</label>
                        <input type="text" id="milestone_name" name="milestone_name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="milestone_type" class="required">Milestone Type</label>
                        <select id="milestone_type" name="milestone_type" class="form-control" required>
                            <option value="Planning">Planning</option>
                            <option value="Implementation" selected>Implementation</option>
                            <option value="Monitoring">Monitoring</option>
                            <option value="Evaluation">Evaluation</option>
                            <option value="Reporting">Reporting</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="milestone_description">Description</label>
                    <textarea id="milestone_description" name="milestone_description" class="form-control" rows="3" placeholder="Describe the milestone..."></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="due_date" class="required">Due Date</label>
                        <input type="date" id="due_date" name="due_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="status" class="required">Status</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="Not Started" selected>Not Started</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="Delayed">Delayed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="responsible_person">Responsible Person</label>
                        <input type="text" id="responsible_person" name="responsible_person" class="form-control" value="<?php echo h($_SESSION['full_name'] ?? ''); ?>" placeholder="Enter name...">
                    </div>

                    <div class="form-group">
                        <label for="progress_percentage">Progress (%)</label>
                        <input type="number" id="progress_percentage" name="progress_percentage" class="form-control" min="0" max="100" step="0.01" value="0" placeholder="0.00">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="budget_allocation">Budget Allocation</label>
                        <input type="number" id="budget_allocation" name="budget_allocation" class="form-control" step="0.01" min="0" placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label for="actual_cost">Actual Cost</label>
                        <input type="number" id="actual_cost" name="actual_cost" class="form-control" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>

                <!-- Deliverables With Date Range -->
                <div class="form-group">
                    <label>Deliverables</label>

                    <div id="deliverablesContainer">
                        <div class="dynamic-item deliverable-item">
                            <div class="deliverable-grid">
                                <div>
                                    <label class="mini-label">Deliverable</label>
                                    <input type="text" name="deliverables[]" class="form-control" placeholder="Enter deliverable">
                                </div>

                                <div>
                                    <label class="mini-label">Start Date</label>
                                    <input type="date" name="deliverable_start_dates[]" class="form-control">
                                </div>

                                <div>
                                    <label class="mini-label">End Date</label>
                                    <input type="date" name="deliverable_end_dates[]" class="form-control">
                                </div>

                                <div class="deliverable-action">
                                    <button type="button" class="btn btn-success" onclick="addDeliverable()">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <small style="color:#7f8c8d;">Add multiple deliverables with their planned date ranges.</small>
                </div>

                <!-- Dependencies -->
                <div class="form-group">
                    <label>Dependencies</label>

                    <div id="dependenciesContainer">
                        <div class="dynamic-item">
                            <div style="display:flex; gap:10px; margin-bottom:10px;">
                                <input type="text" name="dependencies[]" class="form-control" placeholder="Enter dependency">
                                <button type="button" class="btn btn-success" onclick="addDependency()">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <small style="color:#7f8c8d;">Add milestone dependencies.</small>
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Additional notes or comments"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addMilestoneModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_milestone" class="btn btn-success">
                        <i class="fas fa-save"></i> Add Milestone
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Milestone Modal -->
<div id="editMilestoneModal" class="modal">
    <div class="modal-content" style="max-width: 980px;">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Milestone</h3>
            <span class="close" onclick="closeModal('editMilestoneModal')">&times;</span>
        </div>

        <div class="modal-body">
            <?php if (!empty($edit_milestone)): ?>
                <form method="POST" action="includes/milestones-process.php" id="editMilestoneForm">
                    <input type="hidden" name="milestone_id" value="<?php echo (int)$edit_milestone['milestone_id']; ?>">

                    <div class="form-group">
                        <label for="edit_entity_type" class="required">Entity Type</label>
                        <select id="edit_entity_type" name="entity_type" class="form-control" required onchange="toggleEditEntitySelection()">
                            <option value="Project" <?php echo $edit_entity_type === 'Project' ? 'selected' : ''; ?>>Project</option>
                            <?php if ($hasPrograms): ?>
                                <option value="Program" <?php echo $edit_entity_type === 'Program' ? 'selected' : ''; ?>>Program</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group" id="editProjectSelection" style="display:<?php echo $edit_entity_type === 'Project' ? 'block' : 'none'; ?>;">
                        <label for="edit_project_id" class="required">Project</label>
                        <select id="edit_project_id" name="project_id" class="form-control">
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo (int)$project['project_id']; ?>" <?php echo ($edit_entity_type === 'Project' && $edit_project_id === (int)$project['project_id']) ? 'selected' : ''; ?>>
                                    <?php echo h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($hasPrograms): ?>
                        <div class="form-group" id="editProgramSelection" style="display:<?php echo $edit_entity_type === 'Program' ? 'block' : 'none'; ?>;">
                            <label for="edit_program_id" class="required">Program</label>
                            <select id="edit_program_id" name="program_id" class="form-control">
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo (int)$program['id']; ?>" <?php echo ($edit_entity_type === 'Program' && $edit_program_id === (int)$program['id']) ? 'selected' : ''; ?>>
                                        <?php echo h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_milestone_name" class="required">Milestone Name</label>
                            <input type="text" id="edit_milestone_name" name="milestone_name" class="form-control" value="<?php echo h($edit_milestone['milestone_name'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_milestone_type" class="required">Milestone Type</label>
                            <select id="edit_milestone_type" name="milestone_type" class="form-control" required>
                                <?php foreach (['Planning','Implementation','Monitoring','Evaluation','Reporting','Other'] as $type): ?>
                                    <option value="<?php echo h($type); ?>" <?php echo (($edit_milestone['milestone_type'] ?? '') === $type) ? 'selected' : ''; ?>>
                                        <?php echo h($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_milestone_description">Description</label>
                        <textarea id="edit_milestone_description" name="milestone_description" class="form-control" rows="3"><?php echo h($edit_milestone['milestone_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_start_date">Start Date</label>
                            <input type="date" id="edit_start_date" name="start_date" class="form-control" value="<?php echo h($edit_milestone['start_date'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit_due_date" class="required">Due Date</label>
                            <input type="date" id="edit_due_date" name="due_date" class="form-control" value="<?php echo h($edit_milestone['due_date'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_completion_date">Completion Date</label>
                            <input type="date" id="edit_completion_date" name="completion_date" class="form-control" value="<?php echo h($edit_milestone['completion_date'] ?? ''); ?>">
                            <small style="color:#7f8c8d;">Auto-set when status is Completed</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_status" class="required">Status</label>
                            <select id="edit_status" name="status" class="form-control" required>
                                <?php foreach (['Not Started','In Progress','Completed','Delayed','Cancelled'] as $status): ?>
                                    <option value="<?php echo h($status); ?>" <?php echo (($edit_milestone['status'] ?? '') === $status) ? 'selected' : ''; ?>>
                                        <?php echo h($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit_responsible_person">Responsible Person</label>
                            <input type="text" id="edit_responsible_person" name="responsible_person" class="form-control" value="<?php echo h($edit_milestone['responsible_person'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit_progress_percentage">Progress (%)</label>
                            <input type="number" id="edit_progress_percentage" name="progress_percentage" class="form-control" min="0" max="100" step="0.01" value="<?php echo h($edit_milestone['progress_percentage'] ?? '0'); ?>">
                            <small style="color:#7f8c8d;">Auto-set to 100% when Completed</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_budget_allocation">Budget Allocation</label>
                            <input type="number" id="edit_budget_allocation" name="budget_allocation" class="form-control" step="0.01" min="0" value="<?php echo h($edit_milestone['budget_allocation'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit_actual_cost">Actual Cost</label>
                            <input type="number" id="edit_actual_cost" name="actual_cost" class="form-control" step="0.01" min="0" value="<?php echo h($edit_milestone['actual_cost'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Deliverables With Date Range -->
                    <div class="form-group">
                        <label>Deliverables</label>

                        <div id="editDeliverablesContainer">
                            <?php foreach ($editDeliverables as $index => $deliverable): ?>
                                <div class="dynamic-item deliverable-item">
                                    <div class="deliverable-grid">
                                        <div>
                                            <label class="mini-label">Deliverable</label>
                                            <input type="text" name="deliverables[]" class="form-control" value="<?php echo h($deliverable['title'] ?? ''); ?>" placeholder="Enter deliverable">
                                        </div>

                                        <div>
                                            <label class="mini-label">Start Date</label>
                                            <input type="date" name="deliverable_start_dates[]" class="form-control" value="<?php echo h($deliverable['start_date'] ?? ''); ?>">
                                        </div>

                                        <div>
                                            <label class="mini-label">End Date</label>
                                            <input type="date" name="deliverable_end_dates[]" class="form-control" value="<?php echo h($deliverable['end_date'] ?? ''); ?>">
                                        </div>

                                        <div class="deliverable-action">
                                            <?php if ($index === 0): ?>
                                                <button type="button" class="btn btn-success" onclick="addEditDeliverable()">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-danger" onclick="removeDynamicItem(this)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <small style="color:#7f8c8d;">Update deliverables with their planned date ranges.</small>
                    </div>

                    <!-- Dependencies -->
                    <div class="form-group">
                        <label>Dependencies</label>

                        <div id="editDependenciesContainer">
                            <?php foreach ($editDependencies as $index => $dependency): ?>
                                <div class="dynamic-item">
                                    <div style="display:flex; gap:10px; margin-bottom:10px;">
                                        <input type="text" name="dependencies[]" class="form-control" value="<?php echo h($dependency); ?>" placeholder="Enter dependency">

                                        <?php if ($index === 0): ?>
                                            <button type="button" class="btn btn-success" onclick="addEditDependency()">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-danger" onclick="removeDynamicItem(this)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_notes">Notes</label>
                        <textarea id="edit_notes" name="notes" class="form-control" rows="3"><?php echo h($edit_milestone['notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="modal-footer">
                        <button type="button" onclick="closeModal('editMilestoneModal')" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="edit_milestone" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Milestone
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h3 style="color:#E74C3C;"><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
            <span class="close" onclick="closeModal('deleteModal')">&times;</span>
        </div>

        <div class="modal-body">
            <p style="font-size:16px; margin-bottom:20px;">
                Are you sure you want to delete the milestone <strong id="deleteMilestoneName"></strong>?
            </p>

            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i>
                <strong>Warning:</strong> This action cannot be undone. All associated documents and updates will also be deleted.
            </div>

            <form method="POST" action="includes/milestones-process.php">
                <input type="hidden" name="milestone_id" id="deleteMilestoneId">

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('deleteModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="delete_milestone" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Yes, Delete Milestone
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.deliverable-item {
    margin-bottom: 10px;
}

.deliverable-grid {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) 170px 170px 48px;
    gap: 10px;
    align-items: end;
    margin-bottom: 10px;
}

.mini-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #7f8c8d;
    margin-bottom: 4px;
}

.deliverable-action {
    display: flex;
    align-items: end;
}

@media (max-width: 768px) {
    .deliverable-grid {
        grid-template-columns: 1fr;
    }

    .deliverable-action {
        justify-content: flex-start;
    }
}
</style>

<script>
function setRequired(element, required) {
    if (!element) return;
    element.required = required;
}

function showElement(element, show) {
    if (!element) return;
    element.style.display = show ? 'block' : 'none';
}

function clearSelect(element) {
    if (!element) return;
    element.value = '';
}

/* =========================
   ENTITY SELECTION - ADD
========================= */
function toggleEntitySelection() {
    const entityType = document.getElementById('entity_type');
    const projectSelection = document.getElementById('projectSelection');
    const programSelection = document.getElementById('programSelection');
    const projectSelect = document.getElementById('project_id');
    const programSelect = document.getElementById('program_id');

    if (!entityType) return;

    const selectedType = entityType.value;

    if (selectedType === 'Project') {
        showElement(projectSelection, true);
        showElement(programSelection, false);
        setRequired(projectSelect, true);
        setRequired(programSelect, false);
        clearSelect(programSelect);
        return;
    }

    if (selectedType === 'Program') {
        showElement(projectSelection, false);
        showElement(programSelection, true);
        setRequired(projectSelect, false);
        setRequired(programSelect, true);
        clearSelect(projectSelect);
        return;
    }

    showElement(projectSelection, true);
    showElement(programSelection, false);
    setRequired(projectSelect, false);
    setRequired(programSelect, false);
    clearSelect(projectSelect);
    clearSelect(programSelect);
}

/* =========================
   ENTITY SELECTION - EDIT
========================= */
function toggleEditEntitySelection() {
    const entityType = document.getElementById('edit_entity_type');
    const projectSelection = document.getElementById('editProjectSelection');
    const programSelection = document.getElementById('editProgramSelection');
    const projectSelect = document.getElementById('edit_project_id');
    const programSelect = document.getElementById('edit_program_id');

    if (!entityType) return;

    const selectedType = entityType.value;

    if (selectedType === 'Project') {
        showElement(projectSelection, true);
        showElement(programSelection, false);
        setRequired(projectSelect, true);
        setRequired(programSelect, false);
        clearSelect(programSelect);
        return;
    }

    if (selectedType === 'Program') {
        showElement(projectSelection, false);
        showElement(programSelection, true);
        setRequired(projectSelect, false);
        setRequired(programSelect, true);
        clearSelect(projectSelect);
        return;
    }

    showElement(projectSelection, true);
    showElement(programSelection, false);
    setRequired(projectSelect, false);
    setRequired(programSelect, false);
    clearSelect(projectSelect);
    clearSelect(programSelect);
}

/* =========================
   DYNAMIC FIELDS
========================= */
function removeDynamicItem(button) {
    const item = button.closest('.dynamic-item');
    if (item) item.remove();
}

function createDeliverableInput() {
    return `
        <div class="dynamic-item deliverable-item">
            <div class="deliverable-grid">
                <div>
                    <label class="mini-label">Deliverable</label>
                    <input type="text" name="deliverables[]" class="form-control" placeholder="Enter deliverable">
                </div>

                <div>
                    <label class="mini-label">Start Date</label>
                    <input type="date" name="deliverable_start_dates[]" class="form-control">
                </div>

                <div>
                    <label class="mini-label">End Date</label>
                    <input type="date" name="deliverable_end_dates[]" class="form-control">
                </div>

                <div class="deliverable-action">
                    <button type="button" class="btn btn-danger" onclick="removeDynamicItem(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
}

function createDynamicInput(name, placeholder) {
    return `
        <div class="dynamic-item">
            <div style="display:flex; gap:10px; margin-bottom:10px;">
                <input type="text" name="${name}[]" class="form-control" placeholder="${placeholder}">
                <button type="button" class="btn btn-danger" onclick="removeDynamicItem(this)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
}

function addDeliverable() {
    const container = document.getElementById('deliverablesContainer');
    if (!container) return;
    container.insertAdjacentHTML('beforeend', createDeliverableInput());
}

function addEditDeliverable() {
    const container = document.getElementById('editDeliverablesContainer');
    if (!container) return;
    container.insertAdjacentHTML('beforeend', createDeliverableInput());
}

function addDependency() {
    const container = document.getElementById('dependenciesContainer');
    if (!container) return;
    container.insertAdjacentHTML('beforeend', createDynamicInput('dependencies', 'Enter dependency'));
}

function addEditDependency() {
    const container = document.getElementById('editDependenciesContainer');
    if (!container) return;
    container.insertAdjacentHTML('beforeend', createDynamicInput('dependencies', 'Enter dependency'));
}

/* =========================
   DATE RANGE VALIDATION
========================= */
function validateDeliverableDateRanges(formId) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener('submit', function (event) {
        const starts = form.querySelectorAll('input[name="deliverable_start_dates[]"]');
        const ends = form.querySelectorAll('input[name="deliverable_end_dates[]"]');

        for (let i = 0; i < starts.length; i++) {
            const startDate = starts[i].value;
            const endDate = ends[i] ? ends[i].value : '';

            if (startDate && endDate && endDate < startDate) {
                event.preventDefault();
                alert('Deliverable end date cannot be earlier than deliverable start date.');
                ends[i].focus();
                return false;
            }
        }
    });
}

/* =========================
   AUTO UPDATE PROGRESS
========================= */
function setupStatusProgress(statusId, progressId, completionId = null) {
    const status = document.getElementById(statusId);
    const progress = document.getElementById(progressId);
    const completion = completionId ? document.getElementById(completionId) : null;

    if (!status || !progress) return;

    status.addEventListener('change', function () {
        if (status.value === 'Completed') {
            progress.value = 100;

            if (completion && !completion.value) {
                completion.value = new Date().toISOString().split('T')[0];
            }
        } else if (status.value === 'Not Started') {
            progress.value = 0;

            if (completion) {
                completion.value = '';
            }
        }
    });
}

/* =========================
   INIT
========================= */
document.addEventListener('DOMContentLoaded', function () {
    toggleEntitySelection();
    toggleEditEntitySelection();

    setupStatusProgress('status', 'progress_percentage');
    setupStatusProgress('edit_status', 'edit_progress_percentage', 'edit_completion_date');

    validateDeliverableDateRanges('addMilestoneForm');
    validateDeliverableDateRanges('editMilestoneForm');
});
</script>
