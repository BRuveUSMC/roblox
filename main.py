#!/usr/bin/env python3
"""
PHP Development Server
A simple Python app that starts a PHP server.
"""

import os
import subprocess
import threading
import time
import webbrowser
from pathlib import Path
import tkinter as tk
from tkinter import filedialog
import socket

class PHPDevServer:
    def __init__(self):
        self.php_process = None
        self.current_directory = None
        self.php_port = None
        
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
    <p>Hello World, server is working</p>
        </div>
    </div>
</body>
</html>""")
            print(f"[CREATED] Test file: {test_file}")
            return test_file
        return None
    
    def stop_server(self):
        """Stop the PHP server"""
        if self.php_process:
            self.php_process.terminate()
            try:
                self.php_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.php_process.kill()
            self.php_process = None
    
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
        print("PHP Development Server")
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
        
        # Find free port
        php_port = self.find_free_port(8000)
        
        if not php_port:
            print("[ERROR] Could not find free port")
            return
        
        print(f"[SERVER] Starting PHP server on port {php_port}...")
        
        # Start PHP server
        if not self.start_php_server(directory, php_port):
            print("[ERROR] Failed to start PHP server")
            return
        
        # Create test file if needed
        test_file = self.create_test_file(directory, php_port)
        
        # Open browser
        url = f"http://localhost:{php_port}"
        print(f"[SUCCESS] Server started successfully!")
        print(f"[BROWSER] Opening {url} in your browser...")
        
        time.sleep(2)  # Give everything time to start
        webbrowser.open(url)
        
        print("\n" + "=" * 50)
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