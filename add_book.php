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
    
    if (!isset($data['title']) || !isset($data['author']) || !isset($data['genre']) || !isset($data['librarian_id'])) {
        sendResponse(false, 'Title, author, genre, and librarian_id are required');
    }
    
    $title = $data['title'];
    $author = $data['author'];
    $genre = $data['genre'];
    $count = isset($data['count']) ? $data['count'] : 1;
    $librarian_id = $data['librarian_id'];
    
    try {
        // Check if book already exists
        $checkQuery = "SELECT b_id, b_count FROM books WHERE b_title = :title AND b_author = :author";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':title', $title);
        $checkStmt->bindParam(':author', $author);
        $checkStmt->execute();
        
        $existingBook = $checkStmt->fetch();
        
        if ($existingBook) {
            // Book exists, update count
            $newCount = $existingBook['b_count'] + $count;
            $updateQuery = "UPDATE books SET b_count = :count, b_updatedAt = CURRENT_TIMESTAMP WHERE b_id = :book_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':count', $newCount);
            $updateStmt->bindParam(':book_id', $existingBook['b_id']);
            
            if ($updateStmt->execute()) {
                sendResponse(true, 'Book count updated successfully', [
                    'b_id' => $existingBook['b_id'],
                    'b_title' => $title,
                    'b_author' => $author,
                    'b_genre' => $genre,
                    'b_available_count' => $newCount,
                    'data' => [
                        'b_id' => $existingBook['b_id'],
                        'b_title' => $title,
                        'b_author' => $author,
                        'b_genre' => $genre,
                        'b_available_count' => $newCount
                    ]
                ]);
            } else {
                sendResponse(false, 'Failed to update book count');
            }
        } else {
            // New book, insert
            $query = "INSERT INTO books (b_title, b_author, b_genre, b_count, bl_id) VALUES (:title, :author, :genre, :count, :librarian_id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':author', $author);
            $stmt->bindParam(':genre', $genre);
            $stmt->bindParam(':count', $count);
            $stmt->bindParam(':librarian_id', $librarian_id);
            
            if ($stmt->execute()) {
                $book_id = $db->lastInsertId();
                sendResponse(true, 'Book added successfully', [
                    'b_id' => $book_id,
                    'b_title' => $title,
                    'b_author' => $author,
                    'b_genre' => $genre,
                    'b_available_count' => $count,
                    'data' => [
                        'b_id' => $book_id,
                        'b_title' => $title,
                        'b_author' => $author,
                        'b_genre' => $genre,
                        'b_available_count' => $count
                    ]
                ]);
            } else {
                sendResponse(false, 'Failed to add book');
            }
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error adding book: ' . $e->getMessage());
    }
} else {
    sendResponse(false, 'Only POST method allowed');
}
?>

