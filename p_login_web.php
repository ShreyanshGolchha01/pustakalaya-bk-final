<?php
require_once 'p_database.php';

// Only allow POST requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['email']) || !isset($input['password'])) {
    sendError('Email and password are required');
}

$email = trim($input['email']);
$password = trim($input['password']);

// Basic validation
if (empty($email) || empty($password)) {
    sendError('Email and password cannot be empty');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendError('Invalid email format');
}

try {
    // Database connection
    $database = new Database();
    $conn = $database->getConnection();

    // Prepare SQL query to find user with admin role only
    $query = "SELECT l_id, l_name, l_email, l_password, l_mobile, l_role, l_createdAt 
              FROM login 
              WHERE l_email = :email AND l_role = 'admin' 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user exists and has admin role
    if (!$user) {
        sendError('Invalid credentials or insufficient permissions');
    }
    
    // Verify password (plain text comparison since passwords are not hashed)
    if ($password !== $user['l_password']) {
        sendError('Invalid credentials');
    }
    
    // Remove password from response for security
    unset($user['l_password']);
    
    // Update last login time (optional)
    $updateQuery = "UPDATE login SET l_updatedAt = CURRENT_TIMESTAMP WHERE l_id = :id";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bindParam(':id', $user['l_id']);
    $updateStmt->execute();
    
    // Prepare user data for frontend
    $userData = [
        'id' => $user['l_id'],
        'name' => $user['l_name'],
        'email' => $user['l_email'],
        'mobile' => $user['l_mobile'],
        'role' => $user['l_role'],
        'createdAt' => $user['l_createdAt']
    ];
    
    // You can also generate a JWT token here if needed
    // $token = generateJWTToken($userData);
    // $userData['token'] = $token;
    
    sendSuccess('Login successful', $userData);
    
} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}

// Optional: JWT token generation function (uncomment if you want to use JWT)
/*
function generateJWTToken($userData) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'user_id' => $userData['id'],
        'email' => $userData['email'],
        'role' => $userData['role'],
        'exp' => time() + (24 * 60 * 60) // 24 hours
    ]);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, 'your-secret-key', true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}
*/
?>
