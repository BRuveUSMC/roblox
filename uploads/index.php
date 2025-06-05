#!/usr/bin/env python3
"""
PHP Development Server with Auto-Reload
A simple Python app that starts a PHP server and automatically refreshes the browser when files change.
"""

import os
import subprocess
import threading
import time
import webbrowser
from pathlib import Path
import tkinter as tk
from tkinter import filedialog, messagebox
import socket
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler
import http.server
import socketserver
import json
import re
from datetime import datetime
import shutil

class FileChangeHandler(FileSystemEventHandler):
    def __init__(self, callback):
        self.callback = callback
        self.last_modified = {}
        
    def on_modified(self, event):
        if event.is_directory:
            return
            
        # Filter for PHP, HTML, CSS, JS files
        if event.src_path.endswith(('.php', '.html', '.css', '.js', '.htm')):
            current_time = time.time()
            # Debounce - only trigger if file hasn't been modified in last 1 second
            if (event.src_path not in self.last_modified or 
                current_time - self.last_modified[event.src_path] > 1.0):
                self.last_modified[event.src_path] = current_time
                self.callback(event.src_path)

class WebSocketReloadServer:
    def __init__(self, port=8081):
        self.port = port
        self.should_reload = False
        
    def start(self):
        """Start the reload notification server"""
        class ReloadHandler(http.server.SimpleHTTPRequestHandler):
            def __init__(self, *args, reload_server=None, **kwargs):
                self.reload_server = reload_server
                super().__init__(*args, **kwargs)
                
            def do_GET(self):
                if self.path == '/reload-status':
                    self.send_response(200)
                    self.send_header('Content-Type', 'application/json')
                    self.send_header('Access-Control-Allow-Origin', '*')
                    self.send_header('Cache-Control', 'no-cache')
                    self.end_headers()
                    
                    response = {
                        'should_reload': self.reload_server.should_reload,
                        'timestamp': time.time()
                    }
                    
                    if self.reload_server.should_reload:
                        self.reload_server.should_reload = False
                        
                    self.wfile.write(json.dumps(response).encode())
                else:
                    self.send_error(404)
                    
            def log_message(self, format, *args):
                # Suppress logging
                pass
        
        def run_server():
            try:
                with socketserver.TCPServer(("", self.port), 
                                          lambda *args, **kwargs: ReloadHandler(*args, reload_server=self, **kwargs)) as httpd:
                    print(f"[RELOAD SERVER] Started on port {self.port}")
                    httpd.serve_forever()
            except Exception as e:
                print(f"[RELOAD SERVER ERROR] {e}")
                
        threading.Thread(target=run_server, daemon=True).start()
        
    def trigger_reload(self):
        """Trigger browser reload"""
        self.should_reload = True
        print("[RELOAD] Triggering browser reload...")

class PHPDevServer:
    def __init__(self):
        self.php_process = None
        self.observer = None
        self.reload_server = WebSocketReloadServer()
        self.current_directory = None
        self.php_port = None
        self.original_files = {}  # Store original file contents
        
    def find_free_port(self, start_port=8000):
        """Find a free port starting from start_port"""
        for port in range(start_port, start_port + 100):
            try:
                with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
                    s.bind(('localhost', port))
                    return port
            except OSError:
                continue
        return None
    
    def check_php_installation(self):
        """Check if PHP is installed and accessible"""
        try:
            result = subprocess.run(['php', '--version'], 
                                  capture_output=True, text=True, timeout=5)
            return result.returncode == 0
        except (subprocess.TimeoutExpired, FileNotFoundError):
            return False
    
    def get_reload_script(self):
        """Generate the auto-reload JavaScript"""
        return f"""
<!-- PHP Dev Server Auto-Reload Script -->
<script>
(function() {{
    let reloadCheckInterval;
    let lastReloadCheck = Date.now();
    
    function startReloadChecker() {{
        reloadCheckInterval = setInterval(function() {{
            fetch('http://localhost:8081/reload-status', {{
                method: 'GET',
                cache: 'no-cache'
            }})
            .then(response => response.json())
            .then(data => {{
                if (data.should_reload && data.timestamp > lastReloadCheck) {{
                    console.log('[DEV SERVER] Reloading page due to file changes...');
                    location.reload();
                }}
                lastReloadCheck = data.timestamp;
            }})
            .catch(error => {{
                console.log('[DEV SERVER] Reload checker unavailable');
            }});
        }}, 1000); // Check every second
    }}
    
    // Start checking when page loads
    if (document.readyState === 'loading') {{
        document.addEventListener('DOMContentLoaded', startReloadChecker);
    }} else {{
        startReloadChecker();
    }}
    
    // Clean up on page unload
    window.addEventListener('beforeunload', function() {{
        if (reloadCheckInterval) {{
            clearInterval(reloadCheckInterval);
        }}
    }});
}})();
</script>
"""
    
    def get_debug_panel(self):
        """Generate PHP debug information panel"""
        return """
<!-- PHP Dev Server Debug Panel -->
<div id="php-dev-debug" style="position: fixed; top: 10px; right: 10px; background: #2d3748; color: #e2e8f0; padding: 15px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 12px; max-width: 300px; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.3); border: 1px solid #4a5568;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <strong style="color: #68d391;">[PHP DEV SERVER]</strong>
        <button onclick="document.getElementById('php-dev-debug').style.display='none'" style="background: #e53e3e; color: white; border: none; border-radius: 3px; padding: 2px 6px; cursor: pointer; font-size: 10px;">✕</button>
    </div>
    
    <div style="margin-bottom: 8px;">
        <strong>Status:</strong> <span style="color: #68d391;">ACTIVE</span>
    </div>
    
    <div style="margin-bottom: 8px;">
        <strong>PHP Version:</strong> <?php echo phpversion(); ?>
    </div>
    
    <div style="margin-bottom: 8px;">
        <strong>Memory Usage:</strong> <?php echo round(memory_get_usage(true)/1024/1024, 2); ?>MB
    </div>
    
    <div style="margin-bottom: 8px;">
        <strong>Server Time:</strong> <?php echo date('H:i:s'); ?>
    </div>
    
    <div style="margin-bottom: 8px;">
        <strong>Current File:</strong> <?php echo basename($_SERVER['SCRIPT_NAME']); ?>
    </div>
    
    <?php
    // Display PHP errors if any
    $errors = error_get_last();
    if ($errors && $errors['message']) {
        echo '<div style="margin-top: 10px; padding: 8px; background: #742a2a; border-radius: 4px; border-left: 3px solid #e53e3e;">';
        echo '<strong style="color: #feb2b2;">Last Error:</strong><br>';
        echo '<span style="font-size: 10px; color: #fed7d7;">' . htmlspecialchars($errors['message']) . '</span>';
        echo '</div>';
    }
    ?>
    
    <?php
    // Display included files count
    $included_files = get_included_files();
    echo '<div style="margin-top: 8px;">';
    echo '<strong>Included Files:</strong> ' . count($included_files);
    echo '</div>';
    ?>
    
    <div style="margin-top: 10px; font-size: 10px; color: #a0aec0;">
        Auto-reload: Enabled<br>
        Last check: <span id="last-reload-check">--:--:--</span>
    </div>
</div>

<script>
// Update the last check time
setInterval(function() {
    document.getElementById('last-reload-check').textContent = new Date().toLocaleTimeString();
}, 1000);
</script>
"""
    
    def inject_debug_code(self, file_path):
        """Inject debug panel and reload script into PHP files"""
        try:
            # Read the original file
            with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
            
            # Store original content if not already stored
            if file_path not in self.original_files:
                self.original_files[file_path] = content
            
            # Check if already injected
            if 'PHP Dev Server Auto-Reload Script' in content:
                return False
            
            reload_script = self.get_reload_script()
            debug_panel = self.get_debug_panel()
            
            # For PHP files, inject before closing body tag or at the end
            if file_path.endswith('.php'):
                if '</body>' in content.lower():
                    # Inject before closing body tag
                    content = re.sub(
                        r'</body>',
                        f'{debug_panel}\n{reload_script}\n</body>',
                        content,
                        flags=re.IGNORECASE
                    )
                elif '<html>' in content.lower() or '<!doctype' in content.lower():
                    # Has HTML structure, append at the end
                    content += f'\n{debug_panel}\n{reload_script}'
                else:
                    # Pure PHP file, wrap in HTML
                    content = f"""<!DOCTYPE html>
<html>
<head>
    <title>PHP Output - {os.path.basename(file_path)}</title>
    <meta charset="UTF-8">
</head>
<body>
<?php
// Original PHP content starts here
?>
{content}
<?php
// Original PHP content ends here
?>
{debug_panel}
{reload_script}
</body>
</html>"""
            
            elif file_path.endswith(('.html', '.htm')):
                if '</body>' in content.lower():
                    content = re.sub(
                        r'</body>',
                        f'{debug_panel}\n{reload_script}\n</body>',
                        content,
                        flags=re.IGNORECASE
                    )
                else:
                    content += f'\n{debug_panel}\n{reload_script}'
            
            # Write the modified content
            with open(file_path, 'w', encoding='utf-8') as f:
                f.write(content)
            
            print(f"[INJECT] Added debug panel to {os.path.basename(file_path)}")
            return True
            
        except Exception as e:
            print(f"[INJECT ERROR] Failed to inject into {file_path}: {e}")
            return False
    
    def restore_original_files(self):
        """Restore all files to their original state"""
        print("[CLEANUP] Restoring original files...")
        for file_path, original_content in self.original_files.items():
            try:
                with open(file_path, 'w', encoding='utf-8') as f:
                    f.write(original_content)
                print(f"[RESTORED] {os.path.basename(file_path)}")
            except Exception as e:
                print(f"[RESTORE ERROR] {file_path}: {e}")
    
    def inject_debug_to_all_files(self, directory):
        """Inject debug code to all PHP and HTML files in directory"""
        php_files = []
        html_files = []
        
        for root, dirs, files in os.walk(directory):
            for file in files:
                file_path = os.path.join(root, file)
                if file.endswith('.php'):
                    php_files.append(file_path)
                elif file.endswith(('.html', '.htm')):
                    html_files.append(file_path)
        
        total_injected = 0
        for file_path in php_files + html_files:
            if self.inject_debug_code(file_path):
                total_injected += 1
        
        print(f"[INJECT] Debug panel added to {total_injected} files")
        return total_injected
    
    def start_php_server(self, directory, port):
        """Start the PHP built-in server"""
        try:
            cmd = ['php', '-S', f'localhost:{port}', '-t', directory]
            self.php_process = subprocess.Popen(
                cmd, 
                stdout=subprocess.PIPE, 
                stderr=subprocess.PIPE,
                cwd=directory
            )
            self.php_port = port
            return True
        except Exception as e:
            print(f"[SERVER ERROR] Error starting PHP server: {e}")
            return False
    
    def start_file_watcher(self, directory):
        """Start watching files for changes"""
        def on_file_change(filepath):
            print(f"[FILE CHANGED] {os.path.basename(filepath)}")
            
            # Re-inject debug code if it's a PHP/HTML file
            if filepath.endswith(('.php', '.html', '.htm')):
                # Wait a moment for file write to complete
                time.sleep(0.5)
                
                # Restore original and re-inject
                if filepath in self.original_files:
                    with open(filepath, 'w', encoding='utf-8') as f:
                        f.write(self.original_files[filepath])
                    
                    # Re-read the modified content as new original
                    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                        self.original_files[filepath] = f.read()
                
                self.inject_debug_code(filepath)
            
            self.reload_server.trigger_reload()
            
        event_handler = FileChangeHandler(on_file_change)
        self.observer = Observer()
        self.observer.schedule(event_handler, directory, recursive=True)
        self.observer.start()
    
    def create_test_file(self, directory, port):
        """Create a test PHP file if none exists"""
        index_files = ['index.php', 'index.html']
        has_index = any(os.path.exists(os.path.join(directory, f)) for f in index_files)
        
        if not has_index:
            test_file = os.path.join(directory, 'index.php')
            with open(test_file, 'w', encoding='utf-8') as f:
                f.write(f"""<!DOCTYPE html>
<html>
<head>
    <title>PHP Development Server</title>
    <meta charset="UTF-8">
    <style>
        body {{ font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; line-height: 1.6; }}
        .container {{ background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }}
        .status {{ color: #28a745; font-weight: bold; }}
        code {{ background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: 'Courier New', monospace; }}
        .info-grid {{ display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }}
        .info-box {{ background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #007bff; }}
        .file-list {{ background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 200px; overflow-y: auto; }}
    </style>
</head>
<body>
    <div class="container">
        <h1>PHP Development Server</h1>
        <p class="status">[OK] Server is running successfully!</p>
        
        <div class="info-grid">
            <div class="info-box">
                <h3>Server Info</h3>
                <p><strong>Directory:</strong><br><code>{directory}</code></p>
                <p><strong>URL:</strong><br><code>http://localhost:{port}</code></p>
                <p><strong>PHP Version:</strong><br><code><?php echo phpversion(); ?></code></p>
            </div>
            
            <div class="info-box">
                <h3>Runtime Info</h3>
                <p><strong>Current Time:</strong><br><code><?php echo date('Y-m-d H:i:s'); ?></code></p>
                <p><strong>Memory Usage:</strong><br><code><?php echo round(memory_get_usage(true)/1024/1024, 2); ?>MB</code></p>
                <p><strong>Script:</strong><br><code><?php echo basename(__FILE__); ?></code></p>
            </div>
        </div>
        
        <h3>Features</h3>
        <ul>
            <li><strong>Auto-Reload:</strong> Edit any PHP, HTML, CSS, or JS file and the page will automatically refresh!</li>
            <li><strong>Debug Panel:</strong> Check the top-right corner for real-time server information</li>
            <li><strong>Error Monitoring:</strong> PHP errors are displayed in the debug panel</li>
            <li><strong>File Watching:</strong> All changes are monitored and logged</li>
        </ul>
        
        <h3>Files in Directory</h3>
        <div class="file-list">
            <?php
            $files = scandir('.');
            $phpFiles = [];
            $otherFiles = [];
            
            foreach($files as $file) {{
                if($file != '.' && $file != '..') {{
                    if(pathinfo($file, PATHINFO_EXTENSION) === 'php') {{
                        $phpFiles[] = $file;
                    }} else {{
                        $otherFiles[] = $file;
                    }}
                }}
            }}
            
            if(!empty($phpFiles)) {{
                echo '<h4>PHP Files:</h4><ul>';
                foreach($phpFiles as $file) {{
                    echo "<li><code>$file</code> - <a href='$file' target='_blank'>Open</a></li>";
                }}
                echo '</ul>';
            }}
            
            if(!empty($otherFiles)) {{
                echo '<h4>Other Files:</h4><ul>';
                foreach($otherFiles as $file) {{
                    echo "<li><code>$file</code></li>";
                }}
                echo '</ul>';
            }}
            ?>
        </div>
        
        <div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-radius: 5px; border-left: 4px solid #007bff;">
            <h4>Quick Test</h4>
            <p>Current PHP timestamp: <strong><?php echo time(); ?></strong></p>
            <p>Random number: <strong><?php echo rand(1, 1000); ?></strong></p>
        </div>
    </div>
</body>
</html>""")
            print(f"[CREATED] Test file: {test_file}")
            return test_file
        return None
    
    def stop_server(self):
        """Stop the PHP server and file watcher"""
        # Restore original files first
        self.restore_original_files()
        
        if self.php_process:
            self.php_process.terminate()
            try:
                self.php_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.php_process.kill()
            self.php_process = None
            
        if self.observer:
            self.observer.stop()
            self.observer.join()
            self.observer = None
    
    def select_directory(self):
        """Show directory selection dialog"""
        root = tk.Tk()
        root.withdraw()  # Hide the main window
        
        directory = filedialog.askdirectory(
            title="Select PHP Project Directory",
            initialdir=os.getcwd()
        )
        
        root.destroy()
        return directory
    
    def run(self):
        """Main application loop"""
        print("PHP Development Server with Auto-Reload")
        print("=" * 50)
        
        # Check PHP installation
        if not self.check_php_installation():
            print("[ERROR] PHP is not installed or not in PATH")
            print("Please install PHP and make sure it's accessible from command line")
            input("Press Enter to exit...")
            return
        
        print("[OK] PHP installation found")
        
        # Select directory
        print("\n[SELECT] Please select your PHP project directory...")
        directory = self.select_directory()
        
        if not directory:
            print("[ERROR] No directory selected. Exiting...")
            return
        
        self.current_directory = directory
        print(f"[SELECTED] Selected directory: {directory}")
        
        # Find free ports
        php_port = self.find_free_port(8000)
        reload_port = self.find_free_port(8081)
        
        if not php_port or not reload_port:
            print("[ERROR] Could not find free ports")
            return
        
        print(f"[SERVER] Starting PHP server on port {php_port}...")
        print(f"[RELOAD] Starting reload server on port {reload_port}...")
        
        # Start reload server
        self.reload_server.port = reload_port
        self.reload_server.start()
        
        # Start PHP server
        if not self.start_php_server(directory, php_port):
            print("[ERROR] Failed to start PHP server")
            return
        
        # Create test file if needed
        test_file = self.create_test_file(directory, php_port)
        
        # Inject debug code to all existing files
        time.sleep(1)  # Give server time to start
        injected_count = self.inject_debug_to_all_files(directory)
        
        # Start file watcher
        self.start_file_watcher(directory)
        
        # Open browser
        url = f"http://localhost:{php_port}"
        print(f"[SUCCESS] Server started successfully!")
        print(f"[BROWSER] Opening {url} in your browser...")
        
        time.sleep(2)  # Give everything time to start
        webbrowser.open(url)
        
        print("\n" + "=" * 50)
        print("[WATCHING] File watching is ACTIVE")
        print("[INFO] Edit any .php, .html, .css, or .js file to see auto-reload")
        print("[DEBUG] Debug panel injected into all PHP/HTML files")
        print("[STOP] Press Ctrl+C to stop the server")
        print("=" * 50)
        
        try:
            # Keep the main thread alive
            while True:
                time.sleep(1)
                # Check if PHP process is still running
                if self.php_process and self.php_process.poll() is not None:
                    print("[ERROR] PHP server stopped unexpectedly")
                    break
        except KeyboardInterrupt:
            print("\n[STOPPING] Stopping server...")
        finally:
            self.stop_server()
            print("[STOPPED] Server stopped successfully")

def main():
    """Entry point"""
    try:
        server = PHPDevServer()
        server.run()
    except Exception as e:
        print(f"[ERROR] An error occurred: {e}")
        input("Press Enter to exit...")

if __name__ == "__main__":
    main()