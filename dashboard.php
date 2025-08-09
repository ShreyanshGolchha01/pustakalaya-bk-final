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
    // Remove librarian_id dependency - show unified stats for all users
    
    try {
        $stats = [];
        
        // Total donors (all donors in the system)
        $donorQuery = "SELECT COUNT(*) as total_donors FROM donors";
        $donorStmt = $db->prepare($donorQuery);
        $donorStmt->execute();
        $stats['total_donors'] = (int)($donorStmt->fetch()['total_donors'] ?: 0);
        
        // Total books (all books in the system)
        $bookQuery = "SELECT COUNT(*) as total_books, COALESCE(SUM(b_count), 0) as total_copies FROM books";
        $bookStmt = $db->prepare($bookQuery);
        $bookStmt->execute();
        $bookData = $bookStmt->fetch();
        $stats['total_books'] = (int)($bookData['total_books'] ?: 0);
        $stats['total_copies'] = (int)($bookData['total_copies'] ?: 0);
        
        // Total donations (all donations in the system)
        $donationQuery = "SELECT COUNT(*) as total_donations, COALESCE(SUM(d_count), 0) as total_donated_copies FROM donations";
        $donationStmt = $db->prepare($donationQuery);
        $donationStmt->execute();
        $donationData = $donationStmt->fetch();
        $stats['total_donations'] = (int)($donationData['total_donations'] ?: 0);
        $stats['total_donated_copies'] = (int)($donationData['total_donated_copies'] ?: 0);
        
        // Recent donations (last 10 - all recent donations in the system)
        $recentQuery = "SELECT d.d_id, d.d_count, d.d_createdat, 
                              u.u_name, u.u_mobile,
                              b.b_title, b.b_author
                       FROM donations d
                       JOIN donors u ON d.du_id = u.u_id
                       JOIN books b ON d.db_id = b.b_id
                       ORDER BY d.d_createdat DESC LIMIT 10";
        
        $recentStmt = $db->prepare($recentQuery);
        $recentStmt->execute();
        $stats['recent_donations'] = $recentStmt->fetchAll();
        
        // Monthly donation count (last 12 months - all donations in the system)
        $monthlyQuery = "SELECT 
                           DATE_FORMAT(d_createdat, '%Y-%m') as month,
                           COUNT(*) as donations_count,
                           SUM(d_count) as copies_count
                         FROM donations
                         WHERE d_createdat >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                         GROUP BY DATE_FORMAT(d_createdat, '%Y-%m')
                         ORDER BY month DESC";
        
        $monthlyStmt = $db->prepare($monthlyQuery);
        $monthlyStmt->execute();
        $stats['monthly_donations'] = $monthlyStmt->fetchAll();
        
        sendResponse(true, 'Dashboard statistics retrieved successfully', $stats);
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving dashboard data: ' . $e->getMessage());
    }
} else {
    sendResponse(false, 'Only GET method allowed');
}
?>