<?php
session_start();

// Create uploads directory if it doesn't exist
$uploadsDir = 'uploads/';
$imagesDir = 'uploads/images/';
if (!file_exists($uploadsDir)) {
    mkdir($uploadsDir, 0777, true);
}
if (!file_exists($imagesDir)) {
    mkdir($imagesDir, 0777, true);
}

// Initialize posts array in session if not exists
if (!isset($_SESSION['posts'])) {
    $_SESSION['posts'] = [];
}

// Initialize banned IPs array in session if not exists
if (!isset($_SESSION['banned_ips'])) {
    $_SESSION['banned_ips'] = [];
}

// Function to get user IP
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// Function to check if IP is banned
function isIPBanned($ip) {
    global $_SESSION;
    if (isset($_SESSION['banned_ips'][$ip])) {
        $banTime = $_SESSION['banned_ips'][$ip];
        if (time() - $banTime < 1800) { // 30 minutes = 1800 seconds
            return true;
        } else {
            // Ban expired, remove it
            unset($_SESSION['banned_ips'][$ip]);
        }
    }
    return false;
}

// Function to ban IP
function banIP($ip) {
    global $_SESSION;
    $_SESSION['banned_ips'][$ip] = time();
}

// Function to check for forbidden words
function containsForbiddenWords($text) {
    $forbiddenWords = ['leak', 'steal', 'leaking', 'ripped', 'stolen', 'rip', 'leaked', 'pirated', 'cracked'];
    $text = strtolower($text);
    
    foreach ($forbiddenWords as $word) {
        if (strpos($text, $word) !== false) {
            return true;
        }
    }
    return false;
}

$userIP = getUserIP();

// Check if user is banned
if (isIPBanned($userIP)) {
    $remainingTime = 1800 - (time() - $_SESSION['banned_ips'][$userIP]);
    $minutes = floor($remainingTime / 60);
    $seconds = $remainingTime % 60;
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Access Denied</title>
        <style>
            body { background: linear-gradient(135deg, #0f0f23, #1a1a2e); color: #ff6b6b; font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .ban-container { background: rgba(255,255,255,0.1); padding: 40px; border-radius: 20px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.3); backdrop-filter: blur(10px); }
            h1 { color: #ff4757; text-shadow: 0 0 20px #ff4757; }
        </style>
    </head>
    <body>
        <div class='ban-container'>
            <h1>ACCESS DENIED</h1>
            <p>Your IP has been temporarily banned for violating our terms of service.</p>
            <p>Ban expires in: <strong>{$minutes} minutes and {$seconds} seconds</strong></p>
            <p>Reason: Attempting to share potentially stolen or leaked content</p>
        </div>
    </body>
    </html>";
    exit;
}

$warningMessage = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload'])) {
    $name = htmlspecialchars($_POST['name']);
    $description = htmlspecialchars($_POST['description']);
    
    // Check for forbidden words and warn/ban
    if (containsForbiddenWords($name) || containsForbiddenWords($description)) {
        // Allow the upload but ban the user
        $warningMessage = "WARNING: Your content contained prohibited terms suggesting stolen or leaked content. Your upload was processed but your IP has been banned for 30 minutes.";
        banIP($userIP);
    }
    
    $uploadSuccess = true;
    $assetFile = '';
    $imageFile = '';
    
    // Handle asset file upload
    if (isset($_FILES['asset']) && $_FILES['asset']['error'] === UPLOAD_ERR_OK) {
        $assetTmpName = $_FILES['asset']['tmp_name'];
        $assetName = basename($_FILES['asset']['name']);
        $assetPath = $uploadsDir . time() . '_' . $assetName;
        
        if (move_uploaded_file($assetTmpName, $assetPath)) {
            $assetFile = $assetPath;
        } else {
            $uploadSuccess = false;
        }
    }
    
    // Handle image upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imageTmpName = $_FILES['image']['tmp_name'];
        $imageName = basename($_FILES['image']['name']);
        $imagePath = $imagesDir . time() . '_' . $imageName;
        
        if (move_uploaded_file($imageTmpName, $imagePath)) {
            $imageFile = $imagePath;
        }
    }
    
    // Add post to session
    if ($uploadSuccess && $assetFile) {
        $post = [
            'id' => time() . rand(1000, 9999),
            'name' => $name,
            'description' => $description,
            'asset_file' => $assetFile,
            'image_file' => $imageFile,
            'upload_time' => date('Y-m-d H:i:s'),
            'downloads' => 0
        ];
        
        $_SESSION['posts'][] = $post;
        if (!$warningMessage) {
            $successMessage = "Asset uploaded successfully!";
        }
    } else {
        $errorMessage = "Failed to upload asset file.";
    }
}

// Handle file download
if (isset($_GET['download']) && isset($_GET['id'])) {
    $downloadId = $_GET['id'];
    
    foreach ($_SESSION['posts'] as &$post) {
        if ($post['id'] == $downloadId) {
            $post['downloads']++;
            
            if (file_exists($post['asset_file'])) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($post['asset_file']) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($post['asset_file']));
                readfile($post['asset_file']);
                exit;
            }
            break;
        }
    }
}

// Handle post deletion
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $deleteId = $_GET['id'];
    
    foreach ($_SESSION['posts'] as $key => $post) {
        if ($post['id'] == $deleteId) {
            // Delete files
            if (file_exists($post['asset_file'])) {
                unlink($post['asset_file']);
            }
            if ($post['image_file'] && file_exists($post['image_file'])) {
                unlink($post['image_file']);
            }
            
            // Remove from array
            unset($_SESSION['posts'][$key]);
            $_SESSION['posts'] = array_values($_SESSION['posts']);
            break;
        }
    }
    
    header('Location: index.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roblox Asset Sharing</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #0f0f23, #1a1a2e, #16213e);
            color: #e8eaed;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding: 40px 0;
        }
        
        .header h1 {
            font-size: 3.5em;
            background: linear-gradient(45deg, #00d4ff, #0099cc, #0066ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 0 30px rgba(0, 212, 255, 0.3);
            margin-bottom: 10px;
            animation: glow 2s ease-in-out infinite alternate;
        }
        
        @keyframes glow {
            from { text-shadow: 0 0 20px rgba(0, 212, 255, 0.3); }
            to { text-shadow: 0 0 40px rgba(0, 212, 255, 0.6); }
        }
        
        .card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            transition: all 0.3s ease;
            animation: slideUp 0.6s ease-out;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 212, 255, 0.1);
            border-color: rgba(0, 212, 255, 0.3);
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #00d4ff;
            font-weight: 600;
        }
        
        .form-control {
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 12px 16px;
            color: #e8eaed;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #00d4ff;
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.3);
            background: rgba(255, 255, 255, 0.15);
        }
        
        .btn {
            background: linear-gradient(45deg, #00d4ff, #0099cc);
            border: none;
            border-radius: 10px;
            padding: 12px 24px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 212, 255, 0.3);
            background: linear-gradient(45deg, #0099cc, #00d4ff);
        }
        
        .btn-danger {
            background: linear-gradient(45deg, #ff4757, #ff3742);
        }
        
        .btn-danger:hover {
            background: linear-gradient(45deg, #ff3742, #ff4757);
            box-shadow: 0 10px 20px rgba(255, 71, 87, 0.3);
        }
        
        .asset-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }
        
        .asset-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s ease;
            animation: fadeIn 0.8s ease-out;
        }
        
        .asset-item:hover {
            transform: translateY(-5px);
            border-color: #00d4ff;
            box-shadow: 0 15px 30px rgba(0, 212, 255, 0.2);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .asset-preview {
            width: 100px;
            height: 100px;
            border-radius: 10px;
            object-fit: cover;
            float: left;
            margin-right: 20px;
            margin-bottom: 20px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }
        
        .no-preview {
            width: 100px;
            height: 100px;
            background: linear-gradient(45deg, #2c2c54, #40407a);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            float: left;
            margin-right: 20px;
            margin-bottom: 20px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: #6c757d;
            font-size: 12px;
            text-align: center;
        }
        
        .asset-title {
            color: #00d4ff;
            font-size: 1.5em;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .asset-description {
            color: #b3b3b3;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .asset-meta {
            font-size: 0.9em;
            color: #888;
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: slideDown 0.5s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state h3 {
            font-size: 2em;
            margin-bottom: 15px;
            color: #495057;
        }
        
        .stats-bar {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .stats-bar h2 {
            color: #00d4ff;
            margin-bottom: 10px;
        }
        
        .warning-box {
            background: rgba(255, 193, 7, 0.1);
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            color: #ffc107;
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(0, 212, 255, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(0, 212, 255, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 212, 255, 0); }
        }
        
        .footer {
            text-align: center;
            padding: 40px 20px;
            margin-top: 50px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>ROBLOX ASSET SHARING</h1>
            <p>Share your original creations with the community</p>
        </div>
        
        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success">
                <strong>SUCCESS:</strong> <?php echo $successMessage; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-danger">
                <strong>ERROR:</strong> <?php echo $errorMessage; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($warningMessage)): ?>
            <div class="alert alert-warning">
                <strong>WARNING:</strong> <?php echo $warningMessage; ?>
            </div>
        <?php endif; ?>
        
        <div class="card pulse">
            <h2>Upload New Asset</h2>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="form-group">
                    <label for="name">Asset Name</label>
                    <input type="text" name="name" id="name" class="form-control" required placeholder="Enter your asset name">
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="4" placeholder="Describe your asset..."></textarea>
                </div>
                
                <div class="form-group">
                    <label for="asset">Asset File</label>
                    <input type="file" name="asset" id="asset" class="form-control" required accept=".rbxm,.rbxl,.lua,.txt,.json">
                </div>
                
                <div class="form-group">
                    <label for="image">Preview Image (Optional)</label>
                    <input type="file" name="image" id="image" class="form-control" accept="image/*">
                </div>
                
                <button type="submit" name="upload" class="btn" onclick="return validateForm()">Upload Asset</button>
                <button type="reset" class="btn" style="background: linear-gradient(45deg, #6c757d, #495057);">Reset Form</button>
            </form>
            
            <div class="warning-box">
                <strong>TERMS:</strong> Only upload content you own or have permission to share. Uploading stolen, leaked, or ripped content will result in an immediate 30-minute IP ban.
            </div>
        </div>
        
        <div class="stats-bar">
            <h2>Available Assets (<?php echo count($_SESSION['posts']); ?>)</h2>
            <p>Community shared Roblox creations</p>
        </div>
        
        <?php if (empty($_SESSION['posts'])): ?>
            <div class="card">
                <div class="empty-state">
                    <h3>No Assets Yet</h3>
                    <p>Be the first to share your Roblox creations with the community!</p>
                </div>
            </div>
        <?php else: ?>
            <div class="asset-grid">
                <?php foreach (array_reverse($_SESSION['posts']) as $post): ?>
                    <div class="asset-item">
                        <?php if ($post['image_file'] && file_exists($post['image_file'])): ?>
                            <img src="<?php echo $post['image_file']; ?>" alt="Preview" class="asset-preview">
                        <?php else: ?>
                            <div class="no-preview">NO IMAGE</div>
                        <?php endif; ?>
                        
                        <div class="asset-title"><?php echo $post['name']; ?></div>
                        <div class="asset-description"><?php echo nl2br($post['description']); ?></div>
                        <div class="asset-meta">
                            Uploaded: <?php echo $post['upload_time']; ?> | Downloads: <?php echo $post['downloads']; ?>
                        </div>
                        
                        <div style="clear: both;">
                            <a href="?download=1&id=<?php echo $post['id']; ?>" class="btn">Download Asset</a>
                            <a href="?delete=1&id=<?php echo $post['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this asset?')">Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>Roblox Asset Sharing Platform | Community Driven | Original Content Only</p>
            <p><strong>Supported formats:</strong> .rbxm, .rbxl, .lua, .txt, .json</p>
        </div>
    </div>

    <script>
        function validateForm() {
            var name = document.getElementById('name').value;
            var asset = document.getElementById('asset').value;
            
            if (name.trim() === "") {
                alert("Please enter an asset name!");
                return false;
            }
            
            if (asset === "") {
                alert("Please select an asset file to upload!");
                return false;
            }
            
            // Check file extension
            var allowedExtensions = /(\.rbxm|\.rbxl|\.lua|\.txt|\.json)$/i;
            if (!allowedExtensions.exec(asset)) {
                alert("Please upload a valid Roblox asset file (.rbxm, .rbxl, .lua, .txt, .json)!");
                return false;
            }
            
            return true;
        }
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    alert.style.animation = 'slideUp 0.5s ease-out reverse';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 500);
                });
            }, 5000);
            
            // File size validation
            var assetInput = document.getElementById('asset');
            if (assetInput) {
                assetInput.addEventListener('change', function() {
                    var file = this.files[0];
                    if (file && file.size > 10 * 1024 * 1024) {
                        alert("File size cannot exceed 10MB!");
                        this.value = '';
                    }
                });
            }
            
            // Character limit for name
            var nameInput = document.getElementById('name');
            if (nameInput) {
                nameInput.addEventListener('input', function() {
                    if (this.value.length > 100) {
                        this.value = this.value.substring(0, 100);
                        alert("Asset name cannot exceed 100 characters!");
                    }
                });
            }
        });
        
        // Add loading animation when form is submitted
        document.getElementById('uploadForm').addEventListener('submit', function() {
            var submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = 'Uploading...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>