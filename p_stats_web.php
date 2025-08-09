<?php
include 'p_database.php';

// Only allow GET requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Database connection
    $database = new Database();
    $conn = $database->getConnection();

    // Get total books count
    $booksQuery = "SELECT COUNT(*) as total FROM books";
    $booksStmt = $conn->prepare($booksQuery);
    $booksStmt->execute();
    $totalBooks = $booksStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total donors count (users who have donated books)
    $donorsQuery = "SELECT COUNT(*) as total FROM donors";
    $donorsStmt = $conn->prepare($donorsQuery);
    $donorsStmt->execute();
    $totalDonors = $donorsStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total librarians count
    $librariansQuery = "SELECT COUNT(*) as total FROM login WHERE l_role = 'librarian'";
    $librariansStmt = $conn->prepare($librariansQuery);
    $librariansStmt->execute();
    $totalLibrarians = $librariansStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total donations count (sum of all book counts donated)
    $donationsQuery = "SELECT SUM(b_count) as total FROM books";
    $donationsStmt = $conn->prepare($donationsQuery);
    $donationsStmt->execute();
    $totalDonations = $donationsStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Prepare stats data
    $statsData = [
        'totalBooks' => (int)$totalBooks,
        'totalDonors' => (int)$totalDonors,
        'totalLibrarians' => (int)$totalLibrarians,
        'totalDonations' => (int)$totalDonations
    ];

    sendSuccess('Stats retrieved successfully', $statsData);

} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}
?>
