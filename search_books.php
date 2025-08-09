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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $librarian_id = isset($_GET['librarian_id']) ? $_GET['librarian_id'] : '';
    
    try {
        $query = "SELECT b_id, b_title, b_author, b_genre, b_count FROM books WHERE 1=1";
        $params = [];
        
        // Add search filter if provided
        if (!empty($search)) {
            $query .= " AND (b_title LIKE :search OR b_author LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        // Filter by librarian if needed (optional)
        if (!empty($librarian_id)) {
            $query .= " AND bl_id = :librarian_id";
            $params[':librarian_id'] = $librarian_id;
        }
        
        $query .= " ORDER BY b_title ASC";
        
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        
        $books = $stmt->fetchAll();
        
        sendResponse(true, 'Books retrieved successfully', $books);
        
    } catch (Exception $e) {
        sendResponse(false, 'Error searching books: ' . $e->getMessage());
    }
} else {
    sendResponse(false, 'Only GET method allowed');
}
?>
