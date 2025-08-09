<?php
require_once 'p_database.php';

// Handle different actions
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'fetch':
        handleFetchLibrarians();
        break;
    case 'add':
        handleAddLibrarian();
        break;
    case 'update':
        handleUpdateLibrarian();
        break;
    case 'delete':
        handleDeleteLibrarian();
        break;
    default:
        sendError('Invalid action. Use: fetch, add, update, or delete');
        break;
}

// Fetch all librarians with their statistics
function handleFetchLibrarians() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed for fetch action', 405);
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT 
                    l.l_id as id,
                    l.l_name as name,
                    l.l_email as email,
                    l.l_mobile as phone,
                    l.l_createdat as joinDate,
                    COALESCE(COUNT(DISTINCT b.b_id), 0) as booksRecorded,
                    COALESCE(COUNT(DISTINCT d.u_id), 0) as totalDonationsProcessed
                  FROM login l
                  LEFT JOIN books b ON l.l_id = b.bl_id
                  LEFT JOIN donors d ON l.l_id = d.ul_id
                  WHERE l.l_role = 'librarian'
                  GROUP BY l.l_id, l.l_name, l.l_email, l.l_mobile, l.l_createdat
                  ORDER BY l.l_createdat DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $librarians = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $transformedLibrarians = array_map(function($librarian) {
            return [
                'id' => (string)$librarian['id'],
                'name' => $librarian['name'],
                'email' => $librarian['email'],
                'phone' => $librarian['phone'],
                'joinDate' => date('Y-m-d', strtotime($librarian['joinDate'])),
                'booksRecorded' => (int)$librarian['booksRecorded'],
                'totalDonationsProcessed' => (int)$librarian['totalDonationsProcessed']
            ];
        }, $librarians);
        
        sendSuccess('Librarians retrieved successfully', $transformedLibrarians);

    } catch (Exception $e) {
        sendError('Error fetching librarians: ' . $e->getMessage(), 500);
    }
}

// Add new librarian
function handleAddLibrarian() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for add action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['name']) || empty(trim($input['name']))) {
        sendError('Librarian name is required');
    }

    if (!isset($input['email']) || empty(trim($input['email']))) {
        sendError('Email is required');
    }

    if (!isset($input['phone']) || empty(trim($input['phone']))) {
        sendError('Phone number is required');
    }

    if (!isset($input['password']) || empty(trim($input['password']))) {
        sendError('Password is required');
    }

    $name = trim($input['name']);
    $email = trim($input['email']);
    $phone = trim($input['phone']);
    $password = trim($input['password']);

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError('Invalid email format');
    }

    // Validate phone number (10 digits)
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        sendError('Phone number must be exactly 10 digits');
    }

    // Validate password length
    if (strlen($password) < 6) {
        sendError('Password must be at least 6 characters long');
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Check if email already exists
        $emailCheckQuery = "SELECT l_id FROM login WHERE l_email = :email AND l_role = 'librarian'";
        $emailCheckStmt = $conn->prepare($emailCheckQuery);
        $emailCheckStmt->bindParam(':email', $email);
        $emailCheckStmt->execute();
        
        if ($emailCheckStmt->rowCount() > 0) {
            sendError('Email already exists');
        }

        // Check if phone number already exists
        $phoneCheckQuery = "SELECT l_id FROM login WHERE l_mobile = :phone AND l_role = 'librarian'";
        $phoneCheckStmt = $conn->prepare($phoneCheckQuery);
        $phoneCheckStmt->bindParam(':phone', $phone);
        $phoneCheckStmt->execute();
        
        if ($phoneCheckStmt->rowCount() > 0) {
            sendError('Phone number already exists');
        }

        // Hash password
        // $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert new librarian
        $insertQuery = "INSERT INTO login (l_name, l_email, l_mobile, l_password, l_role, l_createdat) 
                       VALUES (:name, :email, :phone, :password, 'librarian', NOW())";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bindParam(':name', $name);
        $insertStmt->bindParam(':email', $email);
        $insertStmt->bindParam(':phone', $phone);
        $insertStmt->bindParam(':password', $password);
        $insertStmt->execute();
        
        $newLibrarianId = $conn->lastInsertId();
        
        // Get the newly created librarian with statistics
        $librarian = getLibrarianById($conn, $newLibrarianId);
        sendSuccess('Librarian added successfully', $librarian);

    } catch (Exception $e) {
        sendError('Error adding librarian: ' . $e->getMessage(), 500);
    }
}

// Update existing librarian
function handleUpdateLibrarian() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for update action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['id']) || empty($input['id'])) {
        sendError('Librarian ID is required');
    }

    if (!isset($input['name']) || empty(trim($input['name']))) {
        sendError('Librarian name is required');
    }

    if (!isset($input['email']) || empty(trim($input['email']))) {
        sendError('Email is required');
    }

    if (!isset($input['phone']) || empty(trim($input['phone']))) {
        sendError('Phone number is required');
    }

    $librarianId = (int)$input['id'];
    $name = trim($input['name']);
    $email = trim($input['email']);
    $phone = trim($input['phone']);
    $password = isset($input['password']) ? trim($input['password']) : '';

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError('Invalid email format');
    }

    // Validate phone number (10 digits)
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        sendError('Phone number must be exactly 10 digits');
    }

    // Validate password if provided
    if (!empty($password) && strlen($password) < 6) {
        sendError('Password must be at least 6 characters long');
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Check if librarian exists
        $checkQuery = "SELECT l_id FROM login WHERE l_id = :librarianId AND l_role = 'librarian'";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':librarianId', $librarianId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() == 0) {
            sendError('Librarian not found', 404);
        }

        // Check if email already exists for another librarian
        $emailCheckQuery = "SELECT l_id FROM login WHERE l_email = :email AND l_id != :librarianId AND l_role = 'librarian'";
        $emailCheckStmt = $conn->prepare($emailCheckQuery);
        $emailCheckStmt->bindParam(':email', $email);
        $emailCheckStmt->bindParam(':librarianId', $librarianId);
        $emailCheckStmt->execute();
        
        if ($emailCheckStmt->rowCount() > 0) {
            sendError('Email already exists for another librarian');
        }

        // Check if phone number already exists for another librarian
        $phoneCheckQuery = "SELECT l_id FROM login WHERE l_mobile = :phone AND l_id != :librarianId AND l_role = 'librarian'";
        $phoneCheckStmt = $conn->prepare($phoneCheckQuery);
        $phoneCheckStmt->bindParam(':phone', $phone);
        $phoneCheckStmt->bindParam(':librarianId', $librarianId);
        $phoneCheckStmt->execute();
        
        if ($phoneCheckStmt->rowCount() > 0) {
            sendError('Phone number already exists for another librarian');
        }

        // Prepare update query
        if (!empty($password)) {
            // Update with password
            // $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $updateQuery = "UPDATE login SET 
                           l_name = :name, 
                           l_email = :email, 
                           l_mobile = :phone, 
                           l_password = :password,
                           l_updatedat = NOW() 
                           WHERE l_id = :librarianId";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bindParam(':password', $password);
        } else {
            // Update without password
            $updateQuery = "UPDATE login SET 
                           l_name = :name, 
                           l_email = :email, 
                           l_mobile = :phone,
                           l_updatedat = NOW() 
                           WHERE l_id = :librarianId";
            $updateStmt = $conn->prepare($updateQuery);
        }
        
        $updateStmt->bindParam(':name', $name);
        $updateStmt->bindParam(':email', $email);
        $updateStmt->bindParam(':phone', $phone);
        $updateStmt->bindParam(':librarianId', $librarianId);
        $updateStmt->execute();
        
        // Get updated librarian with statistics
        $librarian = getLibrarianById($conn, $librarianId);
        sendSuccess('Librarian updated successfully', $librarian);

    } catch (Exception $e) {
        sendError('Error updating librarian: ' . $e->getMessage(), 500);
    }
}

// Delete librarian
function handleDeleteLibrarian() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for delete action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['librarianId']) || empty($input['librarianId'])) {
        sendError('Librarian ID is required');
    }

    $librarianId = (int)$input['librarianId'];

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Check if librarian exists
        $checkQuery = "SELECT l_id FROM login WHERE l_id = :librarianId AND l_role = 'librarian'";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':librarianId', $librarianId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() == 0) {
            sendError('Librarian not found', 404);
        }

        // Check if librarian has recorded books
        $booksCheckQuery = "SELECT COUNT(*) as book_count FROM books WHERE bl_id = :librarianId";
        $booksCheckStmt = $conn->prepare($booksCheckQuery);
        $booksCheckStmt->bindParam(':librarianId', $librarianId);
        $booksCheckStmt->execute();
        $bookCount = $booksCheckStmt->fetch(PDO::FETCH_ASSOC)['book_count'];

        if ($bookCount > 0) {
            sendError('Cannot delete librarian who has recorded books. Please reassign all books first.');
        }

        // Check if librarian has processed donors
        $usersCheckQuery = "SELECT COUNT(*) as user_count FROM donors WHERE ul_id = :librarianId";
        $usersCheckStmt = $conn->prepare($usersCheckQuery);
        $usersCheckStmt->bindParam(':librarianId', $librarianId);
        $usersCheckStmt->execute();
        $userCount = $usersCheckStmt->fetch(PDO::FETCH_ASSOC)['user_count'];

        if ($userCount > 0) {
            sendError('Cannot delete librarian who has processed donors. Please reassign all donors first.');
        }

        // Check if librarian has file records
        $filesCheckQuery = "SELECT COUNT(*) as file_count FROM files WHERE f_lib_id = :librarianId";
        $filesCheckStmt = $conn->prepare($filesCheckQuery);
        $filesCheckStmt->bindParam(':librarianId', $librarianId);
        $filesCheckStmt->execute();
        $fileCount = $filesCheckStmt->fetch(PDO::FETCH_ASSOC)['file_count'];

        if ($fileCount > 0) {
            sendError('Cannot delete librarian who has file records. Please reassign all files first.');
        }

        // Delete librarian
        $deleteQuery = "DELETE FROM login WHERE l_id = :librarianId";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bindParam(':librarianId', $librarianId);
        $deleteStmt->execute();
        
        sendSuccess('Librarian deleted successfully');

    } catch (Exception $e) {
        sendError('Error deleting librarian: ' . $e->getMessage(), 500);
    }
}

// Helper function to get librarian by ID
function getLibrarianById($conn, $librarianId) {
    $selectQuery = "SELECT 
                      l.l_id as id,
                      l.l_name as name,
                      l.l_email as email,
                      l.l_mobile as phone,
                      l.l_createdat as joinDate,
                      COALESCE(COUNT(DISTINCT b.b_id), 0) as booksRecorded,
                      COALESCE(COUNT(DISTINCT d.u_id), 0) as totalDonationsProcessed
                    FROM login l
                    LEFT JOIN books b ON l.l_id = b.bl_id
                    LEFT JOIN donors d ON l.l_id = d.ul_id
                    WHERE l.l_id = :librarianId AND l.l_role = 'librarian'
                    GROUP BY l.l_id, l.l_name, l.l_email, l.l_mobile, l.l_createdat";
    
    $selectStmt = $conn->prepare($selectQuery);
    $selectStmt->bindParam(':librarianId', $librarianId);
    $selectStmt->execute();
    
    $librarian = $selectStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$librarian) {
        return null;
    }
    
    return [
        'id' => (string)$librarian['id'],
        'name' => $librarian['name'],
        'email' => $librarian['email'],
        'phone' => $librarian['phone'],
        'joinDate' => date('Y-m-d', strtotime($librarian['joinDate'])),
        'booksRecorded' => (int)$librarian['booksRecorded'],
        'totalDonationsProcessed' => (int)$librarian['totalDonationsProcessed']
    ];
}
?>
