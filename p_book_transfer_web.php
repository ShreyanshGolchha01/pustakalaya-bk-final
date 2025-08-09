<?php
// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in output

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

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($db, $action);
            break;
        case 'POST':
            handlePostRequest($db, $action);
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}

// Clean output buffer before sending JSON
ob_clean();

function handleGetRequest($db, $action) {
    // Debug: Log the action being received
    error_log("Received GET action: " . $action);
    
    switch ($action) {
        case 'fetch':
            fetchTransfers($db);
            break;
        case 'fetch_books':
            fetchBooks($db);
            break;
        case 'test':
            sendSuccess('API is working fine!', ['test' => true]);
            break;
        case '':
        case null:
            sendError('No action specified in GET request');
            break;
        default:
            sendError('Invalid action for GET request: ' . $action);
    }
}

function handlePostRequest($db, $action) {
    switch ($action) {
        case 'add':
            addTransfer($db);
            break;
        case 'delete':
            deleteTransfer($db);
            break;
        default:
            sendError('Invalid action for POST request');
    }
}

function fetchTransfers($db) {
    try {
        $query = "
            SELECT 
                d.t_id,
                d.tb_id,
                d.t_destination,
                d.tb_count,
                d.t_createdAt,
                d.t_updatedAt,
                b.b_title as book_title,
                b.b_author as book_author,
                b.b_genre as book_category
            FROM destination d
            INNER JOIN books b ON d.tb_id = b.b_id
            ORDER BY d.t_createdAt DESC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $transfers = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transfers[] = [
                't_id' => (int)$row['t_id'],
                'tb_id' => (int)$row['tb_id'],
                't_destination' => $row['t_destination'],
                'tb_count' => (int)$row['tb_count'],
                't_createdAt' => $row['t_createdAt'],
                't_updatedAt' => $row['t_updatedAt'],
                'book_title' => $row['book_title'],
                'book_author' => $row['book_author'],
                'book_category' => $row['book_category']
            ];
        }
        
        sendSuccess('Transfers fetched successfully', $transfers);
        
    } catch (PDOException $e) {
        sendError('Database error while fetching transfers: ' . $e->getMessage());
    }
}

function fetchBooks($db) {
    try {
        $query = "
            SELECT 
                b.b_id as id,
                b.b_title as title,
                b.b_author as author,
                b.b_genre as category,
                b.b_count as count,
                b.bl_id as donorId,
                '' as destination,
                'Admin' as addedBy,
                DATE(b.b_createdAt) as addedDate
            FROM books b
            WHERE b.b_count > 0
            ORDER BY b.b_title ASC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $books = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $books[] = [
                'id' => (string)$row['id'],
                'title' => $row['title'],
                'author' => $row['author'],
                'category' => $row['category'],
                'count' => (int)$row['count'],
                'donorId' => (string)$row['donorId'],
                'destination' => $row['destination'],
                'addedBy' => $row['addedBy'],
                'addedDate' => $row['addedDate']
            ];
        }
        
        sendSuccess('Books fetched successfully', $books);
        
    } catch (PDOException $e) {
        sendError('Database error while fetching books: ' . $e->getMessage());
    }
}

function addTransfer($db) {
    try {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON input');
        }
        
        // Debug log
        error_log("Add transfer input: " . json_encode($input));
        
        // Validate required fields
        $bookId = isset($input['bookId']) ? (int)$input['bookId'] : null;
        $count = isset($input['count']) ? (int)$input['count'] : null;
        $destination = isset($input['destination']) ? trim($input['destination']) : null;
        
        if (!$bookId || !$count || !$destination || $count <= 0) {
            sendError('Missing or invalid required fields: bookId, count, destination');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Check if book exists and has sufficient count
            $checkQuery = "SELECT b_count FROM books WHERE b_id = ?";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$bookId]);
            $book = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$book) {
                throw new Exception('Book not found');
            }
            
            if ($book['b_count'] < $count) {
                throw new Exception('Insufficient book count available. Available: ' . $book['b_count'] . ', Requested: ' . $count);
            }
            
            // Insert transfer record
            $insertQuery = "
                INSERT INTO destination (tb_id, t_destination, tb_count, t_createdAt, t_updatedAt) 
                VALUES (?, ?, ?, NOW(), NOW())
            ";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([$bookId, $destination, $count]);
            
            // Update book count
            $updateQuery = "UPDATE books SET b_count = b_count - ?, b_updatedAt = NOW() WHERE b_id = ?";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$count, $bookId]);
            
            // Get the inserted transfer with book details
            $transferId = $db->lastInsertId();
            $getTransferQuery = "
                SELECT 
                    d.t_id,
                    d.tb_id,
                    d.t_destination,
                    d.tb_count,
                    d.t_createdAt,
                    d.t_updatedAt,
                    b.b_title as book_title,
                    b.b_author as book_author,
                    b.b_genre as book_category
                FROM destination d
                INNER JOIN books b ON d.tb_id = b.b_id
                WHERE d.t_id = ?
            ";
            $getTransferStmt = $db->prepare($getTransferQuery);
            $getTransferStmt->execute([$transferId]);
            $transfer = $getTransferStmt->fetch(PDO::FETCH_ASSOC);
            
            // Commit transaction
            $db->commit();
            
            $transferData = [
                't_id' => (int)$transfer['t_id'],
                'tb_id' => (int)$transfer['tb_id'],
                't_destination' => $transfer['t_destination'],
                'tb_count' => (int)$transfer['tb_count'],
                't_createdAt' => $transfer['t_createdAt'],
                't_updatedAt' => $transfer['t_updatedAt'],
                'book_title' => $transfer['book_title'],
                'book_author' => $transfer['book_author'],
                'book_category' => $transfer['book_category']
            ];
            
            sendSuccess('Transfer added successfully', $transferData);
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $db->rollback();
            error_log("Transfer add error: " . $e->getMessage());
            throw $e;
        }
        
    } catch (PDOException $e) {
        sendError('Database error while adding transfer: ' . $e->getMessage());
    } catch (Exception $e) {
        sendError($e->getMessage());
    }
}

function deleteTransfer($db) {
    try {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON input');
        }
        
        $transferId = isset($input['transferId']) ? (int)$input['transferId'] : null;
        
        if (!$transferId) {
            sendError('Transfer ID is required');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Get transfer details before deletion
            $getTransferQuery = "SELECT tb_id, tb_count FROM destination WHERE t_id = ?";
            $getTransferStmt = $db->prepare($getTransferQuery);
            $getTransferStmt->execute([$transferId]);
            $transfer = $getTransferStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transfer) {
                throw new Exception('Transfer not found');
            }
            
            // Delete transfer record
            $deleteQuery = "DELETE FROM destination WHERE t_id = ?";
            $deleteStmt = $db->prepare($deleteQuery);
            $deleteStmt->execute([$transferId]);
            
            if ($deleteStmt->rowCount() === 0) {
                throw new Exception('Transfer not found or already deleted');
            }
            
            // Restore book count
            $updateQuery = "UPDATE books SET b_count = b_count + ?, b_updatedAt = NOW() WHERE b_id = ?";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$transfer['tb_count'], $transfer['tb_id']]);
            
            // Commit transaction
            $db->commit();
            
            sendSuccess('Transfer deleted successfully');
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $db->rollback();
            throw $e;
        }
        
    } catch (PDOException $e) {
        sendError('Database error while deleting transfer: ' . $e->getMessage());
    } catch (Exception $e) {
        sendError($e->getMessage());
    }
}
?>
