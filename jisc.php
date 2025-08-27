<?php
// jisc.php - JSON Indexing-Sequencer Controller
// Version 1.4
// Implements the definitive fix for the newline/tab conversion bug by
// correctly un-escaping control characters in the Alphabet Generator.

// --- INITIALIZATION & CONFIGURATION ---
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('COMPOSER_DATA_DIR', __DIR__ . '/composer_data');

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

    // Basic validation
    if ($project && (strpos($project, '/') !== false || strpos($project, '..') !== false)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid project name.']);
        exit;
    }

    switch ($action) {
        // Shared helpers from composer
        case 'list_projects':
            echo json_encode(list_composer_projects());
            break;
        case 'list_definition_libs':
            echo json_encode(list_composer_definition_libs($project));
            break;
        // JISC specific actions
        case 'batch_generate_definitions':
            echo json_encode(batch_generate_definitions($project, $payload));
            break;
        case 'get_all_definitions':
            echo json_encode(['status' => 'success', 'definitions' => get_all_definitions($project)]);
            break;
        case 'get_definitions':
             echo json_encode(get_definitions($project, $payload['lib']));
             break;
        case 'execute_sequence':
            echo json_encode(execute_sequence($project, $payload));
            break;
    }
    exit;
}

// --- BACKEND FUNCTIONS ---

function sanitize_filename($filename, $is_path = false) {
    if ($is_path) {
        return preg_replace('/[^A-Za-z0-9_\-\.\/]/', '_', $filename);
    }
    $sanitized = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename);
    return preg_replace('/_+/', '_', $sanitized);
}

// --- Composer Data Interaction Functions ---
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

function get_definitions($project, $lib) {
    $file_path = COMPOSER_DATA_DIR . "/$project/definitions/" . sanitize_filename($lib);
    if (!file_exists($file_path)) return ['status' => 'error', 'message' => 'Definition library not found.'];
    
    $content = json_decode(file_get_contents($file_path), true);
    return ['status' => 'success', 'definitions' => is_array($content) ? $content : []];
}

function get_all_definitions($project) {
    $all_definitions = [];
    $libs_data = list_composer_definition_libs($project);
    if ($libs_data['status'] !== 'success') return [];

    foreach ($libs_data['files'] as $lib_file) {
        $lib_path = COMPOSER_DATA_DIR . "/$project/definitions/" . sanitize_filename($lib_file);
        $content = json_decode(file_get_contents($lib_path), true);
        if (is_array($content)) {
            foreach($content as $def) {
                $all_definitions[$def['name']] = $def;
            }
        }
    }
    return $all_definitions;
}


// --- JISC Core Functions ---

function batch_generate_definitions($project, $payload) {
    $lib_name = sanitize_filename($payload['lib_name']);
    $char_string = $payload['characters'];

    if (empty($lib_name)) return ['status' => 'error', 'message' => 'Library name cannot be empty.'];
    if (!str_ends_with($lib_name, '.json')) $lib_name .= '.json';

    $lib_path = COMPOSER_DATA_DIR . "/$project/definitions/$lib_name";
    if (file_exists($lib_path)) return ['status' => 'error', 'message' => "Library '$lib_name' already exists."];

    // FIX: Un-escape the input string to handle \n, \t, etc. correctly.
    $processed_string = stripcslashes($char_string);
    $definitions = [];
    $unique_chars = array_unique(str_split($processed_string));

    $symbol_map = [
        '`'=>'backtick', '~'=>'tilde', '!'=>'exclamation', '@'=>'at', '#'=>'hash', '$'=>'dollar',
        '%'=>'percent', '^'=>'caret', '&'=>'ampersand', '*'=>'asterisk', '('=>'paren_open', ')'=>'paren_close',
        '-'=>'minus', '_'=>'underscore', '='=>'equals', '+'=>'plus', '['=>'bracket_open', ']'=>'bracket_close',
        '{'=>'brace_open', '}'=>'brace_close', '\\'=>'backslash', '|'=>'pipe', ';'=>'semicolon', ':'=>'colon',
        '\''=>'single_quote', '"'=>'double_quote', ','=>'comma', '.'=>'period', '/'=>'slash', '<'=>'less_than',
        '>'=>'greater_than', '?'=>'question_mark'
    ];

    foreach ($unique_chars as $char) {
        $name = '';
        if ($char === "\n") $name = 'char_newline';
        else if ($char === "\r") $name = 'char_carriage_return';
        else if ($char === "\t") $name = 'char_tab';
        else if ($char === ' ') $name = 'char_space';
        else if (ctype_alnum($char)) {
            $name = (ctype_lower($char) ? 'char_' : 'char_upper_') . strtolower($char);
        } else if (isset($symbol_map[$char])) {
            $name = 'char_' . $symbol_map[$char];
        } else if (ctype_space($char)) {
            $name = 'char_whitespace_' . dechex(ord($char));
        } else {
            $name = 'char_unknown_' . bin2hex($char);
        }

        $final_name = $name;
        $counter = 2;
        while (isset($definitions[$final_name])) {
            $final_name = $name . '_' . $counter++;
        }
        
        $definitions[$final_name] = [
            'id' => 'def_' . uniqid(),
            'name' => $final_name,
            'content' => $char,
            'notes' => 'Auto-generated by JISC'
        ];
    }

    file_put_contents($lib_path, json_encode(array_values($definitions), JSON_PRETTY_PRINT));
    return ['status' => 'success', 'message' => 'Definition library created successfully.'];
}

function execute_sequence($project, $payload) {
    $comp_name = sanitize_filename($payload['comp_name']);
    $definition_ids = $payload['definitions'] ?? [];

    if (empty($comp_name)) return ['status' => 'error', 'message' => 'Composition name cannot be empty.'];
    
    $comp_json_name = $comp_name . '.composition.json';
    $comp_path = COMPOSER_DATA_DIR . "/$project/compositions/$comp_json_name";
    $composition_data = ['definitions' => $definition_ids];
    file_put_contents($comp_path, json_encode($composition_data, JSON_PRETTY_PRINT));

    $all_definitions_by_name = get_all_definitions($project);
    $all_definitions_by_id = [];
    foreach($all_definitions_by_name as $def) {
        $all_definitions_by_id[$def['id']] = $def;
    }

    $full_content = '';
    foreach ($definition_ids as $id) {
        if (isset($all_definitions_by_id[$id])) {
            $full_content .= $all_definitions_by_id[$id]['content'];
        }
    }
    $txt_path = COMPOSER_DATA_DIR . "/$project/compositions/$comp_name.txt";
    file_put_contents($txt_path, $full_content);

    return ['status' => 'success', 'message' => "Composition '$comp_name' created successfully."];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JISC - JSON Indexing-Sequencer Controller</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #111827; --panel-bg: #1f2937; --text-color: #d1d5db; --heading-color: #f9fafb;
            --border-color: #374151; --primary-color: #3b82f6; --primary-hover: #60a5fa;
            --font-sans: 'Inter', sans-serif; --font-mono: 'Roboto Mono', monospace;
            --grid-item-bg: #374151; --grid-item-lit-bg: #4f46e5;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: var(--font-sans); line-height: 1.6; margin: 0; background-color: var(--bg-color); color: var(--text-color); }
        .main-container { max-width: 1400px; margin: 2rem auto; padding: 2rem; }
        .tabs { display: flex; border-bottom: 2px solid var(--border-color); margin-bottom: 2rem; }
        .tab-link { padding: 1rem 1.5rem; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s ease; }
        .tab-link:hover { color: var(--primary-hover); }
        .tab-link.active { color: var(--primary-color); border-bottom-color: var(--primary-color); font-weight: 600; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .panel { background-color: var(--panel-bg); border-radius: 0.5rem; border: 1px solid var(--border-color); padding: 2rem; }
        h1, h2 { color: var(--heading-color); font-weight: 700; }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; font-weight: 500; margin-bottom: 0.5rem; font-size: 0.875rem; }
        input[type="text"], input[type="range"], textarea, select { width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 0.375rem; background-color: #374151; color: var(--text-color); transition: all 0.2s ease; }
        input[type="text"]:focus, textarea:focus, select:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
        textarea { resize: vertical; min-height: 120px; font-family: var(--font-mono); tab-size: <?= $TAB_SIZE ?>; -moz-tab-size: <?= $TAB_SIZE ?>; }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 0.375rem; cursor: pointer; font-size: 1rem; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s ease; }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: var(--primary-hover); }
        .btn:disabled { background-color: #4b5563; cursor: not-allowed; }
        .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.5rem; margin-top: 2rem; padding: 1rem; background-color: var(--bg-color); border-radius: 0.5rem; }
        .grid-item { background-color: var(--grid-item-bg); color: var(--text-color); height: 60px; display: flex; align-items: center; justify-content: center; border-radius: 0.25rem; font-family: var(--font-mono); font-size: 1.25rem; transition: all 0.1s ease-in-out; }
        .grid-item.lit { background-color: var(--grid-item-lit-bg); color: white; transform: scale(1.1); box-shadow: 0 0 20px var(--grid-item-lit-bg); }
        .speed-control { display: flex; align-items: center; gap: 1rem; }
        .speed-control span { font-size: 0.875rem; }
        .two-col-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
    </style>
</head>
<body>

<div class="main-container">
    <h1>JISC - JSON Indexing-Sequencer Controller</h1>
    <nav class="tabs">
        <a class="tab-link active" data-tab="generator">Alphabet Generator</a>
        <a class="tab-link" data-tab="sequencer">Indexing Sequencer</a>
        <a class="tab-link" data-tab="converter">Text-to-Sequence Converter</a>
    </nav>

    <!-- Page 1: Alphabet Generator -->
    <div id="tab-generator" class="tab-content active">
        <div class="panel">
            <h2>Batch Definition Generator</h2>
            <div class="form-group">
                <label for="gen-project-select">Target Composer Project</label>
                <select id="gen-project-select"></select>
            </div>
            <div class="form-group">
                <label for="gen-lib-name">New Definition Library Name</label>
                <input type="text" id="gen-lib-name" placeholder="e.g., my_alphabet">
            </div>
            <div class="form-group">
                <label for="gen-characters">Characters to Define (use \n for newline, \t for tab)</label>
                <textarea id="gen-characters" rows="5" placeholder="Enter all characters here, e.g., abcdefg..."></textarea>
            </div>
            <button id="btn-generate-lib" class="btn btn-primary">Generate Library</button>
        </div>
    </div>

    <!-- Page 2: Indexing Sequencer -->
    <div id="tab-sequencer" class="tab-content">
        <div class="panel">
            <h2>Batch Composition Sequencer</h2>
            <div class="form-group">
                <label for="seq-project-select">Target Composer Project</label>
                <select id="seq-project-select"></select>
            </div>
             <div class="form-group">
                <label for="seq-lib-select">Definition Library for Visualization Grid</label>
                <select id="seq-lib-select"></select>
            </div>
            <div class="form-group">
                <label for="seq-comp-name">New Composition Name</label>
                <input type="text" id="seq-comp-name" placeholder="e.g., hello_world_sequence">
            </div>
            <div class="form-group">
                <label for="seq-sequence-input">Sequence (comma-separated definition names)</label>
                <textarea id="seq-sequence-input" rows="5" placeholder="e.g., char_h,char_e,char_l,char_l,char_o"></textarea>
            </div>
            <div class="form-group speed-control">
                <label for="seq-speed">Execution Speed:</label>
                <input type="range" id="seq-speed" min="50" max="2000" value="300">
                <span id="speed-label">300 ms</span>
            </div>
            <button id="btn-execute-sequence" class="btn btn-primary">Execute Sequence</button>
        </div>
        <div id="visualization-grid" class="grid-container"></div>
    </div>

    <!-- Page 3: Text-to-Sequence Converter -->
    <div id="tab-converter" class="tab-content">
        <div class="panel">
            <h2>Text-to-Sequence Converter</h2>
            <div class="form-group">
                <label for="conv-project-select">Target Composer Project</label>
                <select id="conv-project-select"></select>
            </div>
             <div class="form-group">
                <label for="conv-lib-select">Reference Definition Library (Alphabet)</label>
                <select id="conv-lib-select"></select>
            </div>
            <div class="two-col-grid">
                <div class="form-group">
                    <label for="conv-input-text">Input Text/Code</label>
                    <textarea id="conv-input-text" rows="10"></textarea>
                </div>
                <div class="form-group">
                    <label for="conv-output-sequence">Output Sequence</label>
                    <textarea id="conv-output-sequence" rows="10" readonly></textarea>
                </div>
            </div>
             <div class="form-group speed-control">
                <label for="conv-tab-size">Input Tab Size:</label>
                <input type="range" id="conv-tab-size" min="1" max="16" value="<?= $TAB_SIZE ?>">
                <span id="conv-tab-label"><?= $TAB_SIZE ?> spaces</span>
            </div>
            <button id="btn-convert" class="btn btn-primary">Convert to Sequence</button>
        </div>
    </div>
</div>

<script>
    // --- STATE & DOM ---
    const state = {
        projects: [],
        activeProject: null,
        definitionLibs: [],
        allDefinitions: {}, // By name
        converterDefMap: new Map(),
    };
    const D = { // DOM Elements
        tabs: document.querySelectorAll('.tab-link'),
        tabContents: document.querySelectorAll('.tab-content'),
        // Generator
        genProjectSelect: document.getElementById('gen-project-select'),
        genLibName: document.getElementById('gen-lib-name'),
        genCharacters: document.getElementById('gen-characters'),
        btnGenerateLib: document.getElementById('btn-generate-lib'),
        // Sequencer
        seqProjectSelect: document.getElementById('seq-project-select'),
        seqLibSelect: document.getElementById('seq-lib-select'),
        seqCompName: document.getElementById('seq-comp-name'),
        seqSequenceInput: document.getElementById('seq-sequence-input'),
        seqSpeed: document.getElementById('seq-speed'),
        speedLabel: document.getElementById('speed-label'),
        btnExecuteSequence: document.getElementById('btn-execute-sequence'),
        visGrid: document.getElementById('visualization-grid'),
        // Converter
        convProjectSelect: document.getElementById('conv-project-select'),
        convLibSelect: document.getElementById('conv-lib-select'),
        convInputText: document.getElementById('conv-input-text'),
        convOutputSequence: document.getElementById('conv-output-sequence'),
        convTabSize: document.getElementById('conv-tab-size'),
        convTabLabel: document.getElementById('conv-tab-label'),
        btnConvert: document.getElementById('btn-convert'),
    };

    // --- API HELPER ---
    async function apiCall(action, project = null, payload = {}) {
        const formData = new FormData();
        formData.append('action', action);
        if (project) formData.append('project', project);
        for (const key in payload) {
            if (Array.isArray(payload[key])) {
                payload[key].forEach(item => formData.append(`payload[${key}][]`, item));
            } else {
                 formData.append(`payload[${key}]`, payload[key]);
            }
        }
        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const contentType = response.headers.get("content-type");
            if (response.ok && contentType && contentType.includes("application/json")) {
                return await response.json();
            }
            const text = await response.text();
            throw new Error(`Server returned non-JSON response: ${text}`);
        } catch (error) {
            console.error('API Call Failed:', error);
            alert('An error occurred. Check the console for details.');
            return { status: 'error', message: error.message };
        }
    }

    // --- UI LOGIC ---
    function setupTabs() {
        D.tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.dataset.tab;
                D.tabs.forEach(t => t.classList.remove('active'));
                D.tabContents.forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(`tab-${tabName}`).classList.add('active');
            });
        });
    }

    async function populateProjectDropdowns() {
        const res = await apiCall('list_projects');
        if (res.status === 'success') {
            state.projects = res.projects;
            const options = state.projects.map(p => `<option value="${p}">${p}</option>`).join('');
            D.genProjectSelect.innerHTML = options;
            D.seqProjectSelect.innerHTML = options;
            D.convProjectSelect.innerHTML = options;
            if (state.projects.length > 0) {
                await handleProjectChange(state.projects[0]);
            }
        }
    }
    
    async function handleProjectChange(projectName) {
        state.activeProject = projectName;
        D.seqProjectSelect.value = projectName;
        D.genProjectSelect.value = projectName;
        D.convProjectSelect.value = projectName;
        
        const libsRes = await apiCall('list_definition_libs', state.activeProject);
        if (libsRes.status === 'success') {
            state.definitionLibs = libsRes.files;
            const libOptions = state.definitionLibs.map(lib => `<option value="${lib}">${lib}</option>`).join('');
            D.seqLibSelect.innerHTML = libOptions;
            D.convLibSelect.innerHTML = libOptions;
            
            const allDefsRes = await apiCall('get_all_definitions', state.activeProject);
            if (allDefsRes.status === 'success') {
                state.allDefinitions = allDefsRes.definitions;
            }

            if (state.definitionLibs.length > 0) {
                await handleLibraryChange(state.definitionLibs[0], 'sequencer');
                await handleLibraryChange(state.definitionLibs[0], 'converter');
            } else {
                renderVisualizationGrid([]);
            }
        }
    }

    async function handleLibraryChange(libName, context) {
        const res = await apiCall('get_definitions', state.activeProject, { lib: libName });
        if (res.status === 'success') {
            if (context === 'sequencer') {
                renderVisualizationGrid(res.definitions);
            } else if (context === 'converter') {
                state.converterDefMap.clear();
                res.definitions.forEach(def => {
                    state.converterDefMap.set(def.content, def.name);
                });
            }
        }
    }

    function renderVisualizationGrid(definitions) {
        D.visGrid.innerHTML = '';
        definitions.forEach(def => {
            const item = document.createElement('div');
            item.className = 'grid-item';
            item.textContent = def.content.trim() === '' ? 'Space' : def.content;
            if (def.content === "\n") item.textContent = '↵';
            if (def.content === "\t") item.textContent = '⇥';
            item.dataset.name = def.name;
            D.visGrid.appendChild(item);
        });
    }

    // --- GENERATOR LOGIC ---
    D.btnGenerateLib.addEventListener('click', async () => {
        const project = D.genProjectSelect.value;
        const lib_name = D.genLibName.value;
        const characters = D.genCharacters.value;
        if (!project || !lib_name || !characters) {
            alert('Please fill out all fields.');
            return;
        }
        const res = await apiCall('batch_generate_definitions', project, { lib_name, characters });
        if (res.status === 'success') {
            alert(res.message);
            D.genLibName.value = '';
            D.genCharacters.value = '';
            await handleProjectChange(project);
        } else {
            alert('Error: ' + res.message);
        }
    });

    // --- SEQUENCER LOGIC ---
    D.seqProjectSelect.addEventListener('change', (e) => handleProjectChange(e.target.value));
    D.seqLibSelect.addEventListener('change', (e) => handleLibraryChange(e.target.value, 'sequencer'));
    D.seqSpeed.addEventListener('input', (e) => {
        D.speedLabel.textContent = `${e.target.value} ms`;
    });

    D.btnExecuteSequence.addEventListener('click', async () => {
        const project = D.seqProjectSelect.value;
        const compName = D.seqCompName.value;
        const sequence = D.seqSequenceInput.value.split(',').map(s => s.trim()).filter(s => s);
        
        if (!project || !compName || sequence.length === 0) {
            alert('Please fill out all sequencer fields.');
            return;
        }

        const allDefinitions = state.allDefinitions;
        const compositionDefIds = [];
        const speed = parseInt(D.seqSpeed.value, 10);
        D.btnExecuteSequence.disabled = true;

        for (const defName of sequence) {
            const gridItem = D.visGrid.querySelector(`[data-name="${defName}"]`);
            const def = allDefinitions[defName];

            if (def) {
                compositionDefIds.push(def.id);
                if (gridItem) {
                    gridItem.classList.add('lit');
                    await new Promise(resolve => setTimeout(resolve, speed));
                    gridItem.classList.remove('lit');
                }
            } else {
                console.warn(`Definition "${defName}" not found in any library.`);
            }
        }

        const res = await apiCall('execute_sequence', project, { comp_name: compName, definitions: compositionDefIds });
        if (res.status === 'success') {
            alert(res.message);
        } else {
            alert('Error: ' + res.message);
        }
        D.btnExecuteSequence.disabled = false;
    });

    // --- CONVERTER LOGIC ---
    D.convProjectSelect.addEventListener('change', (e) => handleProjectChange(e.target.value));
    D.convLibSelect.addEventListener('change', (e) => handleLibraryChange(e.target.value, 'converter'));
    D.convTabSize.addEventListener('input', (e) => {
        const size = e.target.value;
        D.convTabLabel.textContent = `${size} spaces`;
        D.convInputText.style.tabSize = size;
        D.convInputText.style.MozTabSize = size;
    });
    D.btnConvert.addEventListener('click', () => {
        const inputText = D.convInputText.value.replace(/\r\n?/g, "\n");
        const sequence = [];
        for (const char of inputText) {
            if (state.converterDefMap.has(char)) {
                sequence.push(state.converterDefMap.get(char));
            } else {
                console.warn(`Character "${char}" (code: ${char.charCodeAt(0)}) not found in selected library.`);
            }
        }
        D.convOutputSequence.value = sequence.join(',');
    });
    
    // --- INITIALIZATION ---
    async function init() {
        setupTabs();
        await populateProjectDropdowns();
    }
    init();

</script>
</body>
</html>
