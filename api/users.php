<?php 

define('ITS_ME_JUSTTOVERIFY', true);
// require_once 'database.php';
// require_once 'responses.php'; 
require_once 'checkuser.php';
require_once 'textbee.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;

function formatPhNumber($number) {
    $clean = preg_replace('/[^0-9]/', '', $number);
    if (strlen($clean) == 11 && substr($clean, 0, 2) == '09') {
        $clean = '63' . substr($clean, 1);
    } elseif (strlen($clean) == 10 && substr($clean, 0, 1) == '9') {
        $clean = '63' . $clean;
    }
    if (substr($clean, 0, 3) == '639' && strlen($clean) == 12) {
        return '+' . $clean;
    }
    return false;
}

// --- REST ROUTING: PARSE THE URI ---
// This extracts any path added after users.php (e.g., /api/users.php/123 -> "123")
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$pathParts = array_filter(explode('/', trim($pathInfo, '/')));
$resourceId = array_shift($pathParts); // This will be null, 'me', 'forgot-password', or a numeric ID

// --- AUTHENTICATION GATEKEEPER ---
// POST to base resource (register) and POST to forgot-password are public
$isPublicEndpoint = ($method === 'POST' && ($resourceId === null || $resourceId === 'forgot-password'));

if ($isPublicEndpoint) {
    $userData = null; 
} else {
    // For GET, PUT, DELETE, enforce the strict login check
    $userData = checkuser(); 
}

// ==========================================
// 1. GET: RETRIEVE RESOURCES
// ==========================================
if ($method === 'GET') {
   
    // SCENARIO A: GET /users.php/me (Get own profile)
    if ($resourceId === 'me' || (string)$resourceId === (string)$userData['user_id']) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") retrieved their own profile", $userData['user_id']);
        Response::success("Profile retrieved", ["user" => $userData]);
    }


    // Admin check for the following GET routes
    if ($userData['role'] !== 'Administrator' || $userData['status'] !== 'Verified') {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to access user records without permission", $userData['user_id']);
        Response::error("Forbidden", 403);
    }

 
    if (isset($_GET['search']) && trim($_GET['search']) !== '') {
        $searchTerm = '%' . trim($_GET['search']) . '%';
        if (strlen(trim($_GET['search'])) < 3) {
            Response::error("Search term must be at least 3 characters long", 400);
        }
        $sql = "SELECT user_id, username, email, role, status, phone_number, name, updated_at, created_at
            FROM users
            WHERE (username LIKE :search_username
                   OR email LIKE :search_email
                   OR name LIKE :search_name
                   OR phone_number LIKE :search_phone)
            ORDER BY user_id DESC
            LIMIT 101"; // Limit to 101 to check if there are more than 100 results, IF THATS THE CASE, THEN the search should be very specific LOL
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
        ':search_username' => $searchTerm,
        ':search_email' => $searchTerm,
        ':search_name' => $searchTerm,
        ':search_phone' => preg_replace('/09/', '+639', $searchTerm, 1)
        ]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        systemLog($userData['name'] . " (" . $userData['username'] . ") performed a search for users with term: " . trim($_GET['search']), $userData['user_id']);
        
        if ($users) {
            Response::success("Search results retrieved", ["users" => $users]);
        } else {
            Response::error("No users found matching the search criteria", 404);
        }
    } 

    // SCENARIO B: GET /users.php/{id} (Get specific user)
    if (is_numeric($resourceId)) {
        $sql = "SELECT user_id, username, email, role, status, phone_number, name, updated_at, created_at FROM users WHERE user_id = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $resourceId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            systemLog($userData['name'] . " (" . $userData['username'] . ") retrieved profile of user ID " . $resourceId, $userData['user_id']);
            Response::success("User retrieved successfully", ["user" => $user]);
        } else {
            Response::error("User not found", 404);
        }
    }

    // SCENARIO C: GET /users.php (List all users)
    if ($resourceId === null) {
      $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        
        // 1. Secure the limit bounds (min 1, max 100)
        if ($limit < 1) $limit = 10;
        if ($limit > 100) $limit = 100; 

        // 2. Query the total users FIRST
        $countSql = "SELECT COUNT(*) FROM users";
        $totalUsers = (int) $pdo->query($countSql)->fetchColumn();
        
        // 3. Calculate total pages (ensure it is at least 1 even if the table is empty)
        $totalPages = max(1, (int)ceil($totalUsers / $limit));
        
        // 4. FIX: Cap the requested page. 
        // It cannot be less than 1, and it cannot be greater than the total available pages.
        $page = max(1, min($page, $totalPages));

        // 5. Now it is 100% safe to calculate the offset without integer overflows
        $offset = ($page - 1) * $limit;

        // 6. Execute the main query
        $sql = "SELECT user_id, username, email, role, status, phone_number, name, updated_at, created_at 
                FROM users 
                ORDER BY user_id DESC 
                LIMIT :limit OFFSET :offset";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        systemLog($userData['name'] . " (" . $userData['username'] . ") retrieved user list (Page $page)", $userData['user_id']);
        
        Response::success("Users retrieved successfully", [
            "users" => $users,
            "pagination" => [
                "current_page" => $page,
                "per_page" => $limit,
                "total_users" => $totalUsers,
                "total_pages" => $totalPages
            ]
        ]);
    }

   
    
    Response::error("User not found", 404);
}

// ==========================================
// 2. POST: CREATE RESOURCES
// ==========================================
if ($method === 'POST') {
    
    // Disallow logged-in users from creating new accounts or resetting passwords this way
    if ($userData) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to access a public POST route while logged in", $userData['user_id']);
        Response::error("Forbidden: You are already logged in.", 403);
    }

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
        
    // Username
    if (empty($username)) {
        Response::error("Username is required.", 400);
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        // Letters, numbers, underscores only (3-20 chars)
        Response::error("Username must be 3-20 characters long and contain only letters, numbers, and underscores.", 400);
    }

    // Email
    if (empty($email)) {
        Response::error("Email is required.", 400);
    } elseif (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
        Response::error("Invalid email format.", 400);
    }

    // Full Name
    if (empty($name)) {
        Response::error("Full name is required.", 400);
    } elseif (!preg_match('/^[a-zA-Z\s.\'-]{2,100}$/', $name)) {
        // Allows letters, spaces, dots, apostrophes, hyphens
        Response::error("Full name contains invalid characters and must be between 2 and 100 characters long.", 400);
    }

    // Phone Number

    if (empty($phone_number)) {
        Response::error("Phone number is required.", 400);
    } elseif (formatPhNumber($phone_number) === false) {
        // Accepts 09xxxxxxxxx or +639xxxxxxxxx
        Response::error("Invalid Philippines phone number format.", 400);
    }

    $phone_number = formatPhNumber($phone_number);

    // Password
    if (empty($password)) {
        Response::error("Password is required.", 400);
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{6,}$/', $password)) {
        // At least 6 chars, 1 uppercase, 1 lowercase, 1 number
        Response::error("Password must be at least 6 characters and include uppercase, lowercase, and a number.", 400);
    }

    systemLog("Public POST request to " . ($resourceId ?? 'users.php') . " with email: $email and username: $username and phone: $phone_number and name: $name", null);

 // SCENARIO A: POST /users.php/forgot-password
    if ($resourceId === 'forgot-password') {

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // The WHERE clause now explicitly excludes accounts with the Admin role
        $sql = "UPDATE users 
                SET password_hash = :hash, status = 'Unverified', updated_at = NOW() 
                WHERE email = :email 
                AND username = :username 
                AND name = :name 
                AND phone_number = :phone_number 
                AND role != 'Administrator'"; 
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':hash' => $hashedPassword, 
            ':email' => $email, 
            ':username' => $username,
            ':name' => $name, 
            ':phone_number' => $phone_number
        ]);

        if ($stmt->rowCount() > 0) {
            systemLog("Password reset successful for: $email $username $name", null);
            Response::success("Password changed successfully. For security, your account is now Unverified. Please wait for admin verification to log in to your account.", null);
        } else {
            // This triggers if credentials don't match OR if someone tries to reset the Admin account
            systemLog("Failed password reset attempt for: $email $username", null);
            Response::error("Not Found: Could not reset password for those credentials. Make sure all details are correct.", 404);
        }
    }
    
    // SCENARIO B: POST /users.php (Register New User)
    else if ($resourceId === null) {

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (username, email, name, phone_number, password_hash, role, status, created_at, updated_at) 
                VALUES (:username, :email, :name, :phone_number, :hash, 'Staff', 'Unverified', NOW(), NOW())";
        $stmt = $pdo->prepare($sql);

        try {
            $stmt->execute([
                ':username' => $username, ':email' => $email, ':name' => $name,
                ':phone_number' => $phone_number, ':hash' => $hashedPassword
            ]);
            systemLog("New user registered: $username ($email)", null);
            Response::success("Registration successful! Your account is Unverified. Please wait for admin verification to log in to your account.", null, 201); 
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                systemLog("Registration failed due to duplicate entry: $username ($email) or phone number: $phone_number", null);
                Response::error("Conflict: The username, phone number, or email is already registered.", 409);
            } else {
                error_log($e->getMessage());
                Response::error("Database error: Could not create user.", 500);
            }
        }
    } 
    else {
        Response::error("Endpoint not found", 404);
    }
}

// ==========================================
// 3. DELETE: REMOVE RESOURCES
// ==========================================
if ($method === 'DELETE') {
    
    if ($userData['role'] !== 'Administrator' || $userData['status'] !== 'Verified') {
        systemLog($userData['name'] . " attempted to delete a user without permission", $userData['user_id']);
        Response::error("Forbidden: Only administrators can perform user deletions", 403);
    }

    if (!is_numeric($resourceId) || $resourceId <= 0) {
        Response::error("Bad Request: Invalid or missing user ID in URL path", 400);
    }

    $userIdToDelete = (int)$resourceId;

    if ($userData['user_id'] === $userIdToDelete) {
        Response::error("Forbidden: You cannot delete your own account", 403);
    }

    $sql = "DELETE FROM users WHERE user_id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $userIdToDelete, PDO::PARAM_INT);
    
    try {
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            systemLog($userData['name'] . " deleted user with ID $userIdToDelete", $userData['user_id']);
            Response::success("User successfully deleted");
        } else {
            Response::error("Not Found: User does not exist", 404);
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        Response::error("Database error: Could not delete user", 500);
    }
}

// ==========================================
// 4. PUT: UPDATE RESOURCES
// ==========================================
if ($method === 'PUT') {
    
    if (!is_numeric($resourceId) || $resourceId <= 0) {
        Response::error("Bad Request: Invalid or missing user ID in URL path", 400);
    }

    $targetId = (int)$resourceId;
    
    $updateFields = [];
    $queryParams = [];

    $rawData = json_decode(file_get_contents("php://input"), true);
    // SCENARIO A: A user is editing their OWN account
    if ($userData['user_id'] === $targetId) {
        if (isset($rawData['name']) && trim($rawData['name']) !== '') {
            if (!preg_match('/^[a-zA-Z\s.\'-]{2,100}$/', $rawData['name'])) {
                Response::error("Full name contains invalid characters and must be between 2 and 100 characters long.", 400);
            }
            $updateFields[] = "name = :name"; $queryParams[':name'] = trim($rawData['name']);
        }
        if (isset($rawData['username']) && trim($rawData['username']) !== '') {
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $rawData['username'])) {
                Response::error("Username must be 3-20 characters long and contain only letters, numbers, and underscores.", 400);
            }
            $updateFields[] = "username = :username"; $queryParams[':username'] = trim($rawData['username']);
        }
        if (isset($rawData['phone_number']) && trim($rawData['phone_number']) !== '') {
            if (formatPhNumber($rawData['phone_number']) === false) {
                Response::error("Invalid Philippines phone number format.", 400);
            }
            $phone_number = formatPhNumber($rawData['phone_number']);
            $updateFields[] = "phone_number = :phone"; $queryParams[':phone'] = $phone_number;
        }
        if (isset($rawData['email']) && filter_var($rawData['email'], FILTER_VALIDATE_EMAIL)) {
            if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $rawData['email'])) {
                Response::error("Invalid email format.", 400);
            }
            $updateFields[] = "email = :email"; $queryParams[':email'] = trim($rawData  ['email']);
        }
        if (isset($rawData['password']) && strlen($rawData['password']) >= 6) {
            if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{6,}$/', $rawData['password'])) {
                Response::error("Password must be at least 6 characters and include uppercase, lowercase, and a number.", 400);
            }
            $updateFields[] = "password_hash = :password_hash";
            $queryParams[':password_hash'] = password_hash($rawData['password'], PASSWORD_DEFAULT);
        }
        if (isset($rawData['role']) || isset($rawData['status'])) {
            Response::error("Forbidden: You cannot change your own role or status", 403);
        }
    } 
    // SCENARIO B: An Admin is editing someone else's account
    else if ($userData['role'] === 'Administrator' && $userData['status'] === 'Verified') {
        if (isset($rawData['role']) && in_array($rawData['role'], ['Administrator', 'Staff'])) {
            $updateFields[] = "role = :role"; $queryParams[':role'] = $rawData['role'];
        }

        $isVerifying = false;
        if (isset($rawData['status']) && in_array($rawData['status'], ['Verified', 'Unverified'])) {
            
            $updateFields[] = "status = :status"; $queryParams[':status'] = $rawData['status'];
            if ($rawData['status'] === 'Verified') {
                $isVerifying = true;
            }
        }
    } 
    else {
        Response::error("Forbidden: You do not have permission to edit this user", 403);
    }

    // Execute the Update
    if (!empty($updateFields)) {
        $updateFields[] = "updated_at = NOW()";
        $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE user_id = :id";
        $queryParams[':id'] = $targetId;
        $stmt = $pdo->prepare($sql);
        
        try {
            $stmt->execute($queryParams);

            // Send SMS
            if (isset($isVerifying) && $isVerifying) {
                $phoneStmt = $pdo->prepare("SELECT phone_number, name FROM users WHERE user_id = :id");
                $phoneStmt->execute([':id' => $targetId]);
                $targetUser = $phoneStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($targetUser && !empty($targetUser['phone_number'])) {
                    $smsMessage = "Hello {$targetUser['name']}, your Memoria account has been Verified by an Administrator. You can now log-in to the system.";
                    $smsStatus = sendSmsViaTextBee($targetUser['phone_number'], $smsMessage, true);
                    if (!$smsStatus['success']) {
                        error_log("Failed to send verification SMS to User ID {$targetId}: " . $smsStatus['error']);
                        systemLog("Failed to send verification SMS to User ID {$targetId}. Error: " . $smsStatus['error'], $userData['user_id']);
    
                        // ✅ FIX: Return a success response, but include the sms_failed flag and a warning message
                        echo json_encode([
                            "success" => true,
                            "sms_failed" => true,
                            "message" => "Saved changes, but failed to send SMS: " . $smsStatus['error']
                        ]);
                        exit; // Stop execution here so it doesn't hit the Response::success below
                    }
                }
            }
            systemLog($userData['name'] . " updated user with ID $targetId", $userData['user_id']);
            Response::success("User updated successfully");
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                Response::error("Conflict: Email or Username is already in use", 409);
            } else {
                error_log($e->getMessage());
                Response::error("Database error: Could not update user", 500);
            }
        }
    } 
    else {
        Response::error("Bad Request: No valid fields provided to update", 400);
    }
}

// --- FALLBACK ---
Response::error("Method Not Allowed", 405);
?>