<?php
// codebase-composer.php - A Modular Software Architecture Composer
// Version 1.7
// Implements accessibility improvements by adding programmatic labels to
// form controls, ensuring compliance with WCAG standards.

// --- INITIALIZATION & CONFIGURATION ---
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DATA_DIR', __DIR__ . '/composer_data');

// Ensure the base data directory exists
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

// Handle Tab Size Setting
if (isset($_POST['action']) && $_POST['action'] === 'set_tab_size') {
    $tabSize = (int)$_POST['tab_size'];
    if ($tabSize > 0 && $tabSize <= 16) {
        $_SESSION['tab_size'] = $tabSize;
    }
    echo json_encode(['status' => 'success', 'tab_size' => $_SESSION['tab_size']]);
    exit;
}
$TAB_SIZE = $_SESSION['tab_size'] ?? 4;


// --- API ROUTER ---
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $project = $_POST['project'] ?? null;
    $payload = $_POST['payload'] ?? [];

    // Basic validation for project name to prevent directory traversal
    if ($project && (strpos($project, '/') !== false || strpos($project, '..') !== false)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid project name.']);
        exit;
    }

    switch ($action) {
        // Project Management
        case 'list_projects':
            echo json_encode(list_projects());
            break;
        case 'create_project':
            echo json_encode(create_project($payload['name']));
            break;
        // Definition Library Management
        case 'list_definition_libs':
            echo json_encode(list_files($project, 'definitions', 'json'));
            break;
        case 'create_definition_lib':
            echo json_encode(create_file($project, 'definitions', $payload['name'], 'json', []));
            break;
        case 'get_definitions':
            echo json_encode(get_definitions($project, $payload['lib']));
            break;
        case 'save_definition':
            echo json_encode(save_definition($project, $payload));
            break;
        case 'delete_definition':
            echo json_encode(delete_definition($project, $payload));
            break;
        // Composition Management
        case 'list_compositions':
            echo json_encode(list_files($project, 'compositions', 'composition.json'));
            break;
        case 'create_composition':
            echo json_encode(create_file($project, 'compositions', $payload['name'], 'composition.json', ['definitions' => []]));
            break;
        case 'get_composition':
            echo json_encode(get_composition($project, $payload['name']));
            break;
        case 'save_composition':
            echo json_encode(save_composition($project, $payload));
            break;
    }
    exit;
}

// --- BACKEND FUNCTIONS ---

function sanitize_filename($filename) {
    // The period character (.) must be included in the allowed character set.
    $sanitized = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
    // Prevent multiple consecutive underscores
    $sanitized = preg_replace('/_+/', '_', $sanitized);
    return $sanitized;
}


// --- Project Functions ---
function list_projects() {
    $projects = [];
    $items = array_diff(scandir(DATA_DIR), ['..', '.']);
    foreach ($items as $item) {
        if (is_dir(DATA_DIR . '/' . $item)) {
            $projects[] = $item;
        }
    }
    return ['status' => 'success', 'projects' => $projects];
}

function create_project($name) {
    $name = sanitize_filename($name);
    if (empty($name)) return ['status' => 'error', 'message' => 'Project name cannot be empty.'];
    $project_path = DATA_DIR . '/' . $name;
    if (is_dir($project_path)) return ['status' => 'error', 'message' => 'Project already exists.'];
    
    mkdir($project_path, 0777, true);
    mkdir($project_path . '/definitions', 0777, true);
    mkdir($project_path . '/compositions', 0777, true);
    
    return ['status' => 'success', 'project' => $name];
}

// --- Generic File/Dir Functions ---
function list_files($project, $type, $extension) {
    $dir_path = DATA_DIR . "/$project/$type";
    $files = [];
    if (!is_dir($dir_path)) return ['status' => 'success', 'files' => []];
    
    $items = array_diff(scandir($dir_path), ['..', '.']);
    foreach ($items as $item) {
        if (pathinfo($item, PATHINFO_EXTENSION) === explode('.', $extension)[0] || str_ends_with($item, $extension)) {
             $files[] = $item;
        }
    }
    return ['status' => 'success', 'files' => $files];
}

function create_file($project, $type, $name, $extension, $initial_content = []) {
    $name = sanitize_filename($name);
    if (empty($name)) return ['status' => 'error', 'message' => 'File name cannot be empty.'];
    if (!str_ends_with(strtolower($name), ".$extension")) {
        $name .= ".$extension";
    }
    $file_path = DATA_DIR . "/$project/$type/$name";
    if (file_exists($file_path)) return ['status' => 'error', 'message' => 'File already exists.'];
    
    file_put_contents($file_path, json_encode($initial_content, JSON_PRETTY_PRINT));
    return ['status' => 'success', 'file' => $name];
}

// --- Definition Functions ---
function get_definitions($project, $lib) {
    $file_path = DATA_DIR . "/$project/definitions/" . sanitize_filename($lib);
    if (!file_exists($file_path)) return ['status' => 'error', 'message' => 'Definition library not found.'];
    
    $content = json_decode(file_get_contents($file_path), true);
    return ['status' => 'success', 'definitions' => is_array($content) ? $content : []];
}

// --- Definition Functions ---
function save_definition($project, $payload) {
    $lib_path = DATA_DIR . "/$project/definitions/" . sanitize_filename($payload['lib']);
    if (!file_exists($lib_path))
        return ['status' => 'error', 'message' => 'Library not found.'];

    $definitions = json_decode(file_get_contents($lib_path), true) ?: [];

    // Treat an empty or missing id as "new"
    $id = trim($payload['id'] ?? '');
    if ($id === '') {
        $id = 'def_' . uniqid();
    }

    $new_def = [
        'id'      => $id,
        'name'    => $payload['name'],
        'content' => $payload['content'],
        'notes'   => $payload['notes']
    ];

    // Upsert
    $found = false;
    foreach ($definitions as &$def) {
        if ($def['id'] === $id) {
            $def   = $new_def;
            $found = true;
            break;
        }
    }
    if (!$found) $definitions[] = $new_def;

    file_put_contents($lib_path, json_encode($definitions, JSON_PRETTY_PRINT));
    return ['status' => 'success', 'definition' => $new_def];
}


function delete_definition($project, $payload) {
    $lib_path = DATA_DIR . "/$project/definitions/" . sanitize_filename($payload['lib']);
    if (!file_exists($lib_path)) return ['status' => 'error', 'message' => 'Library not found.'];
    
    $definitions = json_decode(file_get_contents($lib_path), true);
    if (!is_array($definitions)) $definitions = [];
    $id_to_delete = $payload['id'];
    
    $definitions = array_filter($definitions, function($def) use ($id_to_delete) {
        return $def['id'] !== $id_to_delete;
    });
    
    file_put_contents($lib_path, json_encode(array_values($definitions), JSON_PRETTY_PRINT));
    return ['status' => 'success'];
}

// --- Composition Functions ---
function get_composition($project, $comp_name) {
    $comp_path = DATA_DIR . "/$project/compositions/" . sanitize_filename($comp_name);
    if (!file_exists($comp_path)) return ['status' => 'error', 'message' => 'Composition not found.'];

    $composition = json_decode(file_get_contents($comp_path), true);
    $def_ids = $composition['definitions'] ?? [];
    
    // Load all definitions for the project into a hash map for quick lookup
    $all_definitions = [];
    $def_libs = list_files($project, 'definitions', 'json')['files'];
    foreach ($def_libs as $lib) {
        $defs_data = get_definitions($project, $lib);
        if ($defs_data['status'] === 'success') {
            foreach ($defs_data['definitions'] as $def) {
                $all_definitions[$def['id']] = $def;
            }
        }
    }

    $full_content = '';
    foreach ($def_ids as $id) {
        if (isset($all_definitions[$id])) {
            $full_content .= $all_definitions[$id]['content'];
        }
    }

    return ['status' => 'success', 'composition' => $composition, 'full_content' => $full_content];
}

function save_composition($project, $payload) {
    // fall back to an empty array if “definitions” is missing
    $defs = $payload['definitions'] ?? [];
    $composition = ['definitions' => array_values($defs)];

    // locate the file
    $comp_name = sanitize_filename($payload['name']);
    $comp_path = DATA_DIR . "/$project/compositions/$comp_name";
    if (!file_exists($comp_path))
        return ['status' => 'error', 'message' => 'Composition not found.'];

    // write the JSON
    file_put_contents($comp_path, json_encode($composition, JSON_PRETTY_PRINT));

    // also emit the assembled .txt version
    $assembled_data    = get_composition($project, $comp_name);
    $assembled_content = $assembled_data['full_content'] ?? '';
    $txt_filename      = str_replace('.composition.json', '.txt', $comp_name);
    file_put_contents(DATA_DIR . "/$project/compositions/$txt_filename", $assembled_content);

    return ['status' => 'success'];
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Codebase Composer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #f4f7f9;
            --panel-bg: #ffffff;
            --text-color: #334155;
            --heading-color: #1e293b;
            --border-color: #e2e8f0;
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --danger-color: #ef4444;
            --danger-hover: #dc2626;
            --code-bg: #f8fafc;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --font-sans: 'Inter', sans-serif;
            --font-mono: 'Roboto Mono', monospace;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: var(--font-sans); line-height: 1.6; margin: 0; background-color: var(--bg-color); color: var(--text-color); font-size: 16px; }
        .main-container { display: grid; grid-template-columns: 300px 1fr 1fr; height: 100vh; gap: 1rem; padding: 1rem; }
        .panel { background-color: var(--panel-bg); border-radius: 0.5rem; box-shadow: var(--shadow); border: 1px solid var(--border-color); display: flex; flex-direction: column; overflow: hidden; }
        .panel-header { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .panel-title { font-size: 1.125rem; font-weight: 600; color: var(--heading-color); }
        .panel-body { padding: 1rem; overflow-y: auto; flex-grow: 1; }
        .panel-body.no-padding { padding: 0; }
        .btn { padding: 0.5rem 1rem; border: 1px solid transparent; border-radius: 0.375rem; cursor: pointer; font-size: 0.875rem; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s ease; }
        .btn-primary { background-color: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background-color: var(--primary-hover); }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .btn:disabled { background-color: #9ca3af; border-color: #9ca3af; color: #e5e7eb; cursor: not-allowed; transform: none; box-shadow: none; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: 500; margin-bottom: 0.5rem; font-size: 0.875rem; }
        input[type="text"], textarea { width: 100%; padding: 0.625rem; border: 1px solid var(--border-color); border-radius: 0.375rem; transition: all 0.2s ease; }
        input[type="text"]:focus, textarea:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
        textarea { resize: vertical; min-height: 120px; font-family: var(--font-mono); tab-size: <?= $TAB_SIZE ?>; -moz-tab-size: <?= $TAB_SIZE ?>; }
        .file-list { list-style: none; padding: 0; margin: 0; }
        .file-list-item { padding: 0.75rem 1rem; cursor: pointer; border-bottom: 1px solid var(--border-color); transition: background-color 0.2s ease; }
        .file-list-item:last-child { border-bottom: none; }
        .file-list-item:hover { background-color: #f1f5f9; }
        .file-list-item.active { background-color: var(--primary-color); color: white; font-weight: 500; }
        .definition-item { padding: 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; margin-bottom: 1rem; cursor: pointer; transition: all 0.2s ease; }
        .definition-item:hover { border-color: var(--primary-color); box-shadow: var(--shadow); }
        .definition-name { font-weight: 600; color: var(--heading-color); }
        .definition-content { font-family: var(--font-mono); font-size: 0.875rem; background-color: var(--code-bg); padding: 0.5rem; border-radius: 0.25rem; margin-top: 0.5rem; white-space: pre-wrap; word-break: break-all; }
        .modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fff; margin: 10% auto; padding: 2rem; border-radius: 0.5rem; width: 80%; max-width: 600px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1rem; }
        .hidden { display: none; }
        #composition-editor { width: 100%; height: 100%; border: none; padding: 1rem; resize: none; font-family: var(--font-mono); }
        #composition-editor:focus { outline: none; }
        .settings-bar { padding: 0.5rem 1rem; background-color: var(--code-bg); border-top: 1px solid var(--border-color); display: flex; align-items: center; gap: 1rem; }
    </style>
</head>
<body>

<div class="main-container">
    <!-- Panel 1: Project & File Management -->
    <div class="panel" id="panel-projects">
        <div class="panel-header">
            <h2 class="panel-title">Projects</h2>
            <button class="btn btn-primary btn-sm" id="btn-new-project" aria-label="New Project">+</button>
        </div>
        <div class="panel-body no-padding">
            <ul class="file-list" id="project-list"></ul>
        </div>
        <div id="project-files-container" class="hidden">
            <div class="panel-header">
                <h3 class="panel-title">Def. Libraries</h3>
                <button class="btn btn-primary btn-sm" id="btn-new-def-lib" aria-label="New Definition Library">+</button>
            </div>
            <ul class="file-list" id="def-lib-list"></ul>
            <div class="panel-header">
                <h3 class="panel-title">Compositions</h3>
                <button class="btn btn-primary btn-sm" id="btn-new-composition" aria-label="New Composition">+</button>
            </div>
            <ul class="file-list" id="composition-list"></ul>
        </div>
    </div>

    <!-- Panel 2: Definitions -->
    <div class="panel" id="panel-definitions">
        <div class="panel-header">
            <h2 class="panel-title">Definitions</h2>
            <button class="btn btn-primary btn-sm" id="btn-new-definition" disabled>New Definition</button>
        </div>
        <div class="panel-body">
            <div class="form-group">
                <input type="text" id="search-definitions" placeholder="Search definitions...">
            </div>
            <div id="definition-list-container"></div>
        </div>
    </div>

    <!-- Panel 3: Composition Editor -->
    <div class="panel" id="panel-composition">
        <div class="panel-header">
            <h2 class="panel-title" id="composition-title">Composition</h2>
            <button class="btn btn-primary btn-sm hidden" id="btn-save-composition">Save</button>
        </div>
        <div class="panel-body no-padding">
            <textarea id="composition-editor" readonly aria-labelledby="composition-title"></textarea>
        </div>
        <div class="settings-bar">
            <label for="tab-size-input">Tab Size:</label>
            <input type="number" id="tab-size-input" min="1" max="16" value="<?= $TAB_SIZE ?>" style="width: 60px;">
        </div>
    </div>
</div>

<!-- Modals -->
<div id="new-project-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2>New Project</h2></div>
        <div class="form-group">
            <label for="new-project-name">Project Name:</label>
            <input type="text" id="new-project-name">
        </div>
        <button id="btn-create-project" class="btn btn-primary">Create</button>
        <button class="btn" onclick="closeModal('new-project-modal')">Cancel</button>
    </div>
</div>

<div id="new-file-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2 id="new-file-title">New File</h2></div>
        <div class="form-group">
            <label for="new-file-name">File Name:</label>
            <input type="text" id="new-file-name">
        </div>
        <button id="btn-create-file" class="btn btn-primary">Create</button>
        <button class="btn" onclick="closeModal('new-file-modal')">Cancel</button>
    </div>
</div>

<div id="definition-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2 id="definition-form-title">Definition</h2></div>
        <input type="hidden" id="definition-id">
        <input type="hidden" id="definition-lib">
        <div class="form-group">
            <label for="definition-name">Name:</label>
            <input type="text" id="definition-name" placeholder="e.g., php_open_tag">
        </div>
        <div class="form-group">
            <label for="definition-content">Content:</label>
            <textarea id="definition-content"></textarea>
        </div>
        <div class="form-group">
            <label for="definition-notes">Notes:</label>
            <textarea id="definition-notes" rows="3"></textarea>
        </div>
        <button id="btn-save-definition" class="btn btn-primary">Save</button>
        <button id="btn-delete-definition" class="btn btn-danger hidden">Delete</button>
        <button class="btn" onclick="closeModal('definition-modal')">Cancel</button>
    </div>
</div>

<script>
    // --- STATE MANAGEMENT ---
    const state = {
        projects: [],
        activeProject: null,
        definitionLibs: [],
        activeDefLib: null,
        compositions: [],
        activeComposition: null,
        definitions: [], // Now only holds definitions for the active library
        allDefinitions: {}, // Holds all loaded definitions for composition
        compositionDefIds: [],
    };

    // --- DOM ELEMENTS ---
    const M = {
        projectList: document.getElementById('project-list'),
        projectFilesContainer: document.getElementById('project-files-container'),
        defLibList: document.getElementById('def-lib-list'),
        compositionList: document.getElementById('composition-list'),
        definitionListContainer: document.getElementById('definition-list-container'),
        compositionEditor: document.getElementById('composition-editor'),
        compositionTitle: document.getElementById('composition-title'),
        btnNewDefinition: document.getElementById('btn-new-definition'),
        btnSaveComposition: document.getElementById('btn-save-composition'),
        searchDefinitions: document.getElementById('search-definitions'),
        tabSizeInput: document.getElementById('tab-size-input'),
    };

    // --- API HELPERS ---
    async function apiCall(action, project = null, payload = {}) {
        const formData = new FormData();
        formData.append('action', action);
        if (project) formData.append('project', project);
        

		for (const key in payload) {
			if (Array.isArray(payload[key])) {
				payload[key].forEach(item =>
					formData.append(`payload[${key}][]`, item));
			} else {
				formData.append(`payload[${key}]`, payload[key]);
			}
		}


        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const contentType = response.headers.get("content-type");
            if (!response.ok || !contentType || !contentType.includes("application/json")) {
                const text = await response.text();
                throw new Error(`Server returned non-JSON response: ${text}`);
            }
            return await response.json();
        } catch (error) {
            console.error('API Call Failed:', error);
            alert('An error occurred. Check the console for details.');
            return { status: 'error', message: error.message };
        }
    }

    // --- RENDER FUNCTIONS ---
    function renderProjects() {
        M.projectList.innerHTML = '';
        state.projects.forEach(p => {
            const li = document.createElement('li');
            li.className = 'file-list-item';
            li.textContent = p;
            if (p === state.activeProject) li.classList.add('active');
            li.addEventListener('click', () => selectProject(p));
            M.projectList.appendChild(li);
        });
    }

    function renderDefLibs() {
        M.defLibList.innerHTML = '';
        state.definitionLibs.forEach(lib => {
            const li = document.createElement('li');
            li.className = 'file-list-item';
            li.textContent = lib;
            if (lib === state.activeDefLib) li.classList.add('active');
            li.addEventListener('click', () => selectDefLib(lib));
            M.defLibList.appendChild(li);
        });
    }
    
    function renderCompositions() {
        M.compositionList.innerHTML = '';
        state.compositions.forEach(comp => {
            const li = document.createElement('li');
            li.className = 'file-list-item';
            li.textContent = comp;
            if (comp === state.activeComposition) li.classList.add('active');
            li.addEventListener('click', () => selectComposition(comp));
            M.compositionList.appendChild(li);
        });
    }

    function renderDefinitions() {
        const searchTerm = M.searchDefinitions.value.toLowerCase();
        M.definitionListContainer.innerHTML = '';
        const filteredDefs = state.definitions.filter(def => 
            def.name.toLowerCase().includes(searchTerm) || 
            def.content.toLowerCase().includes(searchTerm)
        );

        filteredDefs.forEach(def => {
            const div = document.createElement('div');
            div.className = 'definition-item';
            div.innerHTML = `
                <div class="definition-name">${escapeHtml(def.name)}</div>
                <div class="definition-content">${escapeHtml(def.content)}</div>
            `;
            div.addEventListener('click', () => addDefinitionToComposition(def.id));
            div.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                openDefinitionModal(def);
            });
            M.definitionListContainer.appendChild(div);
        });
    }

    async function renderCompositionEditor() {
        if (!state.activeComposition) {
            M.compositionEditor.value = '';
            M.compositionTitle.textContent = 'Composition';
            M.btnSaveComposition.classList.add('hidden');
            return;
        }
        M.compositionTitle.textContent = state.activeComposition;
        M.btnSaveComposition.classList.remove('hidden');

        const res = await apiCall('get_composition', state.activeProject, { name: state.activeComposition });
        if (res.status === 'success') {
            state.compositionDefIds = res.composition.definitions || [];
            M.compositionEditor.value = res.full_content;
        }
    }

    // --- EVENT HANDLERS & LOGIC ---
    async function init() {
        const res = await apiCall('list_projects');
        if (res.status === 'success') {
            state.projects = res.projects;
            renderProjects();
        }
        M.searchDefinitions.addEventListener('input', renderDefinitions);
        enableTabInTextarea(document.getElementById('definition-content'));
    }

    async function selectProject(projectName) {
        state.activeProject = projectName;
        state.activeDefLib = null;
        state.activeComposition = null;
        state.definitions = [];
        state.allDefinitions = {};
        M.btnNewDefinition.disabled = true;
        renderProjects();
        renderDefinitions();
        renderCompositionEditor();
        M.projectFilesContainer.classList.remove('hidden');
        
        const [libsRes, compsRes] = await Promise.all([
            apiCall('list_definition_libs', state.activeProject),
            apiCall('list_compositions', state.activeProject)
        ]);

        if (libsRes.status === 'success') {
            state.definitionLibs = libsRes.files;
            renderDefLibs();
            // Pre-load all definitions for composition
            for (const lib of state.definitionLibs) {
                const defsRes = await apiCall('get_definitions', state.activeProject, { lib });
                if (defsRes.status === 'success') {
                    defsRes.definitions.forEach(def => {
                        state.allDefinitions[def.id] = def;
                    });
                }
            }
        }
        if (compsRes.status === 'success') {
            state.compositions = compsRes.files;
            renderCompositions();
        }
    }

    async function selectDefLib(libName) {
        state.activeDefLib = libName;
        M.btnNewDefinition.disabled = false;
        renderDefLibs();
        
        const res = await apiCall('get_definitions', state.activeProject, { lib: libName });
        if (res.status === 'success') {
            state.definitions = res.definitions;
            state.definitions.forEach(def => def.lib = libName);
            renderDefinitions();
        }
    }
    
    async function selectComposition(compName) {
        state.activeComposition = compName;
        renderCompositions();
        await renderCompositionEditor();
    }
    
    function addDefinitionToComposition(defId) {
        if (!state.activeComposition) {
            alert('Please select a composition first.');
            return;
        }
        const def = state.allDefinitions[defId];
        if (def) {
            state.compositionDefIds.push(def.id);
            M.compositionEditor.value += def.content;
        } else {
            const localDef = state.definitions.find(d => d.id === defId);
            if(localDef) {
                 state.compositionDefIds.push(localDef.id);
                 M.compositionEditor.value += localDef.content;
            }
        }
    }

    async function saveComposition() {
        if (!state.activeComposition) return;
        const res = await apiCall('save_composition', state.activeProject, {
            name: state.activeComposition,
            definitions: state.compositionDefIds
        });
        if (res.status === 'success') {
            alert('Composition saved!');
        } else {
            alert('Error saving composition: ' + res.message);
        }
    }

    // --- MODAL & FORM LOGIC ---
    function openModal(id) { document.getElementById(id).style.display = 'block'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    document.getElementById('btn-new-project').addEventListener('click', () => openModal('new-project-modal'));
    document.getElementById('btn-create-project').addEventListener('click', async () => {
        const name = document.getElementById('new-project-name').value;
        const res = await apiCall('create_project', null, { name });
        if (res.status === 'success') {
            state.projects.push(res.project);
            selectProject(res.project);
            closeModal('new-project-modal');
        } else {
            alert('Error: ' + res.message);
        }
    });

    let fileCreationContext = {};
    document.getElementById('btn-new-def-lib').addEventListener('click', () => {
        if (!state.activeProject) return;
        fileCreationContext = { type: 'definitions', action: 'create_definition_lib' };
        document.getElementById('new-file-title').textContent = 'New Definition Library';
        document.getElementById('new-file-name').value = '';
        openModal('new-file-modal');
    });
    document.getElementById('btn-new-composition').addEventListener('click', () => {
        if (!state.activeProject) return;
        fileCreationContext = { type: 'compositions', action: 'create_composition' };
        document.getElementById('new-file-title').textContent = 'New Composition';
        document.getElementById('new-file-name').value = '';
        openModal('new-file-modal');
    });
    document.getElementById('btn-create-file').addEventListener('click', async () => {
        const name = document.getElementById('new-file-name').value;
        const res = await apiCall(fileCreationContext.action, state.activeProject, { name });
        if (res.status === 'success') {
            if (fileCreationContext.type === 'definitions') {
                state.definitionLibs.push(res.file);
                renderDefLibs();
            } else {
                state.compositions.push(res.file);
                renderCompositions();
            }
            closeModal('new-file-modal');
        } else {
            alert('Error: ' + res.message);
        }
    });

    function openDefinitionModal(def = null) {
        if (!state.activeDefLib && !def) {
            alert('Please select a definition library first.');
            return;
        }
        const formTitle = document.getElementById('definition-form-title');
        const idInput = document.getElementById('definition-id');
        const libInput = document.getElementById('definition-lib');
        const nameInput = document.getElementById('definition-name');
        const contentInput = document.getElementById('definition-content');
        const notesInput = document.getElementById('definition-notes');
        const deleteBtn = document.getElementById('btn-delete-definition');

        if (def) {
            formTitle.textContent = 'Edit Definition';
            idInput.value = def.id;
            libInput.value = def.lib; 
            nameInput.value = def.name;
            contentInput.value = def.content;
            notesInput.value = def.notes;
            deleteBtn.classList.remove('hidden');
        } else {
            formTitle.textContent = 'New Definition';
            idInput.value = '';
            libInput.value = state.activeDefLib;
            nameInput.value = '';
            contentInput.value = '';
            notesInput.value = '';
            deleteBtn.classList.add('hidden');
        }
        openModal('definition-modal');
    }

    document.getElementById('btn-new-definition').addEventListener('click', () => openDefinitionModal());
    
    document.getElementById('btn-save-definition').addEventListener('click', async () => {
        const payload = {
            lib: document.getElementById('definition-lib').value,
            id: document.getElementById('definition-id').value,
            name: document.getElementById('definition-name').value,
            content: document.getElementById('definition-content').value,
            notes: document.getElementById('definition-notes').value,
        };
		if (!payload.id.trim()) delete payload.id;   // let PHP create the id
        const res = await apiCall('save_definition', state.activeProject, payload);
        if (res.status === 'success') {
            await selectDefLib(state.activeDefLib);
            closeModal('definition-modal');
        } else {
            alert('Error: ' + res.message);
        }
    });

    document.getElementById('btn-delete-definition').addEventListener('click', async () => {
        if (!confirm('Are you sure?')) return;
        const payload = {
            lib: document.getElementById('definition-lib').value,
            id: document.getElementById('definition-id').value,
        };
        const res = await apiCall('delete_definition', state.activeProject, payload);
        if (res.status === 'success') {
            await selectDefLib(state.activeDefLib);
            closeModal('definition-modal');
        } else {
            alert('Error: ' + res.message);
        }
    });

    document.getElementById('btn-save-composition').addEventListener('click', saveComposition);
    
    // --- UTILITIES ---
    function escapeHtml(unsafe) {
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    function enableTabInTextarea(textarea) {
        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = this.value.substring(0, start) + "\t" + this.value.substring(end);
                this.selectionStart = this.selectionEnd = start + 1;
            }
        });
    }

    M.tabSizeInput.addEventListener('change', async (e) => {
        const size = e.target.value;
        const res = await apiCall('set_tab_size', null, { tab_size: size });
        if (res.status === 'success') {
            const newSize = res.tab_size;
            document.querySelectorAll('textarea').forEach(ta => {
                ta.style.tabSize = newSize;
                ta.style.MozTabSize = newSize;
            });
        }
    });

    // --- INITIAL LOAD ---
    init();

</script>
</body>
</html>
