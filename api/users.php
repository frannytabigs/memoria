<?php 

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'database.php';
require_once 'responses.php'; 
require_once 'usercheck.php';
require_once 'textbee.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;

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
    if ($resourceId === 'me') {
        systemLog($userData['name'] . " (" . $userData['username'] . ") retrieved their own profile", $userData['id']);
        Response::success("Profile retrieved", ["user" => $userData]);
    }
    
    // Admin check for the following GET routes
    if ($userData['role'] !== 'Administrator' || $userData['status'] !== 'Verified') {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to access user records without permission", $userData['id']);
        Response::error("Forbidden", 403);
    }

    // SCENARIO B: GET /users.php/{id} (Get specific user)
    if (is_numeric($resourceId)) {
        $sql = "SELECT id, username, email, role, status, phone_number, name, updated_at, created_at FROM users WHERE id = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $resourceId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            Response::success("User retrieved successfully", ["user" => $user]);
        } else {
            Response::error("User not found", 404);
        }
    }

    // SCENARIO C: GET /users.php (List all users)
    if ($resourceId === null) {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 10;
        if ($limit > 100) $limit = 100; 

        $offset = ($page - 1) * $limit;

        $countSql = "SELECT COUNT(*) FROM users";
        $totalUsers = $pdo->query($countSql)->fetchColumn();
        $totalPages = ceil($totalUsers / $limit);
        
        $sql = "SELECT id, username, email, role, status, phone_number, name, updated_at, created_at 
                FROM users 
                ORDER BY id DESC 
                LIMIT :limit OFFSET :offset";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        systemLog($userData['name'] . " (" . $userData['username'] . ") retrieved user list (Page $page)", $userData['id']);
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
}

// ==========================================
// 2. POST: CREATE RESOURCES
// ==========================================
if ($method === 'POST') {
    
    // Disallow logged-in users from creating new accounts or resetting passwords this way
    if ($userData) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to access a public POST route while logged in", $userData['id']);
        Response::error("Forbidden: You are already logged in.", 403);
    }

    $inputData = json_decode(file_get_contents("php://input"), true);

    // SCENARIO A: POST /users.php/forgot-password
    if ($resourceId === 'forgot-password') {
        
        $email = isset($inputData['email']) ? trim($inputData['email']) : '';
        $username = isset($inputData['username']) ? trim($inputData['username']) : '';
        $name = isset($inputData['name']) ? trim($inputData['name']) : '';
        $phone_number = isset($inputData['phone_number']) ? trim($inputData['phone_number']) : '';
        $newPassword = isset($inputData['new_password']) ? $inputData['new_password'] : '';

        if (empty($email) || empty($newPassword) || strlen($newPassword) < 6 || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($username) || empty($name) || empty($phone_number)) {
            systemLog("Failed forgot password attempt for: $email with missing/invalid fields", null);
            Response::error("Bad Request: All fields are required and must be valid", 400);
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $sql = "UPDATE users 
                SET password_hash = :hash, status = 'Unverified', updated_at = NOW() 
                WHERE email = :email AND username = :username AND name = :name AND phone_number = :phone_number";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':hash' => $hashedPassword, ':email' => $email, ':username' => $username,
            ':name' => $name, ':phone_number' => $phone_number
        ]);

        if ($stmt->rowCount() > 0) {
            systemLog("Password reset successful for: $email $username $name", null);
            Response::success("Password changed successfully. For security, your account is now Unverified.");
        } else {
            systemLog("Failed password reset attempt for: $email $username", null);
            Response::error("Not Found: Could not reset password for those credentials", 404);
        }
    } 
    
    // SCENARIO B: POST /users.php (Register New User)
    else if ($resourceId === null) {
        
        $username = isset($inputData['username']) ? trim($inputData['username']) : '';
        $email = isset($inputData['email']) ? trim($inputData['email']) : '';
        $name = isset($inputData['name']) ? trim($inputData['name']) : '';
        $phone_number = isset($inputData['phone_number']) ? trim($inputData['phone_number']) : '';
        $password = isset($inputData['password']) ? $inputData['password'] : '';

        if (empty($username) || empty($email) || empty($name) || empty($phone_number) || empty($password)) {
            Response::error("Bad Request: All fields are required.", 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error("Bad Request: Invalid email format.", 400);
        if (strlen($password) < 6) Response::error("Bad Request: Password must be at least 6 characters.", 400);

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
            Response::success("Registration successful! Your account is Unverified.", null, 201); 
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                Response::error("Conflict: That Email or Username is already registered.", 409);
            } else {
                error_log($e->getMessage());
                Response::error("Database error: Could not create user.", 500);
            }
        }
    } else {
        Response::error("Endpoint not found", 404);
    }
}

// ==========================================
// 3. DELETE: REMOVE RESOURCES
// ==========================================
if ($method === 'DELETE') {
    
    if ($userData['role'] !== 'Administrator' || $userData['status'] !== 'Verified') {
        systemLog($userData['name'] . " attempted to delete a user without permission", $userData['id']);
        Response::error("Forbidden: Only administrators can perform user deletions", 403);
    }

    if (!is_numeric($resourceId) || $resourceId <= 0) {
        Response::error("Bad Request: Invalid or missing user ID in URL path", 400);
    }

    $userIdToDelete = (int)$resourceId;

    if ($userData['id'] === $userIdToDelete) {
        Response::error("Forbidden: You cannot delete your own account", 403);
    }

    $sql = "DELETE FROM users WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $userIdToDelete, PDO::PARAM_INT);
    
    try {
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            systemLog($userData['name'] . " deleted user with ID $userIdToDelete", $userData['id']);
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
    $inputData = json_decode(file_get_contents("php://input"), true);
    
    if (empty($inputData)) {
        Response::error("Bad Request: No data provided to update", 400);
    }

    $updateFields = [];
    $queryParams = [];

    // SCENARIO A: A user is editing their OWN account
    if ($userData['id'] === $targetId) {
        if (isset($inputData['name']) && trim($inputData['name']) !== '') {
            $updateFields[] = "name = :name"; $queryParams[':name'] = trim($inputData['name']);
        }
        if (isset($inputData['phone_number'])) {
            $updateFields[] = "phone_number = :phone"; $queryParams[':phone'] = trim($inputData['phone_number']);
        }
        if (isset($inputData['email']) && filter_var($inputData['email'], FILTER_VALIDATE_EMAIL)) {
            $updateFields[] = "email = :email"; $queryParams[':email'] = trim($inputData['email']);
        }
        if (isset($inputData['password']) && strlen($inputData['password']) >= 6) {
            $updateFields[] = "password_hash = :password_hash";
            $queryParams[':password_hash'] = password_hash($inputData['password'], PASSWORD_DEFAULT);
        }
        if (isset($inputData['role']) || isset($inputData['status'])) {
            Response::error("Forbidden: You cannot change your own role or status", 403);
        }
    } 
    // SCENARIO B: An Admin is editing someone else's account
    else if ($userData['role'] === 'Administrator' && $userData['status'] === 'Verified') {
        if (isset($inputData['role']) && in_array($inputData['role'], ['Administrator', 'Staff', 'Guest'])) {
            $updateFields[] = "role = :role"; $queryParams[':role'] = $inputData['role'];
        }

        $isVerifying = false;
        if (isset($inputData['status']) && in_array($inputData['status'], ['Verified', 'Unverified'])) {
            $updateFields[] = "status = :status"; $queryParams[':status'] = $inputData['status'];
            if ($inputData['status'] === 'Verified') $isVerifying = true;
        }
    } else {
        Response::error("Forbidden: You do not have permission to edit this user", 403);
    }

    // Execute the Update
    if (!empty($updateFields)) {
        $updateFields[] = "updated_at = NOW()";
        $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $queryParams[':id'] = $targetId;
        $stmt = $pdo->prepare($sql);
        
        try {
            $stmt->execute($queryParams);

            // Send SMS if newly verified
            if (isset($isVerifying) && $isVerifying) {
                $phoneStmt = $pdo->prepare("SELECT phone_number, name FROM users WHERE id = :id");
                $phoneStmt->execute([':id' => $targetId]);
                $targetUser = $phoneStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($targetUser && !empty($targetUser['phone_number'])) {
                    $smsMessage = "Hello {$targetUser['name']}, your Memoria account has been Verified by an Administrator. You now have log-in to the system.";
                    $smsStatus = sendSmsViaTextBee($targetUser['phone_number'], $smsMessage);
                    if (!$smsStatus['success']) {
                        error_log("Failed to send verification SMS to User ID {$targetId}: " . $smsStatus['error']);
                    }
                }
            }
            Response::success("User updated successfully");
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                Response::error("Conflict: Email or Username is already in use", 409);
            } else {
                error_log($e->getMessage());
                Response::error("Database error: Could not update user", 500);
            }
        }
    } else {
        Response::error("Bad Request: No valid fields provided to update", 400);
    }
}

// --- FALLBACK ---
Response::error("Method Not Allowed", 405);
?>