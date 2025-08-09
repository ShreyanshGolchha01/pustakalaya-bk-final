<?php
include 'p_database.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if file was uploaded
    if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
        sendResponse(false, 'No file uploaded or upload error');
        exit();
    }
    
    $file = $_FILES['certificate'];
    $donor_id = isset($_POST['donor_id']) ? $_POST['donor_id'] : null;
    $librarian_id = isset($_POST['librarian_id']) ? $_POST['librarian_id'] : null;
    
    if (!$donor_id || !$librarian_id) {
        sendResponse(false, 'Donor ID and Librarian ID are required');
        exit();
    }
    
    // Validate file type - support all common image formats
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'img', 'webp'];
    
    // Check MIME type first
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    // Check file extension
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($mimeType, $allowedTypes) && !in_array($fileExtension, $allowedExtensions)) {
        sendResponse(false, 'Only image files are allowed (JPEG, JPG, PNG, GIF, BMP, IMG, WEBP)');
        exit();
    }

    // Validate file size (max 8MB)
    if ($file['size'] > 8 * 1024 * 1024) {
        sendResponse(false, 'File size too large. Maximum 8MB allowed');
        exit();
    }
    
    try {
        // Create upload directory if it doesn't exist (relative to current directory)
        $uploadDir = 'uploads/certificates/';
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                sendResponse(false, 'Failed to create upload directory');
                exit();
            }
        }
        
        // Generate unique filename with sanitization
        $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '', pathinfo($file['name'], PATHINFO_FILENAME));
        $fileName = 'cert_' . $donor_id . '_' . $originalName . '_' . time() . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Save file record in database
            $query = "INSERT INTO files (f_path, f_user_id, f_lib_id) VALUES (:path, :user_id, :lib_id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':path', $fileName); // Store relative path
            $stmt->bindParam(':user_id', $donor_id);
            $stmt->bindParam(':lib_id', $librarian_id);
            
            if ($stmt->execute()) {
                $file_id = $db->lastInsertId();
                sendResponse(true, 'Certificate uploaded successfully', [
                    'file_id' => $file_id,
                    'file_path' => $fileName,
                    'full_path' => $filePath
                ]);
            } else {
                // Delete uploaded file if database insert fails
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                sendResponse(false, 'Failed to save file record in database');
            }
        } else {
            sendResponse(false, 'Failed to move uploaded file to destination');
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error uploading certificate: ' . $e->getMessage());
    }
} else {
    sendResponse(false, 'Only POST method allowed');
}
?>
