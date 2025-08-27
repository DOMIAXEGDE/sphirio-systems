<?php
/**
 * Genesis Operating System (GOS)
 * Main entry point, API dispatcher, and HTML shell.
 */

require_once 'bootstrap.php';

/* ==============================================================
 * API Dispatcher
 * ============================================================== */
if (isset($_GET['api'])) {
    jsonHeader();

    $isAuthenticated = isAuthenticated();
    $api = $_GET['api'];
    $response = null;

    // Public endpoints that do not require authentication
    $publicEndpoints = [
        'auth' => ['login', 'register', 'validateToken'],
        'system' => ['getSystemInfo', 'getLanguagePack']
    ];

    $method = $_POST['method'] ?? $_GET['method'] ?? null;

    // Check if the requested endpoint is public
    $isPublicCall = isset($publicEndpoints[$api]) && in_array($method, $publicEndpoints[$api]);

    if (!$isAuthenticated && !$isPublicCall) {
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }

    // Route to the appropriate handler
    try {
        switch ($api) {
            case 'auth':
                $response = handleAuthAPI();
                break;
            case 'filesystem':
                $response = handleFileSystemAPI();
                break;
            case 'users':
                $response = handleUsersAPI();
                break;
            case 'system':
                $response = handleSystemAPI();
                break;
            case 'apps':
                handleAppsAPI(); // This function handles its own exit
                break;
            case 'sandbox':
                $response = handleSandboxAPI();
                break;
            default:
                http_response_code(404);
                $response = ['success' => false, 'message' => 'Unknown API endpoint'];
                break;
        }
    } catch (Throwable $e) {
        error_log("API Error in {$api}: " . $e->getMessage());
        http_response_code(500);
        $response = ['success' => false, 'message' => 'An internal server error occurred.'];
    }

    // The response from handlers is now directly echoed
    if ($response) {
        echo json_encode($response);
    }
    exit;
}

// Initialize system for the main page load
initializeSystem();

// Properly close the output buffer at the end
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genesis OS</title>
    
    <!-- ✅ FIXED: Replaced inline styles with a linked stylesheet -->
    <link rel="stylesheet" href="genesis-os.css">

</head>
<body>
    <!-- CRT effects (toggled by settings) -->
    <div class="crt-overlay" id="crt-overlay"></div>
    <div class="scanline" id="scanline"></div>

    <!-- Splash Screen -->
    <div id="splash-screen" class="splash-screen">
        <div id="splash-logo">G</div>
        <div class="loading-indicator">
            <div class="loading-bar">
                <div id="loading-progress" class="loading-progress"></div>
            </div>
            <div id="loading-status">Initializing...</div>
        </div>
    </div>
    
	<!-- Login Screen -->
	<div id="login-screen" class="login-screen">
		<div class="login-container">
			<h2 class="login-heading">Genesis OS Login</h2>
			<form id="login-form" autocomplete="on">
				<div class="login-input">
					<input type="text" id="username" name="username" placeholder="Username" required>
				</div>
				<div class="login-input">
					<input type="password" id="password" name="password" placeholder="Password" required>
				</div>
				<button type="submit" class="login-button">Log In</button>
				<div id="login-error" class="login-error"></div>
                <div class="login-extra-links">
                    <a id="register-link" style="display: none;">Register as Developer</a>
                </div>
			</form>
		</div>
	</div>
    
    <!-- Desktop Environment -->
    <div id="desktop" class="desktop">
        <div id="workspace" class="workspace">
            <!-- Desktop icons will be created here -->
            <div id="desktop-icons" class="desktop-icons"></div>
            
            <!-- Windows will be created here -->
        </div>
        <div id="taskbar" class="taskbar">
            <button id="start-button" class="start-button">GOS</button>
            <div id="taskbar-items" class="taskbar-items">
                <!-- Taskbar items will be created here -->
            </div>
            <div id="system-tray" class="system-tray">
                <div id="clock" class="clock"></div>
            </div>
        </div>
    </div>
    
    <!-- Start Menu -->
    <div id="start-menu" class="start-menu">
        <!-- Start menu content will be generated -->
    </div>
    
    <!-- Context Menu -->
    <div id="desktop-context-menu" class="context-menu" style="display: none;">
        <div class="context-menu-item" id="ctx-new-folder">New Folder</div>
        <div class="context-menu-item" id="ctx-new-file">New File</div>
        <div class="context-menu-separator"></div>
        <div class="context-menu-item" id="ctx-refresh">Refresh</div>
        <div class="context-menu-item" id="ctx-settings">Settings</div>
    </div>
    
    <!-- Notification Container -->
    <div id="notification-container" class="notification-container">
        <!-- Notifications will be created here -->
    </div>

    <!-- Error Banner -->
    <div id="error-banner" class="error-banner"></div>

    <!-- Debug Console -->
    <pre id="debug-console" class="debug-console"></pre>
    
    <!-- Error Screen -->
    <div id="error-screen" class="error-screen">
        <div class="error-container">
            <h2 id="error-title">System Error</h2>
            <p id="error-message">An error occurred.</p>
            <button id="restart-button" class="button" onclick="window.location.reload()">Restart</button>
        </div>
    </div>
	
<script>

	/**
	 * Genesis OS Kernel
	 * Core system kernel for the Genesis Operating System
	 */
	class Kernel {
		/**
		 * Create a new kernel instance
		 */
		constructor() {
			// System metadata
							this.version = '1.0.0';
							this.buildDate = '2025-07-04';
							this.cacheBust = this.buildDate;
			this.codename = 'Genesis';
			
			// System state
			this.state = {
				status: 'uninitialized', // uninitialized -> initializing -> running -> shuttingDown -> shutdown -> error
				startTime: null,
				lastError: null,
				debug: true,
				useLocalFilesystem: false // Will be determined during initialization
			};
			
			// Core modules
			this.modules = {
				events: null,     // Event management system
				security: null,   // Security and permissions
				filesystem: null, // Virtual file system
				process: null,    // Process management
				ui: null          // User interface management
			};
			
			// Runtime data
			this.applications = new Map();
			this.services = new Map();
			this.api = {}; // <-- ADD THIS LINE
			this.config = null;           // System configuration

			// Translation data
			this.translations = {};
			
			// Current user context
			this.currentUser = null;
			
			// Kernel logger
			this.log = this._createLogger('kernel');
			
			this.log.info(`Genesis OS Kernel v${this.version} created`);
		}
		
		/**
		 * Initialise the kernel and all core systems
		 * (fully backwards‑compatible replacement for the old version)
		 * @async
		 * @returns {Promise<boolean>}  true on success, false on fatal error
		 */
		async initialize() {
			try {
				/* -------------------------------------------------------- *
				 * 0. Boot‑strap
				 * -------------------------------------------------------- */
				this._updateState('initializing');
				this.state.startTime = Date.now();
				const STEP = this._makeStepper([
					10, 'Loading system configuration…',
					20, 'Initialising core modules…',
					30, 'Initialising security…',
					40, 'Initialising filesystem…',
					50, 'Initialising process manager…',
					60, 'Connecting essential services…',
					70, 'Initialising user‑interface…',
					80, 'Loading system state…',
					90, 'Finalising…',
					100,'System ready!'
				]);

				this.log.info('Kernel initialisation started');

				/* -------------------------------------------------------- *
				 * 1. Load configuration
				 * -------------------------------------------------------- */
				STEP.next(); // 10 %
				await this._loadConfiguration();

				/* --- backward compatibility flags ---------------------- */
				if (this.config && typeof this.config.debug_mode === 'boolean') {
					this.state.debug = this.config.debug_mode;
					this.log.info(`Debug mode: ${this.state.debug ? 'enabled' : 'disabled'}`);
				}
				if (this.config && typeof this.config.use_local_filesystem === 'boolean') {
					this.state.useLocalFilesystem = this.config.use_local_filesystem;
					this.log.info(`Using ${this.state.useLocalFilesystem ? 'server' : 'local‑storage'} filesystem`);
				}

				/* -------------------------------------------------------- *
				 * 2. Core modules (events, security, filesystem, process)
				 * -------------------------------------------------------- */
				STEP.next(); // 20 %
				this.modules.events = new EventSystem(this);
				this._registerSystemEvents();

				STEP.next(); // 30 %
				this.modules.security = new SecurityManager(this);

				STEP.next(); // 40 %
				this.modules.filesystem = new FileSystem(this);

				STEP.next(); // 50 %
				this.modules.process = new ProcessManager(this);

				/* -------------------------------------------------------- *
				 * 3. Essential services (including "apps")
				 * -------------------------------------------------------- */
				STEP.next(); // 60 %
				await this._connectEssentialServices();

				// Load language pack
				await this._loadLanguagePack();

				// ---------- NEW (A): ensure the Apps service exists ------
				if (!this.services.has('apps')) {
					this.log.warn('Apps service not found ‑ creating stub (read‑only)');
					this.services.register('apps', {
						async call(method, params) {
							if (method === 'getAppInfo') { return null; }
							if (method === 'listApps')   { return [];   }
							throw new Error(`Apps service stub: unknown method ${method}`);
						}
					});
				}
				// ---------- ensure the service is ready ------------------
				if (typeof this.services.get('apps').ready === 'function') {
					await this._timeout(5_000, this.services.get('apps').ready());
				}
				/* -------------------------------------------------------- *
				 * 4. UI
				 * -------------------------------------------------------- */
				STEP.next(); // 70 %
				this.modules.ui = new UIManager(this);
				await this.modules.ui.initialize();

				/* -------------------------------------------------------- *
				 * 5. System state (login session, etc.)
				 * -------------------------------------------------------- */
				STEP.next(); // 80 %
				const isAuthenticated = await this._loadSystemState();

				/* -------------------------------------------------------- *
				 * 6. Desktop or login
				 * -------------------------------------------------------- */
				STEP.next(); // 90 %
				if (isAuthenticated) {
					this.log.info('User is authenticated -- showing desktop');
					this._showDesktop();
				} else {
					this.log.info('User is not authenticated -- showing login screen');
					this._showLoginScreen();
				}

				/* -------------------------------------------------------- *
				 * 7. Finalise
				 * -------------------------------------------------------- */
				if (!this.state.useLocalFilesystem) {
					try {
						await this.modules.filesystem.initializeLocalStorage();
					} catch (e) {
						// ---------- NEW (C): degrade gracefully -----------
						this.log.warn('LocalStorage unavailable, falling back to in‑memory FS');
						await this.modules.filesystem.initializeInMemory();
					}
				}

				this._updateState('running');
				STEP.next(); // 100 %

				const initTime = ((Date.now() - this.state.startTime) / 1000).toFixed(2);
				this.log.info(`Kernel initialisation completed in ${initTime}s`);

				this.modules.events.emit('system:ready', {
					version: this.version,
					initializationTime: initTime
				});
				return true;

			} catch (error) {
				/* -------------------------------------------------------- *
				 * Fatal error
				 * -------------------------------------------------------- */
				this.state.lastError = error;
				this._updateState('error');

				this.log.error(`Kernel initialisation failed: ${error.message}`, error);
				this._showErrorScreen('System Initialisation Failed', error.message, error.stack);
				return false;
			}
		}

		/* -----------------------------------------------------------------
		 * Small helpers -- keep them private to the kernel
		 * ----------------------------------------------------------------- */

		/** iterable progress helper (D) */
		_makeStepper(pairs) {
			let idx = 0;
			return {
				next: () => {
					const pct  = pairs[idx++];
					const text = pairs[idx++];
					this._updateLoadingProgress(pct, text);
				}
			};
		}

		/**
		 * Promise with timeout (E)
		 * @param {number} ms   milliseconds
		 * @param {Promise} p   promise to race
		 */
		_timeout(ms, p) {
			return Promise.race([
				p,
				new Promise((_, reject) =>
					setTimeout(() => reject(new Error('timeout')), ms)
				)
			]);
		}

		
		/**
		 * Load system configuration
		 * @private
		 * @async
		 */
		async _loadConfiguration() {
			try {
				const response = await fetch(`?api=system&method=getSystemInfo`);
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`);
				}
				
				const result = await response.json();
				if (!result.success) {
					throw new Error(result.message || 'Failed to fetch system info.');
				}
				
				this.config = result.data;
				this.log.info('System configuration loaded successfully.');
				
				// ✅ HARDENED & CORRECT: Use optional chaining to safely access nested properties.
				const allowRegistration = this.config?.security?.allow_developer_registration;

				if (allowRegistration) {
					const registerLink = document.getElementById('register-link');
					if (registerLink) {
						registerLink.style.display = 'inline';
						registerLink.onclick = () => this.modules.ui.showRegistrationDialog();
					}
				}
				
				return true;
			} catch (error) {
				this.log.error('Failed to load system configuration', error);
				throw new Error(`Configuration loading failed: ${error.message}`);
			}
		}
		
		/**
		 * Launch an application
		 * @async
		 * @param {string} appId - Application identifier
		 * @param {Object} params - Launch parameters
		 * @returns {Promise<string>} Process ID of the launched application
		 */
		async launchApplication(appId, params = {}) {
			try {
				this.log.info(`Launching application: ${appId}`);

				/* -----------------------------------------------------------
				 * 1.  Permission check
				 * --------------------------------------------------------- */
				if (!this.modules.security.hasPermission(`app.launch.${appId}`)) {
					this.modules.ui.showNotification(
						'Permission Denied',
						`You don't have permission to launch ${appId}`,
						5000
					);
					throw new Error(`Permission denied: Cannot launch ${appId}`);
				}

				/* -----------------------------------------------------------
				 * 2.  Fetch manifest      (note the { id : appId } param)
				 * --------------------------------------------------------- */
									const appInfo = await this.services
											.get('apps')
											.call('getAppInfo', { id: appId });

									if (!appInfo) {
											throw new Error(`Application not found: ${appId}`);
									}

									if (Array.isArray(appInfo.permissions)) {
											for (const perm of appInfo.permissions) {
													if (!this.modules.security.hasPermission(perm)) {
															this.modules.ui.showNotification(
																	'Permission Denied',
																	`Missing permission ${perm} for ${appId}`,
																	5000
															);
															throw new Error(`Permission denied: ${perm}`);
													}
											}
									}

				/* -----------------------------------------------------------
				 * 3.  Create a process / window
				 * --------------------------------------------------------- */
				const process = await this.modules.process.createProcess(
					appId,
					appInfo,
					params
				);

				/* -----------------------------------------------------------
				 * 4.  Resolve the entry point and execute it
				 * --------------------------------------------------------- */
				await this._bootApplicationCode(appInfo, process);

				/* -----------------------------------------------------------
				 * 5.  Book‑keeping & events
				 * --------------------------------------------------------- */
				this.applications.set(process.id, process);

				this.modules.events.emit('application:launched', {
					appId,
					processId: process.id,
					windowId: process.windowId
				});

				return process.id;
			} catch (error) {
				this.log.error(`Failed to launch application ${appId}:`, error);

				this.modules.events.emit('application:launchFailed', {
					appId,
					error: error.message
				});

				this.modules.ui.showNotification(
					'Application Error',
					`Failed to launch ${appId}: ${error.message}`,
					5000
				);

				throw error;
			}
		}
		
		/**
		 * Connect to essential backend services
		 * @private
		 * @async
		 */
		async _connectEssentialServices() {
			try {
				// Connect to essential services
				const essentialServices = ['auth', 'users', 'apps', 'filesystem', 'system', 'sandbox'];

				for (const serviceName of essentialServices) {
					this.log.info(`Connecting to ${serviceName} service...`);
					const service = this._createServiceProxy(serviceName);

					// Store the service in both locations
					this.services.set(serviceName, service);
					this.api[serviceName] = service; // <-- ADD THIS LINE
				}

				this.log.info('All essential services connected');
				return true;
			} catch (error) {
				this.log.error('Failed to connect to essential services', error);
				throw new Error(`Service connection failed: ${error.message}`);
			}
		}
		
		/**
		 * Create a service proxy for API calls
		 * @private
		 * @param {string} serviceName - Name of the service
		 * @returns {Object} Service proxy object
		 */
		_createServiceProxy(serviceName) {
			const kernel = this;
			
			return {
				name: serviceName,
				status: 'connected',
				
				/**
				 * Call a method on the service
				 * @async
				 * @param {string} method - Method name
				 * @param {Object} params - Method parameters
				 * @returns {Promise<any>} Response data
				 */
				async call(method, params = {}) {
					try {
						const requestId = kernel._generateRequestId();
						
						// Create FormData instead of JSON
						const formData = new FormData();
						formData.append('method', method);
						formData.append('params', JSON.stringify(params));
						
						// Send request to backend with FormData

													const response = await fetch(`genesis-os.php?api=${this.name}&v=${kernel.cacheBust}&_=${Date.now()}`, {

							method: 'POST',
							headers: {
								'X-GOS-Request-ID': requestId
							},
							body: formData,
							credentials: 'same-origin'
						});
						
						// Rest of the function remains the same...
						
						// Check for HTTP errors
						if (!response.ok) {
							throw new Error(`Service returned status ${response.status} ${response.statusText}`);
						}
						
						// Parse response
						const result = await response.json();
						
						// Check for API errors
						if (result.success === false) {
							throw new Error(result.message || 'Unknown service error');
						}
						
						return result.data;
					} catch (error) {
						kernel.log.error(`Service ${this.name}.${method} call failed:`, error);
						throw error;
					}
				}
			};
		}
		
		/**
		 * Load saved system state and check authentication
		 * @private
		 * @async
		 * @returns {Promise<boolean>} True if user is authenticated
		 */
		async _loadSystemState() {
			try {
				// Check if user is authenticated
				if (this.services.has('auth')) {
					this.log.info('Checking authentication state...');
					
					const authService = this.services.get('auth');
					const validationResult = await authService.call('validateToken', {});
					
					if (validationResult.valid && validationResult.user) {
						this.log.info(`Valid session found for user: ${validationResult.user.username}`);
						
						// Set current user
						this.currentUser = validationResult.user;
						
						// Update security context with user permissions
						this.modules.security.handleLogin({
							user: validationResult.user,
							permissions: validationResult.permissions || []
						});
						
						// Emit user login event
						this.modules.events.emit('auth:restored', {
							user: validationResult.user
						});
						
						return true;
					} else {
						this.log.info('No valid authentication session found');
					}
				}
				
				return false;
			} catch (error) {
				this.log.error('Failed to load system state', error);
				// Don't throw here, as we can continue without a restored session
				return false;
			}
		}
		
		/**
		 * Register handlers for critical system events
		 * @private
		 */
		_registerSystemEvents() {
			// Handle authentication events
			this.modules.events.on('auth:login', (data) => {
				this.log.info(`User logged in: ${data.user.username}`);
				this.currentUser = data.user;
				
				// Update security context
				this.modules.security.handleLogin(data);
				
				// Show desktop
				this._showDesktop();
			});
			
			this.modules.events.on('auth:logout', () => {
				this.log.info('User logged out');
				this.currentUser = null;
				
				// Update security context
				this.modules.security.handleLogout();
				
				// Show login screen
				this._showLoginScreen();
			});
			
			// Handle system shutdown event
			this.modules.events.on('system:shutdown', (data) => {
				this.log.info(`System shutdown initiated: ${data.reason}`);
				this.shutdown(data.reason);
			});
			
			// Handle application lifecycle events
			this.modules.events.on('application:launched', (data) => {
				this.log.info(`Application launched: ${data.appId} (Process ID: ${data.processId})`);
			});
			
			this.modules.events.on('application:terminated', (data) => {
				this.log.info(`Application terminated: ${data.appId} (Process ID: ${data.processId})`);
				
				// Remove from running applications
				this.applications.delete(data.processId);
			});
		}
		
		/**
		 * Update the loading progress during initialization
		 * @private
		 * @param {number} progress - Progress percentage (0-100)
		 * @param {string} status - Status message
		 */
		_updateLoadingProgress(progress, status) {
			const progressBar = document.getElementById('loading-progress');
			const statusText = document.getElementById('loading-status');
			
			if (progressBar) {
				progressBar.style.width = `${progress}%`;
			}
			
			if (statusText && status) {
				statusText.textContent = status;
			}
		}
		
		_showLoginScreen() {
			const splash = document.getElementById('splash-screen');
			const login = document.getElementById('login-screen');
			splash.classList.add('fade-out');
			
			setTimeout(() => {
				splash.style.display = 'none';
				login.style.display = 'flex';
				setTimeout(() => login.classList.add('show'), 50);
			}, 500);

			// ✅ FIXED: This logic now correctly runs every time the login screen is shown.
			const allowRegistration = this.config?.security?.allow_developer_registration;
			const registerLink = document.getElementById('register-link');

			if (allowRegistration && registerLink) {
				// Only show the link if no user is currently logged in.
				// This handles the case where a user logs out and returns to this screen.
				if (!this.currentUser) {
					registerLink.style.display = 'inline';
					registerLink.onclick = () => this.modules.ui.showRegistrationDialog();
				} else {
					registerLink.style.display = 'none';
				}
			} else if (registerLink) {
				registerLink.style.display = 'none';
			}

			const form = document.getElementById('login-form');
			form.onsubmit = async (e) => {
				e.preventDefault();
				const errorDiv = document.getElementById('login-error');
				errorDiv.textContent = '';
				try {
					const result = await this.api.auth.call('login', { username: form.username.value, password: form.password.value });
					this.modules.events.emit('auth:login', { user: result.user, permissions: result.permissions });
					login.classList.remove('show');
					setTimeout(() => login.style.display = 'none', 500);
				} catch (error) {
					errorDiv.textContent = error.message;
				}
			};
		}
		
		/**
		 * Show the desktop environment
		 * @private
		 */
		_showDesktop() {
			// Hide splash screen
			const splashScreen = document.getElementById('splash-screen');
			splashScreen.classList.add('fade-out');
			
			// Hide login screen if visible
			const loginScreen = document.getElementById('login-screen');
			loginScreen.classList.remove('show');
			
			setTimeout(() => {
				splashScreen.style.display = 'none';
				loginScreen.style.display = 'none';
				
				// Show desktop
				const desktop = document.getElementById('desktop');
				desktop.style.display = 'block';
				
				setTimeout(() => {
					desktop.classList.add('show');
				}, 50);
				
				// Create desktop icons
				this._createDesktopIcons();
				
				// Update the clock
				this._updateClock();
				setInterval(() => this._updateClock(), 60000);
				
				// Set up start button
				document.getElementById('start-button').addEventListener('click', () => {
					this.toggleStartMenu();
				});
				
				// Set up desktop context menu
				const desktopElement = document.getElementById('desktop');
				desktopElement.addEventListener('contextmenu', (e) => {
					// Only show context menu on desktop background or icons container
					if (e.target === desktopElement || e.target.id === 'desktop-icons') {
						e.preventDefault();
						this._showContextMenu(e.pageX, e.pageY);
					}
				});
				
				// Close context menu when clicking elsewhere
				document.addEventListener('click', () => {
					document.getElementById('desktop-context-menu').style.display = 'none';
				});
				
				// Set up context menu items
				document.getElementById('ctx-new-folder').addEventListener('click', () => {
					this._createNewFolder();
				});
				
				document.getElementById('ctx-new-file').addEventListener('click', () => {
					this._createNewFile();
				});
				
				document.getElementById('ctx-refresh').addEventListener('click', () => {
					this._refreshDesktop();
				});
				
				document.getElementById('ctx-settings').addEventListener('click', () => {
					this.launchApplication('settings');
				});
				
				// Show welcome notification
				this.modules.ui.showNotification(
					'Welcome to Genesis OS',
					`Hello, ${this.currentUser.name}! Welcome to Genesis OS v${this.version}.`,
					5000
				);
			}, 500);
		}
		
		_createDesktopIcons() {
			const desktopIcons = document.getElementById('desktop-icons');
			desktopIcons.innerHTML = '';
			
			// Log the start of the process for debugging
			this.log.debug('Creating desktop icons');
			
			// Request the full app list from the service. This is the single source of truth.
			this.services.get('apps').call('listApps').then(allApps => {
				
				// Filter for built-in apps only. External/filesystem apps have a `_path` property, 
                // while built-in apps do not. This prevents external apps from cluttering the desktop.
                const builtInApps = allApps.filter(app => !app._path);

				this.log.debug(`Found ${builtInApps.length} built-in apps for desktop icons.`);
				
				// Create an icon for each built-in app that is marked for desktop display.
				builtInApps.forEach(app => {
					if (app.desktopIcon !== false) {
						const iconElement = document.createElement('div');
						iconElement.className = 'desktop-icon';
						iconElement.dataset.appId = app.id;
						
						const iconImage = document.createElement('div');
						iconImage.className = 'icon-image';
						iconImage.textContent = app.icon || app.id.charAt(0).toUpperCase();
						
						const iconText = document.createElement('div');
						iconText.className = 'icon-text';
						iconText.textContent = app.title;
						
						iconElement.appendChild(iconImage);
						iconElement.appendChild(iconText);
						
						// Add click handler to launch the application.
						iconElement.addEventListener('click', () => {
							this.launchApplication(app.id);
						});
						
						// Add context menu handler (currently does nothing).
						iconElement.addEventListener('contextmenu', (e) => {
							e.preventDefault();
							// Custom context menu for desktop icons could be added here.
						});
						
						desktopIcons.appendChild(iconElement);
					}
				});
			}).catch(error => {
				this.log.error('Failed to load app list for desktop icons:', error);
				
				// Show error notification to the user.
				this.modules.ui.showNotification(
					'Error',
					'Failed to load desktop applications',
					5000
				);
			});
		}
		
		/**
		 * Show the context menu
		 * @private
		 * @param {number} x - X position
		 * @param {number} y - Y position
		 */
		_showContextMenu(x, y) {
			const contextMenu = document.getElementById('desktop-context-menu');
			contextMenu.style.left = `${x}px`;
			contextMenu.style.top = `${y}px`;
			contextMenu.style.display = 'block';
		}
		
		/**
		 * Create a new folder on desktop
		 * @private
		 */
		_createNewFolder() {
			const folderName = prompt('Enter folder name:');
			if (!folderName) return;
			
			const path = `/users/${this.currentUser.username}/Desktop/${folderName}`;
			
			this.modules.filesystem.createDirectory(path).then(() => {
				this.modules.ui.showNotification(
					'Folder Created',
					`Created folder: ${folderName}`,
					3000
				);
				this._refreshDesktop();
			}).catch(error => {
				this.modules.ui.showNotification(
					'Error',
					`Failed to create folder: ${error.message}`,
					5000
				);
			});
		}
		
		/**
		 * Create a new file on desktop
		 * @private
		 */
		_createNewFile() {
			const fileName = prompt('Enter file name:');
			if (!fileName) return;
			
			const path = `/users/${this.currentUser.username}/Desktop/${fileName}`;
			
			this.modules.filesystem.writeFile(path, '').then(() => {
				this.modules.ui.showNotification(
					'File Created',
					`Created file: ${fileName}`,
					3000
				);
				this._refreshDesktop();
			}).catch(error => {
				this.modules.ui.showNotification(
					'Error',
					`Failed to create file: ${error.message}`,
					5000
				);
			});
		}
		
		/**
		 * Refresh the desktop
		 * @private
		 */
		_refreshDesktop() {
			this._createDesktopIcons();
			this.modules.ui.showNotification(
				'Desktop Refreshed',
				'The desktop has been refreshed',
				2000
			);
		}
		
		/**
		 * Show the error screen
		 * @private
		 * @param {string} title - Error title
		 * @param {string} message - Error message
		 * @param {string} details - Error details (stack trace)
		 */
		_showErrorScreen(title, message, details) {
			// Hide other screens
			document.getElementById('splash-screen').style.display = 'none';
			document.getElementById('login-screen').style.display = 'none';
			document.getElementById('desktop').style.display = 'none';
			
			// Set error information
			document.getElementById('error-title').textContent = title;
			document.getElementById('error-message').textContent = message;
			
			const errorDetails = document.getElementById('error-details');
			if (details) {
				errorDetails.textContent = details;
				errorDetails.style.display = 'block';
			}
			
			// Show error screen
			document.getElementById('error-screen').style.display = 'flex';
			
			// Set up restart button
			document.getElementById('restart-button').onclick = () => {
				window.location.reload();
			};
		}
		
		/**
		 * Update the clock element
		 * @private
		 */
		_updateClock() {
			const clock = document.getElementById('clock');
			if (!clock) return;
			
			const now = new Date();
			const hours = now.getHours().toString().padStart(2, '0');
			const minutes = now.getMinutes().toString().padStart(2, '0');
			clock.textContent = `${hours}:${minutes}`;
		}
		
		/**
		 * Toggle the start menu visibility
		 */
		toggleStartMenu() {
			// Check if start menu already exists and is populated
			let startMenu = document.getElementById('start-menu');
			
			if (startMenu.classList.contains('active')) {
				startMenu.classList.remove('active');
				return;
			}
			
			// Populate the start menu
			this._populateStartMenu();
			
			// Show the menu
			startMenu.classList.add('active');
			
			// Close when clicking outside
			document.addEventListener('click', function closeMenu(e) {
				if (!startMenu.contains(e.target) && e.target.id !== 'start-button') {
					startMenu.classList.remove('active');
					document.removeEventListener('click', closeMenu);
				}
			});
		}
		
/**
		 * Populate the start menu with content.
		 * ✅ MODIFIED: Now includes a search bar and moves the logout button.
		 * @private
		 */
		_populateStartMenu() {
			const startMenu = document.getElementById('start-menu');
			const userName = this.currentUser ? this.currentUser.name : 'Guest';

			// --- 1. Create the new HTML structure ---
			startMenu.innerHTML = `
				<div class="user-info" style="display: flex; justify-content: space-between; align-items: center;">
					<div style="display: flex; align-items: center;">
						<div class="user-avatar">${userName.charAt(0)}</div>
						<div class="user-name">${userName}</div>
					</div>
					<div class="menu-item" data-action="logout" title="Log Out" style="padding: 5px 10px; margin-right: 10px;">
						<div class="menu-item-icon">🚪</div>
					</div>
				</div>
				<div class="start-menu-search-box" style="padding: 5px 10px;">
					<input type="text" id="start-menu-search" placeholder="Search applications..." style="width: 100%; padding: 8px; background: var(--terminal-bg); border: 1px solid var(--main-border); color: var(--main-text); border-radius: 3px;">
				</div>
				<div class="menu-items"></div>
			`;
			
			const menuItemsContainer = startMenu.querySelector('.menu-items');

			// --- 2. Fetch and render app list ---
			this.services.get('apps').call('listApps').then(apps => {
				let menuHtml = '';
				apps.forEach(app => {
					menuHtml += `
						<div class="menu-item" data-app="${app.id}">
							<div class="menu-item-icon">${app.icon || '📦'}</div>
							<div>${app.title}</div>
						</div>
					`;
				});
				menuItemsContainer.innerHTML = menuHtml;

				// --- 3. Add Event Listeners ---
				menuItemsContainer.querySelectorAll('.menu-item[data-app]').forEach(item => {
					item.addEventListener('click', () => {
						this.launchApplication(item.getAttribute('data-app'));
						this.toggleStartMenu();
					});
				});

				// Logout button
				startMenu.querySelector('.menu-item[data-action="logout"]').addEventListener('click', () => {
					this.logout();
				});

				// Search functionality
				document.getElementById('start-menu-search').addEventListener('input', (e) => {
					const searchTerm = e.target.value.toLowerCase();
					menuItemsContainer.querySelectorAll('.menu-item[data-app]').forEach(item => {
						const appName = item.textContent.toLowerCase();
						if (appName.includes(searchTerm)) {
							item.style.display = 'flex';
						} else {
							item.style.display = 'none';
						}
					});
				});

			}).catch(error => {
				this.log.error('Failed to load app list for start menu', error);
				menuItemsContainer.innerHTML = `<div class="menu-item">Error loading applications</div>`;
			});
		}
		
		/**
		 * Load the application's entry point and hand over control.
		 * ✅ MODIFIED: Now uses the `getAppAsset` API endpoint to securely load module scripts.
		 * @private
		 * @param {Object} appInfo  -- manifest from getAppInfo
		 * @param {Object} process  -- result of createProcess()
		 */
		async _bootApplicationCode(appInfo, process) {
			const entry = appInfo.entry || '';

			if (appInfo._path && entry.endsWith('.js')) {
				const windowContent = document.querySelector(`#${process.windowId} .window-content`);
				const iframe = document.createElement('iframe');
				//iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
				// ✅ FIXED: Added 'allow-downloads', 'allow-popups', and 'allow-forms' to the sandbox.
				// This resolves the download error and increases compatibility for applications.
				iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-downloads');
				iframe.style.border = 'none';
				iframe.style.width = '100%';
				iframe.style.height = '100%';
				windowContent.innerHTML = '';
				windowContent.appendChild(iframe);

				// in genesis-os.php -> _bootApplicationCode method

				const gosApiProxy = {
					app: { id: appInfo.id, title: appInfo.title, version: appInfo.version, params: process.params },
					// ✅ ADDED: Expose the current user's data to the sandboxed application.
					user: {
						username: this.currentUser.username,
						name: this.currentUser.name,
						roles: this.currentUser.roles
					},
					window: {
						getContainer: () => iframe.contentDocument.body,
						setTitle: (newTitle) => {
							const titleEl = document.querySelector(`#${process.windowId} .window-title`);
							if (titleEl) titleEl.textContent = newTitle;
						},
						close: () => this.modules.process.terminateProcess(process.id),
					},
					filesystem: this.modules.filesystem,
					ui: this.modules.ui,
					events: this.modules.events,
				};

				iframe.addEventListener('load', () => {
					try {
						const scriptUrl = `//${window.location.host}${window.location.pathname}?api=apps&method=getAppAsset&appId=${appInfo.id}&file=${entry}&v=${this.cacheBust}`;
						
                        // ✅ SIMPLIFIED: Create a global map on the parent window to hold API proxies.
                        // This is a more robust way to bridge the sandbox than deep-path traversal.
                        if (!window.gosApiProxies) {
                            window.gosApiProxies = {};
                        }
                        window.gosApiProxies[process.id] = gosApiProxy;

                        const script = iframe.contentDocument.createElement('script');
                        script.type = 'module';
                        script.innerHTML = `
                            try {
                                const module = await import("${scriptUrl}");
                                if (module.initialize && typeof module.initialize === 'function') {
                                    // ✅ SIMPLIFIED: Retrieve the API proxy from the new global map.
                                    const apiProxy = window.parent.gosApiProxies[${process.id}];
                                    module.initialize(apiProxy);
                                } else {
                                    throw new Error("Application entry point must export an 'initialize' function.");
                                }
                            } catch(e) {
                                document.body.innerHTML = '<div style="padding:20px;color:red;"><h4>App Error</h4><p>' + e.message + '</p></div>';
                                console.error('Error within sandboxed app:', e);
                            }
                        `;
                        
                        iframe.contentDocument.body.appendChild(script);

					} catch (e) {
						this.log.error(`Failed to initialize sandboxed app ${appInfo.id}:`, e);
						iframe.contentDocument.body.innerHTML = `<div style="padding:20px;color:red;"><h4>App Error</h4><p>${e.message}</p></div>`;
					}
				});

				iframe.src = 'sandbox.html';
				return;
			}
			
			// --- Handle Built-in System Applications ---
			else if (typeof window[entry] === 'function') {
				await window[entry](process);
				return;
			}

			throw new Error(`Cannot resolve entry point "${entry}" for app ${appInfo.id}`);
		}

		/**
		 * Load language pack
		 * @private
		 * @async
		 */
		async _loadLanguagePack() {
			try {
					const systemService = this.services.get('system');
					const lang = this.config?.ui?.language || 'en';
					const result = await systemService.call('getLanguagePack', { lang });
					this.translations = result || {};
					return true;
			} catch (error) {
					this.log.warn('Failed to load language pack', error);
					this.translations = {};
					return false;
			}
		}

		/**
		 * Translate a key using loaded language pack
		 * @param {string} key
		 * @returns {string}
		 */
		translate(key) {
			return this.translations[key] || key;
		}
		
		/**
		 * Log out the current user
		 * @async
		 */
		async logout() {
			try {
				this.log.info('Logging out user...');
				
				// Call logout API
				if (this.services.has('auth')) {
					await this.services.get('auth').call('logout', {});
				}
				
				// Close all running applications
				for (const [processId, process] of this.applications.entries()) {
					this.log.info(`Terminating process: ${processId}`);
					await this.modules.process.terminateProcess(processId);
				}
				
				// Clear current user
				this.currentUser = null;
				
				// Update security context
				this.modules.security.handleLogout();
				
				// Emit logout event
				this.modules.events.emit('auth:logout', {});
			} catch (error) {
				this.log.error('Logout failed:', error);
				throw error;
			}
		}
		
		/**
		 * Shut down the system
		 * @async
		 * @param {string} reason - Reason for shutdown
		 */
		async shutdown(reason = 'user_initiated') {
			try {
				this.log.info(`Initiating system shutdown (Reason: ${reason})...`);
				this._updateState('shuttingDown');
				
				// Emit pre-shutdown event
				this.modules.events.emit('system:shuttingDown', { reason });
				
				// Close all running applications
				for (const [processId, process] of this.applications.entries()) {
					this.log.info(`Terminating process: ${processId}`);
					await this.modules.process.terminateProcess(processId);
				}
				
				// Log out user if logged in
				if (this.currentUser) {
					await this.logout();
				}
				
				// Disconnect from all services
				for (const [serviceName, service] of this.services.entries()) {
					this.log.info(`Disconnecting from service: ${serviceName}`);
					service.status = 'disconnected';
				}
				
				// Final shutdown event
				this.modules.events.emit('system:shutdown', { reason });
				
				this._updateState('shutdown');
				this.log.info('System shutdown complete');
				
				// Show shutdown message
				document.body.innerHTML = `
					<div style="display:flex;justify-content:center;align-items:center;height:100vh;font-size:24px;background-color:var(--main-bg);color:var(--main-text);">
						<div style="text-align:center;">
							<p>Genesis OS has been shut down.</p>
							<p style="font-size:16px;margin-top:20px;opacity:0.7;">Refresh the page to restart.</p>
						</div>
					</div>
				`;
				
				return true;
			} catch (error) {
				this.log.error('Shutdown failed:', error);
				this._updateState('error');
				throw error;
			}
		}
		
		/**
		 * Create a logger for a specific component
		 * @private
		 * @param {string} component - Component name
		 * @returns {Object} Logger object
		 */
		_createLogger(component) {
			return {
				/**
				 * Log an informational message
				 * @param {string} message - Message to log
				 * @param {Object} [data] - Additional data to log
				 */
				info: (message, data) => {
					if (!this.state.debug && !component.startsWith('kernel')) return;
					console.info(`[GOS:${component}] ${message}`, data || '');
				},
				
				/**
				 * Log a warning message
				 * @param {string} message - Message to log
				 * @param {Object} [data] - Additional data to log
				 */
				warn: (message, data) => {
					console.warn(`[GOS:${component}] ${message}`, data || '');
				},
				
				/**
				 * Log an error message
				 * @param {string} message - Message to log
				 * @param {Error|Object} [error] - Error object or data
				 */
				error: (message, error) => {
					console.error(`[GOS:${component}] ${message}`, error || '');
				},
				
				/**
				 * Log a debug message (only shown in debug mode)
				 * @param {string} message - Message to log
				 * @param {Object} [data] - Additional data to log
				 */
				debug: (message, data) => {
					if (!this.state.debug) return;
					console.debug(`[GOS:${component}] ${message}`, data || '');
				}
			};
		}
		
		/**
		 * Update the kernel state
		 * @private
		 * @param {string} status - New status
		 */
		_updateState(status) {
			const previous = this.state.status;
			this.state.status = status;
			this.log.info(`Kernel state updated: ${status}`);

			// Emit state change event if events module is available
			if (this.modules.events) {
				this.modules.events.emit('kernel:stateChanged', {
					previousStatus: previous,
					currentStatus: status
				});
			}
		}
		
		/**
		 * Generate a unique request ID
		 * @private
		 * @returns {string} Unique ID
		 */

		_generateRequestId() {
				return 'req_' + Math.random().toString(36).substring(2, 15) +
						   Math.random().toString(36).substring(2, 15);
		}

		/**
		 * Dynamically load a script once
		 * @private
		 * @param {string} url - Script URL
		 * @returns {Promise<void>}
		 */
		_lazyLoadScript(url) {
				if (document.querySelector(`script[data-src="${url}"]`)) {
						return Promise.resolve();
				}
				return new Promise((resolve, reject) => {
						const s = document.createElement('script');
						s.type = 'module';
						s.dataset.src = url;
						s.onload = () => resolve();
						s.onerror = () => reject(new Error('Failed to load '+url));
						s.src = url + (url.includes('?') ? '&' : '?') + 'v=' + this.cacheBust;
						document.body.appendChild(s);
				});
		}
		
		/**
		 * Get system information
		 * @returns {Object} System information
		 */
		getSystemInfo() {
			return {
				name: this.config ? this.config.name : 'Genesis OS',
				version: this.version,
				buildDate: this.buildDate,
				codename: this.codename,
				status: this.state.status,
				uptime: this.state.startTime ? Math.floor((Date.now() - this.state.startTime) / 1000) : 0,
				user: this.currentUser ? {
					username: this.currentUser.username,
					name: this.currentUser.name,
					roles: this.currentUser.roles // <-- CORRECTED
				} : null,
				debug: this.state.debug,
				useLocalFilesystem: this.state.useLocalFilesystem,
				modules: Object.fromEntries(
					Object.entries(this.modules)
						.filter(([_, module]) => module !== null)
						.map(([name, _]) => [name, true])
				),
				services: Array.from(this.services.keys()),
				applications: this.applications.size
			};
		}
	}

	/**
	 * Event System
	 * Manages event subscriptions and notifications
	 */
	class EventSystem {
		/**
		 * Create a new event system
		 * @param {Kernel} kernel - Kernel reference
		 */
		constructor(kernel) {
			this.kernel = kernel;
			this.events = new Map();
			this.log = kernel._createLogger('events');
			
			this.log.info('Event system initialized');
		}
		
		/**
		 * Register an event listener
		 * @param {string} eventName - Event name to listen for
		 * @param {Function} handler - Event handler function
		 * @returns {EventSystem} This event system for chaining
		 */
		on(eventName, handler) {
			if (!this.events.has(eventName)) {
				this.events.set(eventName, []);
			}
			
			this.events.get(eventName).push(handler);
			this.log.debug(`Registered handler for event: ${eventName}`);
			
			return this;
		}
		
		/**
		 * Remove an event listener
		 * @param {string} eventName - Event name
		 * @param {Function} [handler] - Event handler (if not provided, all handlers are removed)
		 * @returns {EventSystem} This event system for chaining
		 */
		off(eventName, handler) {
			if (!this.events.has(eventName)) return this;
			
			if (!handler) {
				// Remove all handlers
				this.events.delete(eventName);
				this.log.debug(`Removed all handlers for event: ${eventName}`);
			} else {
				// Remove specific handler
				const handlers = this.events.get(eventName);
				const index = handlers.indexOf(handler);
				
				if (index !== -1) {
					handlers.splice(index, 1);
					this.log.debug(`Removed handler for event: ${eventName}`);
				}
				
				if (handlers.length === 0) {
					this.events.delete(eventName);
				}
			}
			
			return this;
		}
		
		/**
		 * Emit an event
		 * @param {string} eventName - Event name to emit
		 * @param {Object} data - Event data
		 */
		emit(eventName, data = {}) {
			if (!this.events.has(eventName)) {
				this.log.debug(`No handlers for event: ${eventName}`);
				return;
			}
			
			this.log.debug(`Emitting event: ${eventName}`, data);
			
			for (const handler of this.events.get(eventName)) {
				try {
					handler(data);
				} catch (error) {
					this.log.error(`Error in event handler for ${eventName}:`, error);
				}
			}
		}
	}
	
	/**
	 * Security Manager
	 * Handles user authentication, permissions, and security policies.
	 * This version is updated to support a multi-role user system.
	 */
	class SecurityManager {
		/**
		 * Create a new security manager
		 * @param {Kernel} kernel - Kernel reference
		 */
		constructor(kernel) {
			this.kernel = kernel;
			this.log = kernel._createLogger('security');
			this.permissions = new Set();
			this.user = null; // This will store the full user object on login

			this.log.info('Security manager initialized');
		}

		/**
		 * Handle user login. This method is called by the Kernel's event system.
		 * @param {Object} data - Login data containing the user object and their permissions.
		 */
		handleLogin(data) {
			this.user = data.user;

			// Set permissions from the server
			this.permissions.clear();
			if (Array.isArray(data.permissions)) {
				data.permissions.forEach(permission => {
					this.permissions.add(permission);
				});
			}

			this.log.info(`User ${this.user.username} logged in with ${this.permissions.size} permissions`);
		}

		/**
		 * Handle user logout.
		 */
		handleLogout() {
			this.user = null;
			this.permissions.clear();
			this.log.info('User logged out, permissions cleared');
		}

		/**
		 * NEW: Checks if the current user has the 'admin' role.
		 * This is the key function needed by the App Store UI to show the Submissions tab.
		 * @returns {boolean} True if the user is an administrator.
		 */
		isAdmin() {
			// Correctly checks if the user object exists and its 'roles' array includes 'admin'.
			return this.user && Array.isArray(this.user.roles) && this.user.roles.includes('admin');
		}

		/**
		 * Check if the current user has a specific permission.
		 * @param {string} permission - The permission to check (e.g., 'process.exec').
		 * @returns {boolean} True if the user has the permission.
		 */
		hasPermission(permission) {
			if (!this.user) {
				return false;
			}

			// UPDATED: Use the new isAdmin() method for the check.
			// This makes the permission check compatible with the multi-role system.
			if (this.isAdmin()) {
				return true; // Admins have all permissions.
			}

			// Check for direct permission
			if (this.permissions.has(permission)) {
				return true;
			}

			// Check for wildcard permissions (e.g., app.launch.*)
			const parts = permission.split('.');
			while (parts.length > 1) {
				parts.pop();
				const wildcard = parts.join('.') + '.*';
				if (this.permissions.has(wildcard)) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Get the current user's permissions as an array.
		 * @returns {Array<string>} A list of the user's permissions.
		 */
		getUserPermissions() {
			return Array.from(this.permissions);
		}
	}
	
	/**
	 * File System
	 * Provides a virtual file system interface
	 */
	class FileSystem {
		/**
		 * Create a new file system
		 * @param {Kernel} kernel - Kernel reference
		 */
		constructor(kernel) {
			this.kernel = kernel;
			this.log = kernel._createLogger('filesystem');
			
			this.log.info('File system initialized');
		}
		
		/**
		 * Initialize LocalStorage-based filesystem
		 * @async
		 */
		async initializeLocalStorage() {
			// Create basic directory structure if it doesn't exist
			const basicDirs = [
				'/',
				'/users',
				`/users/${this.kernel.currentUser.username}`,
				`/users/${this.kernel.currentUser.username}/Desktop`,
				`/users/${this.kernel.currentUser.username}/Documents`,
				'/apps',
				'/system'
			];
			
			// Check if filesystem is initialized
			if (!localStorage.getItem('gos_filesystem')) {
				this.log.info('Initializing localStorage filesystem');
				
				// Create initial filesystem structure
				const fs = {};
				
				basicDirs.forEach(dir => {
					fs[dir] = {
						type: 'directory',
						children: [],
						created: Date.now(),
						modified: Date.now()
					};
					
					// Add to parent
					if (dir !== '/') {
						const parentDir = dir.substring(0, dir.lastIndexOf('/')) || '/';
						const dirName = dir.substring(dir.lastIndexOf('/') + 1);
						
						if (fs[parentDir] && !fs[parentDir].children.includes(dirName)) {
							fs[parentDir].children.push(dirName);
						}
					}
				});
				
				// Save filesystem
				localStorage.setItem('gos_filesystem', JSON.stringify(fs));
				
				// Create welcome file
				await this.writeFile(`/users/${this.kernel.currentUser.username}/Desktop/welcome.txt`, 
					`Welcome to Genesis OS!\n\nThis is your personal desktop. You can create files and folders here.\n\nEnjoy exploring the system!`);
				
				this.log.info('localStorage filesystem initialized');
			}
		}
		
		/**
		 * Check if a file exists
		 * @param {string} path - Virtual file path
		 * @returns {boolean} True if file exists
		 */
		fileExists(path) {
			if (this.kernel.state.useLocalFilesystem) {
				// Use server filesystem - call would go here
				// For now, we'll just try to read the file and catch errors
				try {
					this.readFile(path);
					return true;
				} catch (error) {
					return false;
				}
			} else {
				// Use localStorage-based filesystem
				const fs = this._getLocalFilesystem();
				return fs[path] !== undefined && fs[path].type === 'file';
			}
		}

		/**
		 * Check if a directory exists
		 * @param {string} path - Virtual directory path
		 * @returns {boolean} True if directory exists
		 */
		directoryExists(path) {
			if (this.kernel.state.useLocalFilesystem) {
				// Use server filesystem - call would go here
				// For now, we'll just try to list the directory and catch errors
				try {
					this.listDirectory(path);
					return true;
				} catch (error) {
					return false;
				}
			} else {
				// Use localStorage-based filesystem
				const fs = this._getLocalFilesystem();
				return fs[path] !== undefined && fs[path].type === 'directory';
			}
		}			
		
		/**
		 * Read a file
		 * @async
		 * @param {string} path - Virtual file path
		 * @returns {Promise<Object>} File content and metadata
		 */
		async readFile(path) {
			try {
				this.log.debug(`Reading file: ${path}`);
				
				// Check permission
				if (!this.kernel.modules.security.hasPermission('filesystem.read.*')) {
					throw new Error('Permission denied');
				}
				
				if (this.kernel.state.useLocalFilesystem) {
					// Call filesystem service
					const result = await this.kernel.services.get('filesystem').call('readFile', { path });
					return result;
				} else {
					// Use localStorage-based filesystem
					const fs = this._getLocalFilesystem();
					
					if (!fs[path] || fs[path].type !== 'file') {
						throw new Error('File not found');
					}
					
					return {
						content: fs[path].content,
						size: fs[path].content.length,
						modified: fs[path].modified
					};
				}
			} catch (error) {
				this.log.error(`Failed to read file ${path}:`, error);
				throw error;
			}
		}
		
		/**
		 * Write a file
		 * @async
		 * @param {string} path - Virtual file path
		 * @param {string} content - File content
		 * @returns {Promise<Object>} File metadata
		 */
		async writeFile(path, content) {
			try {
				this.log.debug(`Writing file: ${path}`);
				
				// Check permission (more specific permission check could be done based on path)
				if (!this.kernel.modules.security.hasPermission('filesystem.write.*')) {
					throw new Error('Permission denied');
				}
				
				if (this.kernel.state.useLocalFilesystem) {
					// Call filesystem service
					const result = await this.kernel.services.get('filesystem').call('writeFile', {
						path,
						content
					});
					return result;
				} else {
					// Use localStorage-based filesystem
					const fs = this._getLocalFilesystem();
					
					// Get parent directory
					const parentDir = path.substring(0, path.lastIndexOf('/')) || '/';
					const fileName = path.substring(path.lastIndexOf('/') + 1);
					
					// Make sure parent directory exists
					if (!fs[parentDir] || fs[parentDir].type !== 'directory') {
						throw new Error('Parent directory does not exist');
					}
					
					// Add file to parent if it doesn't exist
					if (!fs[path]) {
						if (!fs[parentDir].children.includes(fileName)) {
							fs[parentDir].children.push(fileName);
						}
					}
					
					// Create or update file
					fs[path] = {
						type: 'file',
						content: content,
						created: fs[path] ? fs[path].created : Date.now(),
						modified: Date.now()
					};
					
					// Save filesystem
					this._saveLocalFilesystem(fs);
					
					return {
						path: path,
						size: content.length,
						modified: fs[path].modified
					};
				}
			} catch (error) {
				this.log.error(`Failed to write file ${path}:`, error);
				throw error;
			}
		}
		
		/**
		 * Delete a file or directory
		 * @async
		 * @param {string} path - Virtual file path
		 * @returns {Promise<boolean>} True if deleted successfully
		 */
		async deleteFile(path) {
			try {
				this.log.debug(`Deleting file/directory: ${path}`);
				
				// Check permission
				if (!this.kernel.modules.security.hasPermission('filesystem.delete.*')) {
					throw new Error('Permission denied');
				}
				
				if (this.kernel.state.useLocalFilesystem) {
					// Call filesystem service
					await this.kernel.services.get('filesystem').call('deleteFile', { path });
					return true;
				} else {
					// Use localStorage-based filesystem
					const fs = this._getLocalFilesystem();
					
					if (!fs[path]) {
						throw new Error('File or directory not found');
					}
					
					// Get parent directory
					const parentDir = path.substring(0, path.lastIndexOf('/')) || '/';
					const name = path.substring(path.lastIndexOf('/') + 1);
					
					// Make sure parent directory exists
					if (!fs[parentDir] || fs[parentDir].type !== 'directory') {
						throw new Error('Parent directory does not exist');
					}
					
					// If it's a directory, make sure it's empty
					if (fs[path].type === 'directory' && fs[path].children.length > 0) {
						throw new Error('Directory is not empty');
					}
					
					// Remove from parent
					const childIndex = fs[parentDir].children.indexOf(name);
					if (childIndex !== -1) {
						fs[parentDir].children.splice(childIndex, 1);
					}
					
					// Remove file or directory
					delete fs[path];
					
					// Save filesystem
					this._saveLocalFilesystem(fs);
					
					return true;
				}
			} catch (error) {
				this.log.error(`Failed to delete ${path}:`, error);
				throw error;
			}
		}
		
		/**
		 * List directory contents
		 * @async
		 * @param {string} path - Virtual directory path
		 * @returns {Promise<Array>} Directory contents
		 */
		async listDirectory(path) {
			try {
				this.log.debug(`Listing directory: ${path}`);
				
				// Check permission
				if (!this.kernel.modules.security.hasPermission('filesystem.read.*')) {
					throw new Error('Permission denied');
				}
				
				if (this.kernel.state.useLocalFilesystem) {
					// Call filesystem service
					const result = await this.kernel.services.get('filesystem').call('listDirectory', { path });
					return result;
				} else {
					// Use localStorage-based filesystem
					const fs = this._getLocalFilesystem();
					
					if (!fs[path] || fs[path].type !== 'directory') {
						throw new Error('Directory not found');
					}
					
					const items = [];
					
					// Add items from directory
					for (const childName of fs[path].children) {
						const childPath = path === '/' ? `/${childName}` : `${path}/${childName}`;
						const child = fs[childPath];
						
						if (!child) continue;
						
						const item = {
							name: childName,
							path: childPath,
							type: child.type,
							modified: child.modified
						};
						
						if (child.type === 'file') {
							item.size = child.content ? child.content.length : 0;
							item.extension = childName.includes('.') ? 
								childName.substring(childName.lastIndexOf('.') + 1) : '';
						}
						
						items.push(item);
					}
					
					// Sort directories first, then files
					items.sort((a, b) => {
						if (a.type === 'directory' && b.type !== 'directory') return -1;
						if (a.type !== 'directory' && b.type === 'directory') return 1;
						return a.name.localeCompare(b.name);
					});
					
					return items;
				}
			} catch (error) {
				this.log.error(`Failed to list directory ${path}:`, error);
				throw error;
			}
		}
		
		/**
		 * Create a directory
		 * @async
		 * @param {string} path - Virtual directory path
		 * @returns {Promise<boolean>} True if created successfully
		 */
		async createDirectory(path) {
			try {
				this.log.debug(`Creating directory: ${path}`);
				
				// Check permission
				if (!this.kernel.modules.security.hasPermission('filesystem.write.*')) {
					throw new Error('Permission denied');
				}
				
				if (this.kernel.state.useLocalFilesystem) {
					// Call filesystem service
					await this.kernel.services.get('filesystem').call('createDirectory', { path });
					return true;
				} else {
					// Use localStorage-based filesystem
					const fs = this._getLocalFilesystem();
					
					// Check if directory already exists
					if (fs[path]) {
						throw new Error('Path already exists');
					}
					
					// Get parent directory
					const parentDir = path.substring(0, path.lastIndexOf('/')) || '/';
					const dirName = path.substring(path.lastIndexOf('/') + 1);
					
					// Make sure parent directory exists
					if (!fs[parentDir] || fs[parentDir].type !== 'directory') {
						throw new Error('Parent directory does not exist');
					}
					
					// Create directory
					fs[path] = {
						type: 'directory',
						children: [],
						created: Date.now(),
						modified: Date.now()
					};
					
					// Add to parent
					if (!fs[parentDir].children.includes(dirName)) {
						fs[parentDir].children.push(dirName);
					}
					
					// Save filesystem
					this._saveLocalFilesystem(fs);
					
					return true;
				}
			} catch (error) {
				this.log.error(`Failed to create directory ${path}:`, error);
				throw error;
			}
		}
		
		/**
		 * Get the local filesystem from localStorage
		 * @private
		 * @returns {Object} Filesystem object
		 */
		_getLocalFilesystem() {
			try {
				const fs = localStorage.getItem('gos_filesystem');
				return fs ? JSON.parse(fs) : {};
			} catch (error) {
				this.log.error('Failed to read filesystem from localStorage:', error);
				return {};
			}
		}
		
		/**
		 * Save the local filesystem to localStorage
		 * @private
		 * @param {Object} fs - Filesystem object
		 */
		_saveLocalFilesystem(fs) {
			try {
				localStorage.setItem('gos_filesystem', JSON.stringify(fs));
			} catch (error) {
				this.log.error('Failed to save filesystem to localStorage:', error);
				throw error;
			}
		}
	}

	/**
	 * Process Manager
	 * Manages application processes and windows
	 */
	class ProcessManager {
		/**
		 * Create a new process manager
		 * @param {Kernel} kernel - Kernel reference
		 */
		constructor(kernel) {
			this.kernel = kernel;
			this.log = kernel._createLogger('process');
			this.processes = new Map();
			this.nextPid = 1000;
			this.windowZIndex = 100;
			
			this.log.info('Process manager initialized');
		}
		
		/**
		 * Create a new process for an application
		 * @async
		 * @param {string} appId - Application ID
		 * @param {Object} appInfo - Application metadata
		 * @param {Object} params - Launch parameters
		 * @returns {Promise<Object>} Process object
		 */
		async createProcess(appId, appInfo, params = {}) {
			try {
				const pid = this.nextPid++;
				
				this.log.info(`Creating process ${pid} for application ${appId}`);
				
				// Create window for the process
				const windowId = `window-${pid}`;
				const windowOptions = Object.assign({}, appInfo.window || {}, { help: appInfo.help || '' });
				const window = this._createWindow(windowId, appInfo.title || appId, appInfo.icon, windowOptions);
				
				// Create process object
				const process = {
					id: pid,
					appId,
					windowId,
					window,
					startTime: Date.now(),
					status: 'starting',
					params
				};
				
				// Store in process map
				this.processes.set(pid, process);
				
				// Initialize the application
				await this._initializeApplication(process, appInfo);
				
				// Update process status
				process.status = 'running';
				
				return process;
			} catch (error) {
				this.log.error(`Failed to create process for ${appId}:`, error);
				throw error;
			}
		}
		
		/**
		 * Terminate a process
		 * @async
		 * @param {number} pid - Process ID
		 * @returns {Promise<boolean>} True if terminated successfully
		 */
		async terminateProcess(pid) {
			try {
				if (!this.processes.has(pid)) {
					throw new Error(`Process not found: ${pid}`);
				}
				
				const process = this.processes.get(pid);
				this.log.info(`Terminating process ${pid} (${process.appId})`);
				
				// Update process status
				process.status = 'terminating';
				
				// Remove window
				this._removeWindow(process.windowId);
				
				// Remove taskbar item
				this._removeTaskbarItem(process.windowId);
				
				// Update process status and remove from list
				process.status = 'terminated';
				this.processes.delete(pid);
				
				// Emit terminated event
				this.kernel.modules.events.emit('application:terminated', {
					processId: pid,
					appId: process.appId
				});
				
				return true;
			} catch (error) {
				this.log.error(`Failed to terminate process ${pid}:`, error);
				throw error;
			}
		}
		
		/**
		 * Get a list of all registered apps (system + installed)
		 * @returns {Array} List of app information objects
		 */
		getRegisteredApps() {
			// Start with system apps
			let apps = [];
			
			// Try to get system apps from service
			try {
				this.kernel.services.get('apps').call('listApps')
					.then(systemApps => {
						apps = systemApps;
					})
					.catch(err => {
						this.log.warn('Failed to get system apps', err);
					});
			} catch (e) {
				this.log.warn('Error accessing apps service', e);
			}
			
			// Add installed apps from localStorage
			try {
				const installedApps = JSON.parse(localStorage.getItem('gos_installed_apps') || '[]');
				this.log.debug(`Found ${installedApps.length} installed apps`);
				
				installedApps.forEach(app => {
					if (app.desktopIcon !== false) {
						apps = [...apps, ...installedApps];
					}
				});
			} catch (e) {
				this.log.warn('Failed to load installed apps from localStorage', e);
			}
			
			return apps;
		}

		/**
		 * Start an application by ID
		 * @param {string} appId - Application ID to start
		 * @param {Object} params - Launch parameters
		 * @returns {Promise<string>} Process ID
		 */
		async startApp(appId, params = {}) {
			return this.kernel.launchApplication(appId, params);
		}
		
		/**
		 * Create a window for an application
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} title - Window title
		 * @param {string} icon - Window icon
		 * @param {Object} options - Window options
		 * @returns {HTMLElement} Window element
		 */
		_createWindow(windowId, title, icon, options = {}) {
			this.log.debug(`Creating window: ${windowId}`);
			
			const workspace = document.getElementById('workspace');
			
			// Create window element
			const window = document.createElement('div');
			window.id = windowId;
			window.className = 'window';
			
			// Set initial position and size
			const width = options.width || 800;
			const height = options.height || 600;
			
			// Center the window
			const workspaceRect = workspace.getBoundingClientRect();
			const left = Math.max(0, (workspaceRect.width - width) / 2);
			const top = Math.max(0, (workspaceRect.height - height) / 2);
			
			window.style.width = `${width}px`;
			window.style.height = `${height}px`;
			window.style.left = `${left}px`;
			window.style.top = `${top}px`;
			window.style.zIndex = this.windowZIndex++;
			
			// Create window content
			window.innerHTML = `
				<div class="window-header">
					<div class="window-title">${title}</div>
						<div class="window-controls">
							<button class="window-control window-help" title="Help">?</button>
							<button class="window-control window-minimize" title="Minimize">_</button>
							<button class="window-control window-maximize" title="Maximize">□</button>
							<button class="window-control window-close" title="Close">×</button>
						</div>
				</div>
				<div class="window-content">
					<div class="app-loading">Loading ${title}...</div>
				</div>
			`;
			
			// Add window to workspace
			workspace.appendChild(window);
			
			// Add window to taskbar
			this._addTaskbarItem(windowId, title, icon);
			
			// Make window draggable
			this._makeWindowDraggable(window);
			
			// Make window resizable
			if (options.resizable !== false) {
				this._makeWindowResizable(window);
			}
			
			// Set up window controls
			const minimize = window.querySelector('.window-minimize');
			const maximize = window.querySelector('.window-maximize');
			const close = window.querySelector('.window-close');
			const helpBtn = window.querySelector('.window-help');
			const helpHtml = options.help || null;
			
			minimize.addEventListener('click', () => {
				window.classList.add('minimized');
				
				// Update taskbar item
				const taskbarItem = document.getElementById(`taskbar-${windowId}`);
				if (taskbarItem) {
					taskbarItem.classList.remove('active');
				}
			});
			
			maximize.addEventListener('click', () => {
				window.classList.toggle('maximized');
			});
			
			close.addEventListener('click', () => {
					// Find the process by window ID and terminate it
					for (const [pid, process] of this.processes.entries()) {
							if (process.windowId === windowId) {
									this.terminateProcess(pid);
									break;
							}
					}
			});

			if (helpBtn && helpHtml !== null) {
					helpBtn.addEventListener('click', () => {
							this.kernel.modules.ui.showHelp(helpHtml || 'No help available.');
					});
			}
			
			// Make window active when clicked
			window.addEventListener('mousedown', () => {
				this._activateWindow(window);
			});
			
			return window;
		}
		
		/**
		 * Remove a window
		 * @private
		 * @param {string} windowId - Window ID
		 */
		_removeWindow(windowId) {
			const window = document.getElementById(windowId);
			if (window) {
				window.remove();
			}
		}
		
		/**
		 * Add a taskbar item for a window
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} title - Window title
		 * @param {string} icon - Window icon
		 */
		_addTaskbarItem(windowId, title, icon) {
			const taskbarItems = document.getElementById('taskbar-items');
			
			const item = document.createElement('div');
			item.id = `taskbar-${windowId}`;
			item.className = 'taskbar-item active';
			item.innerHTML = `
				<div class="taskbar-item-icon">${icon || '📄'}</div>
				<div class="taskbar-item-title">${title}</div>
			`;
			
			// Toggle window visibility when taskbar item is clicked
			item.addEventListener('click', () => {
				const window = document.getElementById(windowId);
				if (window) {
					if (window.classList.contains('minimized')) {
						window.classList.remove('minimized');
						item.classList.add('active');
						this._activateWindow(window);
					} else if (window === this._getActiveWindow()) {
						window.classList.add('minimized');
						item.classList.remove('active');
					} else {
						this._activateWindow(window);
					}
				}
			});
			
			taskbarItems.appendChild(item);
		}
		
		/**
		 * Remove a taskbar item
		 * @private
		 * @param {string} windowId - Window ID
		 */
		_removeTaskbarItem(windowId) {
			const taskbarItem = document.getElementById(`taskbar-${windowId}`);
			if (taskbarItem) {
				taskbarItem.remove();
			}
		}
		
		/**
		 * Make a window draggable
		 * @private
		 * @param {HTMLElement} window - Window element
		 */
		_makeWindowDraggable(window) {
			const header = window.querySelector('.window-header');
			
			let isDragging = false;
			let offsetX = 0;
			let offsetY = 0;
			
			header.addEventListener('mousedown', (e) => {
				// Ignore if clicking on window controls
				if (e.target.closest('.window-controls')) {
					return;
				}
				
				// Activate window
				this._activateWindow(window);
				
				// Don't drag if maximized
				if (window.classList.contains('maximized')) {
					return;
				}
				
				isDragging = true;
				
				const rect = window.getBoundingClientRect();
				offsetX = e.clientX - rect.left;
				offsetY = e.clientY - rect.top;
				
				// Set initial cursor
				document.body.style.cursor = 'move';
				
				// Prevent text selection while dragging
				e.preventDefault();
			});
			
			document.addEventListener('mousemove', (e) => {
				if (!isDragging) return;
				
				const workspaceRect = document.getElementById('workspace').getBoundingClientRect();
				
				// Calculate new position
				let left = e.clientX - offsetX;
				let top = e.clientY - offsetY;
				
				// Keep window within workspace bounds
				left = Math.max(0, Math.min(left, workspaceRect.width - 100));
				top = Math.max(0, Math.min(top, workspaceRect.height - 30));
				
				window.style.left = `${left}px`;
				window.style.top = `${top}px`;
			});
			
			document.addEventListener('mouseup', () => {
				if (isDragging) {
					isDragging = false;
					document.body.style.cursor = '';
				}
			});
		}
		
		/**
		 * Make a window resizable
		 * @private
		 * @param {HTMLElement} window - Window element
		 */
		_makeWindowResizable(window) {
			// Minimum dimensions
			const minWidth = 300;
			const minHeight = 200;
			
			// Create resize handle
			const resizeHandle = document.createElement('div');
			resizeHandle.style.position = 'absolute';
			resizeHandle.style.right = '0';
			resizeHandle.style.bottom = '0';
			resizeHandle.style.width = '15px';
			resizeHandle.style.height = '15px';
			resizeHandle.style.cursor = 'nwse-resize';
			resizeHandle.style.zIndex = '10';
			
			window.appendChild(resizeHandle);
			
			let isResizing = false;
			let startX = 0;
			let startY = 0;
			let startWidth = 0;
			let startHeight = 0;
			
			resizeHandle.addEventListener('mousedown', (e) => {
				// Don't resize if maximized
				if (window.classList.contains('maximized')) {
					return;
				}
				
				isResizing = true;
				
				startX = e.clientX;
				startY = e.clientY;
				startWidth = window.offsetWidth;
				startHeight = window.offsetHeight;
				
				// Set cursor
				document.body.style.cursor = 'nwse-resize';
				
				// Prevent text selection while resizing
				e.preventDefault();
				
				// Activate window
				this._activateWindow(window);
			});
			
			document.addEventListener('mousemove', (e) => {
				if (!isResizing) return;
				
				// Calculate new dimensions
				const width = Math.max(minWidth, startWidth + (e.clientX - startX));
				const height = Math.max(minHeight, startHeight + (e.clientY - startY));
				
				window.style.width = `${width}px`;
				window.style.height = `${height}px`;
			});
			
			document.addEventListener('mouseup', () => {
				if (isResizing) {
					isResizing = false;
					document.body.style.cursor = '';
				}
			});
		}
		
		/**
		 * Activate a window (bring to front)
		 * @private
		 * @param {HTMLElement} window - Window element
		 */
		_activateWindow(window) {
			// Update z-index
			window.style.zIndex = this.windowZIndex++;
			
			// Update active status in taskbar
			const windowId = window.id;
			const taskbarItems = document.querySelectorAll('.taskbar-item');
			
			taskbarItems.forEach(item => {
				item.classList.remove('active');
			});
			
			const activeItem = document.getElementById(`taskbar-${windowId}`);
			if (activeItem) {
				activeItem.classList.add('active');
			}
		}
		
		/**
		 * Get the currently active window
		 * @private
		 * @returns {HTMLElement|null} Active window element
		 */
		_getActiveWindow() {
			const windows = document.querySelectorAll('.window');
			let activeWindow = null;
			let highestZIndex = -1;
			
			windows.forEach(window => {
				const zIndex = parseInt(window.style.zIndex || 0);
				if (zIndex > highestZIndex) {
					highestZIndex = zIndex;
					activeWindow = window;
				}
			});
			
			return activeWindow;
		}
		
		/**
		 * Initialize an application in a window
		 * @private
		 * @async
		 * @param {Object} process - Process object
		 * @param {Object} appInfo - Application metadata
		 */
		async _initializeApplication(process, appInfo) {
			try {
				const windowContent = document.querySelector(`#${process.windowId} .window-content`);
				
				// Clear loading indicator
				windowContent.innerHTML = '';
				
				// Create app container
				const appContainer = document.createElement('div');
				appContainer.className = `app-${appInfo.id}`;
				appContainer.style.height = '100%';
				windowContent.appendChild(appContainer);
				
				// Initialize app based on appId
				switch (appInfo.id) {
					case 'mathematicalSandbox':
						this._initializeMathematicalSandbox(appContainer, process, appInfo);
						break;
						
					case 'fileManager':
						this._initializeFileManager(appContainer, process, appInfo);
						break;
						
					case 'terminal':
						this._initializeTerminal(appContainer, process, appInfo);
						break;
						
					case 'editor':
						this._initializeCodeEditor(appContainer, process, appInfo);
						break;
						
					case 'settings':
						this._initializeSettings(appContainer, process, appInfo);
						break;
						
					case 'appStore':
							this._initializeAppStore(appContainer, process, appInfo);
							break;

					case 'diagnostics':
							this._initializeDiagnostics(appContainer, process, appInfo);
							break;

					case 'dev-center': // <-- Corrected ID
							this._initializeDeveloperCenter(appContainer, process, appInfo);
							break;
						
					default:
						appContainer.innerHTML = `
							<div style="padding: 20px; text-align: center;">
								<h3>Application ${appInfo.id} not implemented</h3>
								<p>This application is not available in the current version.</p>
							</div>
						`;
				}
				
				this.log.info(`Application ${appInfo.id} initialized in window ${process.windowId}`);
			} catch (error) {
				this.log.error(`Failed to initialize application ${appInfo.id}:`, error);
				
				// Show error message in window
				const windowContent = document.querySelector(`#${process.windowId} .window-content`);
				windowContent.innerHTML = `
					<div style="padding: 20px; color: #f44336;">
						<h3>Application Error</h3>
						<p>${error.message}</p>
						<pre style="margin-top: 10px; background: var(--terminal-bg); padding: 10px; overflow: auto; font-size: 12px;">${error.stack || ''}</pre>
					</div>
				`;
				
				throw error;
			}
		}
		
		// This method is inside the ProcessManager class in genesis-os.php

		_initializeDeveloperCenter(container, process, options) {
			container.innerHTML = `
				<div style="padding: 20px; font-family: sans-serif;">
					<h2>Developer Submission Portal</h2>
					<p>Submit your application for review. Please provide a valid JSON manifest and your application's JavaScript code.</p>
					<div id="dev-form-status" style="margin-bottom: 15px; padding: 10px; border-radius: 3px; display: none;"></div>
					<form id="dev-submit-form">
						<label for="manifest" style="display: block; margin-bottom: 5px;"><strong>Manifest (JSON)</strong></label>
						<textarea id="manifest" name="manifest" rows="10" required style="width: 100%; padding: 8px; border: 1px solid var(--main-border); border-radius: 3px; font-family: monospace;"></textarea>
						<br><br>
						<label for="code" style="display: block; margin-bottom: 5px;"><strong>Application Code (JavaScript)</strong></label>
						<textarea id="code" name="code" rows="15" required style="width: 100%; padding: 8px; border: 1px solid var(--main-border); border-radius: 3px; font-family: monospace;"></textarea>
						<br><br>
						<button type="submit" class="button button-primary">Submit for Review</button>
					</form>
				</div>
			`;

			const form = container.querySelector('#dev-submit-form');
			const statusDiv = container.querySelector('#dev-form-status');

			form.addEventListener('submit', (e) => {
				e.preventDefault();
				statusDiv.style.display = 'none';

				const manifestText = container.querySelector('#manifest').value;
				const codeText = container.querySelector('#code').value;

				// --- Frontend Validation ---
				let manifest;
				try {
					manifest = JSON.parse(manifestText);
				} catch (err) {
					statusDiv.textContent = 'Error: Manifest is not valid JSON.';
					statusDiv.style.backgroundColor = '#f8d7da';
					statusDiv.style.color = '#721c24';
					statusDiv.style.display = 'block';
					return;
				}
                
                // ✅ FIXED: Validation now correctly checks for 'title' and 'entry' to match the backend.
				if (!manifest.id || !manifest.title || !manifest.entry) {
					statusDiv.textContent = 'Error: Manifest is missing required fields (id, title, entry).';
					statusDiv.style.backgroundColor = '#f8d7da';
					statusDiv.style.color = '#721c24';
					statusDiv.style.display = 'block';
					return;
				}

				// --- API Call ---
				statusDiv.textContent = 'Submitting...';
				statusDiv.style.backgroundColor = '#e2e3e5';
				statusDiv.style.color = '#383d41';
				statusDiv.style.display = 'block';

				this.kernel.api.apps.call('submitApp', { manifest: manifestText, code: codeText })
					.then(data => {
						statusDiv.textContent = 'Success! Your application has been submitted for review.';
						statusDiv.style.backgroundColor = '#d4edda';
						statusDiv.style.color = '#155724';
						form.reset();
					})
					.catch(error => {
						statusDiv.textContent = 'Error: ' + error.message;
						statusDiv.style.backgroundColor = '#f8d7da';
						statusDiv.style.color = '#721c24';
					});
			});
		}
		
		/**
		 * Initialize Mathematical Sandbox application
		 * @private
		 * @param {HTMLElement} container - Container element
		 * @param {Object} process - Process object
		 * @param {Object} appInfo - Application metadata
		 */
		_initializeMathematicalSandbox(container, process, appInfo) {
			// Create mathematical sandbox UI
			container.innerHTML = `
				<div style="display: flex; flex-direction: column; height: 100%;">
					<div style="padding: 5px; border-bottom: 1px solid var(--main-border);">
						<button id="${process.windowId}-new-grid" class="button">New Grid</button>
						<button id="${process.windowId}-save-grid" class="button">Save</button>
						<button id="${process.windowId}-load-grid" class="button">Load</button>
						<button id="${process.windowId}-undo-btn" class="button">Undo</button>
						<button id="${process.windowId}-redo-btn" class="button">Redo</button>
						<button id="${process.windowId}-math-menu" class="button">Math</button>
						<button id="${process.windowId}-help-btn" class="button">Help</button>
					</div>
					
					<div id="${process.windowId}-setup-panel" style="padding: 20px;">
						<h3>Grid Configuration</h3>
						<div style="margin-bottom: 10px;">
							<label>Cell Size (pixels): </label>
							<input type="number" id="${process.windowId}-cell-size" min="50" value="70">
						</div>
						<div style="margin-bottom: 10px;">
							<label>Grid Rows: </label>
							<input type="number" id="${process.windowId}-grid-rows" min="1" value="4">
						</div>
						<div style="margin-bottom: 10px;">
							<label>Grid Columns: </label>
							<input type="number" id="${process.windowId}-grid-cols" min="1" value="4">
						</div>
						<button id="${process.windowId}-create-grid" class="button button-primary">Create Grid</button>
					</div>
					
					<div id="${process.windowId}-grid-container" class="grid-container" style="display: none;">
						<div id="${process.windowId}-grid" class="grid"></div>
					</div>
					
					<div id="${process.windowId}-status-bar" style="padding: 5px; border-top: 1px solid var(--main-border); font-size: 12px;">
						Ready.
					</div>
				</div>
				
				<!-- Cell Properties Modal -->
				<div id="${process.windowId}-cell-modal" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background-color: var(--window-body); border: 1px solid var(--main-border); padding: 15px; z-index: 1000; width: 300px;">
					<h3>Cell Properties</h3>
					<div style="margin-bottom: 10px;">
						<label>Text: </label>
						<input type="text" id="${process.windowId}-cell-text">
					</div>
					<div style="margin-bottom: 10px;">
						<label>Expression: </label>
						<input type="text" id="${process.windowId}-cell-expression">
					</div>
					<div style="margin-bottom: 10px;">
						<label>Background Color: </label>
						<input type="color" id="${process.windowId}-cell-bg-color" value="#222222">
					</div>
					<div style="margin-bottom: 10px;">
						<label>Font Size: </label>
						<input type="range" id="${process.windowId}-cell-font-size" min="6" max="72" value="12">
						<span id="${process.windowId}-font-size-value">12</span>
					</div>
					<div style="margin-bottom: 10px;">
						<label>Font Color: </label>
						<input type="color" id="${process.windowId}-cell-font-color" value="#33ff33">
					</div>
					<div style="text-align: right;">
						<button id="${process.windowId}-apply-cell" class="button button-primary">Apply</button>
						<button id="${process.windowId}-cancel-cell" class="button">Cancel</button>
					</div>
				</div>
			`;
			
			// App state
			const state = {
				squareData: {},       // Cell data
				splitSquares: {},     // Split cells info
				actionHistory: [],    // For undo
				redoStack: [],        // For redo
				squareSize: 70,       // Default cell size
				numRows: 4,           // Default rows
				numCols: 4,           // Default columns
				currentCell: null     // Currently selected cell
			};
			
			// Initialize event handlers for grid creation
			document.getElementById(`${process.windowId}-create-grid`).addEventListener('click', () => {
				createGrid();
			});
			
			document.getElementById(`${process.windowId}-new-grid`).addEventListener('click', () => {
				if (confirm('Create a new grid? All unsaved changes will be lost.')) {
					document.getElementById(`${process.windowId}-setup-panel`).style.display = 'block';
					document.getElementById(`${process.windowId}-grid-container`).style.display = 'none';
					state.squareData = {};
					state.splitSquares = {};
					state.actionHistory = [];
					state.redoStack = [];
				}
			});
			
			document.getElementById(`${process.windowId}-save-grid`).addEventListener('click', () => {
				saveGrid();
			});
			
			document.getElementById(`${process.windowId}-load-grid`).addEventListener('click', () => {
				loadGrid();
			});
			
			document.getElementById(`${process.windowId}-undo-btn`).addEventListener('click', () => {
				undo();
			});
			
			document.getElementById(`${process.windowId}-redo-btn`).addEventListener('click', () => {
				redo();
			});
			
			document.getElementById(`${process.windowId}-help-btn`).addEventListener('click', () => {
				alert('Mathematical Sandbox Help\n\n' + 
					  'Left Click: Edit cell\n' + 
					  'Right Click: Split/merge cell\n' + 
					  'Expressions: Use standard JavaScript math expressions (e.g., sin(x), 2+3)\n' + 
					  'Math Menu: Access additional mathematical tools');
			});
			
			document.getElementById(`${process.windowId}-cancel-cell`).addEventListener('click', () => {
				document.getElementById(`${process.windowId}-cell-modal`).style.display = 'none';
			});
			
			document.getElementById(`${process.windowId}-apply-cell`).addEventListener('click', () => {
				applyCellProperties();
			});
			
			document.getElementById(`${process.windowId}-cell-font-size`).addEventListener('input', function() {
				document.getElementById(`${process.windowId}-font-size-value`).textContent = this.value;
			});
			
			// Function to create the grid
			function createGrid() {
				state.squareSize = parseInt(document.getElementById(`${process.windowId}-cell-size`).value) || 70;
				state.numRows = parseInt(document.getElementById(`${process.windowId}-grid-rows`).value) || 4;
				state.numCols = parseInt(document.getElementById(`${process.windowId}-grid-cols`).value) || 4;
				
				// Hide setup, show grid
				document.getElementById(`${process.windowId}-setup-panel`).style.display = 'none';
				document.getElementById(`${process.windowId}-grid-container`).style.display = 'block';
				
				// Create grid
				drawGrid();
				
				// Update status
				updateStatus('Grid created');
			}
			
			// Function to draw the grid
			function drawGrid() {
				const grid = document.getElementById(`${process.windowId}-grid`);
				grid.innerHTML = '';
				
				// Set grid template
				grid.style.gridTemplateColumns = `repeat(${state.numCols}, ${state.squareSize}px)`;
				
				// Create cells
				for (let i = 0; i < state.numRows; i++) {
					for (let j = 0; j < state.numCols; j++) {
						const cell = document.createElement('div');
						cell.className = 'cell';
						cell.dataset.row = i;
						cell.dataset.col = j;
						
						// Add event listeners
						cell.addEventListener('click', handleCellClick);
						cell.addEventListener('contextmenu', handleCellRightClick);
						
						grid.appendChild(cell);
					}
				}
			}
			
			// Handle cell click
			function handleCellClick(e) {
				const cell = e.currentTarget;
				const row = parseInt(cell.dataset.row);
				const col = parseInt(cell.dataset.col);
				const subRow = cell.dataset.subrow !== undefined ? parseInt(cell.dataset.subrow) : undefined;
				const subCol = cell.dataset.subcol !== undefined ? parseInt(cell.dataset.subcol) : undefined;
				
				openCellProperties(row, col, subRow, subCol);
			}
			
			// Handle cell right click
			function handleCellRightClick(e) {
				e.preventDefault();
				
				const cell = e.currentTarget;
				const row = parseInt(cell.dataset.row);
				const col = parseInt(cell.dataset.col);
				
				const key = `${row}_${col}`;
				
				if (state.splitSquares[key]) {
					// If already split, merge
					if (confirm('Merge this cell?')) {
						mergeCell(row, col);
					}
				} else {
					// If not split, offer to split
					const splitSize = prompt('Enter split size (rows,cols):', '2,2');
					if (splitSize) {
						const [rows, cols] = splitSize.split(',').map(Number);
						if (rows > 0 && cols > 0) {
							splitCell(row, col, rows, cols);
						}
					}
				}
			}
			
			// Open cell properties
			function openCellProperties(row, col, subRow, subCol) {
				const key = subRow !== undefined && subCol !== undefined ? 
						  `${row}_${col}_${subRow}_${subCol}` : `${row}_${col}`;
				
				const cellData = state.squareData[key] || {};
				
				// Populate form
				document.getElementById(`${process.windowId}-cell-text`).value = cellData.text || '';
				document.getElementById(`${process.windowId}-cell-expression`).value = cellData.expression || '';
				document.getElementById(`${process.windowId}-cell-bg-color`).value = cellData.color || '#222222';
				document.getElementById(`${process.windowId}-cell-font-size`).value = cellData.fontSize || 12;
				document.getElementById(`${process.windowId}-font-size-value`).textContent = cellData.fontSize || 12;
				document.getElementById(`${process.windowId}-cell-font-color`).value = cellData.fontColor || '#33ff33';
				
				// Store current cell
				state.currentCell = { row, col, subRow, subCol };
				
				// Show modal
				document.getElementById(`${process.windowId}-cell-modal`).style.display = 'block';
			}
			
			// Apply cell properties
			function applyCellProperties() {
				if (!state.currentCell) return;
				
				const { row, col, subRow, subCol } = state.currentCell;
				const key = subRow !== undefined && subCol !== undefined ? 
						  `${row}_${col}_${subRow}_${subCol}` : `${row}_${col}`;
				
				// Get form values
				const text = document.getElementById(`${process.windowId}-cell-text`).value;
				const expression = document.getElementById(`${process.windowId}-cell-expression`).value;
				const color = document.getElementById(`${process.windowId}-cell-bg-color`).value;
				const fontSize = parseInt(document.getElementById(`${process.windowId}-cell-font-size`).value);
				const fontColor = document.getElementById(`${process.windowId}-cell-font-color`).value;
				
				// Save previous state for undo
				const prevState = state.squareData[key] ? { ...state.squareData[key] } : null;
				state.actionHistory.push({ key, prevState });
				state.redoStack = []; // Clear redo stack
				
				// Update data
				state.squareData[key] = {
					text: text,
					expression: expression,
					color: color,
					fontSize: fontSize,
					fontColor: fontColor
				};
				
				// Update display
				updateCellDisplay(row, col, subRow, subCol);
				
				// Hide modal
				document.getElementById(`${process.windowId}-cell-modal`).style.display = 'none';
				
				// Update status
				updateStatus('Cell updated');
			}
			
			// Update cell display
			function updateCellDisplay(row, col, subRow, subCol) {
				let cell;
				const key = subRow !== undefined && subCol !== undefined ? 
						  `${row}_${col}_${subRow}_${subCol}` : `${row}_${col}`;
				
				if (subRow !== undefined && subCol !== undefined) {
					// Find sub-cell
					const mainCell = document.querySelector(`#${process.windowId}-grid .cell[data-row="${row}"][data-col="${col}"]`);
					cell = mainCell.querySelector(`.sub-cell[data-subrow="${subRow}"][data-subcol="${subCol}"]`);
				} else {
					// Find main cell
					cell = document.querySelector(`#${process.windowId}-grid .cell[data-row="${row}"][data-col="${col}"]`);
				}
				
				if (!cell) return;
				
				const cellData = state.squareData[key] || {};
				
				// Apply styling
				cell.style.backgroundColor = cellData.color || '#222222';
				cell.style.color = cellData.fontColor || '#33ff33';
				cell.style.fontSize = `${cellData.fontSize || 12}px`;
				
				// Set content
				let displayText = cellData.text || '';
				
				// Evaluate expression
				if (cellData.expression) {
					try {
						// Basic expression evaluation
						const expr = cellData.expression
							.replace(/sin\(/g, 'Math.sin(')
							.replace(/cos\(/g, 'Math.cos(')
							.replace(/tan\(/g, 'Math.tan(')
							.replace(/sqrt\(/g, 'Math.sqrt(')
							.replace(/abs\(/g, 'Math.abs(')
							.replace(/pi/g, 'Math.PI')
							.replace(/\^/g, '**');
						
						const result = eval(expr);
						displayText = String(result);
					} catch (e) {
						displayText = "Error";
					}
				}
				
				cell.textContent = displayText;
			}
			
			// Split a cell
			function splitCell(row, col, rows, cols) {
				const key = `${row}_${col}`;
				
				// Save state for undo
				state.actionHistory.push({
					type: 'split',
					key: key,
					prevState: {
						isSplit: false,
						data: state.squareData[key]
					}
				});
				state.redoStack = [];
				
				// Mark as split
				state.splitSquares[key] = { rows, cols };
				
				// Get the cell
				const cell = document.querySelector(`#${process.windowId}-grid .cell[data-row="${row}"][data-col="${col}"]`);
				cell.classList.add('split');
				cell.innerHTML = '';
				cell.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
				
				// Create sub-cells
				for (let i = 0; i < rows; i++) {
					for (let j = 0; j < cols; j++) {
						const subCell = document.createElement('div');
						subCell.className = 'sub-cell';
						subCell.dataset.row = row;
						subCell.dataset.col = col;
						subCell.dataset.subrow = i;
						subCell.dataset.subcol = j;
						
						// Add event listeners
						subCell.addEventListener('click', handleCellClick);
						
						cell.appendChild(subCell);
					}
				}
				
				updateStatus('Cell split');
			}
			
			// Merge a split cell
			function mergeCell(row, col) {
				const key = `${row}_${col}`;
				
				if (!state.splitSquares[key]) return;
				
				// Save state for undo
				const subCells = {};
				const subCellElements = document.querySelectorAll(`#${process.windowId}-grid .cell[data-row="${row}"][data-col="${col}"] .sub-cell`);
				
				subCellElements.forEach(subCell => {
					const subRow = parseInt(subCell.dataset.subrow);
					const subCol = parseInt(subCell.dataset.subcol);
					const subKey = `${row}_${col}_${subRow}_${subCol}`;
					subCells[subKey] = state.squareData[subKey];
				});
				
				state.actionHistory.push({
					type: 'merge',
					key: key,
					prevState: {
						isSplit: true,
						splitInfo: { ...state.splitSquares[key] },
						subCells: subCells
					}
				});
				state.redoStack = [];
				
				// Remove split flag
				delete state.splitSquares[key];
				
				// Remove sub-cell data
				Object.keys(state.squareData).forEach(dataKey => {
					if (dataKey.startsWith(`${key}_`)) {
						delete state.squareData[dataKey];
					}
				});
				
				// Reset cell
				const cell = document.querySelector(`#${process.windowId}-grid .cell[data-row="${row}"][data-col="${col}"]`);
				cell.classList.remove('split');
				cell.innerHTML = '';
				cell.style.removeProperty('grid-template-columns');
				
				// Update display
				updateCellDisplay(row, col);
				
				updateStatus('Cell merged');
			}
			
			// Undo function
			function undo() {
				if (state.actionHistory.length === 0) {
					alert('Nothing to undo');
					return;
				}
				
				const action = state.actionHistory.pop();
				
				if (action.type === 'split') {
					// Undo split (merge the cell)
					const [row, col] = action.key.split('_').map(Number);
					
					// Save current state for redo
					const subCells = {};
					const subCellElements = document.querySelectorAll(`#${process.windowId}-grid .cell[data-row="${row}"][data-col="${col}"] .sub-cell`);
					
					subCellElements.forEach(subCell => {
						const subRow = parseInt(subCell.dataset.subrow);
						const subCol = parseInt(subCell.dataset.subcol);
						const subKey = `${row}_${col}_${subRow}_${subCol}`;
						subCells[subKey] = state.squareData[subKey];
					});
					
					state.redoStack.push({
						type: 'split',
						key: action.key,
						redoState: {
							isSplit: true,
							splitInfo: { ...state.splitSquares[action.key] },
							subCells: subCells
						}
					});
					
					// Merge the cell
					mergeCell(row, col);
					
					// Restore previous cell state
					if (action.prevState.data) {
						state.squareData[action.key] = action.prevState.data;
						updateCellDisplay(row, col);
					}
				} else if (action.type === 'merge') {
					// Undo merge (re-split the cell)
					const [row, col] = action.key.split('_').map(Number);
					
					// Save current state for redo
					state.redoStack.push({
						type: 'merge',
						key: action.key,
						redoState: {
							isSplit: false,
							data: state.squareData[action.key]
						}
					});
					
					// Restore the split
					const splitInfo = action.prevState.splitInfo;
					splitCell(row, col, splitInfo.rows, splitInfo.cols);
					
					// Restore sub-cell data
					for (let subKey in action.prevState.subCells) {
						if (action.prevState.subCells[subKey]) {
							state.squareData[subKey] = action.prevState.subCells[subKey];
							
							// Extract sub-row and sub-col from key
							const [_, __, subRow, subCol] = subKey.split('_').map(Number);
							updateCellDisplay(row, col, subRow, subCol);
						}
					}
				} else {
					// Regular cell update
					const key = action.key;
					let row, col, subRow, subCol;
					
					if (key.split('_').length === 4) {
						[row, col, subRow, subCol] = key.split('_').map(Number);
					} else {
						[row, col] = key.split('_').map(Number);
					}
					
					// Save current state for redo
					state.redoStack.push({
						key: key,
						redoState: { ...state.squareData[key] }
					});
					
					// Restore previous state
					if (action.prevState) {
						state.squareData[key] = action.prevState;
					} else {
						delete state.squareData[key];
					}
					
					// Update display
					updateCellDisplay(row, col, subRow, subCol);
				}
				
				updateStatus('Undo completed');
			}
			
			// Redo function
			function redo() {
				if (state.redoStack.length === 0) {
					alert('Nothing to redo');
					return;
				}
				
				const action = state.redoStack.pop();
				
				if (action.type === 'split') {
					// Redo split
					const [row, col] = action.key.split('_').map(Number);
					
					// Save current state for undo
					state.actionHistory.push({
						type: 'split',
						key: action.key,
						prevState: {
							isSplit: false,
							data: state.squareData[action.key]
						}
					});
					
					// Redo the split
					const splitInfo = action.redoState.splitInfo;
					splitCell(row, col, splitInfo.rows, splitInfo.cols);
					
					// Restore sub-cell data
					for (let subKey in action.redoState.subCells) {
						if (action.redoState.subCells[subKey]) {
							state.squareData[subKey] = action.redoState.subCells[subKey];
							
							// Extract sub-row and sub-col from key
							const [_, __, subRow, subCol] = subKey.split('_').map(Number);
							updateCellDisplay(row, col, subRow, subCol);
						}
					}
				} else if (action.type === 'merge') {
					// Redo merge
					const [row, col] = action.key.split('_').map(Number);
					
					// Save current state for undo
					const subCells = {};
					const subCellElements = document.querySelectorAll(`#${process.windowId}-grid .cell[data-row="${row}"][data-col="${col}"] .sub-cell`);
					
					subCellElements.forEach(subCell => {
						const subRow = parseInt(subCell.dataset.subrow);
						const subCol = parseInt(subCell.dataset.subcol);
						const subKey = `${row}_${col}_${subRow}_${subCol}`;
						subCells[subKey] = state.squareData[subKey];
					});
					
					state.actionHistory.push({
						type: 'merge',
						key: action.key,
						prevState: {
							isSplit: true,
							splitInfo: { ...state.splitSquares[action.key] },
							subCells: subCells
						}
					});
					
					// Merge the cell
					mergeCell(row, col);
					
					// Restore cell state
					if (action.redoState.data) {
						state.squareData[action.key] = action.redoState.data;
						updateCellDisplay(row, col);
					}
				} else {
					// Regular cell update
					const key = action.key;
					let row, col, subRow, subCol;
					
					if (key.split('_').length === 4) {
						[row, col, subRow, subCol] = key.split('_').map(Number);
					} else {
						[row, col] = key.split('_').map(Number);
					}
					
					// Save current state for undo
					state.actionHistory.push({
						key: key,
						prevState: state.squareData[key] ? { ...state.squareData[key] } : null
					});
					
					// Restore redo state
					state.squareData[key] = action.redoState;
					
					// Update display
					updateCellDisplay(row, col, subRow, subCol);
				}
				
				updateStatus('Redo completed');
			}
			
			// Save grid to file
			function saveGrid() {
				// Create a data object with all grid info
				const gridData = {
					squareData: state.squareData,
					splitSquares: state.splitSquares,
					numRows: state.numRows,
					numCols: state.numCols,
					squareSize: state.squareSize
				};
				
				// Ask for filename
				const fileName = prompt("Enter filename to save:", "grid.json");
				if (!fileName) return;
				
				const username = this.kernel?.currentUser?.username || 'guest';
				const path = `/users/${username}/Documents/${fileName}`;
				
				// Try to save via filesystem
				try {
					this.kernel.modules.filesystem.writeFile(path, JSON.stringify(gridData, null, 2))
						.then(() => {
							updateStatus(`Grid saved to ${path}`);
						})
						.catch(error => {
							// Fallback to localStorage
							localStorage.setItem(`grid_${fileName}`, JSON.stringify(gridData));
							updateStatus(`Grid saved to local storage: ${fileName}`);
						});
				} catch (error) {
					// Fallback to localStorage
					localStorage.setItem(`grid_${fileName}`, JSON.stringify(gridData));
					updateStatus(`Grid saved to local storage: ${fileName}`);
				}
			}
			
			// Load grid from file
			function loadGrid() {
				// Ask for filename
				const fileName = prompt("Enter filename to load:", "grid.json");
				if (!fileName) return;
				
				const username = this.kernel?.currentUser?.username || 'guest';
				const path = `/users/${username}/Documents/${fileName}`;
				
				// Try to load via filesystem
				try {
					this.kernel.modules.filesystem.readFile(path)
						.then(result => {
							const gridData = JSON.parse(result.content);
							loadGridData(gridData);
						})
						.catch(error => {
							// Try localStorage
							const savedGrid = localStorage.getItem(`grid_${fileName}`);
							if (savedGrid) {
								const gridData = JSON.parse(savedGrid);
								loadGridData(gridData);
								updateStatus(`Grid loaded from local storage: ${fileName}`);
							} else {
								alert(`Error loading grid: File not found`);
							}
						});
				} catch (error) {
					// Try localStorage
					const savedGrid = localStorage.getItem(`grid_${fileName}`);
					if (savedGrid) {
						const gridData = JSON.parse(savedGrid);
						loadGridData(gridData);
						updateStatus(`Grid loaded from local storage: ${fileName}`);
					} else {
						alert(`Error loading grid: ${error.message}`);
					}
				}
			}
			
			// Load grid data
			function loadGridData(gridData) {
				// Validate data structure
				if (!gridData.squareData || !gridData.numRows || !gridData.numCols) {
					alert('Invalid grid data format');
					return;
				}
				
				// Load the data
				state.squareData = gridData.squareData;
				state.splitSquares = gridData.splitSquares || {};
				state.numRows = gridData.numRows;
				state.numCols = gridData.numCols;
				state.squareSize = gridData.squareSize;
				
				// Clear history
				state.actionHistory = [];
				state.redoStack = [];
				
				// Switch to grid view
				document.getElementById(`${process.windowId}-setup-panel`).style.display = 'none';
				document.getElementById(`${process.windowId}-grid-container`).style.display = 'block';
				
				// Draw the grid
				drawGrid();
				
				// Update cell displays
				for (let key in state.squareData) {
					let row, col, subRow, subCol;
					
					if (key.split('_').length === 4) {
						[row, col, subRow, subCol] = key.split('_').map(Number);
						
						// Make sure parent cell is split
						const parentKey = `${row}_${col}`;
						if (state.splitSquares[parentKey]) {
							const cell = document.querySelector(`#${process.windowId}-grid .cell[data-row="${row}"][data-col="${col}"]`);
							if (!cell.classList.contains('split')) {
								const splitInfo = state.splitSquares[parentKey];
								splitCell(row, col, splitInfo.rows, splitInfo.cols);
							}
						}
						
						updateCellDisplay(row, col, subRow, subCol);
					} else {
						[row, col] = key.split('_').map(Number);
						updateCellDisplay(row, col);
					}
				}
				
				updateStatus(`Grid loaded successfully`);
			}
			
			// Update status bar
			function updateStatus(message) {
				document.getElementById(`${process.windowId}-status-bar`).textContent = message;
			}
		}
		
		/**
		 * Initialize File Manager application
		 * @private
		 * @param {HTMLElement} container - Container element
		 * @param {Object} process - Process object
		 * @param {Object} appInfo - Application metadata
		 */
		_initializeFileManager(container, process, appInfo) {
			// Create file manager UI
			container.innerHTML = `
				<div class="file-browser">
					<div class="file-toolbar">
						<button id="${process.windowId}-back" class="button" title="Back">←</button>
						<button id="${process.windowId}-forward" class="button" title="Forward">→</button>
						<button id="${process.windowId}-up" class="button" title="Up">↑</button>
						<button id="${process.windowId}-refresh" class="button" title="Refresh">⟳</button>
						<span style="margin: 0 10px;">Path:</span>
						<input type="text" id="${process.windowId}-path" style="flex: 1; min-width: 200px;">
						<button id="${process.windowId}-go" class="button">Go</button>
					</div>
					<div class="file-browser-content">
						<div class="file-sidebar">
							<div class="file-sidebar-item" data-path="/users/${this.kernel.currentUser.username}">
								<div class="file-sidebar-icon">👤</div>
								<div>My Files</div>
							</div>
							<div class="file-sidebar-item" data-path="/users/${this.kernel.currentUser.username}/Desktop">
								<div class="file-sidebar-icon">🖥️</div>
								<div>Desktop</div>
							</div>
							<div class="file-sidebar-item" data-path="/users/${this.kernel.currentUser.username}/Documents">
								<div class="file-sidebar-icon">📄</div>
								<div>Documents</div>
							</div>
							<div class="file-sidebar-item" data-path="/apps">
								<div class="file-sidebar-icon">📦</div>
								<div>Applications</div>
							</div>
							<div class="file-sidebar-item" data-path="/system">
								<div class="file-sidebar-icon">⚙️</div>
								<div>System</div>
							</div>
						</div>
						<div id="${process.windowId}-file-main" class="file-main">
							<div id="${process.windowId}-file-list" class="file-list"></div>
						</div>
					</div>
				</div>
			`;
			
			// File manager state
			const state = {
				currentPath: `/users/${this.kernel.currentUser.username}`,
				selectedFile: null,
				history: [`/users/${this.kernel.currentUser.username}`],
				historyIndex: 0
			};
			
			// Initialize path input
			document.getElementById(`${process.windowId}-path`).value = state.currentPath;
			
			// Load current directory
			this._loadDirectory(process.windowId, state.currentPath, state);
			
			// Set active sidebar item
			const initialSidebarItem = container.querySelector(`.file-sidebar-item[data-path="${state.currentPath}"]`);
			if (initialSidebarItem) {
				initialSidebarItem.classList.add('active');
			}
			
			// Set up event handlers
			document.getElementById(`${process.windowId}-back`).addEventListener('click', () => {
				if (state.historyIndex > 0) {
					state.historyIndex--;
					const path = state.history[state.historyIndex];
					this._loadDirectory(process.windowId, path, state);
					document.getElementById(`${process.windowId}-path`).value = path;
				}
			});
			
			document.getElementById(`${process.windowId}-forward`).addEventListener('click', () => {
				if (state.historyIndex < state.history.length - 1) {
					state.historyIndex++;
					const path = state.history[state.historyIndex];
					this._loadDirectory(process.windowId, path, state);
					document.getElementById(`${process.windowId}-path`).value = path;
				}
			});
			
			document.getElementById(`${process.windowId}-up`).addEventListener('click', () => {
				const parentPath = state.currentPath.substring(0, state.currentPath.lastIndexOf('/')) || '/';
				this._navigateTo(process.windowId, parentPath, state);
			});
			
			document.getElementById(`${process.windowId}-refresh`).addEventListener('click', () => {
				this._loadDirectory(process.windowId, state.currentPath, state);
			});
			
			document.getElementById(`${process.windowId}-go`).addEventListener('click', () => {
				const path = document.getElementById(`${process.windowId}-path`).value;
				this._navigateTo(process.windowId, path, state);
			});
			
			document.getElementById(`${process.windowId}-path`).addEventListener('keypress', (e) => {
				if (e.key === 'Enter') {
					const path = e.target.value;
					this._navigateTo(process.windowId, path, state);
				}
			});
			
			// Side panel navigation
			const sidebar = container.querySelector('.file-sidebar');
			sidebar.addEventListener('click', (e) => {
				const item = e.target.closest('.file-sidebar-item');
				if (item) {
					const path = item.getAttribute('data-path');
					this._navigateTo(process.windowId, path, state);
					
					// Update active item
					sidebar.querySelectorAll('.file-sidebar-item').forEach(el => {
						el.classList.remove('active');
					});
					item.classList.add('active');
				}
			});
		}
		
		/**
		 * Load directory contents in file manager
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} path - Directory path
		 * @param {Object} state - File manager state
		 */
		_loadDirectory(windowId, path, state) {
			const fileList = document.getElementById(`${windowId}-file-list`);
			fileList.innerHTML = '<div style="text-align: center; padding: 20px;">Loading...</div>';
			
			// Update state
			state.currentPath = path;
			
			// List directory contents
			this.kernel.modules.filesystem.listDirectory(path).then(items => {
				// Clear file list
				fileList.innerHTML = '';
				
				if (items.length === 0) {
					fileList.innerHTML = '<div style="text-align: center; padding: 20px; opacity: 0.7;">This folder is empty</div>';
					return;
				}
				
				// Add each item to the file list
				items.forEach(item => {
					const itemElement = document.createElement('div');
					itemElement.className = 'file-item';
					itemElement.dataset.path = item.path;
					itemElement.dataset.type = item.type;
					
					const icon = item.type === 'directory' ? '📁' : this._getFileIcon(item.extension || '');
					
					itemElement.innerHTML = `
						<div class="file-icon">${icon}</div>
						<div class="file-name">${item.name}</div>
					`;
					
					// Add click handler
					itemElement.addEventListener('click', (e) => {
						// Deselect any previously selected item
						fileList.querySelectorAll('.file-item').forEach(el => {
							el.classList.remove('selected');
						});
						
						// Select this item
						itemElement.classList.add('selected');
						state.selectedFile = item.path;
					});
					
					// Add double-click handler
					itemElement.addEventListener('dblclick', (e) => {
						if (item.type === 'directory') {
							// Navigate to directory
							this._navigateTo(windowId, item.path, state);
						} else {
							// Open file
							this._openFile(item.path);
						}
					});
					
					fileList.appendChild(itemElement);
				});
			}).catch(error => {
				fileList.innerHTML = `
					<div style="text-align: center; padding: 20px; color: #f44336;">
						Error loading directory: ${error.message}
					</div>
				`;
			});
		}
		
		/**
		 * Navigate to a directory in file manager
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} path - Directory path
		 * @param {Object} state - File manager state
		 */
		_navigateTo(windowId, path, state) {
		
			// --- SECURITY CHECK (JAIL) ---
			const user = this.kernel.currentUser;
			const isAdmin = user && user.roles.includes('admin');
			//const isDeveloper = user && user.roles.includes('developer');
			
			//if (!isAdmin && !isDeveloper) {
			if (!isAdmin) {
				const userHomeDir = `/users/${user.username}`;
				// Ensure the requested path is within the user's own home directory.
				if (!path.startsWith(userHomeDir)) {
					this.kernel.modules.ui.showNotification('Access Denied', 'You can only browse your user directory.', 3000);
					return; // Abort the navigation.
				}
			}
			// --- END SECURITY CHECK ---

			// Update path input
			document.getElementById(`${windowId}-path`).value = path;
			
			// Add to history if it's a new path
			if (path !== state.currentPath) {
				if (state.historyIndex < state.history.length - 1) {
					state.history = state.history.slice(0, state.historyIndex + 1);
				}
				
				state.history.push(path);
				state.historyIndex = state.history.length - 1;
			}
			
			// Load directory
			this._loadDirectory(windowId, path, state);
		}
		
		/**
		 * Open a file
		 * @private
		 * @param {string} path - File path
		 */
		_openFile(path) {
			// Determine file type
			const extension = path.split('.').pop().toLowerCase();
			
			// Launch appropriate app based on file type
			if (['txt', 'md', 'js', 'json', 'html', 'css', 'php'].includes(extension)) {
				this.kernel.launchApplication('editor', { filePath: path });
			} else {
				// For other file types
				this.kernel.modules.ui.showNotification(
					'File',
					`Opening ${path.split('/').pop()}`,
					2000
				);
				
				// You could add handlers for other file types here
			}
		}
		
		/**
		 * Get an icon for a file extension
		 * @private
		 * @param {string} extension - File extension
		 * @returns {string} Icon emoji
		 */
		_getFileIcon(extension) {
			const icons = {
				'txt': '📝',
				'md': '📝',
				'js': '📜',
				'json': '📋',
				'html': '🌐',
				'css': '🎨',
				'php': '🐘',
				'jpg': '🖼️',
				'jpeg': '🖼️',
				'png': '🖼️',
				'gif': '🖼️',
				'pdf': '📕',
				'zip': '🗜️',
				'mp3': '🎵',
				'mp4': '🎬'
			};
			
			return icons[extension.toLowerCase()] || '📄';
		}
		
		/**
		 * Initialize Terminal application
		 * @private
		 * @param {HTMLElement} container - Container element
		 * @param {Object} process - Process object
		 * @param {Object} appInfo - Application metadata
		 */
		_initializeTerminal(container, process, appInfo) {
			// Create terminal UI
			container.innerHTML = `
				<div class="terminal">
					<div id="${process.windowId}-output" class="terminal-output"></div>
					<div class="terminal-input-line">
						<div id="${process.windowId}-prompt" class="terminal-prompt">guest@gos:~$</div>
						<input type="text" id="${process.windowId}-input" class="terminal-input" autofocus>
					</div>
				</div>
			`;
			
			// Terminal state
			const state = {
				history: [],
				historyIndex: -1,
				currentDirectory: `/users/${this.kernel.currentUser.username}`
			};
			
			// Initial output
			const outputElement = document.getElementById(`${process.windowId}-output`);
			outputElement.innerHTML = `
				<div style="color: var(--highlight);">Genesis OS Terminal v1.0</div>
				<div>Type 'help' for a list of available commands.</div>
				<div>&nbsp;</div>
			`;
			
			// Update prompt
			this._updateTerminalPrompt(process.windowId, state);
			
			// Handle input
			const inputElement = document.getElementById(`${process.windowId}-input`);
			inputElement.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					const command = inputElement.value;
					
					if (command.trim()) {
						// Add to history
						state.history.push(command);
						state.historyIndex = state.history.length;
						
						// Show command in output
						const promptText = document.getElementById(`${process.windowId}-prompt`).textContent;
						this._appendTerminalOutput(process.windowId, `<span style="color: var(--highlight);">${promptText}</span> ${command}`);
						
						// Execute command
						this._executeTerminalCommand(process.windowId, command, state);
						
						// Clear input
						inputElement.value = '';
					}
				} else if (e.key === 'ArrowUp') {
					// Navigate history up
					if (state.history.length > 0 && state.historyIndex > 0) {
						state.historyIndex--;
						inputElement.value = state.history[state.historyIndex];
					}
					e.preventDefault();
				} else if (e.key === 'ArrowDown') {
					// Navigate history down
					if (state.historyIndex < state.history.length - 1) {
						state.historyIndex++;
						inputElement.value = state.history[state.historyIndex];
					} else {
						// Clear input at end of history
						state.historyIndex = state.history.length;
						inputElement.value = '';
					}
					e.preventDefault();
				}
			});
			
			// Focus input when terminal clicked
			container.addEventListener('click', () => {
				inputElement.focus();
			});
		}
		
		/**
		 * Update terminal prompt
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {Object} state - Terminal state
		 */
		_updateTerminalPrompt(windowId, state) {
			const promptElement = document.getElementById(`${windowId}-prompt`);
			const username = this.kernel.currentUser.username;
			
	// Format the directory for display
			let displayPath = state.currentDirectory;
			if (displayPath.startsWith(`/users/${username}`)) {
				displayPath = '~' + displayPath.substring(`/users/${username}`.length) || '~';
			}
			
			promptElement.textContent = `${username}@gos:${displayPath}$`;
		}
		
		/**
		 * Append output to terminal
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} text - Output text
		 */
		_appendTerminalOutput(windowId, text) {
			const outputElement = document.getElementById(`${windowId}-output`);
			outputElement.innerHTML += `<div>${text}</div>`;
			outputElement.scrollTop = outputElement.scrollHeight;
		}
		
		/**
		 * Execute a terminal command
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} command - Command text
		 * @param {Object} state - Terminal state
		 */
		_executeTerminalCommand(windowId, command, state) {
			// Parse command and arguments
			const parts = command.split(' ');
			const cmd = parts[0].toLowerCase();
			const args = parts.slice(1);
			
			// Execute command
			switch (cmd) {
				case 'help':
					this._appendTerminalOutput(windowId, `
						Available commands:
						<br>help - Show this help
						<br>ls, dir [path] - List directory contents
						<br>cd [path] - Change directory
						<br>pwd - Print working directory
						<br>cat, type [file] - Show file content
						<br>mkdir [path] - Create directory
						<br>touch [file] [content] - Create file
						<br>rm, del [file] - Remove file
						<br>clear, cls - Clear terminal
						<br>echo [text] - Display text
						<br>whoami - Show current user
						<br>date - Show current date
					`);
					break;
					
				case 'ls':
				case 'dir':
					this._terminalListDirectory(windowId, args[0] || state.currentDirectory, state);
					break;
					
				case 'cd':
					this._terminalChangeDirectory(windowId, args[0], state);
					break;
					
				case 'pwd':
					this._appendTerminalOutput(windowId, state.currentDirectory);
					break;
					
				case 'cat':
				case 'type':
					if (!args[0]) {
						this._appendTerminalOutput(windowId, 'Usage: cat [file]');
					} else {
						this._terminalShowFile(windowId, args[0], state);
					}
					break;
					
				case 'mkdir':
					if (!args[0]) {
						this._appendTerminalOutput(windowId, 'Usage: mkdir [directory]');
					} else {
						this._terminalCreateDirectory(windowId, args[0], state);
					}
					break;
					
				case 'touch':
				case 'new':
					if (!args[0]) {
						this._appendTerminalOutput(windowId, 'Usage: touch [file] [content]');
					} else {
						const content = args.slice(1).join(' ');
						this._terminalCreateFile(windowId, args[0], content, state);
					}
					break;
					
				case 'rm':
				case 'del':
					if (!args[0]) {
						this._appendTerminalOutput(windowId, 'Usage: rm [file]');
					} else {
						this._terminalRemoveFile(windowId, args[0], state);
					}
					break;
					
				case 'clear':
				case 'cls':
					document.getElementById(`${windowId}-output`).innerHTML = '';
					break;
					
				case 'echo':
					this._appendTerminalOutput(windowId, args.join(' '));
					break;
					
				case 'whoami':
					this._appendTerminalOutput(windowId, this.kernel.currentUser.username);
					break;
					
				case 'date':
					this._appendTerminalOutput(windowId, new Date().toString());
					break;
					
				default:
					this._appendTerminalOutput(windowId, `Command not found: ${cmd}. Type 'help' for available commands.`);
			}
		}
		
		/**
		 * List directory contents in terminal
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} path - Directory path
		 * @param {Object} state - Terminal state
		 */
		_terminalListDirectory(windowId, path, state) {
			// Resolve relative path
			path = this._resolveTerminalPath(path, state.currentDirectory);
			
			this.kernel.modules.filesystem.listDirectory(path).then(items => {
				if (items.length === 0) {
					this._appendTerminalOutput(windowId, `Directory is empty: ${path}`);
					return;
				}
				
				let output = '';
				
				// Add directories
				items.filter(item => item.type === 'directory')
					.forEach(item => {
						output += `<span style="color: var(--highlight);">${item.name}/</span>  `;
					});
				
				// Add files
				items.filter(item => item.type !== 'directory')
					.forEach(item => {
						output += `${item.name}  `;
					});
				
				this._appendTerminalOutput(windowId, output);
			}).catch(error => {
				this._appendTerminalOutput(windowId, `Error: ${error.message}`);
			});
		}
		
		/**
		 * Normalizes a client-side path, resolving '..' and '.' segments.
		 * @private
		 * @param {string} path - The path to normalize.
		 * @returns {string} The normalized, absolute path.
		 */
		_normalizePath(path) {
			const parts = path.split('/').filter(p => p.length > 0);
			const absolutes = [];
			for (const part of parts) {
				if (part === '.') {
					continue;
				}
				if (part === '..') {
					if (absolutes.length > 0) {
						absolutes.pop();
					}
				} else {
					absolutes.push(part);
				}
			}
			// Return the joined path, ensuring it starts with a single '/'.
			return '/' + absolutes.join('/');
		}
		
		/**
		 * Change directory in terminal, with security jail for non-admin users.
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} path - Directory path
		 * @param {Object} state - Terminal state
		 */
		_terminalChangeDirectory(windowId, path, state) {
			if (!path) {
				state.currentDirectory = `/users/${this.kernel.currentUser.username}`;
				this._updateTerminalPrompt(windowId, state);
				return;
			}
			
			// Resolve the path parts first, then normalize to handle '..'
			const resolvedPath = this._resolveTerminalPath(path, state.currentDirectory);
			const normalizedPath = this._normalizePath(resolvedPath);

			// --- SECURITY CHECK (JAIL) ---
			const user = this.kernel.currentUser;
			const isAdmin = user && user.roles.includes('admin');
			//const isDeveloper = user && user.roles.includes('developer');

			//if (!isAdmin && !isDeveloper) {
			if (!isAdmin) {
				const userHomeDir = `/users/${user.username}`;
				// Check the final, normalized path against the user's home directory.
				if (!normalizedPath.startsWith(userHomeDir)) {
					this._appendTerminalOutput(windowId, `Error: Permission denied. Cannot navigate outside your home directory.`);
					return; // Abort the directory change.
				}
			}
			// --- END SECURITY CHECK ---

			// Verify the normalized directory exists and update the state.
			this.kernel.api.filesystem.call('listDirectory', { path: normalizedPath }).then(() => {
				state.currentDirectory = normalizedPath;
				this._updateTerminalPrompt(windowId, state);
			}).catch(error => {
				this._appendTerminalOutput(windowId, `Error: ${error.message}`);
			});
		}

		
		/**
		 * Show file content in terminal
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} path - File path
		 * @param {Object} state - Terminal state
		 */
		_terminalShowFile(windowId, path, state) {
			// Resolve path
			const targetPath = this._resolveTerminalPath(path, state.currentDirectory);
			
			this.kernel.modules.filesystem.readFile(targetPath).then(result => {
				this._appendTerminalOutput(windowId, result.content);
			}).catch(error => {
				this._appendTerminalOutput(windowId, `Error: ${error.message}`);
			});
		}
		
		/**
		 * Create directory in terminal
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} path - Directory path
		 * @param {Object} state - Terminal state
		 */
		_terminalCreateDirectory(windowId, path, state) {
			// Resolve path
			const targetPath = this._resolveTerminalPath(path, state.currentDirectory);
			
			this.kernel.modules.filesystem.createDirectory(targetPath).then(() => {
				this._appendTerminalOutput(windowId, `Directory created: ${targetPath}`);
			}).catch(error => {
				this._appendTerminalOutput(windowId, `Error: ${error.message}`);
			});
		}
		
		/**
		 * Create file in terminal
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} path - File path
		 * @param {string} content - File content
		 * @param {Object} state - Terminal state
		 */
		_terminalCreateFile(windowId, path, content, state) {
			// Resolve path
			const targetPath = this._resolveTerminalPath(path, state.currentDirectory);
			
			this.kernel.modules.filesystem.writeFile(targetPath, content).then(() => {
				this._appendTerminalOutput(windowId, `File created: ${targetPath}`);
			}).catch(error => {
				this._appendTerminalOutput(windowId, `Error: ${error.message}`);
			});
		}
		
		/**
		 * Remove file in terminal
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} path - File path
		 * @param {Object} state - Terminal state
		 */
		_terminalRemoveFile(windowId, path, state) {
			// Resolve path
			const targetPath = this._resolveTerminalPath(path, state.currentDirectory);
			
			this.kernel.modules.filesystem.deleteFile(targetPath).then(() => {
				this._appendTerminalOutput(windowId, `Removed: ${targetPath}`);
			}).catch(error => {
				this._appendTerminalOutput(windowId, `Error: ${error.message}`);
			});
		}
		
		/**
		 * Resolve a path in terminal
		 * @private
		 * @param {string} path - Path to resolve
		 * @param {string} currentDir - Current directory
		 * @returns {string} Resolved path
		 */
		_resolveTerminalPath(path, currentDir) {
			if (!path) return currentDir;
			
			// Handle home directory
			if (path === '~') {
				return `/users/${this.kernel.currentUser.username}`;
			}
			
			if (path.startsWith('~/')) {
				return `/users/${this.kernel.currentUser.username}${path.substring(1)}`;
			}
			
			// Absolute path
			if (path.startsWith('/')) {
				return path;
			}
			
			// Relative path
			return currentDir === '/' ? `/${path}` : `${currentDir}/${path}`;
		}
		
		_initializeCodeEditor(container, process, appInfo) {
			container.innerHTML = `
				<div class="editor">
					<div class="editor-toolbar">
						<button id="${process.windowId}-new" class="button">New</button>
						<button id="${process.windowId}-open" class="button">Open</button>
						<button id="${process.windowId}-save" class="button button-primary">Save</button>
						<span id="${process.windowId}-filename" style="margin-left: 20px; opacity: 0.7;">Untitled</span>
					</div>
					<div class="editor-content">
						<textarea id="${process.windowId}-editor" class="editor-textarea" spellcheck="false"></textarea>
					</div>
				</div>
			`;
			
			const state = { currentFile: null, modified: false };
			const editorTextarea = document.getElementById(`${process.windowId}-editor`);
			const filenameSpan = document.getElementById(`${process.windowId}-filename`);
			
			// --- SECURITY HELPER FUNCTION ---
			// This function checks if a given path is within the user's home directory.
			const isPathAllowed = (path) => {
				if (!path) return false;

				const user = this.kernel.currentUser;
				const isAdmin = user && user.roles.includes('admin');

				// Admins are always allowed full access.
				if (isAdmin) {
					return true;
				}

				// For standard users, the path must be within their home directory.
				const normalizedPath = this._normalizePath(path);
				const userHomeDir = `/users/${user.username}`;
				return normalizedPath.startsWith(userHomeDir);
			};
			// --- END SECURITY HELPER ---

			// ✅ NEW: Apply tab size from UI settings
			editorTextarea.style.tabSize = this.kernel.modules.ui.currentTabSize || 4;
			
			// Initialize event handlers
			document.getElementById(`${process.windowId}-new`).addEventListener('click', () => {
				if (state.modified && !confirm('You have unsaved changes. Discard them?')) {
					return;
				}
				
				document.getElementById(`${process.windowId}-editor`).value = '';
				document.getElementById(`${process.windowId}-filename`).textContent = 'Untitled';
				state.currentFile = null;
				state.modified = false;
			});
			
			// SECURED "OPEN" ACTION
			document.getElementById(`${process.windowId}-open`).addEventListener('click', () => {
				if (state.modified && !confirm('You have unsaved changes. Discard them?')) {
					return;
				}
				const path = prompt('Enter file path to open:', `/users/${this.kernel.currentUser.username}/`);
				if (!path) return;

				// --- SECURITY CHECK ---
				if (!isPathAllowed(path)) {
					this.kernel.modules.ui.showNotification('Access Denied', 'You can only open files from your home directory.', 4000);
					return;
				}
				// --- END CHECK ---

				this.kernel.modules.filesystem.readFile(path).then(result => {
					editorTextarea.value = result.content;
					filenameSpan.textContent = path;
					state.currentFile = path;
					state.modified = false;
				}).catch(error => {
					this.kernel.modules.ui.showNotification('Error', `Could not open file: ${error.message}`, 5000);
				});
			});
			
			// SECURED "SAVE" ACTION
			document.getElementById(`${process.windowId}-save`).addEventListener('click', () => {
				const content = editorTextarea.value;
				
				if (state.currentFile) {
					// A simple save on an already opened/allowed file does not need a new check.
					this.kernel.modules.filesystem.writeFile(state.currentFile, content).then(() => {
						this.kernel.modules.ui.showNotification('Saved', `File saved: ${state.currentFile}`, 2000);
						state.modified = false;
						filenameSpan.textContent = state.currentFile;
					}).catch(error => {
						this.kernel.modules.ui.showNotification('Error', `Could not save file: ${error.message}`, 5000);
					});
				} else {
					// "Save As..." prompts for a new path.
					const path = prompt('Enter file path to save:', `/users/${this.kernel.currentUser.username}/untitled.txt`);
					if (!path) return;

					// --- SECURITY CHECK ---
					if (!isPathAllowed(path)) {
						this.kernel.modules.ui.showNotification('Access Denied', 'You can only save files within your home directory.', 4000);
						return;
					}
					// --- END CHECK ---

					this.kernel.modules.filesystem.writeFile(path, content).then(() => {
						filenameSpan.textContent = path;
						state.currentFile = path;
						state.modified = false;
						this.kernel.modules.ui.showNotification('Saved', `File saved: ${path}`, 2000);
					}).catch(error => {
						this.kernel.modules.ui.showNotification('Error', `Could not save file: ${error.message}`, 5000);
					});
				}
			});

			// ✅ NEW: Enhanced keydown handler for Tab and Shift+Tab support
			editorTextarea.addEventListener('keydown', (e) => {
				if (e.key === 'Tab') {
					e.preventDefault();
					const tabSize = this.kernel.modules.ui.currentTabSize || 4;
					const tab = ' '.repeat(tabSize);
					const start = editorTextarea.selectionStart;
					const end = editorTextarea.selectionEnd;

					if (e.shiftKey) { // Handle outdenting (Shift+Tab)
						const lineStart = editorTextarea.value.lastIndexOf('\n', start - 1) + 1;
						if (editorTextarea.value.substring(lineStart, lineStart + tabSize) === tab) {
							editorTextarea.setRangeText('', lineStart, lineStart + tabSize, 'end');
						}
					} else { // Handle indenting (Tab)
						editorTextarea.setRangeText(tab, start, end, 'end');
					}
					state.modified = true;
					filenameSpan.textContent = state.currentFile ? `${state.currentFile} *` : 'Untitled *';
				}
			});
			
			// Track modifications
			document.getElementById(`${process.windowId}-editor`).addEventListener('input', () => {
				if (!state.modified) {
					state.modified = true;
					const filename = document.getElementById(`${process.windowId}-filename`);
					filename.textContent = `${filename.textContent} *`;
				}
			});
			
			// SECURED INITIAL FILE LOAD FROM LAUNCH PARAMETERS
			if (process.params.filePath) {
				// --- SECURITY CHECK ---
				if (!isPathAllowed(process.params.filePath)) {
					this.kernel.modules.ui.showNotification('Access Denied', 'Cannot open file outside your home directory.', 4000);
					filenameSpan.textContent = 'Access Denied';
					editorTextarea.value = '## ACCESS DENIED ##\n\nYou do not have permission to open this file.';
					editorTextarea.disabled = true;
					return;
				}
				// --- END CHECK ---

				this.kernel.modules.filesystem.readFile(process.params.filePath).then(result => {
					editorTextarea.value = result.content;
					filenameSpan.textContent = process.params.filePath;
					state.currentFile = process.params.filePath;
					state.modified = false;
				}).catch(error => {
					this.kernel.modules.ui.showNotification('Error', `Could not open file: ${error.message}`, 5000);
				});
			}
			
		}
		
		/**
		 * Initialize Settings application
		 * @private
		 */
		_initializeSettings(container, process, appInfo) {
			// ✅ MODIFIED: Add "Account" and "User Management" to the sidebar.
			let adminNav = '';
			if (this.kernel.modules.security.hasPermission('users.manage')) {
				adminNav = `<div class="settings-nav-item" data-section="user-management">User Management</div>`;
			}

			container.innerHTML = `
				<div class="settings">
					<div class="settings-sidebar">
						<div class="settings-nav-item active" data-section="account">My Account</div>
						<div class="settings-nav-item" data-section="appearance">Appearance</div>
						${adminNav}
						<div class="settings-nav-item" data-section="about">About</div>
					</div>
					<div id="${process.windowId}-settings-content" class="settings-content"></div>
				</div>
			`;

			this._loadSettingsSection(process.windowId, 'account');

			const sidebar = container.querySelector('.settings-sidebar');
			sidebar.addEventListener('click', (e) => {
				const item = e.target.closest('.settings-nav-item');
				if (item) {
					sidebar.querySelectorAll('.settings-nav-item').forEach(el => el.classList.remove('active'));
					item.classList.add('active');
					const section = item.getAttribute('data-section');
					this._loadSettingsSection(process.windowId, section);
				}
			});
		}
		
		/**
		 * Load a settings section
		 * @private
		 * @param {string} windowId - Window ID
		 * @param {string} section - Section name
		 */
		_loadSettingsSection(windowId, section) {
			const content = document.getElementById(`${windowId}-settings-content`);
			
			switch (section) {
				// ✅ NEW: "My Account" section for changing password.
				case 'account':
					const currentUser = this.kernel.currentUser;
					content.innerHTML = `
						<div class="settings-section">
							<h2 class="settings-section-title">My Account</h2>
							<p>Username: ${currentUser.username}</p>
							<p>Name: ${currentUser.name}</p>
							<p>Roles: ${currentUser.roles.join(', ')}</p>
						</div>
						<div class="settings-section">
							<h3 class="settings-section-title">Change Password</h3>
							<div class="form-group">
								<label for="current-password">Current Password</label>
								<input type="password" id="current-password">
							</div>
							<div class="form-group">
								<label for="new-password">New Password</label>
								<input type="password" id="new-password">
							</div>
							<button id="change-password-btn" class="button button-primary">Update Password</button>
						</div>
					`;
					document.getElementById('change-password-btn').onclick = async () => {
						const currentPassword = document.getElementById('current-password').value;
						const newPassword = document.getElementById('new-password').value;
						try {
							await this.kernel.api.users.call('setPassword', { username: currentUser.username, currentPassword, newPassword });
							this.kernel.modules.ui.showNotification('Success', 'Password updated successfully.');
							document.getElementById('current-password').value = '';
							document.getElementById('new-password').value = '';
						} catch(e) {
							this.kernel.modules.ui.showNotification('Error', e.message, 5000);
						}
					};
					break;
					
				// ✅ NEW: "User Management" section for admins.
				case 'user-management':
					content.innerHTML = `<h2 class="settings-section-title">User Management</h2><div id="user-list">Loading...</div>`;
					this._renderUserManagement(windowId);
					break;
					
				case 'appearance':
					content.innerHTML = `
						<div class="settings-section">
							<h2 class="settings-section-title">Appearance Settings</h2>
							
							<div class="settings-option">
								<label class="settings-label">Theme</label>
								<select id="${windowId}-theme" class="settings-select">
									<option value="vintage">Vintage</option>
									<option value="dark">Dark</option>
									<option value="light">Light</option>
									<option value="blue">Blue</option>
								</select>
							</div>
							
							<div class="settings-option">
								<label class="settings-label">Font Size</label>
								<select id="${windowId}-font-size" class="settings-select">
									<option value="small">Small</option>
									<option value="medium">Medium</option>
									<option value="large">Large</option>
								</select>
							</div>
							
							<div class="settings-option">
								<label class="settings-label">
									<input type="checkbox" id="${windowId}-animations" class="settings-checkbox">
									Enable animations
								</label>
							</div>
							
							<div class="settings-option">
								<label class="settings-label">
									<input type="checkbox" id="${windowId}-crt-effects" class="settings-checkbox">
									Enable CRT effects
								</label>
							</div>
							
							<div class="settings-option">
								<button id="${windowId}-save-appearance" class="button button-primary">Save Changes</button>
								<button id="${windowId}-reset-appearance" class="button">Reset to Defaults</button>
							</div>
						</div>
					`;
					
					// Set current values
					document.getElementById(`${windowId}-theme`).value = this.kernel.modules.ui.currentTheme || 'vintage';
					document.getElementById(`${windowId}-font-size`).value = this.kernel.modules.ui.currentFontSize || 'medium';
					document.getElementById(`${windowId}-animations`).checked = this.kernel.modules.ui.animations !== false;
					document.getElementById(`${windowId}-crt-effects`).checked = this.kernel.modules.ui.crtEffects === true;
					
					// Save button handler
					document.getElementById(`${windowId}-save-appearance`).addEventListener('click', () => {
						const theme = document.getElementById(`${windowId}-theme`).value;
						const fontSize = document.getElementById(`${windowId}-font-size`).value;
						const animations = document.getElementById(`${windowId}-animations`).checked;
						const crtEffects = document.getElementById(`${windowId}-crt-effects`).checked;
						
						// Apply changes
						this.kernel.modules.ui.applyTheme(theme);
						this.kernel.modules.ui.applyFontSize(fontSize);
						this.kernel.modules.ui.setAnimations(animations);
						this.kernel.modules.ui.setCrtEffects(crtEffects);
						
						// Show notification
						this.kernel.modules.ui.showNotification('Settings', 'Appearance settings saved', 2000);
					});
					
					// Reset button handler
					document.getElementById(`${windowId}-reset-appearance`).addEventListener('click', () => {
						document.getElementById(`${windowId}-theme`).value = 'vintage';
						document.getElementById(`${windowId}-font-size`).value = 'medium';
						document.getElementById(`${windowId}-animations`).checked = true;
						document.getElementById(`${windowId}-crt-effects`).checked = false;
					});
					break;
					
				case 'system':
					content.innerHTML = `
						<div class="settings-section">
							<h2 class="settings-section-title">System Settings</h2>
							
							<div class="settings-option">
								<label class="settings-label">
									<input type="checkbox" id="${windowId}-debug" class="settings-checkbox">
									Enable debug mode
								</label>
							</div>
							
							<div class="settings-option">
								<button id="${windowId}-save-system" class="button button-primary">Save Changes</button>
							</div>
							
							<div class="settings-section" style="margin-top: 30px;">
								<h3 class="settings-section-title">System Maintenance</h3>
								
								<div class="settings-option">
									<button id="${windowId}-clear-storage" class="button">Clear Local Storage</button>
								</div>
								
								<div class="settings-option">
									<button id="${windowId}-restart" class="button">Restart System</button>
								</div>
							</div>
						</div>
					`;
					
					// Set current values
					document.getElementById(`${windowId}-debug`).checked = this.kernel.state.debug;
					
					// Save button handler
					document.getElementById(`${windowId}-save-system`).addEventListener('click', () => {
						const debug = document.getElementById(`${windowId}-debug`).checked;
						
						// Apply changes
						this.kernel.state.debug = debug;
						
						// Show notification
						this.kernel.modules.ui.showNotification('Settings', 'System settings saved', 2000);
					});
					
					// Clear storage button handler
					document.getElementById(`${windowId}-clear-storage`).addEventListener('click', () => {
						if (confirm('This will clear all stored data. Are you sure?')) {
							localStorage.clear();
							this.kernel.modules.ui.showNotification('System', 'Local storage cleared', 2000);
						}
					});
					
					// Restart button handler
					document.getElementById(`${windowId}-restart`).addEventListener('click', () => {
						if (confirm('Are you sure you want to restart the system?')) {
							window.location.reload();
						}
					});
					break;

				case 'about':
						const systemInfo = this.kernel.getSystemInfo();

						content.innerHTML = `
								<div class="settings-section">
										<h2 class="settings-section-title">About Genesis OS</h2>

										<div style="text-align: center; margin-bottom: 20px;">
												<div style="font-size: 72px; margin-bottom: 10px;">G</div>
												<h3>Genesis Operating System</h3>
												<div>Version ${systemInfo.version} (${systemInfo.buildDate})</div>
										</div>

										<div class="settings-option">
												<label class="settings-label">System Information</label>
												<div style="background-color: var(--terminal-bg); padding: 15px; border-radius: 4px;">
														<div>Version: ${systemInfo.version}</div>
														<div>Build Date: ${systemInfo.buildDate}</div>
														<div>Code Name: ${systemInfo.codename}</div>
														<div>Uptime: ${Math.floor(systemInfo.uptime / 60)} minutes, ${systemInfo.uptime % 60} seconds</div>
														<div>User: ${systemInfo.user ? systemInfo.user.username : 'Not logged in'}</div>
														<div>Filesystem: ${systemInfo.useLocalFilesystem ? 'Server-based' : 'LocalStorage-based'}</div>
												</div>
										</div>

										<div class="settings-option" style="margin-top: 20px;">
												<label class="settings-label">Credits</label>
												<div style="background-color: var(--terminal-bg); padding: 10px; border-radius: 4px; font-size: 13px;">
														Genesis OS is an open-source experiment demonstrating a monolithic browser OS.<br>
														Created by the Genesis OS Team.
												</div>
										</div>

										<div class="settings-option" style="margin-top: 20px;">
												<label class="settings-label">Change Log</label>
												<div style="background-color: var(--terminal-bg); padding: 10px; border-radius: 4px; font-size: 13px; max-height:150px; overflow:auto;">
														<ul>
																<li>v1.0.0 - Initial release</li>
																<li>v1.0.1 - Minor fixes and improvements</li>
														</ul>
												</div>
										</div>

										<div class="settings-option" style="margin-top: 20px;">
												<div style="text-align: center; opacity: 0.7;">
														© 2025 Genesis OS Team<br>
														All rights reserved.
												</div>
										</div>
								</div>
						`;
						break;
					
				default:
					content.innerHTML = `
						<div class="settings-section">
							<h2 class="settings-section-title">${section.charAt(0).toUpperCase() + section.slice(1)} Settings</h2>
							<p>This section is not yet implemented.</p>
						</div>
					`;
			}
		}

		/**
		 * Initializes the App Store application UI.
		 * This updated version includes a "Submissions" tab for administrators.
		 * @param {HTMLElement} container - The content container of the app window.
		 * @param {object} process - The process object from the kernel.
		 * @param {object} options - Launch options.
		 */
		_initializeAppStore(container, process, options) {
			// --- 1. Basic UI Structure & Styles ---
			container.innerHTML = `
				<style>
					.appstore-container { display: flex; flex-direction: column; height: 100%; background-color: var(--window-bg); color: var(--text-color); }
					.appstore-nav { display: flex; border-bottom: 1px solid var(--border-color); padding: 0 10px; }
					.appstore-tab { padding: 10px 15px; cursor: pointer; border-bottom: 2px solid transparent; }
					.appstore-tab.active { border-bottom-color: var(--accent-color); font-weight: bold; }
					.appstore-content { flex-grow: 1; overflow-y: auto; }
					.appstore-pane { display: none; padding: 20px; }
					.appstore-pane.active { display: block; }
					.app-grid, .submission-list { display: grid; gap: 20px; }
					.app-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
					.app-card, .submission-card { background-color: var(--input-bg); border: 1px solid var(--border-color); border-radius: 5px; padding: 15px; text-align: center; }
					.submission-card { text-align: left; }
					.submission-card h4 { margin: 0 0 5px 0; }
					.submission-details { margin-top: 15px; display: flex; gap: 10px; }
					.submission-actions { margin-top: 15px; display: flex; gap: 10px; }
					.submission-actions button, .submission-details button { padding: 5px 10px; cursor: pointer; border: 1px solid var(--main-border); border-radius: 3px; background: var(--button-bg); color: var(--button-text); }
					.approve-btn { background-color: #28a745 !important; color: white !important; border-color: #28a745 !important; }
					.reject-btn { background-color: #dc3545 !important; color: white !important; border-color: #dc3545 !important; }
				</style>
				<div class="appstore-container">
					<nav class="appstore-nav">
						<div class="appstore-tab active" data-pane="browse-pane">Browse</div>
					</nav>
					<div class="appstore-content">
						<div id="browse-pane" class="appstore-pane active">
							<div class="app-grid">Loading applications...</div>
						</div>
						<div id="submissions-pane" class="appstore-pane">
							<div class="submission-list">Loading submissions...</div>
						</div>
					</div>
				</div>
			`;

			const nav = container.querySelector('.appstore-nav');
			const panes = container.querySelectorAll('.appstore-pane');
			let submissionsCache = [];

			// --- 2. Tab Switching Logic ---
			const _switchTab = (tabEl) => {
				container.querySelectorAll('.appstore-tab').forEach(t => t.classList.remove('active'));
				panes.forEach(p => p.classList.remove('active'));
				tabEl.classList.add('active');
				container.querySelector(`#${tabEl.dataset.pane}`).classList.add('active');
			};

			// --- 3. Core Rendering Functions ---
			const _renderApps = () => {
				const grid = container.querySelector('#browse-pane .app-grid');
				grid.innerHTML = 'Loading applications...';
				this.kernel.api.apps.call('listApps')
					.then(apps => {
						grid.innerHTML = '';
						if (apps.length === 0) {
							grid.innerHTML = '<p>No applications installed.</p>';
							return;
						}
						apps.forEach(app => {
							const card = document.createElement('div');
							card.className = 'app-card';
							card.innerHTML = `<div class="icon">${app.icon || '📦'}</div><h4>${app.title}</h4><p>${app.description}</p>`;
							card.onclick = () => this.kernel.launchApplication(app.id);
							grid.appendChild(card);
						});
					})
					.catch(err => {
						grid.innerHTML = `<p style="color:red;">Error loading apps: ${err.message}</p>`;
					});
			};

			const _renderSubmissions = () => {
				const list = container.querySelector('#submissions-pane .submission-list');
				list.innerHTML = 'Loading submissions...';
				this.kernel.api.apps.call('listSubmissions')
					.then(submissions => {
						submissionsCache = submissions;
						list.innerHTML = '';
						if (submissions.length === 0) {
							list.innerHTML = '<p>No pending submissions.</p>';
							return;
						}
						submissions.forEach(sub => {
							const card = document.createElement('div');
							card.className = 'submission-card';
							card.dataset.id = sub.manifest.id;
							card.innerHTML = `
								<h4>${sub.manifest.title} <small>(v${sub.manifest.version})</small></h4>
								<p><strong>ID:</strong> ${sub.manifest.id}</p>
								<p>${sub.manifest.description || 'No description provided.'}</p>
								<p><small>Submitted by: ${sub.submitted_by} on ${new Date(sub.submitted_at).toLocaleString()}</small></p>
								<div class="submission-details">
									<button class="button" data-action="view-manifest">View Manifest</button>
									<button class="button" data-action="view-code">View Code</button>
								</div>
								<div class="submission-actions">
									<button class="approve-btn" data-action="approve">Approve</button>
									<button class="reject-btn" data-action="reject">Reject</button>
								</div>
							`;
							list.appendChild(card);
						});
					})
					.catch(err => {
						list.innerHTML = `<p style="color:red;">Error loading submissions: ${err.message}</p>`;
					});
			};
			
			const showCodeDialog = (title, content) => {
				const pre = document.createElement('pre');
				pre.style.whiteSpace = 'pre-wrap';
				pre.style.wordBreak = 'break-all';
				pre.style.maxHeight = '400px';
				pre.style.overflowY = 'auto';
				pre.style.background = 'var(--terminal-bg)';
				pre.style.padding = '10px';
				pre.style.border = '1px solid var(--main-border)';
				pre.style.borderRadius = '3px';
				pre.textContent = content;

				this.kernel.modules.ui.showDialog(title, pre.outerHTML, { buttons: ['Copy', 'Close'] })
					.then(result => {
						if (result.button === 'Copy') {
							navigator.clipboard.writeText(content).then(() => {
								this.kernel.modules.ui.showNotification('Success', 'Content copied to clipboard.');
							}).catch(err => {
								this.kernel.modules.ui.showNotification('Error', 'Failed to copy content.', 5000);
							});
						}
					});
			};

			// --- 4. Admin-Specific UI and Event Handling ---
			if (this.kernel.modules.security && this.kernel.modules.security.isAdmin()) {
				const submissionsTab = document.createElement('div');
				submissionsTab.className = 'appstore-tab';
				submissionsTab.dataset.pane = 'submissions-pane';
				submissionsTab.textContent = 'Submissions';
				nav.appendChild(submissionsTab);
			}

			container.querySelectorAll('.appstore-tab').forEach(tab => {
				tab.addEventListener('click', () => {
					_switchTab(tab);
					if (tab.dataset.pane === 'submissions-pane') {
						_renderSubmissions();
					}
				});
			});

			container.querySelector('#submissions-pane').addEventListener('click', e => {
				const target = e.target;
				const action = target.dataset.action;
				const card = target.closest('.submission-card');
				if (!action || !card) return;

				const appId = card.dataset.id;
				const submission = submissionsCache.find(s => s.manifest.id === appId);
				if (!submission) {
					this.kernel.modules.ui.showNotification('Error', 'Could not find submission data. Please refresh.', 5000);
					return;
				}

				switch (action) {
					case 'approve':
						this.kernel.api.apps.call('approveSubmission', { id: appId })
							.then(() => {
								this.kernel.modules.ui.showNotification('Success', `App '${appId}' approved.`);
								_renderSubmissions();
								_renderApps();
							})
							.catch(err => this.kernel.modules.ui.showNotification('Error', err.message, 5000));
						break;
					case 'reject':
						this.kernel.api.apps.call('rejectSubmission', { id: appId })
							.then(() => {
								this.kernel.modules.ui.showNotification('Success', `App '${appId}' rejected.`);
								_renderSubmissions();
							})
							.catch(err => this.kernel.modules.ui.showNotification('Error', err.message, 5000));
						break;
					case 'view-manifest':
						showCodeDialog('Manifest: ' + submission.manifest.title, JSON.stringify(submission.manifest, null, 2));
						break;
					case 'view-code':
						showCodeDialog('Code: ' + submission.manifest.title, submission.code);
						break;
				}
			});

			// --- 5. Initial Load ---
			_renderApps();
		}



		/**
		 * Load featured apps for the App Store
		 * @private
		 * @param {string} windowId - Window ID
		 */
		_loadFeaturedApps(windowId) {
			const contentDiv = document.getElementById(`${windowId}-content-featured`);
			
			// Fetch featured apps from local storage or server
			// For now, we'll use some demo data
			const featuredApps = [
				{
					id: 'calculator',
					title: 'Scientific Calculator',
					description: 'Advanced calculator with scientific functions',
					icon: '🧮',
					author: 'GOS Team',
					version: '1.0.0',
					category: 'Utilities',
					featured: true,
					banner: '🧪 Featured App'
				},
				{
					id: 'notes',
					title: 'Quick Notes',
					description: 'Simple note-taking application with markdown support',
					icon: '📝',
					author: 'GOS Team',
					version: '1.0.0',
					category: 'Productivity',
					featured: true,
					banner: '📌 Staff Pick'
				}
			];
			
			// Create HTML content
			let html = `
				<div class="featured-banner" style="background: linear-gradient(135deg, var(--highlight), var(--main-border)); color: var(--main-bg); padding: 20px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
					<h2 style="margin-top: 0;">Welcome to the Genesis OS App Store</h2>
					<p>Discover apps, share your creations, and join the community</p>
				</div>
				
				<h3>Featured Apps</h3>
				<div class="app-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
			`;
			
			// Add app cards for featured apps
			featuredApps.forEach(app => {
				html += this._createAppCard(app);
			});
			
			html += `
				</div>
				
				<div style="margin-top: 30px; text-align: center;">
					<h3>Develop Your Own Apps</h3>
					<p>Create apps using the built-in development tools and share them with the community</p>
					<button id="${windowId}-goto-develop" class="button button-primary" style="margin-top: 10px;">Start Developing</button>
				</div>
			`;
			
			// Set content
			contentDiv.innerHTML = html;
			
			// Add event listeners - AFTER the HTML has been added to DOM
			// Use requestAnimationFrame to ensure DOM is updated
			requestAnimationFrame(() => {
				// First check if the develop button exists
				const developButton = document.getElementById(`${windowId}-goto-develop`);
				if (developButton) {
					developButton.addEventListener('click', () => {
						// Switch to develop tab
						const developTab = document.getElementById(`${windowId}-tab-develop`);
						if (developTab) {
							developTab.click();
						}
					});
				}
				
				// Add event listeners for app buttons
				featuredApps.forEach(app => {
					const installButton = document.getElementById(`${windowId}-install-${app.id}`);
					if (installButton) {
						installButton.addEventListener('click', (e) => {
							e.stopPropagation();
							this._installApp(app);
						});
					}
					
					const appCard = document.getElementById(`${windowId}-app-${app.id}`);
					if (appCard) {
						appCard.addEventListener('click', () => {
							this._showAppDetails(app, windowId);
						});
					}
				});
			});
		}

		/**
		 * Load all apps for the App Store
		 * @private
		 * @param {string} windowId - Window ID
		 */
		_loadAllApps(windowId) {
			const contentDiv = document.getElementById(`${windowId}-content-all`);
			if (!contentDiv) {
				console.error(`Content div not found: ${windowId}-content-all`);
				return;
			}
			
			// Fetch all apps from local storage or server
			// For now, we'll use some demo data
			const allApps = [
				{
					id: 'calculator',
					title: 'Scientific Calculator',
					description: 'Advanced calculator with scientific functions',
					icon: '🧮',
					author: 'GOS Team',
					version: '1.0.0',
					category: 'Utilities'
				},
				{
					id: 'notes',
					title: 'Quick Notes',
					description: 'Simple note-taking application with markdown support',
					icon: '📝',
					author: 'GOS Team',
					version: '1.0.0',
					category: 'Productivity'
				},
				{
					id: 'calendar',
					title: 'Calendar',
					description: 'Schedule and manage your events',
					icon: '📅',
					author: 'GOS Team',
					version: '1.0.0',
					category: 'Productivity'
				},
				{
					id: 'weather',
					title: 'Weather',
					description: 'Check the weather in your area',
					icon: '🌤️',
					author: 'GOS Team',
					version: '1.0.0',
					category: 'Utilities'
				},
				{
					id: 'games',
					title: 'Game Collection',
					description: 'A collection of simple games',
					icon: '🎮',
					author: 'Community',
					version: '1.0.0',
					category: 'Games'
				}
			];
			
			// Create HTML content
			let html = `
				<h3>Browse All Apps</h3>
				
				<div class="category-filter" style="margin-bottom: 20px;">
					<label>Category: </label>
					<select id="${windowId}-category-filter" class="settings-select" style="width: auto; display: inline-block;">
						<option value="All">All Categories</option>
						<option value="Productivity">Productivity</option>
						<option value="Development">Development</option>
						<option value="Science">Science</option>
						<option value="System">System</option>
						<option value="Games">Games</option>
						<option value="Utilities">Utilities</option>
					</select>
				</div>
				
				<div id="${windowId}-app-grid" class="app-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
			`;
			
			// Add app cards for all apps
			allApps.forEach(app => {
				html += this._createAppCard(app);
			});
			
			html += `</div>`;
			
			// Set content first
			contentDiv.innerHTML = html;
			
			// Use requestAnimationFrame to ensure DOM is updated before adding event listeners
			requestAnimationFrame(() => {
				// Add event listener for category filter
				const categoryFilter = document.getElementById(`${windowId}-category-filter`);
				if (categoryFilter) {
					categoryFilter.addEventListener('change', (e) => {
						const category = e.target.value;
						
						// Filter apps by category
						const filteredApps = category === 'All' 
							? allApps 
							: allApps.filter(app => app.category === category);
						
						// Update app grid
						const appGrid = document.getElementById(`${windowId}-app-grid`);
						if (!appGrid) return;
						
						appGrid.innerHTML = '';
						
						// Add filtered apps to the grid
						filteredApps.forEach(app => {
							appGrid.innerHTML += this._createAppCard(app);
						});
						
						// Re-add event listeners after updating the grid content
						// Need to use another requestAnimationFrame to ensure DOM is updated
						requestAnimationFrame(() => {
							// Add event listeners for newly created buttons
							filteredApps.forEach(app => {
								const installButton = document.getElementById(`${windowId}-install-${app.id}`);
								if (installButton) {
									installButton.addEventListener('click', (e) => {
										e.stopPropagation();
										this._installApp(app);
									});
								}
								
								const appCard = document.getElementById(`${windowId}-app-${app.id}`);
								if (appCard) {
									appCard.addEventListener('click', () => {
										this._showAppDetails(app, windowId);
									});
								}
							});
						});
					});
				}
				
				// Add event listeners for install buttons and app cards
				allApps.forEach(app => {
					const installButton = document.getElementById(`${windowId}-install-${app.id}`);
					if (installButton) {
						installButton.addEventListener('click', (e) => {
							e.stopPropagation();
							this._installApp(app);
						});
					}
					
					const appCard = document.getElementById(`${windowId}-app-${app.id}`);
					if (appCard) {
						appCard.addEventListener('click', () => {
							this._showAppDetails(app, windowId);
						});
					}
				});
			});
		}

		/**
		 * Load installed apps for the App Store
		 * @private
		 * @param {string} windowId - Window ID
		 */
		_loadInstalledApps(windowId) {
			const contentDiv = document.getElementById(`${windowId}-content-installed`);
			if (!contentDiv) {
				console.error(`Content div not found: ${windowId}-content-installed`);
				return;
			}
			
			// Get installed apps from local storage
			const installedApps = JSON.parse(localStorage.getItem('gos_installed_apps') || '[]');
			
			// Create HTML content
			let html = `
				<h3>My Installed Apps</h3>
			`;
			
			if (installedApps.length === 0) {
				html += `
					<div style="text-align: center; padding: 40px; opacity: 0.7;">
						<p>You haven't installed any apps yet</p>
						<button id="${windowId}-browse-apps" class="button button-primary" style="margin-top: 10px;">Browse Apps</button>
					</div>
				`;
			} else {
				html += `
					<div class="app-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
				`;
				
				// Add app cards for installed apps
				installedApps.forEach(app => {
					html += this._createAppCard(app, true);
				});
				
				html += `</div>`;
			}
			
			// Set content first
			contentDiv.innerHTML = html;
			
			// Use requestAnimationFrame to ensure DOM is updated before adding event listeners
			requestAnimationFrame(() => {
				if (installedApps.length === 0) {
					// Handle the empty state with "Browse Apps" button
					const browseAppsButton = document.getElementById(`${windowId}-browse-apps`);
					if (browseAppsButton) {
						browseAppsButton.addEventListener('click', () => {
							// Switch to all apps tab
							const allAppsTab = document.getElementById(`${windowId}-tab-all`);
							if (allAppsTab) {
								allAppsTab.click();
							} else {
								console.warn(`All apps tab element not found: ${windowId}-tab-all`);
							}
						});
					}
				} else {
					// Add event listeners for app cards and uninstall buttons
					installedApps.forEach(app => {
						// Add event listener for uninstall button
						const uninstallButton = document.getElementById(`${windowId}-uninstall-${app.id}`);
						if (uninstallButton) {
							uninstallButton.addEventListener('click', (e) => {
								e.stopPropagation(); // Prevent triggering the app card click
								this._uninstallApp(app.id, windowId);
							});
						}
						
						// Add event listener for app card click
						const appCard = document.getElementById(`${windowId}-app-${app.id}`);
						if (appCard) {
							appCard.addEventListener('click', () => {
								this._showAppDetails(app, windowId);
							});
						}
					});
				}
			});
		}

		/**
		 * Create an app card HTML
		 * @private
		 * @param {Object} app - App data
		 * @param {boolean} [installed=false] - Whether the app is installed
		 * @returns {string} App card HTML
		 */
		_createAppCard(app, installed = false) {
			// Create banner HTML if app has a banner
			const bannerHtml = app.banner 
				? `<div style="background: var(--highlight); color: var(--main-bg); padding: 5px 15px; font-size: 12px;">${app.banner}</div>` 
				: '';
			
			// Create button HTML based on installed status
			const buttonHtml = installed
				? `<button id="${this.kernel.modules.process.windowId}-uninstall-${app.id}" class="button">Uninstall</button>`
				: `<button id="${this.kernel.modules.process.windowId}-install-${app.id}" class="button button-primary">Install</button>`;
			
			return `
				<div id="${this.kernel.modules.process.windowId}-app-${app.id}" class="app-card" style="background-color: var(--terminal-bg); border-radius: 8px; overflow: hidden; cursor: pointer; transition: transform 0.2s; height: 100%;">
					${bannerHtml}
					<div style="padding: 20px;">
						<div style="display: flex; align-items: center; margin-bottom: 15px;">
							<div style="font-size: 32px; margin-right: 15px;">${app.icon}</div>
							<div>
								<h3 style="margin: 0 0 5px 0;">${app.title}</h3>
								<div style="font-size: 12px; opacity: 0.7;">by ${app.author} | v${app.version}</div>
							</div>
						</div>
						
						<p style="margin-bottom: 15px; height: 40px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${app.description}</p>
						
						<div style="display: flex; justify-content: space-between; align-items: center;">
							<div style="font-size: 12px; background-color: rgba(var(--highlight), 0.1); padding: 3px 8px; border-radius: 12px;">${app.category}</div>
							${buttonHtml}
						</div>
					</div>
				</div>
			`;
		}

		/**
		 * Initialize the develop tab for the App Store
		 * @private
		 * @param {string} windowId - Window ID
		 */
		_initializeDevelopTab(windowId) {
			const contentDiv = document.getElementById(`${windowId}-content-develop`);
			if (!contentDiv) {
				console.error(`Content div not found: ${windowId}-content-develop`);
				return;
			}
			
			// Default code template with proper escaping
			const defaultCode = `{
	  // App initialization code
	  initialize: function(container, process, appInfo) {
		// Create app UI
		container.innerHTML = \`
		  <div style="padding: 20px; text-align: center;">
			<h2>\${appInfo.title}</h2>
			<p>Hello, World!</p>
		  </div>
		\`;
		
		// Add your app logic here
	  }
	}`;
			
			// Create HTML content
			contentDiv.innerHTML = `
				<h3>Develop Your Own App</h3>
				
				<div class="app-development-container" style="display: flex; flex-direction: column; gap: 20px;">
					<div class="app-metadata" style="background-color: var(--terminal-bg); padding: 20px; border-radius: 8px;">
						<h4 style="margin-top: 0;">App Metadata</h4>
						
						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
							<div class="settings-option">
								<label class="settings-label">App ID (unique, no spaces)</label>
								<input type="text" id="${windowId}-app-id" class="settings-input" placeholder="my-awesome-app">
							</div>
							
							<div class="settings-option">
								<label class="settings-label">App Title</label>
								<input type="text" id="${windowId}-app-title" class="settings-input" placeholder="My Awesome App">
							</div>
							
							<div class="settings-option">
								<label class="settings-label">Icon (emoji)</label>
								<input type="text" id="${windowId}-app-icon" class="settings-input" placeholder="🚀">
							</div>
							
							<div class="settings-option">
								<label class="settings-label">Category</label>
								<select id="${windowId}-app-category" class="settings-select">
									<option value="Productivity">Productivity</option>
									<option value="Development">Development</option>
									<option value="Science">Science</option>
									<option value="System">System</option>
									<option value="Games">Games</option>
									<option value="Utilities">Utilities</option>
								</select>
							</div>
							
							<div class="settings-option">
								<label class="settings-label">Author</label>
								<input type="text" id="${windowId}-app-author" class="settings-input" placeholder="Your Name">
							</div>
							
							<div class="settings-option">
								<label class="settings-label">Version</label>
								<input type="text" id="${windowId}-app-version" class="settings-input" placeholder="1.0.0" value="1.0.0">
							</div>
						</div>
						
						<div class="settings-option" style="margin-top: 15px;">
							<label class="settings-label">Description</label>
							<textarea id="${windowId}-app-description" class="settings-input" style="height: 80px; resize: vertical;" placeholder="Describe your app here..."></textarea>
						</div>
						
						<div class="settings-option" style="margin-top: 15px;">
							<label class="settings-label">Required Permissions</label>
							<div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 5px;">
								<label style="display: flex; align-items: center;">
									<input type="checkbox" id="${windowId}-perm-filesystem" checked>
									<span style="margin-left: 5px;">Filesystem Access</span>
								</label>
								<label style="display: flex; align-items: center;">
									<input type="checkbox" id="${windowId}-perm-network">
									<span style="margin-left: 5px;">Network Access</span>
								</label>
								<label style="display: flex; align-items: center;">
									<input type="checkbox" id="${windowId}-perm-system">
									<span style="margin-left: 5px;">System Access</span>
								</label>
							</div>
						</div>
					</div>
					
					<div class="app-code" style="background-color: var(--terminal-bg); padding: 20px; border-radius: 8px;">
						<h4 style="margin-top: 0;">App Code</h4>
						<p style="opacity: 0.7; margin-bottom: 15px;">Write your app code in JavaScript. This code will be executed when your app is launched.</p>
						
						<textarea id="${windowId}-app-code" style="width: 100%; height: 300px; background-color: var(--terminal-bg); color: var(--main-text); border: 1px solid var(--main-border); font-family: 'Courier New', monospace; font-size: 14px; padding: 10px; resize: vertical; tab-size: 2;"></textarea>
					</div>
					
					<div class="app-actions" style="display: flex; gap: 10px; justify-content: space-between;">
						<div>
							<button id="${windowId}-clear-app" class="button">Clear Form</button>
							<button id="${windowId}-test-app" class="button">Test App</button>
						</div>
						<div>
							<button id="${windowId}-export-app" class="button">Export as JSON</button>
							<button id="${windowId}-save-app" class="button button-primary">Save App</button>
						</div>
					</div>
					
					<div class="app-json-preview" style="background-color: var(--terminal-bg); padding: 20px; border-radius: 8px; display: none;">
						<h4 style="margin-top: 0;">App JSON Preview</h4>
						<pre id="${windowId}-app-json" style="white-space: pre-wrap; word-break: break-all; overflow-x: auto; background-color: var(--terminal-bg); padding: 10px; border: 1px solid var(--main-border); border-radius: 4px; font-family: 'Courier New', monospace; font-size: 12px;"></pre>
						
						<div style="margin-top: 15px;">
							<button id="${windowId}-copy-json" class="button">Copy JSON</button>
							<button id="${windowId}-close-preview" class="button">Close Preview</button>
						</div>
					</div>
					
					<div class="app-import" style="background-color: var(--terminal-bg); padding: 20px; border-radius: 8px;">
						<h4 style="margin-top: 0;">Import App from JSON</h4>
						<p style="opacity: 0.7; margin-bottom: 15px;">Paste the app JSON here to import an existing app.</p>
						
						<textarea id="${windowId}-import-json" style="width: 100%; height: 100px; background-color: var(--terminal-bg); color: var(--main-text); border: 1px solid var(--main-border); font-family: 'Courier New', monospace; font-size: 14px; padding: 10px; resize: vertical;" placeholder='{"id": "app-id", "title": "App Title", ...}'></textarea>
						
						<div style="margin-top: 10px;">
							<button id="${windowId}-import-app" class="button">Import App</button>
						</div>
					</div>
				</div>
			`;
			
			// Use requestAnimationFrame to ensure DOM is updated before interacting with elements
			requestAnimationFrame(() => {
				// Set default code after HTML is in DOM
				const codeTextarea = document.getElementById(`${windowId}-app-code`);
				if (codeTextarea) {
					codeTextarea.value = defaultCode;
				}
				
				// Add event listeners for each button
				const clearAppBtn = document.getElementById(`${windowId}-clear-app`);
				if (clearAppBtn) {
					clearAppBtn.addEventListener('click', () => {
						// Get form elements with null checks
						const appIdInput = document.getElementById(`${windowId}-app-id`);
						const appTitleInput = document.getElementById(`${windowId}-app-title`);
						const appIconInput = document.getElementById(`${windowId}-app-icon`);
						const appDescInput = document.getElementById(`${windowId}-app-description`);
						const appAuthorInput = document.getElementById(`${windowId}-app-author`);
						const appVersionInput = document.getElementById(`${windowId}-app-version`);
						const appCodeTextarea = document.getElementById(`${windowId}-app-code`);
						
						// Clear form if elements exist
						if (appIdInput) appIdInput.value = '';
						if (appTitleInput) appTitleInput.value = '';
						if (appIconInput) appIconInput.value = '';
						if (appDescInput) appDescInput.value = '';
						if (appAuthorInput) appAuthorInput.value = '';
						if (appVersionInput) appVersionInput.value = '1.0.0';
						if (appCodeTextarea) appCodeTextarea.value = defaultCode;
					});
				}
				
				const testAppBtn = document.getElementById(`${windowId}-test-app`);
				if (testAppBtn) {
					testAppBtn.addEventListener('click', () => {
						// Validate form
						const appIdInput = document.getElementById(`${windowId}-app-id`);
						const appTitleInput = document.getElementById(`${windowId}-app-title`);
						
						if (!appIdInput || !appTitleInput) {
							console.error('Required form elements not found');
							return;
						}
						
						const appId = appIdInput.value;
						const appTitle = appTitleInput.value;
						
						if (!appId || !appTitle) {
							this.kernel.modules.ui.showNotification('Error', 'App ID and Title are required', 3000);
							return;
						}
						
						// Get app data
						const appData = this._getAppDataFromForm(windowId);
						if (!appData) {
							this.kernel.modules.ui.showNotification('Error', 'Failed to get app data', 3000);
							return;
						}
						
						// Show notification
						this.kernel.modules.ui.showNotification('Testing', `Testing app: ${appTitle}`, 2000);
					});
				}
				
				const exportAppBtn = document.getElementById(`${windowId}-export-app`);
				if (exportAppBtn) {
					exportAppBtn.addEventListener('click', () => {
						// Validate form
						const appIdInput = document.getElementById(`${windowId}-app-id`);
						const appTitleInput = document.getElementById(`${windowId}-app-title`);
						
						if (!appIdInput || !appTitleInput) {
							console.error('Required form elements not found');
							return;
						}
						
						const appId = appIdInput.value;
						const appTitle = appTitleInput.value;
						
						if (!appId || !appTitle) {
							this.kernel.modules.ui.showNotification('Error', 'App ID and Title are required', 3000);
							return;
						}
						
						// Get app data
						const appData = this._getAppDataFromForm(windowId);
						if (!appData) {
							this.kernel.modules.ui.showNotification('Error', 'Failed to get app data', 3000);
							return;
						}
						
						// Show JSON preview
						const jsonPreElement = document.getElementById(`${windowId}-app-json`);
						const jsonPreviewContainer = document.querySelector(`#${windowId}-content-develop .app-json-preview`);
						
						if (jsonPreElement && jsonPreviewContainer) {
							jsonPreElement.textContent = JSON.stringify(appData, null, 2);
							jsonPreviewContainer.style.display = 'block';
						}
					});
				}
				
				const copyJsonBtn = document.getElementById(`${windowId}-copy-json`);
				if (copyJsonBtn) {
					copyJsonBtn.addEventListener('click', () => {
						const jsonPreElement = document.getElementById(`${windowId}-app-json`);
						if (!jsonPreElement) return;
						
						const json = jsonPreElement.textContent;
						
						// Copy to clipboard
						navigator.clipboard.writeText(json).then(() => {
							this.kernel.modules.ui.showNotification('Success', 'JSON copied to clipboard', 2000);
						}).catch(err => {
							this.kernel.modules.ui.showNotification('Error', 'Failed to copy JSON', 3000);
						});
					});
				}
				
				const closePreviewBtn = document.getElementById(`${windowId}-close-preview`);
				if (closePreviewBtn) {
					closePreviewBtn.addEventListener('click', () => {
						const jsonPreviewContainer = document.querySelector(`#${windowId}-content-develop .app-json-preview`);
						if (jsonPreviewContainer) {
							jsonPreviewContainer.style.display = 'none';
						}
					});
				}
				
				const saveAppBtn = document.getElementById(`${windowId}-save-app`);
				if (saveAppBtn) {
					saveAppBtn.addEventListener('click', () => {
						// Validate form
						const appIdInput = document.getElementById(`${windowId}-app-id`);
						const appTitleInput = document.getElementById(`${windowId}-app-title`);
						
						if (!appIdInput || !appTitleInput) {
							console.error('Required form elements not found');
							return;
						}
						
						const appId = appIdInput.value;
						const appTitle = appTitleInput.value;
						
						if (!appId || !appTitle) {
							this.kernel.modules.ui.showNotification('Error', 'App ID and Title are required', 3000);
							return;
						}
						
						// Get app data
						const appData = this._getAppDataFromForm(windowId);
						if (!appData) {
							this.kernel.modules.ui.showNotification('Error', 'Failed to get app data', 3000);
							return;
						}
						
						// Save app
						this._saveApp(appData);
						
						// Show notification
						this.kernel.modules.ui.showNotification('Success', `App "${appTitle}" saved successfully`, 3000);
					});
				}
				
				const importAppBtn = document.getElementById(`${windowId}-import-app`);
				if (importAppBtn) {
					importAppBtn.addEventListener('click', () => {
						const importJsonTextarea = document.getElementById(`${windowId}-import-json`);
						if (!importJsonTextarea) return;
						
						const jsonText = importJsonTextarea.value;
						
						if (!jsonText) {
							this.kernel.modules.ui.showNotification('Error', 'No JSON data provided', 3000);
							return;
						}
						
						try {
							const appData = JSON.parse(jsonText);
							
							// Validate app data
							if (!appData.id || !appData.title) {
								this.kernel.modules.ui.showNotification('Error', 'Invalid app data', 3000);
								return;
							}
							
							// Get all form elements
							const appIdInput = document.getElementById(`${windowId}-app-id`);
							const appTitleInput = document.getElementById(`${windowId}-app-title`);
							const appIconInput = document.getElementById(`${windowId}-app-icon`);
							const appDescInput = document.getElementById(`${windowId}-app-description`);
							const appAuthorInput = document.getElementById(`${windowId}-app-author`);
							const appVersionInput = document.getElementById(`${windowId}-app-version`);
							const appCategorySelect = document.getElementById(`${windowId}-app-category`);
							const permFileCheck = document.getElementById(`${windowId}-perm-filesystem`);
							const permNetworkCheck = document.getElementById(`${windowId}-perm-network`);
							const permSystemCheck = document.getElementById(`${windowId}-perm-system`);
							const appCodeTextarea = document.getElementById(`${windowId}-app-code`);
							
							// Fill form with app data if elements exist
							if (appIdInput) appIdInput.value = appData.id;
							if (appTitleInput) appTitleInput.value = appData.title;
							if (appIconInput) appIconInput.value = appData.icon || '';
							if (appDescInput) appDescInput.value = appData.description || '';
							if (appAuthorInput) appAuthorInput.value = appData.author || '';
							if (appVersionInput) appVersionInput.value = appData.version || '1.0.0';
							if (appCategorySelect) appCategorySelect.value = appData.category || 'Utilities';
							
							// Set permissions
							if (appData.permissions) {
								if (permFileCheck) permFileCheck.checked = appData.permissions.includes('filesystem');
								if (permNetworkCheck) permNetworkCheck.checked = appData.permissions.includes('network');
								if (permSystemCheck) permSystemCheck.checked = appData.permissions.includes('system');
							}
							
							// Set code
							if (appCodeTextarea && appData.code) {
								appCodeTextarea.value = typeof appData.code === 'string' 
									? appData.code 
									: JSON.stringify(appData.code, null, 2);
							}
							
							// Show notification
							this.kernel.modules.ui.showNotification('Success', `App "${appData.title}" imported successfully`, 3000);
						} catch (error) {
							this.kernel.modules.ui.showNotification('Error', `Failed to parse JSON: ${error.message}`, 5000);
						}
					});
				}
			});
		}
		
		/**
		 * Initialize the community tab for the App Store
		 * @private
		 * @param {string} windowId - Window ID
		 */
		_initializeCommunityTab(windowId) {
			const contentDiv = document.getElementById(`${windowId}-content-community`);
			if (!contentDiv) {
				console.error(`Content div not found: ${windowId}-content-community`);
				return;
			}
			
			// Create HTML content
			contentDiv.innerHTML = `
				<div style="display: flex; flex-direction: column; gap: 30px;">
					<div class="community-section">
						<h3>Community Support</h3>
						
						<div style="background-color: var(--terminal-bg); padding: 20px; border-radius: 8px;">
							<p>Get support and share your apps with the Genesis OS community!</p>
							
							<h4 style="margin-top: 20px;">Submit Your App for Review</h4>
							<p>Have you created an app you'd like to share with the community? Submit it for review and it could be featured in the App Store!</p>
							
							<div style="background-color: rgba(var(--highlight), 0.1); padding: 15px; border-radius: 4px; margin-top: 15px;">
								<p><strong>Email your app JSON file to:</strong></p>
								<p style="font-size: 18px; margin-top: 5px;">appsubmissions@genesis-os.example.com</p>
							</div>
							
							<p style="margin-top: 15px;">Our team will review your submission and get back to you within 5 business days.</p>
						</div>
					</div>
					
					<div class="community-section">
						<h3>App Submission Guidelines</h3>
						
						<div style="background-color: var(--terminal-bg); padding: 20px; border-radius: 8px;">
							<p>Please follow these guidelines when submitting your app:</p>
							
							<ul style="margin-top: 15px; padding-left: 20px;">
								<li>Apps must be safe and respect user privacy</li>
								<li>Clearly document what your app does and what permissions it needs</li>
								<li>Include a descriptive icon and title</li>
								<li>Test your app thoroughly before submitting</li>
								<li>Make sure your app works in different themes and screen sizes</li>
								<li>Include comprehensive documentation if your app is complex</li>
							</ul>
							
							<h4 style="margin-top: 20px;">JSON Format</h4>
							<p>Your app JSON should follow this structure:</p>
							
							<pre style="background-color: rgba(0,0,0,0.2); padding: 10px; border-radius: 4px; margin-top: 10px; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 12px;">{
	  "id": "unique-app-id",
	  "title": "Your App Title",
	  "description": "Detailed description of your app",
	  "icon": "📱",
	  "author": "Your Name",
	  "version": "1.0.0",
	  "category": "Utilities",
	  "permissions": ["filesystem", "network"],
	  "code": {
		"initialize": function(container, process, appInfo) {
		  // Your app code
		}
	  }
	}</pre>
						</div>
					</div>
					
					<div class="community-section">
						<h3>Get Involved</h3>
						
						<div style="background-color: var(--terminal-bg); padding: 20px; border-radius: 8px;">
							<p>Join the Genesis OS community and help shape the future of the platform!</p>
							
							<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;">
								<div id="${windowId}-forum-card" style="text-align: center; padding: 15px; border: 1px solid var(--main-border); border-radius: 8px; cursor: pointer;">
									<div style="font-size: 32px; margin-bottom: 10px;">💬</div>
									<h4 style="margin: 0 0 10px 0;">Join the Forum</h4>
									<p>Discuss ideas and get help from other users</p>
								</div>
								
								<div id="${windowId}-contribute-card" style="text-align: center; padding: 15px; border: 1px solid var(--main-border); border-radius: 8px; cursor: pointer;">
									<div style="font-size: 32px; margin-bottom: 10px;">🔧</div>
									<h4 style="margin: 0 0 10px 0;">Contribute</h4>
									<p>Help improve the platform with your skills</p>
								</div>
								
								<div id="${windowId}-docs-card" style="text-align: center; padding: 15px; border: 1px solid var(--main-border); border-radius: 8px; cursor: pointer;">
									<div style="font-size: 32px; margin-bottom: 10px;">📚</div>
									<h4 style="margin: 0 0 10px 0;">Documentation</h4>
									<p>Learn how to make the most of Genesis OS</p>
								</div>
							</div>
						</div>
					</div>
					
					<div class="community-section">
						<h3>Contact Us</h3>
						
						<div style="background-color: var(--terminal-bg); padding: 20px; border-radius: 8px;">
							<p>Have questions or suggestions? We'd love to hear from you!</p>
							
							<form id="${windowId}-contact-form" style="margin-top: 20px;">
								<div style="margin-bottom: 15px;">
									<label style="display: block; margin-bottom: 5px;">Your Name</label>
									<input type="text" id="${windowId}-contact-name" style="width: 100%; padding: 8px; background-color: var(--terminal-bg); color: var(--main-text); border: 1px solid var(--main-border); border-radius: 4px;">
								</div>
								
								<div style="margin-bottom: 15px;">
									<label style="display: block; margin-bottom: 5px;">Email Address</label>
									<input type="email" id="${windowId}-contact-email" style="width: 100%; padding: 8px; background-color: var(--terminal-bg); color: var(--main-text); border: 1px solid var(--main-border); border-radius: 4px;">
								</div>
								
								<div style="margin-bottom: 15px;">
									<label style="display: block; margin-bottom: 5px;">Message</label>
									<textarea id="${windowId}-contact-message" style="width: 100%; height: 120px; padding: 8px; background-color: var(--terminal-bg); color: var(--main-text); border: 1px solid var(--main-border); border-radius: 4px; resize: vertical;"></textarea>
								</div>
								
								<button id="${windowId}-contact-submit" type="button" class="button button-primary" style="width: 100%;">Send Message</button>
							</form>
						</div>
					</div>
				</div>
			`;
			
			// Use requestAnimationFrame to ensure DOM is updated before adding event listeners
			requestAnimationFrame(() => {
				// Add event listeners for cards in the "Get Involved" section
				const forumCard = document.getElementById(`${windowId}-forum-card`);
				if (forumCard) {
					forumCard.addEventListener('click', () => {
						this.kernel.modules.ui.showNotification('Forum', 'Opening forum in a new window...', 2000);
						// In a real implementation, this might open a URL or launch a forum app
					});
					
					// Add hover effect
					forumCard.addEventListener('mouseenter', () => {
						forumCard.style.backgroundColor = 'var(--highlight)';
						forumCard.style.color = 'var(--main-bg)';
					});
					
					forumCard.addEventListener('mouseleave', () => {
						forumCard.style.backgroundColor = '';
						forumCard.style.color = '';
					});
				}
				
				const contributeCard = document.getElementById(`${windowId}-contribute-card`);
				if (contributeCard) {
					contributeCard.addEventListener('click', () => {
						this.kernel.modules.ui.showNotification('Contribute', 'Opening contribution guidelines...', 2000);
						// In a real implementation, this might open guidelines or a GitHub page
					});
					
					// Add hover effect
					contributeCard.addEventListener('mouseenter', () => {
						contributeCard.style.backgroundColor = 'var(--highlight)';
						contributeCard.style.color = 'var(--main-bg)';
					});
					
					contributeCard.addEventListener('mouseleave', () => {
						contributeCard.style.backgroundColor = '';
						contributeCard.style.color = '';
					});
				}
				
				const docsCard = document.getElementById(`${windowId}-docs-card`);
				if (docsCard) {
					docsCard.addEventListener('click', () => {
						this.kernel.modules.ui.showNotification('Documentation', 'Opening documentation...', 2000);
						// In a real implementation, this might open the documentation
					});
					
					// Add hover effect
					docsCard.addEventListener('mouseenter', () => {
						docsCard.style.backgroundColor = 'var(--highlight)';
						docsCard.style.color = 'var(--main-bg)';
					});
					
					docsCard.addEventListener('mouseleave', () => {
						docsCard.style.backgroundColor = '';
						docsCard.style.color = '';
					});
				}
				
				// Add event listener for contact form submission
				const contactSubmitBtn = document.getElementById(`${windowId}-contact-submit`);
				if (contactSubmitBtn) {
					contactSubmitBtn.addEventListener('click', () => {
						// Get form elements
						const nameInput = document.getElementById(`${windowId}-contact-name`);
						const emailInput = document.getElementById(`${windowId}-contact-email`);
						const messageInput = document.getElementById(`${windowId}-contact-message`);
						
						if (!nameInput || !emailInput || !messageInput) {
							console.error('Contact form elements not found');
							return;
						}
						
						// Get form values
						const name = nameInput.value.trim();
						const email = emailInput.value.trim();
						const message = messageInput.value.trim();
						
						// Validate form
						if (!name || !email || !message) {
							this.kernel.modules.ui.showNotification('Error', 'Please fill out all fields', 3000);
							return;
						}
						
						// Simple email validation
						const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
						if (!emailRegex.test(email)) {
							this.kernel.modules.ui.showNotification('Error', 'Please enter a valid email address', 3000);
							return;
						}
						
						// In a real implementation, this would send the message to a server
						// For now, just show a success notification
						this.kernel.modules.ui.showNotification('Success', 'Your message has been sent!', 3000);
						
						// Clear form
						nameInput.value = '';
						emailInput.value = '';
						messageInput.value = '';
					});
				}
			});
		}

	     /**
		 * Show app details
		 * @private
		 * @param {Object} app - App data
		 * @param {string} windowId - Window ID
		 */
		_showAppDetails(app, windowId) {
			// Create dialog
			const backdrop = document.createElement('div');
			backdrop.className = 'modal-backdrop';
			backdrop.style.zIndex = '2000';
			
			const dialog = document.createElement('div');
			dialog.className = 'modal';
			dialog.style.width = '600px';
			
			// Create buttons HTML based on app installation status
			const buttonsHtml = this._isAppInstalled(app.id)
				? `<button class="button" data-action="uninstall">Uninstall</button>
				   <button class="button button-primary" data-action="launch">Launch</button>`
				: `<button class="button button-primary" data-action="install">Install</button>`;
			
			// Build dialog content
			dialog.innerHTML = `
				<div class="modal-header">
					<div class="modal-title">${app.title}</div>
				</div>
				<div class="modal-body">
					<div style="display: flex; margin-bottom: 20px;">
						<div style="font-size: 48px; margin-right: 20px;">${app.icon}</div>
						<div>
							<h3 style="margin: 0 0 5px 0;">${app.title}</h3>
							<div style="font-size: 14px; opacity: 0.7;">by ${app.author} | v${app.version}</div>
							<div style="font-size: 14px; margin-top: 5px;">Category: ${app.category}</div>
						</div>
					</div>
					
					<div style="margin-bottom: 20px;">
						<h4>Description</h4>
						<p>${app.description}</p>
					</div>
					
					<div>
						<h4>Details</h4>
						<table style="width: 100%; border-collapse: collapse;">
							<tr>
								<td style="padding: 8px 0; border-bottom: 1px solid var(--main-border); width: 120px;">App ID</td>
								<td style="padding: 8px 0; border-bottom: 1px solid var(--main-border);">${app.id}</td>
							</tr>
							<tr>
								<td style="padding: 8px 0; border-bottom: 1px solid var(--main-border);">Version</td>
								<td style="padding: 8px 0; border-bottom: 1px solid var(--main-border);">${app.version}</td>
							</tr>
							<tr>
								<td style="padding: 8px 0; border-bottom: 1px solid var(--main-border);">Author</td>
								<td style="padding: 8px 0; border-bottom: 1px solid var(--main-border);">${app.author}</td>
							</tr>
							<tr>
								<td style="padding: 8px 0;">Permissions</td>
								<td style="padding: 8px 0;">${app.permissions ? app.permissions.join(', ') : 'Basic access'}</td>
							</tr>
						</table>
					</div>
				</div>
				<div class="modal-footer">
					<button class="button" data-action="close">Close</button>
					${buttonsHtml}
				</div>
			`;
			
			// Add to DOM
			backdrop.appendChild(dialog);
			document.body.appendChild(backdrop);
			
			// Show dialog with animation
			setTimeout(() => {
				backdrop.classList.add('show');
			}, 10);
			
			// Set up button handlers
			dialog.querySelector('button[data-action="close"]').addEventListener('click', () => {
				backdrop.classList.remove('show');
				setTimeout(() => {
					backdrop.remove();
				}, 300);
			});
			
			if (this._isAppInstalled(app.id)) {
				dialog.querySelector('button[data-action="uninstall"]').addEventListener('click', () => {
					this._uninstallApp(app.id, windowId);
					backdrop.classList.remove('show');
					setTimeout(() => {
						backdrop.remove();
					}, 300);
				});
				
				dialog.querySelector('button[data-action="launch"]').addEventListener('click', () => {
					this._launchApp(app.id);
					backdrop.classList.remove('show');
					setTimeout(() => {
						backdrop.remove();
					}, 300);
				});
			} else {
				dialog.querySelector('button[data-action="install"]').addEventListener('click', () => {
					this._installApp(app);
					backdrop.classList.remove('show');
					setTimeout(() => {
						backdrop.remove();
					}, 300);
				});
			}
		}

		/**
		 * Install an app
		 * @private
		 * @param {Object} app - App data
		 */
		_installApp(app) {
			// Check if app is already installed
			if (this._isAppInstalled(app.id)) {
				this.kernel.modules.ui.showNotification('Info', `${app.title} is already installed`, 3000);
				return;
			}
			
			// Get installed apps from local storage
			const installedApps = JSON.parse(localStorage.getItem('gos_installed_apps') || '[]');
			
			// Prepare app for installation
			const appToInstall = {
				...app,
				// Add required properties for system compatibility
				window: app.window || {
					width: 800,
					height: 600,
					resizable: true
				},
				permissions: app.permissions || ['filesystem.read.*', 'filesystem.write.user.*'],
				desktopIcon: true  // Explicitly set desktop icon to true
			};
			
			// Add new app
			installedApps.push(appToInstall);
			
			// Save to local storage
			localStorage.setItem('gos_installed_apps', JSON.stringify(installedApps));
			
			// Create app manifest file in the filesystem if possible
			try {
				const username = this.kernel.currentUser.username;
				const appManifestPath = `/users/${username}/Apps/${app.id}.json`;
				
				// Create directory if it doesn't exist
				if (!this.kernel.modules.filesystem.directoryExists(`/users/${username}/Apps`)) {
					this.kernel.modules.filesystem.createDirectory(`/users/${username}/Apps`);
				}
				
				// Save app manifest
				this.kernel.modules.filesystem.writeFile(appManifestPath, JSON.stringify(appToInstall, null, 2));
			} catch (e) {
				this.log.warn(`Could not save app manifest to filesystem: ${e.message}`);
				// Continue anyway as we've saved to localStorage
			}
			
			// Show notification
			this.kernel.modules.ui.showNotification('Success', `${app.title} installed successfully`, 3000);
			
			// Refresh installed apps tab
			this._loadInstalledApps(this.kernel.modules.process.windowId);
			
			// Refresh desktop icons to show new app
			try {
				this.kernel._createDesktopIcons();
			} catch (e) {
				this.log.warn(`Could not refresh desktop icons: ${e.message}`);
			}
		}

		/**
		 * Uninstall an app
		 * @private
		 * @param {string} appId - App ID
		 * @param {string} windowId - Window ID
		 */
		_uninstallApp(appId, windowId) {
			// Get installed apps from local storage
			const installedApps = JSON.parse(localStorage.getItem('gos_installed_apps') || '[]');
			
			// Find app
			const appIndex = installedApps.findIndex(app => app.id === appId);
			
			if (appIndex === -1) {
				this.kernel.modules.ui.showNotification('Error', 'App not found', 3000);
				return;
			}
			
			// Get app name for notification
			const appTitle = installedApps[appIndex].title;
			
			// Remove app
			installedApps.splice(appIndex, 1);
			
			// Save to local storage
			localStorage.setItem('gos_installed_apps', JSON.stringify(installedApps));
			
			// Remove app manifest from filesystem if possible
			try {
				const username = this.kernel.currentUser.username;
				const appManifestPath = `/users/${username}/Apps/${appId}.json`;
				
				// Delete file if it exists
				if (this.kernel.modules.filesystem.fileExists(appManifestPath)) {
					this.kernel.modules.filesystem.deleteFile(appManifestPath);
				}
			} catch (e) {
				this.log.warn(`Could not remove app manifest from filesystem: ${e.message}`);
				// Continue anyway as we've removed from localStorage
			}
			
			// Show notification
			this.kernel.modules.ui.showNotification('Success', `${appTitle} uninstalled successfully`, 3000);
			
			// Refresh installed apps tab
			this._loadInstalledApps(windowId);
			
			// Refresh desktop icons to remove app
			try {
				this.kernel._createDesktopIcons();
			} catch (e) {
				this.log.warn(`Could not refresh desktop icons: ${e.message}`);
			}
		}

		/**
		 * Launch an app
		 * @private
		 * @param {string} appId - App ID
		 */
		_launchApp(appId) {
			// Get installed apps from local storage
			const installedApps = JSON.parse(localStorage.getItem('gos_installed_apps') || '[]');
			
			// Find app
			const app = installedApps.find(app => app.id === appId);
			
			if (!app) {
				this.kernel.modules.ui.showNotification('Error', 'App not found', 3000);
				return;
			}
			
			// Use the kernel's application launching mechanism
			try {
				this.kernel.launchApplication(appId);
			} catch (error) {
				this.kernel.modules.ui.showNotification('Error', `Failed to launch ${app.title}: ${error.message}`, 5000);
			}
		}

		/**
		 * Check if an app is installed
		 * @private
		 * @param {string} appId - App ID
		 * @returns {boolean} True if app is installed
		 */
		_isAppInstalled(appId) {
			// Get installed apps from local storage
			const installedApps = JSON.parse(localStorage.getItem('gos_installed_apps') || '[]');
			
			// Check if app is installed
			return installedApps.some(app => app.id === appId);
		}

		/**
		 * Get app data from form
		 * @private
		 * @param {string} windowId - Window ID
		 * @returns {Object} App data
		 */
		_getAppDataFromForm(windowId) {
			// Get form values
			const appId = document.getElementById(`${windowId}-app-id`).value;
			const appTitle = document.getElementById(`${windowId}-app-title`).value;
			const appIcon = document.getElementById(`${windowId}-app-icon`).value;
			const appDescription = document.getElementById(`${windowId}-app-description`).value;
			const appAuthor = document.getElementById(`${windowId}-app-author`).value;
			const appVersion = document.getElementById(`${windowId}-app-version`).value;
			const appCategory = document.getElementById(`${windowId}-app-category`).value;
			const appCode = document.getElementById(`${windowId}-app-code`).value;
			
			// Get permissions
			const permissions = [];
			if (document.getElementById(`${windowId}-perm-filesystem`).checked) {
				permissions.push('filesystem');
			}
			if (document.getElementById(`${windowId}-perm-network`).checked) {
				permissions.push('network');
			}
			if (document.getElementById(`${windowId}-perm-system`).checked) {
				permissions.push('system');
			}
			
			// Create app data
			return {
				id: appId,
				title: appTitle,
				icon: appIcon,
				description: appDescription,
				author: appAuthor,
				version: appVersion,
				category: appCategory,
				permissions: permissions,
				code: appCode
			};
		}

		/**
		 * Save an app
		 * @private
		 * @param {Object} appData - App data
		 */
		_saveApp(appData) {
				// Get developer apps from local storage
				const developerApps = JSON.parse(localStorage.getItem('gos_developer_apps') || '[]');
			
			// Check if app already exists
			const appIndex = developerApps.findIndex(app => app.id === appData.id);
			
			if (appIndex !== -1) {
				// Update app
				developerApps[appIndex] = appData;
			} else {
				// Add new app
				developerApps.push(appData);
			}
			
			// Save to local storage
			localStorage.setItem('gos_developer_apps', JSON.stringify(developerApps));
		}

		/**
		 * Initialize Diagnostics application (admin only)
		 * @private
		 * @param {HTMLElement} container - Container element
		 * @param {Object} process - Process object
		 * @param {Object} appInfo - Application metadata
		 */
		async _initializeDiagnostics(container, process, appInfo) {
			container.innerHTML = `
					<div style="padding:20px; font-size:14px;">
							<button id="${process.windowId}-run-tests" class="button button-primary">Run Self Tests</button>
							<button id="${process.windowId}-toggle-console" class="button" style="margin-left:10px;">Toggle Debug Console</button>
							<pre id="${process.windowId}-test-output" style="margin-top:15px;height:200px;overflow:auto;background:var(--terminal-bg);padding:10px;"></pre>
					</div>`;

			const runBtn = document.getElementById(`${process.windowId}-run-tests`);
			const out = document.getElementById(`${process.windowId}-test-output`);
			runBtn.addEventListener('click', async () => {
					out.textContent = 'Running tests...';
					try {
							const result = await this.kernel.services.get('system').call('runSelfTests');
							const lines = Object.entries(result).map(([k,v]) => `${k}: ${v ? 'OK' : 'FAIL'}`);
							out.textContent = lines.join('\n');
					} catch (e) {
							out.textContent = 'Error: ' + e.message;
					}
			});

			const toggleBtn = document.getElementById(`${process.windowId}-toggle-console`);
			if (toggleBtn) {
					toggleBtn.addEventListener('click', () => {
							this.kernel.modules.ui.toggleDebugConsole();
					});
			}
		}
		
		async _renderUserManagement(windowId) {
			const container = document.getElementById('user-list');
			try {
				const users = await this.kernel.api.users.call('listUsers');
				let userListHtml = '';
				users.forEach(user => {
					userListHtml += `
						<div class="user-list-item" style="display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid var(--main-border);">
							<div>
								<strong>${user.name}</strong> (${user.username})<br>
								<small>Roles: ${user.roles.join(', ')}</small>
							</div>
							<div>
								<button class="button" data-action="edit" data-username="${user.username}">Edit</button>
								${user.username !== 'admin' ? `<button class="button" data-action="delete" data-username="${user.username}">Delete</button>` : ''}
							</div>
						</div>
					`;
				});
				container.innerHTML = userListHtml;

				// Add event listeners for edit/delete buttons
				container.querySelectorAll('button').forEach(btn => {
					btn.onclick = async (e) => {
						const action = e.target.dataset.action;
						const username = e.target.dataset.username;

						if (action === 'delete') {
							this.kernel.modules.ui.showDialog('Confirm Deletion', `Are you sure you want to delete user '${username}'? This cannot be undone.`, { buttons: ['Cancel', 'Delete']})
								.then(async (result) => {
									if (result.button === 'Delete') {
                                        try {
										    await this.kernel.api.users.call('deleteUser', { username });
                                            this.kernel.modules.ui.showNotification('Success', `User '${username}' has been deleted.`);
										    this._renderUserManagement(windowId); // Refresh list
                                        } catch (error) {
                                            this.kernel.modules.ui.showNotification('Error', error.message, 5000);
                                        }
									}
								});
						}
						
						if (action === 'edit') {
                            try {
                                const user = await this.kernel.api.users.call('getUserDetails', { username });

                                // ... code to build rolesCheckboxes and dialogBody remains the same ...
                                const allRoles = ['user', 'developer', 'admin'];
                                const rolesCheckboxes = allRoles.map(role => `
                                    <label style="margin-right: 15px; display: inline-block;">
                                        <input type="checkbox" name="roles" value="${role}" ${user.roles.includes(role) ? 'checked' : ''}>
                                        ${role.charAt(0).toUpperCase() + role.slice(1)}
                                    </label>
                                `).join('');

                                const dialogBody = `
                                    <div class="form-group" style="margin-bottom: 15px;">
                                        <label for="edit-name" style="display: block; margin-bottom: 5px;">Full Name</label>
                                        <input type="text" id="edit-name" value="${user.name}" style="width: 100%; padding: 8px;">
                                    </div>
                                    <div class="form-group" style="margin-bottom: 15px;">
                                        <label style="display: block; margin-bottom: 5px;">Roles</label>
                                        <div id="roles-container">${rolesCheckboxes}</div>
                                    </div>
                                    <hr style="margin: 20px 0; border-color: var(--main-border); opacity: 0.5;">
                                    <button class="button" id="change-password-dialog-btn">Change Password</button>
                                `;

                                // This part remains the same
                                this.kernel.modules.ui.showDialog(`Edit User: ${username}`, dialogBody, { 
                                    buttons: ['Cancel', 'Save Changes'],
                                    getFormData: () => {
                                        const name = document.getElementById('edit-name')?.value;
                                        const roles = Array.from(document.querySelectorAll('#roles-container input:checked')).map(cb => cb.value);
                                        return { name, roles };
                                    }
                                }).then(async (result) => {
                                    if (result.button === 'Save Changes' && result.formData) {
                                        await this.kernel.api.users.call('updateUser', { 
                                            username, 
                                            name: result.formData.name, 
                                            roles: result.formData.roles 
                                        });
                                        this.kernel.modules.ui.showNotification('Success', `User '${username}' updated.`);
                                        this._renderUserManagement(windowId);
                                    }
                                });
                                
                                setTimeout(() => {
                                    const changePasswordBtn = document.getElementById('change-password-dialog-btn');
                                    if(changePasswordBtn) {
                                        changePasswordBtn.onclick = async () => {

                                            // ✅ FIXED: The showDialog call for the password now includes getFormData.
                                            const passwordResult = await this.kernel.modules.ui.showDialog('Set New Password', `
                                                <div class="form-group">
                                                    <label for="new-password" style="display: block; margin-bottom: 5px;">New Password</label>
                                                    <input type="password" id="new-password" style="width: 100%; padding: 8px;" autocomplete="new-password">
                                                </div>
                                            `, { 
                                                buttons: ['Cancel', 'Set Password'],
                                                getFormData: () => {
                                                    const input = document.getElementById('new-password');
                                                    return { password: input ? input.value : null };
                                                }
                                            });

                                            // ✅ FIXED: The newPassword value is now safely retrieved from the dialog's result object.
                                            if (passwordResult.button === 'Set Password' && passwordResult.formData) {
                                                const newPassword = passwordResult.formData.password;
                                                try {
                                                    await this.kernel.api.users.call('setPassword', { username, newPassword });
                                                    this.kernel.modules.ui.showNotification('Success', `Password for '${username}' has been changed.`);
                                                } catch (error) {
                                                    this.kernel.modules.ui.showNotification('Error', error.message, 5000);
                                                }
                                            }
                                        };
                                    }
                                }, 100);

                            } catch (error) {
                                this.kernel.modules.ui.showNotification('Error', error.message, 5000);
                            }
						}
					};
				});
			} catch (e) {
				container.innerHTML = `<p style="color:red;">Error loading users: ${e.message}</p>`;
			}
		}
		
	}
	
	/**
	 * UI Manager
	 * Manages user interface components
	 */
	class UIManager {
		/**
		 * Create a new UI manager
		 * @param {Kernel} kernel - Kernel reference
		 */
		constructor(kernel) {
			this.kernel = kernel;
			this.log = kernel._createLogger('ui');
			
			// UI state
			this.currentTheme = 'vintage';
			this.currentFontSize = 'medium';
			this.animations = true;
			this.crtEffects = false;
			
			this.log.info('UI manager initialized');
		}
		
		/**
		 * Initialize UI manager
		 * @async
		 */
		async initialize() {
			// Load preferences from localStorage if available
			this._loadPreferences();
			
			// Apply theme
			this.applyTheme(this.currentTheme);
			
			// Apply font size
			this.applyFontSize(this.currentFontSize);
			
			// Apply animation setting
			this.setAnimations(this.animations);
			
			// Apply CRT effects
			this.setCrtEffects(this.crtEffects);
			
			return true;
		}
		
		/**
		 * Load UI preferences from localStorage
		 * @private
		 */
		_loadPreferences() {
			try {
				const preferences = localStorage.getItem('gos_ui_preferences');
				if (preferences) {
					const prefs = JSON.parse(preferences);
					this.currentTheme = prefs.theme || 'vintage';
					this.currentFontSize = prefs.fontSize || 'medium';
					this.animations = prefs.animations !== false;
					this.crtEffects = prefs.crtEffects === true;
				}
			} catch (error) {
				this.log.error('Failed to load UI preferences:', error);
			}
		}
		
		/**
		 * Save UI preferences to localStorage
		 * @private
		 */
		_savePreferences() {
			try {
				const preferences = {
					theme: this.currentTheme,
					fontSize: this.currentFontSize,
					animations: this.animations,
					crtEffects: this.crtEffects
				};
				
				localStorage.setItem('gos_ui_preferences', JSON.stringify(preferences));
			} catch (error) {
				this.log.error('Failed to save UI preferences:', error);
			}
		}
				
		/**
		 * Apply theme
		 * @param {string} theme - Theme name
		 */
		applyTheme(theme) {
			// Update body class
			document.body.classList.remove('theme-vintage', 'theme-dark', 'theme-light', 'theme-blue');
			document.body.classList.add(`theme-${theme}`);
			
			// Store current theme
			this.currentTheme = theme;
			
			// Save preferences
			this._savePreferences();
			
			this.log.info(`Applied theme: ${theme}`);
		}
		
		/**
		 * Apply font size
		 * @param {string} size - Font size (small, medium, large)
		 */
		applyFontSize(size) {
			const sizes = {
				small: '14px',
				medium: '16px',
				large: '18px'
			};
			
			document.documentElement.style.fontSize = sizes[size] || sizes.medium;
			
			// Store current font size
			this.currentFontSize = size;
			
			// Save preferences
			this._savePreferences();
			
			this.log.info(`Applied font size: ${size}`);
		}
		
		/**
		 * Set animations enabled/disabled
		 * @param {boolean} enabled - Whether animations are enabled
		 */
		setAnimations(enabled) {
			// Store setting
			this.animations = enabled;
			
			// Apply setting
			document.documentElement.style.setProperty('--animation-duration', enabled ? '0.3s' : '0s');
			
			// Save preferences
			this._savePreferences();
			
			this.log.info(`Animations ${enabled ? 'enabled' : 'disabled'}`);
		}
		
		/**
		 * Set CRT effects enabled/disabled
		 * @param {boolean} enabled - Whether CRT effects are enabled
		 */
		setCrtEffects(enabled) {
			// Store setting
			this.crtEffects = enabled;
			
			// Apply setting
			document.getElementById('crt-overlay').style.display = enabled ? 'block' : 'none';
			document.getElementById('scanline').style.display = enabled ? 'block' : 'none';
			
			// Save preferences
			this._savePreferences();
			
					this.log.info(`CRT effects ${enabled ? 'enabled' : 'disabled'}`);
			}

			/** Apply UI translations to static elements */
			applyTranslations() {
					const t = (key) => this.kernel.translate(key);
					const heading = document.querySelector('.login-heading');
					const username = document.getElementById('username');
					const password = document.getElementById('password');
					const button = document.querySelector('#login-form button');
					if (heading) heading.textContent = t('login_heading');
					if (username) username.placeholder = t('username');
					if (password) password.placeholder = t('password');
					if (button) button.textContent = t('login_button');
			}
			
		/**
		 * Show a notification
		 * @param {string} title - Notification title
		 * @param {string} message - Notification message
		 * @param {number} [duration=3000] - Display duration in milliseconds
		 */
		showNotification(title, message, duration = 3000) {
			const container = document.getElementById('notification-container');
			
			// Create notification element
			const notification = document.createElement('div');
			notification.className = 'notification';
			notification.innerHTML = `
				<div class="notification-title">${title}</div>
				<div class="notification-body">${message}</div>
			`;
			
			// Add to container
			container.appendChild(notification);
			
			// Show with animation
			setTimeout(() => {
				notification.classList.add('show');
			}, 10);
			
			// Remove after duration
			setTimeout(() => {
				notification.classList.remove('show');
				
				// Remove from DOM after animation completes
				setTimeout(() => {
					if (notification.parentNode) {
						notification.remove();
					}
				}, 300);
			}, duration);
			
					return notification;
			}

		/**
		 * Display a persistent error banner
		 * @param {string} message - Error message
		 */
		showErrorBanner(message) {
				const banner = document.getElementById('error-banner');
				if (!banner) return;
				banner.textContent = message;
				banner.style.display = 'block';
		}

		/** Hide the persistent error banner */
		hideErrorBanner() {
				const banner = document.getElementById('error-banner');
				if (banner) banner.style.display = 'none';
		}

		/** Toggle debug console visibility */
		toggleDebugConsole() {
				const consoleEl = document.getElementById('debug-console');
				if (!consoleEl) return;
				if (consoleEl.style.display === 'block') {
						consoleEl.style.display = 'none';
				} else {
						consoleEl.style.display = 'block';
				}
		}

		/**
		 * Display a help dialog with custom content
		 * @param {string} html - Help HTML content
		 */
		showHelp(html) {
				const title = 'Help';
				this.showDialog(title, html, { buttons: ['Close'] });
		}
		
		/**
		 * Show a dialog, now with complete logic and form data handling.
		 * @param {string} title - Dialog title
		 * @param {string} message - Dialog message (HTML content)
		 * @param {Object} [options] - Dialog options
		 * @returns {Promise<Object>} Resolves with an object containing the clicked button and any form data
		 */
		showDialog(title, message, options = {}) {
			return new Promise(resolve => {
				// Default options from the original file, ensuring full compatibility
				const defaultOptions = {
					buttons: ['OK'],
					defaultButton: 'OK',
					cancelButton: null,
                    getFormData: null // The new callback for capturing form data
				};
				
				const dialogOptions = { ...defaultOptions, ...options };
				
				// Create backdrop and modal elements
				const backdrop = document.createElement('div');
				backdrop.className = 'modal-backdrop';
				const dialog = document.createElement('div');
				dialog.className = 'modal';
				
				// Build dialog content, including the button mapping from the original file
				dialog.innerHTML = `
					<div class="modal-header">
						<div class="modal-title">${title}</div>
					</div>
					<div class="modal-body">
						${message}
					</div>
					<div class="modal-footer">
						${dialogOptions.buttons.map(buttonText => `
							<button class="button ${buttonText === dialogOptions.defaultButton ? 'button-primary' : ''}" data-button="${buttonText}">
								${buttonText}
							</button>
						`).join('')}
					</div>
				`;
				
				backdrop.appendChild(dialog);
				document.body.appendChild(backdrop);
				
				// Show dialog with animation
				setTimeout(() => {
					backdrop.classList.add('show');
				}, 10);
				
                // Function to close the dialog and resolve the promise
                const closeDialog = (buttonName) => {
                    let formData = null;
                    // ✅ FIXED: Capture form data *before* the modal is removed from the DOM.
                    if (typeof dialogOptions.getFormData === 'function') {
                        formData = dialogOptions.getFormData();
                    }

                    backdrop.classList.remove('show');
                    setTimeout(() => {
                        if (backdrop.parentNode) {
                            backdrop.remove();
                        }
                        // Resolve with the consistent object format
                        resolve({ button: buttonName, formData: formData });
                    }, 300);
                };

				// Set up button handlers
				dialog.querySelectorAll('.button').forEach(button => {
					button.addEventListener('click', () => {
                        const buttonName = button.getAttribute('data-button');
						closeDialog(buttonName);
					});
				});
				
				// Handle Escape key, preserving original functionality
				if (dialogOptions.cancelButton) {
					const escHandler = (e) => {
						if (e.key === 'Escape') {
							document.removeEventListener('keydown', escHandler);
							closeDialog(dialogOptions.cancelButton);
						}
					};
					document.addEventListener('keydown', escHandler);
				}
			});
		}
		
		/**
		 * Displays the developer registration modal dialog.
		 * This method builds the registration form, handles submission,
		 * calls the authentication API, and provides user feedback.
		 */
		showRegistrationDialog() {
			const body = `
				<div class="form-group"><label for="reg-username">Username</label><input type="text" id="reg-username" required></div>
				<div class="form-group"><label for="reg-name">Full Name</label><input type="text" id="reg-name" required></div>
				<div class="form-group"><label for="reg-password">Password (min. 8 characters)</label><input type="password" id="reg-password" required></div>
				<div id="reg-error" class="login-error"></div>`;

			// This function is passed to showDialog and runs *before* the modal closes,
			// safely capturing the form data.
			const getFormData = () => {
				return {
					username: document.getElementById('reg-username').value,
					name: document.getElementById('reg-name').value,
					password: document.getElementById('reg-password').value
				};
			};
			
			this.showDialog('Developer Registration', body, { buttons: ['Cancel', 'Register'], getFormData: getFormData })
				.then(async (result) => {
					// Check which button was clicked and if we have form data.
					if (result.button === 'Register' && result.formData) {
						try {
							const apiResult = await this.kernel.api.auth.call('register', result.formData);
							this.showNotification('Success', apiResult.message);
						} catch (error) {
							// ✅ FIXED: Simply show the server's validation error in a notification.
							// This is a cleaner and more reliable way to give user feedback.
							this.showNotification('Registration Failed', error.message, 5000);
						}
					}
				});
		}

		
	}
	
	// Initialize Genesis OS
	document.addEventListener('DOMContentLoaded', async () => {
		const debugEl = document.getElementById('debug-console');
		['log','warn','error'].forEach(type => {
				const orig = console[type];
				console[type] = function(...args) {
						orig.apply(console, args);
						if (debugEl) {
								debugEl.textContent += `[${type}] ` + args.join(' ') + '\n';
						}
				};
		});
		// Global error handlers
		window.addEventListener('error', e => {
			if (window.kernel && window.kernel.services?.has('system')) {
					window.kernel.services.get('system').call('logError', {
							message: e.message,
							details: e.error?.stack || ''
					}).catch(() => {});
			}
			if (window.kernel && window.kernel.modules?.ui) {
					window.kernel.modules.ui.showErrorBanner('A system error occurred: ' + e.message);
			}
		});
		window.addEventListener('unhandledrejection', e => {
			const msg = e.reason?.message || e.reason;
			const details = e.reason?.stack || '';
			if (window.kernel && window.kernel.services?.has('system')) {
					window.kernel.services.get('system').call('logError', {
							message: msg,
							details: details
					}).catch(() => {});
			}
			if (window.kernel && window.kernel.modules?.ui) {
					window.kernel.modules.ui.showErrorBanner('A system error occurred: ' + msg);
			}
		});

		try {
			// Create kernel instance
			window.kernel = new Kernel();

			// Initialize kernel
			await window.kernel.initialize();
		} catch (error) {
			console.error('Failed to initialize Genesis OS:', error);

			const splashScreen = document.getElementById('splash-screen');
			if (splashScreen) {
					splashScreen.style.display = 'none';
			}

			const errorScreen = document.getElementById('error-screen');
			if (errorScreen) {
					const errorTitle = document.getElementById('error-title');
					const errorMessage = document.getElementById('error-message');
					const errorDetails = document.getElementById('error-details');

					errorTitle.textContent = 'System Initialization Failed';
					errorMessage.textContent = error.message;

					if (error.stack) {
							errorDetails.textContent = error.stack;
							errorDetails.style.display = 'block';
					}

					errorScreen.style.display = 'flex';
			}
		}

	});
	
	
	/**
	 * =================================================================
	 * GOS Built-in Application Entry Points
	 * =================================================================
	 * This script defines the global functions that the Kernel uses
	 * to launch the built-in applications. Each function serves as
	 * an entry point that initializes the application's UI inside
	 * its designated window.
	 */

	/**
	 * Entry point for the Code Editor application.
	 * @param {object} process - The process object from the kernel.
	 */
	function editorApp(process) {
		const container = document.querySelector(`#${process.windowId} .window-content`);
		if (container) {
			// The _initializeCodeEditor method is defined within the Kernel's ProcessManager
			kernel.modules.process._initializeCodeEditor(container, process, {});
		}
	}

	/**
	 * Entry point for the File Manager application.
	 * @param {object} process - The process object from the kernel.
	 */
	function fileManagerApp(process) {
		const container = document.querySelector(`#${process.windowId} .window-content`);
		if (container) {
			kernel.modules.process._initializeFileManager(container, process, {});
		}
	}

	/**
	 * Entry point for the Mathematical Sandbox application.
	 * @param {object} process - The process object from the kernel.
	 */
	function mathematicalSandboxApp(process) {
		const container = document.querySelector(`#${process.windowId} .window-content`);
		if (container) {
			kernel.modules.process._initializeMathematicalSandbox(container, process, {});
		}
	}

	/**
	 * Entry point for the Terminal application.
	 * @param {object} process - The process object from the kernel.
	 */
	function terminalApp(process) {
		const container = document.querySelector(`#${process.windowId} .window-content`);
		if (container) {
			kernel.modules.process._initializeTerminal(container, process, {});
		}
	}

	/**
	 * Entry point for the Settings application.
	 * @param {object} process - The process object from the kernel.
	 */
	function settingsApp(process) {
		const container = document.querySelector(`#${process.windowId} .window-content`);
		if (container) {
			kernel.modules.process._initializeSettings(container, process, {});
		}
	}

	/**
	 * Entry point for the App Store application.
	 * @param {object} process - The process object from the kernel.
	 */
	function appStoreApp(process) {
		const container = document.querySelector(`#${process.windowId} .window-content`);
		if (container) {
			kernel.modules.process._initializeAppStore(container, process, {});
		}
	}

	/**
	 * Entry point for the Diagnostics application.
	 * @param {object} process - The process object from the kernel.
	 */
	function diagnosticsApp(process) {
		const container = document.querySelector(`#${process.windowId} .window-content`);
		if (container) {
			kernel.modules.process._initializeDiagnostics(container, process, {});
		}
	}

    /**
     * Entry point for the Developer Center application.
     * @param {object} process - The process object from the kernel.
     */
    function devCenterApp(process) {
        const container = document.querySelector(`#${process.windowId} .window-content`);
        if (container) {
            kernel.modules.process._initializeDeveloperCenter(container, process, {});
        }
    }
	
  </script>
</body>
</html>