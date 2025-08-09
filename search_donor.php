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
    
    if (!isset($data['mobile'])) {
        sendResponse(false, 'Mobile number is required');
    }
    
    $mobile = $data['mobile'];
    
    try {
        // Search for donor by mobile number
        $query = "SELECT u_id, u_name, u_mobile FROM donors WHERE u_mobile = :mobile";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':mobile', $mobile);
        $stmt->execute();
        
        $donor = $stmt->fetch();
        
        if ($donor) {
            sendResponse(true, 'Donor found', $donor);
        } else {
            sendResponse(false, 'Donor not found');
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error searching donor: ' . $e->getMessage());
    }
} else {
    sendResponse(false, 'Only POST method allowed');
}
?>
