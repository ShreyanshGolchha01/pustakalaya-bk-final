<?php
require_once 'p_database.php';

// Handle different actions
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'fetch':
        handleFetchBooks();
        break;
    case 'add':
        handleAddBook();
        break;
    case 'update':
        handleUpdateBook();
        break;
    case 'delete':
        handleDeleteBook();
        break;
    case 'getDonors':
        handleGetDonors();
        break;
    case 'getLibrarians':
        handleGetLibrarians();
        break;
    default:
        sendError('Invalid action. Use: fetch, add, update, delete, getDonors, or getLibrarians');
        break;
}

// Fetch all books with their details
function handleFetchBooks() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed for fetch action', 405);
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT 
                    b.b_id as id,
                    b.b_title as title,
                    b.b_author as author,
                    b.b_genre as category,
                    b.b_count as count,
                    b.b_createdAt as addedDate,
                    l.l_name as addedBy,
                    l.l_id as librarianId,
                    COALESCE(SUM(don.d_count), 0) as donatedCount,
                    COALESCE(GROUP_CONCAT(DISTINCT CONCAT(d.u_name, ' (', don.d_count, ')') SEPARATOR ', '), 'No donations') as donorInfo
                  FROM books b
                  LEFT JOIN login l ON b.bl_id = l.l_id
                  LEFT JOIN donations don ON b.b_id = don.db_id
                  LEFT JOIN donors d ON don.du_id = d.u_id
                  GROUP BY b.b_id, b.b_title, b.b_author, b.b_genre, b.b_count, b.b_createdAt, l.l_name, l.l_id
                  ORDER BY b.b_createdAt DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $transformedBooks = array_map(function($book) {
            return [
                'id' => (string)$book['id'],
                'title' => $book['title'],
                'author' => $book['author'],
                'category' => $book['category'],
                'count' => (int)$book['count'],
                'addedDate' => date('Y-m-d', strtotime($book['addedDate'])),
                'addedBy' => $book['addedBy'] ?? 'Unknown',
                'librarianId' => (string)$book['librarianId'],
                'donatedCount' => (int)$book['donatedCount'],
                'donorInfo' => $book['donorInfo'],
                'destination' => 'Library', // Default destination
                'donorId' => '' // Will be populated from donations if needed
            ];
        }, $books);
        
        sendSuccess('Books retrieved successfully', $transformedBooks);

    } catch (Exception $e) {
        sendError('Error fetching books: ' . $e->getMessage(), 500);
    }
}

// Add new book
function handleAddBook() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for add action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    if (!isset($input['title']) || empty(trim($input['title']))) {
        sendError('Book title is required');
    }

    if (!isset($input['author']) || empty(trim($input['author']))) {
        sendError('Author name is required');
    }

    if (!isset($input['category']) || empty(trim($input['category']))) {
        sendError('Book category/genre is required');
    }

    if (!isset($input['count']) || !is_numeric($input['count']) || $input['count'] < 1) {
        sendError('Valid book count is required (minimum 1)');
    }

    $title = trim($input['title']);
    $author = trim($input['author']);
    $category = trim($input['category']);
    $count = (int)$input['count'];
    $librarianId = isset($input['librarianId']) ? (int)$input['librarianId'] : null;
    $donorId = isset($input['donorId']) && !empty($input['donorId']) ? (int)$input['donorId'] : null;

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
                sendError('No librarian available to assign this book');
            }
            $librarianId = $librarian['l_id'];
        }

        // Verify librarian exists
        $libCheckQuery = "SELECT l_id FROM login WHERE l_id = :librarianId AND l_role = 'librarian'";
        $libCheckStmt = $conn->prepare($libCheckQuery);
        $libCheckStmt->bindParam(':librarianId', $librarianId);
        $libCheckStmt->execute();
        
        if ($libCheckStmt->rowCount() == 0) {
            sendError('Invalid librarian ID or librarian not found');
        }

        // Check if donor exists (if provided)
        if ($donorId) {
            $donorCheckQuery = "SELECT u_id FROM donors WHERE u_id = :donorId";
            $donorCheckStmt = $conn->prepare($donorCheckQuery);
            $donorCheckStmt->bindParam(':donorId', $donorId);
            $donorCheckStmt->execute();
            
            if ($donorCheckStmt->rowCount() == 0) {
                sendError('Invalid donor ID or donor not found');
            }
        }

        // Insert new book
        $insertQuery = "INSERT INTO books (b_title, b_author, b_genre, b_count, bl_id, b_createdAt) 
                       VALUES (:title, :author, :category, :count, :librarianId, NOW())";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bindParam(':title', $title);
        $insertStmt->bindParam(':author', $author);
        $insertStmt->bindParam(':category', $category);
        $insertStmt->bindParam(':count', $count);
        $insertStmt->bindParam(':librarianId', $librarianId);
        $insertStmt->execute();
        
        $newBookId = $conn->lastInsertId();

        // If donor is specified, create a donation record
        if ($donorId) {
            $donationQuery = "INSERT INTO donations (du_id, dl_id, db_id, d_count, d_createdat) 
                             VALUES (:donorId, :librarianId, :bookId, :count, NOW())";
            $donationStmt = $conn->prepare($donationQuery);
            $donationStmt->bindParam(':donorId', $donorId);
            $donationStmt->bindParam(':librarianId', $librarianId);
            $donationStmt->bindParam(':bookId', $newBookId);
            $donationStmt->bindParam(':count', $count);
            $donationStmt->execute();
        }
        
        // Get the newly created book with details
        $book = getBookById($conn, $newBookId);
        sendSuccess('Book added successfully', $book);

    } catch (Exception $e) {
        sendError('Error adding book: ' . $e->getMessage(), 500);
    }
}

// Update existing book
function handleUpdateBook() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for update action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['id']) || empty($input['id'])) {
        sendError('Book ID is required');
    }

    if (!isset($input['title']) || empty(trim($input['title']))) {
        sendError('Book title is required');
    }

    if (!isset($input['author']) || empty(trim($input['author']))) {
        sendError('Author name is required');
    }

    if (!isset($input['category']) || empty(trim($input['category']))) {
        sendError('Book category/genre is required');
    }

    if (!isset($input['count']) || !is_numeric($input['count']) || $input['count'] < 0) {
        sendError('Valid book count is required (minimum 0)');
    }

    $bookId = (int)$input['id'];
    $title = trim($input['title']);
    $author = trim($input['author']);
    $category = trim($input['category']);
    $count = (int)$input['count'];

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Check if book exists
        $checkQuery = "SELECT b_id FROM books WHERE b_id = :bookId";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':bookId', $bookId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() == 0) {
            sendError('Book not found', 404);
        }

        // Update book
        $updateQuery = "UPDATE books SET 
                       b_title = :title, 
                       b_author = :author, 
                       b_genre = :category, 
                       b_count = :count, 
                       b_updatedAt = NOW() 
                       WHERE b_id = :bookId";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bindParam(':title', $title);
        $updateStmt->bindParam(':author', $author);
        $updateStmt->bindParam(':category', $category);
        $updateStmt->bindParam(':count', $count);
        $updateStmt->bindParam(':bookId', $bookId);
        $updateStmt->execute();
        
        // Get updated book with details
        $book = getBookById($conn, $bookId);
        sendSuccess('Book updated successfully', $book);

    } catch (Exception $e) {
        sendError('Error updating book: ' . $e->getMessage(), 500);
    }
}

// Delete book
function handleDeleteBook() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Method not allowed for delete action', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['bookId']) || empty($input['bookId'])) {
        sendError('Book ID is required');
    }

    $bookId = (int)$input['bookId'];

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Check if book exists
        $checkQuery = "SELECT b_id FROM books WHERE b_id = :bookId";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':bookId', $bookId);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() == 0) {
            sendError('Book not found', 404);
        }

        // Check if book has donations
        $donationsCheckQuery = "SELECT COUNT(*) as donation_count FROM donations WHERE db_id = :bookId";
        $donationsCheckStmt = $conn->prepare($donationsCheckQuery);
        $donationsCheckStmt->bindParam(':bookId', $bookId);
        $donationsCheckStmt->execute();
        $donationCount = $donationsCheckStmt->fetch(PDO::FETCH_ASSOC)['donation_count'];

        if ($donationCount > 0) {
            sendError('Cannot delete book that has donation records. Please remove all donations first.');
        }

        // Delete book
        $deleteQuery = "DELETE FROM books WHERE b_id = :bookId";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bindParam(':bookId', $bookId);
        $deleteStmt->execute();
        
        sendSuccess('Book deleted successfully');

    } catch (Exception $e) {
        sendError('Error deleting book: ' . $e->getMessage(), 500);
    }
}

// Get list of donors for dropdown
function handleGetDonors() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed for getDonors action', 405);
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT u_id as id, u_name as name, u_mobile as phone 
                  FROM donors 
                  ORDER BY u_name ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $transformedDonors = array_map(function($donor) {
            return [
                'id' => (string)$donor['id'],
                'name' => $donor['name'],
                'phone' => $donor['phone'],
                'label' => $donor['name'] . ' (' . $donor['phone'] . ')'
            ];
        }, $donors);
        
        sendSuccess('Donors retrieved successfully', $transformedDonors);

    } catch (Exception $e) {
        sendError('Error fetching donors: ' . $e->getMessage(), 500);
    }
}

// Get list of librarians for dropdown
function handleGetLibrarians() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Method not allowed for getLibrarians action', 405);
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT l_id as id, l_name as name, l_email as email 
                  FROM login 
                  WHERE l_role = 'librarian' 
                  ORDER BY l_name ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $librarians = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $transformedLibrarians = array_map(function($librarian) {
            return [
                'id' => (string)$librarian['id'],
                'name' => $librarian['name'],
                'email' => $librarian['email'],
                'label' => $librarian['name'] . ' (' . $librarian['email'] . ')'
            ];
        }, $librarians);
        
        sendSuccess('Librarians retrieved successfully', $transformedLibrarians);

    } catch (Exception $e) {
        sendError('Error fetching librarians: ' . $e->getMessage(), 500);
    }
}

// Helper function to get book by ID
function getBookById($conn, $bookId) {
    $selectQuery = "SELECT 
                      b.b_id as id,
                      b.b_title as title,
                      b.b_author as author,
                      b.b_genre as category,
                      b.b_count as count,
                      b.b_createdAt as addedDate,
                      l.l_name as addedBy,
                      l.l_id as librarianId,
                      COALESCE(SUM(don.d_count), 0) as donatedCount,
                      COALESCE(GROUP_CONCAT(DISTINCT CONCAT(d.u_name, ' (', don.d_count, ')') SEPARATOR ', '), 'No donations') as donorInfo
                    FROM books b
                    LEFT JOIN login l ON b.bl_id = l.l_id
                    LEFT JOIN donations don ON b.b_id = don.db_id
                    LEFT JOIN donors d ON don.du_id = d.u_id
                    WHERE b.b_id = :bookId
                    GROUP BY b.b_id, b.b_title, b.b_author, b.b_genre, b.b_count, b.b_createdAt, l.l_name, l.l_id";
    
    $selectStmt = $conn->prepare($selectQuery);
    $selectStmt->bindParam(':bookId', $bookId);
    $selectStmt->execute();
    
    $book = $selectStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$book) {
        return null;
    }
    
    return [
        'id' => (string)$book['id'],
        'title' => $book['title'],
        'author' => $book['author'],
        'category' => $book['category'],
        'count' => (int)$book['count'],
        'addedDate' => date('Y-m-d', strtotime($book['addedDate'])),
        'addedBy' => $book['addedBy'] ?? 'Unknown',
        'librarianId' => (string)$book['librarianId'],
        'donatedCount' => (int)$book['donatedCount'],
        'donorInfo' => $book['donorInfo'],
        'destination' => 'Library', // Default destination
        'donorId' => '' // Will be populated from donations if needed
    ];
}
?>
