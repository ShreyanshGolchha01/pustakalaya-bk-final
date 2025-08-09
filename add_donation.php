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
    
    // Debug logging
    error_log("=== ADD DONATION API ===");
    error_log("Request data: " . json_encode($data));
    
    if (!isset($data['donor_id']) || !isset($data['librarian_id']) || !isset($data['books'])) {
        sendResponse(false, 'Donor ID, librarian ID, and books are required');
    }
    
    $donor_id = $data['donor_id'];
    $librarian_id = $data['librarian_id'];
    $books = $data['books']; // Array of {book_id, count}
    $certificate_path = isset($data['certificate_path']) ? $data['certificate_path'] : null;
    
    // Validate donor_id and librarian_id
    if (empty($donor_id) || empty($librarian_id)) {
        sendResponse(false, 'Donor ID and Librarian ID cannot be empty');
    }
    
    // Validate books array
    if (empty($books) || !is_array($books)) {
        sendResponse(false, 'Books array is required and cannot be empty');
    }
    
    // Validate each book entry
    foreach ($books as $index => $book) {
        if (!isset($book['book_id']) || !isset($book['count'])) {
            sendResponse(false, "Book at index $index must have book_id and count");
        }
        if (empty($book['book_id'])) {
            sendResponse(false, "Book at index $index has empty book_id");
        }
        if (!is_numeric($book['count']) || $book['count'] <= 0) {
            sendResponse(false, "Book at index $index has invalid count");
        }
    }
    
    error_log("Validation passed. Donor ID: $donor_id, Librarian ID: $librarian_id, Books: " . json_encode($books));
    
    try {
        $db->beginTransaction();
        
        // Add donations for each book
        $donationQuery = "INSERT INTO donations (du_id, dl_id, db_id, d_count) VALUES (:donor_id, :librarian_id, :book_id, :count)";
        $donationStmt = $db->prepare($donationQuery);
        
        // Update book counts
        $updateBookQuery = "UPDATE books SET b_count = b_count + :count, b_updatedAt = CURRENT_TIMESTAMP WHERE b_id = :book_id";
        $updateBookStmt = $db->prepare($updateBookQuery);
        
        foreach ($books as $book) {
            if (!isset($book['book_id']) || !isset($book['count'])) {
                throw new Exception('Each book must have book_id and count');
            }
            
            $book_id = $book['book_id'];
            $count = $book['count'];
            
            error_log("Processing book - ID: $book_id, Count: $count");
            
            // Validate book exists
            $checkBookQuery = "SELECT b_id FROM books WHERE b_id = :book_id";
            $checkBookStmt = $db->prepare($checkBookQuery);
            $checkBookStmt->bindParam(':book_id', $book_id);
            $checkBookStmt->execute();
            
            if (!$checkBookStmt->fetch()) {
                throw new Exception("Book with ID $book_id does not exist");
            }
            
            // Insert donation record
            $donationStmt->bindParam(':donor_id', $donor_id);
            $donationStmt->bindParam(':librarian_id', $librarian_id);
            $donationStmt->bindParam(':book_id', $book_id);
            $donationStmt->bindParam(':count', $count);
            
            if (!$donationStmt->execute()) {
                throw new Exception("Failed to insert donation record for book ID $book_id");
            }
            
            // Update book count
            $updateBookStmt->bindParam(':count', $count);
            $updateBookStmt->bindParam(':book_id', $book_id);
            
            if (!$updateBookStmt->execute()) {
                throw new Exception("Failed to update book count for book ID $book_id");
            }
            
            error_log("Successfully processed book ID: $book_id");
        }
        
        // If certificate path is provided, save file record
        if ($certificate_path) {
            error_log("Saving certificate file record: $certificate_path");
            
            // Check if donor exists before saving file record
            $checkDonorQuery = "SELECT u_id FROM donors WHERE u_id = :donor_id";
            $checkDonorStmt = $db->prepare($checkDonorQuery);
            $checkDonorStmt->bindParam(':donor_id', $donor_id);
            $checkDonorStmt->execute();
            
            if (!$checkDonorStmt->fetch()) {
                throw new Exception("Donor with ID $donor_id does not exist");
            }
            
            $fileQuery = "INSERT INTO files (f_path, f_user_id, f_lib_id) VALUES (:path, :user_id, :lib_id)";
            $fileStmt = $db->prepare($fileQuery);
            $fileStmt->bindParam(':path', $certificate_path);
            $fileStmt->bindParam(':user_id', $donor_id);
            $fileStmt->bindParam(':lib_id', $librarian_id);
            
            if (!$fileStmt->execute()) {
                throw new Exception("Failed to save certificate file record");
            }
            
            error_log("Certificate file record saved successfully");
        }
        
        $db->commit();
        
        error_log("Donation transaction completed successfully");
        
        sendResponse(true, 'Donation recorded successfully', [
            'donor_id' => $donor_id,
            'books_count' => count($books),
            'total_copies' => array_sum(array_column($books, 'count'))
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Donation transaction failed: " . $e->getMessage());
        sendResponse(false, 'Error recording donation: ' . $e->getMessage());
    }
} else {
    sendResponse(false, 'Only POST method allowed');
}
?>