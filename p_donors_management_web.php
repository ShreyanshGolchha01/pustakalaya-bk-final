<?php
// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'p_database.php';

// Handle different actions
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'fetch':
        handleFetchDonors();
        break;
    case 'add':
        handleAddDonor();
        break;
    case 'update':
        handleUpdateDonor();
        break;
    case 'delete':
        handleDeleteDonor();
        break;
    case 'getDonatedBooks':
        handleGetDonatedBooks();
        break;
    case 'getLatestCertificate':
        handleGetLatestCertificate();
        break;
    case 'debugFiles':
        handleDebugFiles();
        break;
    default:
        sendError('Invalid action. Use: fetch, add, update, delete, getDonatedBooks, getLatestCertificate, or debugFiles');
        break;
}

// Fetch all donors with their statistics
function handleFetchDonors() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed for fetch action', 405);
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT 
                    d.u_id as id,
                    d.u_name as name,
                    d.u_mobile as phone,
                    d.u_createdat as joinDate,
                    COALESCE(SUM(don.d_count), 0) as totalDonations,
                    COALESCE(MAX(don.d_createdat), d.u_createdat) as lastDonationDate,
                    l.l_name as librarianName
                  FROM donors d
                  LEFT JOIN donations don ON d.u_id = don.du_id
                  LEFT JOIN login l ON d.ul_id = l.l_id
                  GROUP BY d.u_id, d.u_name, d.u_mobile, d.u_createdat, l.l_name
                  ORDER BY d.u_createdat DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $transformedDonors = array_map(function($donor) {
            return [
                'id' => (string)$donor['id'],
                'name' => $donor['name'],
                'phone' => $donor['phone'],
                'email' => '', // Not in current schema
                'address' => '', // Not in current schema
                'totalDonations' => (int)$donor['totalDonations'],
                'lastDonationDate' => date('Y-m-d', strtotime($donor['lastDonationDate'])),
                'donatedBooks' => [], // Will be loaded separately
                'librarianName' => $donor['librarianName'] ?? 'Not Assigned'
            ];
        }, $donors);
        
        sendSuccess('Donors retrieved successfully', $transformedDonors);

    } catch (Exception $e) {
        sendError('Error fetching donors: ' . $e->getMessage(), 500);
    }
}

// Add new donor
function handleAddDonor() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for add action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['name']) || empty(trim($input['name']))) {
        sendError('Donor name is required');
    }

    if (!isset($input['phone']) || empty(trim($input['phone']))) {
        sendError('Phone number is required');
    }

    $name = trim($input['name']);
    $phone = trim($input['phone']);
    $librarianId = isset($input['librarianId']) ? (int)$input['librarianId'] : null;

    // Validate phone number (10 digits)
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        sendError('Phone number must be exactly 10 digits');
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // If no librarian specified, get the first available librarian
        if (!$librarianId) {
            $libQuery = "SELECT l_id FROM login WHERE l_role = 'librarian' LIMIT 1";
            $libStmt = $conn->prepare($libQuery);
            $libStmt->execute();
            $librarian = $libStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$librarian) {
                sendError('No librarian available to assign this donor');
            }
            $librarianId = $librarian['l_id'];
        }

        // Check if phone number already exists
        $phoneCheckQuery = "SELECT u_id FROM donors WHERE u_mobile = :phone";
        $phoneCheckStmt = $conn->prepare($phoneCheckQuery);
        $phoneCheckStmt->bindParam(':phone', $phone);
        $phoneCheckStmt->execute();
        
        if ($phoneCheckStmt->rowCount() > 0) {
            sendError('Phone number already exists');
        }

        // Insert new donor
        $insertQuery = "INSERT INTO donors (u_name, u_mobile, ul_id, u_createdat) 
                       VALUES (:name, :phone, :librarianId, NOW())";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bindParam(':name', $name);
        $insertStmt->bindParam(':phone', $phone);
        $insertStmt->bindParam(':librarianId', $librarianId);
        $insertStmt->execute();
        
        $newDonorId = $conn->lastInsertId();
        
        // Get the newly created donor with statistics
        $donor = getDonorById($conn, $newDonorId);
        sendSuccess('Donor added successfully', $donor);

    } catch (Exception $e) {
        sendError('Error adding donor: ' . $e->getMessage(), 500);
    }
}

// Update existing donor
function handleUpdateDonor() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for update action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['id']) || empty($input['id'])) {
        sendError('Donor ID is required');
    }

    if (!isset($input['name']) || empty(trim($input['name']))) {
        sendError('Donor name is required');
    }

    if (!isset($input['phone']) || empty(trim($input['phone']))) {
        sendError('Phone number is required');
    }

    $donorId = (int)$input['id'];
    $name = trim($input['name']);
    $phone = trim($input['phone']);

    // Validate phone number (10 digits)
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        sendError('Phone number must be exactly 10 digits');
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Check if donor exists
        $checkQuery = "SELECT u_id FROM donors WHERE u_id = :donorId";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':donorId', $donorId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() == 0) {
            sendError('Donor not found', 404);
        }

        // Check if phone number already exists for another donor
        $phoneCheckQuery = "SELECT u_id FROM donors WHERE u_mobile = :phone AND u_id != :donorId";
        $phoneCheckStmt = $conn->prepare($phoneCheckQuery);
        $phoneCheckStmt->bindParam(':phone', $phone);
        $phoneCheckStmt->bindParam(':donorId', $donorId);
        $phoneCheckStmt->execute();
        
        if ($phoneCheckStmt->rowCount() > 0) {
            sendError('Phone number already exists for another donor');
        }

        // Update donor
        $updateQuery = "UPDATE donors SET 
                       u_name = :name, 
                       u_mobile = :phone, 
                       u_updatedat = NOW() 
                       WHERE u_id = :donorId";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bindParam(':name', $name);
        $updateStmt->bindParam(':phone', $phone);
        $updateStmt->bindParam(':donorId', $donorId);
        $updateStmt->execute();
        
        // Get updated donor with statistics
        $donor = getDonorById($conn, $donorId);
        sendSuccess('Donor updated successfully', $donor);

    } catch (Exception $e) {
        sendError('Error updating donor: ' . $e->getMessage(), 500);
    }
}

// Delete donor
function handleDeleteDonor() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for delete action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['donorId']) || empty($input['donorId'])) {
        sendError('Donor ID is required');
    }

    $donorId = (int)$input['donorId'];

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Check if donor exists
        $checkQuery = "SELECT u_id FROM donors WHERE u_id = :donorId";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':donorId', $donorId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() == 0) {
            sendError('Donor not found', 404);
        }

        // Check if donor has donations
        $donationsCheckQuery = "SELECT COUNT(*) as donation_count FROM donations WHERE du_id = :donorId";
        $donationsCheckStmt = $conn->prepare($donationsCheckQuery);
        $donationsCheckStmt->bindParam(':donorId', $donorId);
        $donationsCheckStmt->execute();
        $donationCount = $donationsCheckStmt->fetch(PDO::FETCH_ASSOC)['donation_count'];

        if ($donationCount > 0) {
            sendError('Cannot delete donor who has made donations. Please remove all donations first.');
        }

        // Delete donor
        $deleteQuery = "DELETE FROM donors WHERE u_id = :donorId";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bindParam(':donorId', $donorId);
        $deleteStmt->execute();
        
        sendSuccess('Donor deleted successfully');

    } catch (Exception $e) {
        sendError('Error deleting donor: ' . $e->getMessage(), 500);
    }
}

// Get donated books by donor
function handleGetDonatedBooks() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed for getDonatedBooks action', 405);
    }

    $donorId = $_GET['donorId'] ?? '';
    
    if (empty($donorId)) {
        sendError('Donor ID is required');
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT 
                    b.b_id as id,
                    b.b_title as title,
                    b.b_author as author,
                    b.b_genre as category,
                    don.d_count as count,
                    don.d_createdat as donationDate,
                    l.l_name as librarianName
                  FROM donations don
                  JOIN books b ON don.db_id = b.b_id
                  JOIN login l ON don.dl_id = l.l_id
                  WHERE don.du_id = :donorId
                  ORDER BY don.d_createdat DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':donorId', $donorId);
        $stmt->execute();
        $donatedBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $transformedBooks = array_map(function($book) {
            return [
                'id' => (string)$book['id'],
                'title' => $book['title'],
                'author' => $book['author'],
                'category' => $book['category'],
                'count' => (int)$book['count'],
                'donationDate' => date('Y-m-d', strtotime($book['donationDate'])),
                'librarianName' => $book['librarianName']
            ];
        }, $donatedBooks);
        
        sendSuccess('Donated books retrieved successfully', $transformedBooks);

    } catch (Exception $e) {
        sendError('Error fetching donated books: ' . $e->getMessage(), 500);
    }
}

// Helper function to get donor by ID
function getDonorById($conn, $donorId) {
    $selectQuery = "SELECT 
                      d.u_id as id,
                      d.u_name as name,
                      d.u_mobile as phone,
                      d.u_createdat as joinDate,
                      COALESCE(SUM(don.d_count), 0) as totalDonations,
                      COALESCE(MAX(don.d_createdat), d.u_createdat) as lastDonationDate,
                      l.l_name as librarianName
                    FROM donors d
                    LEFT JOIN donations don ON d.u_id = don.du_id
                    LEFT JOIN login l ON d.ul_id = l.l_id
                    WHERE d.u_id = :donorId
                    GROUP BY d.u_id, d.u_name, d.u_mobile, d.u_createdat, l.l_name";
    
    $selectStmt = $conn->prepare($selectQuery);
    $selectStmt->bindParam(':donorId', $donorId);
    $selectStmt->execute();
    
    $donor = $selectStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$donor) {
        return null;
    }
    
    return [
        'id' => (string)$donor['id'],
        'name' => $donor['name'],
        'phone' => $donor['phone'],
        'email' => '', // Not in current schema
        'address' => '', // Not in current schema
        'totalDonations' => (int)$donor['totalDonations'],
        'lastDonationDate' => date('Y-m-d', strtotime($donor['lastDonationDate'])),
        'donatedBooks' => [], // Will be loaded separately
        'librarianName' => $donor['librarianName'] ?? 'Not Assigned'
    ];
}

// Handle getting latest donation certificate for a donor
function handleGetLatestCertificate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed for getLatestCertificate action', 405);
    }

    $donorId = $_GET['donorId'] ?? '';
    
    if (empty($donorId)) {
        sendError('Donor ID is required');
        return;
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Get the latest certificate file for this donor
        $query = "SELECT 
                    f.f_id,
                    f.f_path,
                    f.f_createdat,
                    f.f_updatedat
                  FROM files f
                  WHERE f.f_user_id = :donorId 
                  ORDER BY f.f_createdat DESC 
                  LIMIT 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':donorId', $donorId);
        $stmt->execute();
        
        $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($certificate) {
            sendSuccess('Latest certificate retrieved successfully', [
                'f_id' => (int)$certificate['f_id'],
                'f_path' => 'certificates/' . $certificate['f_path'], // Add certificates folder prefix
                'f_createdat' => $certificate['f_createdat'],
                'f_updatedat' => $certificate['f_updatedat']
            ]);
        } else {
            sendSuccess('No certificate found for this donor', null);
        }
        
    } catch (Exception $e) {
        error_log("Get latest certificate error: " . $e->getMessage());
        sendError('Database error occurred');
    }
}

// Debug function to check files table
function handleDebugFiles() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed for debugFiles action', 405);
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Get all files with their donor info
        $query = "SELECT 
                    f.f_id,
                    f.f_path,
                    f.f_user_id,
                    f.f_createdat,
                    d.u_name as donor_name
                  FROM files f
                  LEFT JOIN donors d ON f.f_user_id = d.u_id
                  ORDER BY f.f_createdat DESC 
                  LIMIT 10";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendSuccess('Files debug info retrieved', $files);
        
    } catch (Exception $e) {
        error_log("Debug files error: " . $e->getMessage());
        sendError('Database error occurred');
    }
}
?>
