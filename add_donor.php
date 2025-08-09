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
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['name']) || !isset($data['mobile']) || !isset($data['librarian_id'])) {
        sendResponse(false, 'Name, mobile, and librarian_id are required');
    }
    
    $name = $data['name'];
    $mobile = $data['mobile'];
    $librarian_id = $data['librarian_id'];
    
    try {
        // Check if donor already exists
        $checkQuery = "SELECT u_id FROM donors WHERE u_mobile = :mobile";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':mobile', $mobile);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            sendResponse(false, 'Donor with this mobile number already exists');
        }
        
        // Insert new donor
        $query = "INSERT INTO donors (u_name, u_mobile, ul_id) VALUES (:name, :mobile, :librarian_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':mobile', $mobile);
        $stmt->bindParam(':librarian_id', $librarian_id);
        
        if ($stmt->execute()) {
            $donor_id = $db->lastInsertId();
            sendResponse(true, 'Donor added successfully', [
                'u_id' => $donor_id,
                'donor_id' => $donor_id, // Add both for compatibility
                'u_name' => $name,
                'name' => $name,
                'u_mobile' => $mobile,
                'mobile' => $mobile
            ]);
        }  else {
            sendResponse(false, 'Failed to add donor');
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error adding donor: ' . $e->getMessage());
    }
} else {
    sendResponse(false, 'Only POST method allowed');
}
?>
