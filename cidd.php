<?php
// cidd.php - Codebase Interactive Dictionary Database v4.1
// Implements robust name-uniqueness validation when promoting definitions
// to ensure full compatibility with the finalized codebase-composer.php.

// --- CONFIGURATION ---
define('LINES_PER_PAGE', 1000);
define('CIDD_UPLOAD_DIR', __DIR__ . '/uploads');
define('CIDD_DB_FILE', __DIR__ . '/cidd_db.json');
define('COMPOSER_DATA_DIR', __DIR__ . '/composer_data'); // Integration point

// --- INITIALIZATION ---
if (!is_dir(CIDD_UPLOAD_DIR)) mkdir(CIDD_UPLOAD_DIR, 0777, true);
if (!file_exists(CIDD_DB_FILE)) file_put_contents(CIDD_DB_FILE, json_encode([]));

// --- API ROUTER ---
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];
    $data = json_decode(file_get_contents(CIDD_DB_FILE), true);
    if (!is_array($data)) $data = [];

    switch ($action) {
        // Standard CIDD Actions
        case 'add_entry':
        case 'update_entry':
        case 'delete_entry':
            handle_cidd_crud($action, $data);
            break;
        // File Upload & Chunk Loading
        case 'get_codebase_chunk':
            handle_chunk_request();
            break;
        // Composer Integration Actions
        case 'list_composer_projects':
            echo json_encode(list_composer_projects());
            break;
        case 'list_composer_libs':
            $project = $_GET['project'] ?? '';
            echo json_encode(list_composer_definition_libs($project));
            break;
        case 'promote_to_composer':
            handle_promotion($data);
            break;
    }
    exit;
}

// --- BACKEND HANDLERS & FUNCTIONS ---

function handle_cidd_crud($action, &$data) {
    if ($action === 'add_entry') {
        $new_entry = [
            'id' => uniqid('entry_'),
            'codebase' => $_POST['codebase'],
            'selection' => $_POST['selection'],
            'selection_type' => $_POST['selection_type'],
            'start_line' => (int)$_POST['start_line'],
            'end_line' => (int)$_POST['end_line'],
            'start_char' => isset($_POST['start_char']) && $_POST['start_char'] !== '' ? (int)$_POST['start_char'] : null,
            'end_char' => isset($_POST['end_char']) && $_POST['end_char'] !== '' ? (int)$_POST['end_char'] : null,
            'definition' => $_POST['definition'],
            'context' => $_POST['context'],
            'notes' => $_POST['notes'],
            'created_at' => date('c'),
            'composer_definition_id' => null // New field for integration
        ];
        $data[] = $new_entry;
        file_put_contents(CIDD_DB_FILE, json_encode($data, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'entry' => $new_entry]);
    } elseif ($action === 'update_entry') {
        $id = $_POST['id'];
        foreach ($data as &$entry) {
            if ($entry['id'] === $id) {
                $entry['definition'] = $_POST['definition'];
                $entry['context'] = $_POST['context'];
                $entry['notes'] = $_POST['notes'];
                file_put_contents(CIDD_DB_FILE, json_encode($data, JSON_PRETTY_PRINT));
                echo json_encode(['status' => 'success', 'entry' => $entry]);
                return;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Entry not found.']);
    } elseif ($action === 'delete_entry') {
        $id = $_POST['id'];
        $data = array_filter($data, fn($entry) => $entry['id'] !== $id);
        file_put_contents(CIDD_DB_FILE, json_encode(array_values($data), JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
    }
}

function handle_chunk_request() {
    $filePath = $_GET['filepath'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    
    if (empty($filePath) || !file_exists($filePath) || strpos(realpath($filePath), realpath(CIDD_UPLOAD_DIR)) !== 0) {
        echo json_encode(['error' => 'Invalid file path.']);
        exit;
    }

    $start_line = (($page - 1) * LINES_PER_PAGE) + 1;
    $end_line = $start_line + LINES_PER_PAGE - 1;
    $content = [];
    $line_number = 0;
    
    $handle = fopen($filePath, "r");
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $line_number++;
            if ($line_number >= $start_line && $line_number <= $end_line) {
                $content[] = $line;
            }
            if ($line_number > $end_line) break;
        }
        fclose($handle);
    }
    echo json_encode(['content' => $content, 'page' => $page]);
}

function handle_promotion(&$cidd_data) {
    $cidd_id = $_POST['cidd_id'];
    $project = $_POST['project'];
    $library = $_POST['library'];

    // Find the CIDD entry
    $source_entry = null;
    foreach ($cidd_data as $entry) {
        if ($entry['id'] === $cidd_id) {
            $source_entry = $entry;
            break;
        }
    }
    if (!$source_entry) {
        echo json_encode(['status' => 'error', 'message' => 'Source dictionary entry not found.']);
        return;
    }
    if (!empty($source_entry['composer_definition_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'This entry has already been promoted.']);
        return;
    }

    // Read the Composer library file to check for name collisions
    $lib_path = COMPOSER_DATA_DIR . "/$project/definitions/" . sanitize_filename($library);
    if (!file_exists($lib_path)) {
        echo json_encode(['status' => 'error', 'message' => 'Composer library not found.']);
        return;
    }
    $composer_defs = json_decode(file_get_contents($lib_path), true);
    if (!is_array($composer_defs)) $composer_defs = [];

    // COMPATIBILITY FIX: Ensure unique name for the new definition
    $base_name = sanitize_filename($source_entry['context'] . '_' . $source_entry['selection_type']);
    $final_name = $base_name;
    $counter = 2;
    while (true) {
        $name_exists = false;
        foreach ($composer_defs as $def) {
            if ($def['name'] === $final_name) {
                $name_exists = true;
                break;
            }
        }
        if (!$name_exists) {
            break;
        }
        $final_name = $base_name . '_' . $counter++;
    }

    // Prepare the new Composer definition
    $composer_def_id = 'def_' . uniqid();
    $new_def = [
        'id' => $composer_def_id,
        'name' => $final_name, // Use the unique name
        'content' => $source_entry['selection'],
        'notes' => $source_entry['notes']
    ];
    
    $composer_defs[] = $new_def;
    file_put_contents($lib_path, json_encode($composer_defs, JSON_PRETTY_PRINT));

    // Update the CIDD entry to link it
    foreach ($cidd_data as &$entry) {
        if ($entry['id'] === $cidd_id) {
            $entry['composer_definition_id'] = $composer_def_id;
            break;
        }
    }
    file_put_contents(CIDD_DB_FILE, json_encode($cidd_data, JSON_PRETTY_PRINT));

    echo json_encode(['status' => 'success', 'message' => 'Promoted to Composer definition ' . $composer_def_id]);
}

// --- Composer Integration Read Functions ---
function list_composer_projects() {
    $projects = [];
    if (!is_dir(COMPOSER_DATA_DIR)) return ['status' => 'success', 'projects' => []];
    $items = array_diff(scandir(COMPOSER_DATA_DIR), ['..', '.']);
    foreach ($items as $item) {
        if (is_dir(COMPOSER_DATA_DIR . '/' . $item)) {
            $projects[] = $item;
        }
    }
    return ['status' => 'success', 'projects' => $projects];
}

function list_composer_definition_libs($project) {
    $project = sanitize_filename($project);
    $dir_path = COMPOSER_DATA_DIR . "/$project/definitions";
    $files = [];
    if (!is_dir($dir_path)) return ['status' => 'success', 'files' => []];
    
    $items = array_diff(scandir($dir_path), ['..', '.']);
    foreach ($items as $item) {
        if (pathinfo($item, PATHINFO_EXTENSION) === 'json') {
             $files[] = $item;
        }
    }
    return ['status' => 'success', 'files' => $files];
}

function sanitize_filename($filename) {
    return preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
}

// --- PAGE LOAD LOGIC (File Upload) ---
$uploaded_file = null;
$error_message = '';
$total_lines = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['codebase_file'])) {
    if ($_FILES['codebase_file']['error'] === UPLOAD_ERR_OK) {
        $file_extension = pathinfo($_FILES['codebase_file']['name'], PATHINFO_EXTENSION);
        if (strtolower($file_extension) === 'txt') {
            $uploaded_file = CIDD_UPLOAD_DIR . '/' . basename($_FILES['codebase_file']['name']);
            if (move_uploaded_file($_FILES['codebase_file']['tmp_name'], $uploaded_file)) {
                $total_lines = 0;
                $handle = fopen($uploaded_file, "r");
                if ($handle) {
                    while (fgets($handle) !== false) $total_lines++;
                    fclose($handle);
                }
            } else {
                $error_message = 'Failed to move uploaded file.';
                $uploaded_file = null;
            }
        } else {
            $error_message = 'Only .txt files are allowed.';
        }
    } else {
        $error_message = 'Error uploading file.';
    }
}

$dictionary_data = json_decode(file_get_contents(CIDD_DB_FILE), true);
if (!is_array($dictionary_data)) $dictionary_data = [];
$is_composer_present = is_dir(COMPOSER_DATA_DIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CIDD - Codebase Interactive Dictionary</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #f7f8fc; --container-bg: #ffffff; --text-color: #333333; --heading-color: #111111;
            --border-color: #e0e0e0; --primary-color: #4a90e2; --primary-hover: #357abd; --secondary-color: #6c757d;
            --secondary-hover: #5a6268; --danger-color: #e94e77; --danger-hover: #d6336c; --code-bg: #f1f3f5;
            --shadow-color: rgba(0, 0, 0, 0.08); --line-number-color: #999; --success-color: #28a745;
        }
        body { font-family: 'Inter', sans-serif; line-height: 1.6; margin: 0; background-color: var(--bg-color); color: var(--text-color); }
        .container { max-width: 1600px; margin: 20px auto; padding: 20px 40px; background-color: var(--container-bg); border-radius: 12px; box-shadow: 0 4px 12px var(--shadow-color); }
        h1, h2, h3 { color: var(--heading-color); font-weight: 700; }
        h1 { font-size: 2.25rem; } h2 { font-size: 1.75rem; margin-top: 0; } h3 { font-size: 1.25rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 15px; }
        .grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        .panel { border: 1px solid var(--border-color); border-radius: 8px; height: 75vh; display: flex; flex-direction: column; }
        .panel-header { padding: 15px 20px; border-bottom: 1px solid var(--border-color); }
        #codebase-viewer-container { padding: 0; overflow-y: auto; flex-grow: 1; font-family: "SF Mono", monospace; font-size: 14px; }
        .code-line { display: flex; white-space: pre-wrap; }
        .line-number { text-align: right; padding: 0 10px; color: var(--line-number-color); background-color: var(--code-bg); user-select: none; min-width: 50px; box-sizing: border-box; }
        .line-content { flex-grow: 1; padding-left: 10px; }
        #dictionary-manager { padding: 20px; overflow-y: auto; flex-grow: 1; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 500; margin-bottom: 8px; }
        input[type="text"], input[type="file"], textarea, select { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; box-sizing: border-box; font-family: 'Inter', sans-serif; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        input[type="text"]:focus, textarea:focus, select:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.25); }
        textarea { resize: vertical; min-height: 100px; }
        textarea#selection { background-color: #f8f9fa; cursor: not-allowed; }
        .btn { padding: 12px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 500; text-decoration: none; display: inline-block; transition: all 0.2s ease-in-out; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 2px 8px var(--shadow-color); }
        .btn-primary { background-color: var(--primary-color); color: white; } .btn-primary:hover { background-color: var(--primary-hover); }
        .btn-secondary { background-color: var(--secondary-color); color: white; } .btn-secondary:hover { background-color: var(--secondary-hover); }
        .btn-danger { background-color: var(--danger-color); color: white; } .btn-danger:hover { background-color: var(--danger-hover); }
        .btn-success { background-color: var(--success-color); color: white; }
        .entry { border: 1px solid transparent; border-bottom: 1px solid var(--border-color); padding: 15px; margin: 0 -15px 10px; transition: background-color 0.2s ease, border-color 0.2s ease; }
        .entry:hover { background-color: #fcfdff; border-color: #d0e3f8; }
        .entry-code { background-color: var(--code-bg); padding: 8px 12px; border-radius: 4px; font-family: "SF Mono", monospace; display: block; margin-bottom: 10px; word-break: break-all; }
        .entry p { margin: 5px 0; } .entry-actions { margin-top: 15px; }
        .entry-actions .btn { padding: 6px 12px; font-size: 14px; margin-right: 10px; }
        .error { color: var(--danger-color); font-weight: bold; } .hidden { display: none; }
        .upload-form { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        #loader { text-align: center; padding: 20px; }
        .modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fff; margin: 10% auto; padding: 2rem; border-radius: 0.5rem; width: 80%; max-width: 600px; }
        .linked-status { font-size: 0.8rem; font-weight: bold; color: var(--success-color); background-color: #e9f7ef; padding: 2px 6px; border-radius: 4px; display: inline-block; }
        @media (max-width: 992px) { .grid-container { grid-template-columns: 1fr; } .panel { height: auto; min-height: 50vh; } .container { padding: 20px; } }
    </style>
</head>
<body>

<div class="container">
    <h1>CIDD - Codebase Interactive Dictionary</h1>
    <div class="form-group">
        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <input type="file" name="codebase_file" id="codebase_file" accept=".txt" required>
            <button type="submit" class="btn btn-primary">Load Codebase</button>
        </form>
        <?php if ($error_message): ?><p class="error"><?= htmlspecialchars($error_message) ?></p><?php endif; ?>
    </div>

    <div class="grid-container">
        <div class="panel">
            <div class="panel-header"><h2>Codebase Viewer</h2></div>
            <div id="codebase-viewer-container" data-codebase-path="<?= htmlspecialchars($uploaded_file ?? '') ?>" data-total-lines="<?= $total_lines ?>">
                <div id="codebase-viewer">
                    <?php
                    if ($uploaded_file && file_exists($uploaded_file)) {
                        $handle = fopen($uploaded_file, "r");
                        if ($handle) {
                            for ($i = 1; $i <= min($total_lines, LINES_PER_PAGE); $i++) {
                                if (($line = fgets($handle)) === false) break;
                                echo '<div class="code-line"><div class="line-number">' . $i . '</div><div class="line-content">' . htmlspecialchars($line) . '</div></div>';
                            }
                            fclose($handle);
                        }
                    } else { echo '<p style="padding: 20px;">Please upload a codebase file to begin.</p>'; }
                    ?>
                </div>
                 <div id="loader" class="hidden"><p>Loading...</p></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header"><h2>Dictionary Manager</h2></div>
            <div id="dictionary-manager">
                <form id="entry-form" class="hidden">
                    <h3 id="form-title">New Entry</h3>
                    <input type="hidden" id="entry-id" name="id">
                    <div class="form-group">
                        <label for="selection" id="selection-label">Selected Code</label>
                        <textarea id="selection" name="selection" readonly rows="5"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="definition">Definition:</label>
                        <textarea id="definition" name="definition" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="context">Context:</label>
                        <textarea id="context" name="context"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="notes">Notes:</label>
                        <textarea id="notes" name="notes"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Entry</button>
                    <button type="button" id="cancel-edit" class="btn btn-secondary">Cancel</button>
                </form>
                <div id="dictionary-entries"><h3>Entries</h3></div>
            </div>
        </div>
    </div>
</div>

<!-- Promotion Modal -->
<div id="promotion-modal" class="modal">
    <div class="modal-content">
        <h3>Promote to Composer Definition</h3>
        <input type="hidden" id="promote-cidd-id">
        <div class="form-group">
            <label for="composer-projects">Select Composer Project:</label>
            <select id="composer-projects"></select>
        </div>
        <div class="form-group">
            <label for="composer-libs">Select Definition Library:</label>
            <select id="composer-libs"></select>
        </div>
        <button id="btn-confirm-promotion" class="btn btn-primary">Confirm Promotion</button>
        <button class="btn btn-secondary" onclick="closeModal('promotion-modal')">Cancel</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- STATE & DOM ---
    const state = {
        dictionary: <?= json_encode($dictionary_data) ?>,
        codebasePath: document.getElementById('codebase-viewer-container').dataset.codebasePath,
        totalLines: parseInt(document.getElementById('codebase-viewer-container').dataset.totalLines, 10),
        currentPage: 1,
        isLoading: false,
        isComposerPresent: <?= json_encode($is_composer_present) ?>,
    };
    const D = { // DOM Elements
        viewerContainer: document.getElementById('codebase-viewer-container'),
        viewer: document.getElementById('codebase-viewer'),
        loader: document.getElementById('loader'),
        entryForm: document.getElementById('entry-form'),
        formTitle: document.getElementById('form-title'),
        dictionaryEntries: document.getElementById('dictionary-entries'),
        selectionTextarea: document.getElementById('selection'),
        selectionLabel: document.getElementById('selection-label'),
        entryIdInput: document.getElementById('entry-id'),
        definitionInput: document.getElementById('definition'),
        contextInput: document.getElementById('context'),
        notesInput: document.getElementById('notes'),
        cancelEditBtn: document.getElementById('cancel-edit'),
        promotionModal: document.getElementById('promotion-modal'),
        composerProjectsSelect: document.getElementById('composer-projects'),
        composerLibsSelect: document.getElementById('composer-libs'),
        promoteCiddIdInput: document.getElementById('promote-cidd-id'),
        btnConfirmPromotion: document.getElementById('btn-confirm-promotion'),
    };

    // --- RENDER FUNCTIONS ---
    function renderDictionary() {
        const container = document.createElement('div');
        const filtered = state.dictionary.filter(e => e.codebase === state.codebasePath);

        if (filtered.length === 0) {
            container.innerHTML = '<p>No entries for this codebase yet.</p>';
        } else {
            filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).forEach(entry => {
                const el = document.createElement('div');
                el.className = 'entry';
                el.dataset.id = entry.id;
                
                let lineInfo = '';
                switch(entry.selection_type) {
                    case 'character': lineInfo = `Line ${entry.start_line}, Chars ${entry.start_char}-${entry.end_char}`; break;
                    case 'line': lineInfo = `Line ${entry.start_line}`; break;
                    case 'block': lineInfo = `Lines ${entry.start_line}-${entry.end_line}`; break;
                }

                let linkedStatus = entry.composer_definition_id ? `<span class="linked-status">Linked to Composer</span>` : '';
                let promoteBtn = !entry.composer_definition_id && state.isComposerPresent ? `<button class="btn btn-success promote-btn">Promote to Composer</button>` : '';

                el.innerHTML = `
                    <p><strong>${lineInfo}</strong> ${linkedStatus}</p>
                    <code class="entry-code">${escapeHtml(entry.selection)}</code>
                    <p><strong>Definition:</strong> ${escapeHtml(entry.definition)}</p>
                    <p><strong>Context:</strong> ${escapeHtml(entry.context || 'N/A')}</p>
                    <p><strong>Notes:</strong> ${escapeHtml(entry.notes || 'N/A')}</p>
                    <div class="entry-actions">
                        <button class="btn btn-secondary edit-btn">Edit</button>
                        <button class="btn btn-danger delete-btn">Delete</button>
                        ${promoteBtn}
                    </div>`;
                container.appendChild(el);
            });
        }
        const h3 = D.dictionaryEntries.querySelector('h3');
        D.dictionaryEntries.innerHTML = '';
        if(h3) D.dictionaryEntries.appendChild(h3);
        D.dictionaryEntries.appendChild(container);
    }

    // --- API & ASYNC LOGIC ---
    async function apiCall(action, params = {}, method = 'GET') {
        let url = `?action=${action}`;
        let options = { method };
        if (method === 'GET') {
            url += '&' + new URLSearchParams(params).toString();
        } else {
            const formData = new FormData();
            for (const key in params) {
                formData.append(key, params[key]);
            }
            options.body = formData;
        }
        try {
            const response = await fetch(url, options);
            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            alert('An error occurred. Check console.');
        }
    }

    async function fetchNextPage() {
        if (state.isLoading || (state.currentPage * LINES_PER_PAGE) >= state.totalLines) return;
        state.isLoading = true;
        D.loader.classList.remove('hidden');
        const data = await apiCall('get_codebase_chunk', { filepath: state.codebasePath, page: state.currentPage + 1 });
        if (data && data.content) {
            state.currentPage++;
            const startLineNum = ((data.page - 1) * LINES_PER_PAGE) + 1;
            data.content.forEach((line, index) => {
                const lineNum = startLineNum + index;
                const lineEl = document.createElement('div');
                lineEl.className = 'code-line';
                lineEl.innerHTML = `<div class="line-number">${lineNum}</div><div class="line-content">${escapeHtml(line)}</div>`;
                D.viewer.appendChild(lineEl);
            });
        }
        state.isLoading = false;
        D.loader.classList.add('hidden');
    }

    // --- EVENT HANDLERS ---
    D.viewerContainer.addEventListener('scroll', () => {
        const { scrollTop, scrollHeight, clientHeight } = D.viewerContainer;
        if (scrollTop + clientHeight >= scrollHeight - 200) fetchNextPage();
    });

    D.viewer.addEventListener('mouseup', () => {
        const selection = window.getSelection();
        if (!selection.rangeCount || selection.isCollapsed) return;
        
        const range = selection.getRangeAt(0);
        const startLineEl = findParentCodeLine(range.startContainer);
        const endLineEl = findParentCodeLine(range.endContainer);
        if (!startLineEl || !endLineEl) return;

        const startLine = parseInt(startLineEl.querySelector('.line-number').textContent, 10);
        const endLine = parseInt(endLineEl.querySelector('.line-number').textContent, 10);
        
        let reconstructedText = range.toString();
        if (reconstructedText.trim() === '') return;

        let selectionType, startChar = null, endChar = null;
        if (startLine === endLine) {
            const fullLineText = startLineEl.querySelector('.line-content').textContent;
            selectionType = (reconstructedText.trim() === fullLineText.trim()) ? 'line' : 'character';
            if (selectionType === 'character') {
                const tempRange = document.createRange();
                tempRange.selectNodeContents(startLineEl.querySelector('.line-content'));
                tempRange.setEnd(range.startContainer, range.startOffset);
                startChar = tempRange.toString().length;
                endChar = startChar + reconstructedText.length;
            }
        } else {
            selectionType = 'block';
        }

        resetForm();
        D.entryForm.classList.remove('hidden');
        D.selectionTextarea.value = reconstructedText;
        D.selectionTextarea.dataset.selectionType = selectionType;
        D.selectionTextarea.dataset.startLine = startLine;
        D.selectionTextarea.dataset.endLine = endLine;
        D.selectionTextarea.dataset.startChar = startChar ?? '';
        D.selectionTextarea.dataset.endChar = endChar ?? '';

        switch(selectionType) {
            case 'character': D.formTitle.textContent = 'New Character Entry'; D.selectionLabel.textContent = `Selected Code (Line ${startLine}, Chars ${startChar}-${endChar})`; break;
            case 'line': D.formTitle.textContent = 'New Line Entry'; D.selectionLabel.textContent = `Selected Code (Line ${startLine})`; break;
            case 'block': D.formTitle.textContent = 'New Block Entry'; D.selectionLabel.textContent = `Selected Code (Lines ${startLine}-${endLine})`; break;
        }
        D.definitionInput.focus();
    });
    
    D.entryForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = D.entryIdInput.value;
        const action = id ? 'update_entry' : 'add_entry';
        const params = {
            id: id,
            codebase: state.codebasePath,
            selection: D.selectionTextarea.value,
            selection_type: D.selectionTextarea.dataset.selectionType,
            start_line: D.selectionTextarea.dataset.startLine,
            end_line: D.selectionTextarea.dataset.endLine,
            start_char: D.selectionTextarea.dataset.startChar,
            end_char: D.selectionTextarea.dataset.endChar,
            definition: D.definitionInput.value,
            context: D.contextInput.value,
            notes: D.notesInput.value,
        };
        const data = await apiCall(action, params, 'POST');
        if (data.status === 'success') {
            if (action === 'add_entry') {
                state.dictionary.push(data.entry);
            } else {
                const index = state.dictionary.findIndex(entry => entry.id === id);
                if (index !== -1) state.dictionary[index] = data.entry;
            }
            renderDictionary();
            resetForm();
        } else {
            alert('Error: ' + (data.message || 'Could not save entry.'));
        }
    });

    D.dictionaryEntries.addEventListener('click', async (e) => {
        const entryEl = e.target.closest('.entry');
        if (!entryEl) return;
        const id = entryEl.dataset.id;
        const entry = state.dictionary.find(e => e.id === id);

        if (e.target.classList.contains('edit-btn')) {
            D.formTitle.textContent = 'Edit Entry';
            D.entryForm.classList.remove('hidden');
            D.entryIdInput.value = entry.id;
            D.selectionTextarea.value = entry.selection;
            D.definitionInput.value = entry.definition;
            D.contextInput.value = entry.context;
            D.notesInput.value = entry.notes;
            D.entryForm.scrollIntoView({ behavior: 'smooth' });
        } else if (e.target.classList.contains('delete-btn')) {
            if (confirm('Are you sure?')) {
                const data = await apiCall('delete_entry', { id }, 'POST');
                if (data.status === 'success') {
                    state.dictionary = state.dictionary.filter(e => e.id !== id);
                    renderDictionary();
                }
            }
        } else if (e.target.classList.contains('promote-btn')) {
            D.promoteCiddIdInput.value = id;
            const projectsData = await apiCall('list_composer_projects');
            if (projectsData.status === 'success' && projectsData.projects.length > 0) {
                D.composerProjectsSelect.innerHTML = projectsData.projects.map(p => `<option value="${p}">${p}</option>`).join('');
                await updateComposerLibsDropdown();
                openModal('promotion-modal');
            } else {
                alert('No Composer projects found.');
            }
        }
    });

    D.composerProjectsSelect.addEventListener('change', updateComposerLibsDropdown);
    D.btnConfirmPromotion.addEventListener('click', async () => {
        const params = {
            cidd_id: D.promoteCiddIdInput.value,
            project: D.composerProjectsSelect.value,
            library: D.composerLibsSelect.value,
        };
        const data = await apiCall('promote_to_composer', params, 'POST');
        if (data.status === 'success') {
            const index = state.dictionary.findIndex(e => e.id === params.cidd_id);
            if (index !== -1) state.dictionary[index].composer_definition_id = data.message.split(' ').pop();
            renderDictionary();
            closeModal('promotion-modal');
            alert('Promotion successful!');
        } else {
            alert('Error: ' + (data.message || 'Promotion failed.'));
        }
    });

    // --- UTILITY FUNCTIONS ---
    async function updateComposerLibsDropdown() {
        const project = D.composerProjectsSelect.value;
        const libsData = await apiCall('list_composer_libs', { project });
        if (libsData.status === 'success') {
            D.composerLibsSelect.innerHTML = libsData.files.map(f => `<option value="${f}">${f}</option>`).join('');
        }
    }
    function findParentCodeLine(node) {
        if (!node) return null;
        if (node.nodeType === 1 && node.classList.contains('code-line')) return node;
        return findParentCodeLine(node.parentElement);
    }
    function resetForm() {
        D.entryForm.reset();
        D.entryIdInput.value = '';
        D.entryForm.classList.add('hidden');
    }
    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return '';
        return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
    function openModal(id) { document.getElementById(id).style.display = 'block'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    D.cancelEditBtn.addEventListener('click', resetForm);

    // --- INITIALIZATION ---
    if(state.codebasePath) {
        renderDictionary();
    }
});
</script>

</body>
</html>