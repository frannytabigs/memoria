<?php 

define('ITS_ME_JUSTTOVERIFY', true);
require_once 'database.php';
require_once 'responses.php'; 
require_once 'usercheck.php';
require_once 'textbee.php';
require_once 'logs.php';

$method = $_SERVER['REQUEST_METHOD'] ?? null;
$action = $_GET['action'] ?? 'register'; // Default to 'register' if no action is specified

// --- AUTHENTICATION GATEKEEPER ---
// Allow guests to access forgot_password and register
$publicActions = ['forgot_password', 'register'];

if ($method === 'POST' && in_array($action, $publicActions)) {
    $userData = null; 
} else {
    // For GET, PUT, DELETE, and normal operations, enforce the strict login check
    $userData = checkuser(); 
}

// --- 1. HANDLE GET REQUESTS ---
if ($method === 'GET') {
   
    if ($userData['role'] === 'Administrator' && $userData['status'] === 'Verified') {
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
   
    if ($userData['role'] === 'Staff' && $userData['status'] === 'Verified') {
       systemLog($userData['name'] . " (" . $userData['username'] . ") retrieved their own profile", $userData['id']);
       Response::success("Logged in", ["user" => $userData]);
       
    }
   systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to access user list without permission", $userData['id']);
   Response::error("Forbidden", 403);
   
}

// --- 2. HANDLE DELETE REQUESTS ---
if ($method === 'DELETE') {
    
    // Ensure user is logged in and has exact permissions
    if ($userData['role'] !== 'Administrator' || $userData['status'] !== 'Verified') {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to delete a user without permission", $userData['id']);
        Response::error("Forbidden: Only administrators can perform user deletions", 403);
    }

    $userIdToDelete = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($userIdToDelete <= 0) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to delete a user with an invalid ID", $userData['id']);
        Response::error("Bad Request: Invalid or missing user ID", 400);
        
    }

    if ($userData['id'] === $userIdToDelete) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to delete their own account", $userData['id']);
        Response::error("Forbidden: You cannot delete your own account", 403);
        
    }

    $sql = "DELETE FROM users WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $userIdToDelete, PDO::PARAM_INT);
    
    try {
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            systemLog($userData['name'] . " (" . $userData['username'] . ") deleted user with ID $userIdToDelete", $userData['id']);
            Response::success("User successfully deleted");
        } else {
            systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to delete user with ID $userIdToDelete but it did not exist", $userData['id']);
            Response::error("Not Found: User does not exist", 404);
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        systemLog($userData['name'] . " (" . $userData['username'] . ") encountered a database error while trying to delete user with ID $userIdToDelete", $userData['id']);
        Response::error("Database error: Could not delete user", 500);
    }
    
    
}

// --- 3. HANDLE PUT/EDIT REQUESTS ---
if ($method === 'PUT') {
    
    $targetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($targetId <= 0) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to edit a user with an invalid ID", $userData['id']);
        Response::error("Bad Request: Invalid or missing user ID", 400);
        
    }

    $inputData = json_decode(file_get_contents("php://input"), true);
    
    if (empty($inputData)) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to edit user with ID $targetId but provided no data", $userData['id']);
        Response::error("Bad Request: No data provided to update", 400);
        
    }

    $updateFields = [];
    $queryParams = [];

    // SCENARIO A: A user is editing their OWN account
    if ($userData['id'] === $targetId) {
        
        if (isset($inputData['name']) && trim($inputData['name']) !== '') {
            $updateFields[] = "name = :name";
            $queryParams[':name'] = trim($inputData['name']);
        }

        if (isset($inputData['phone_number'])) {
            $updateFields[] = "phone_number = :phone";
            $queryParams[':phone'] = trim($inputData['phone_number']);
        }
        
        if (isset($inputData['email']) && filter_var($inputData['email'], FILTER_VALIDATE_EMAIL)) {
            $updateFields[] = "email = :email";
            $queryParams[':email'] = trim($inputData['email']);
        }

        if (isset($inputData['password']) && strlen($inputData['password']) >= 6) {
            $updateFields[] = "password_hash = :password_hash";
            $queryParams[':password_hash'] = password_hash($inputData['password'], PASSWORD_DEFAULT);
        }

        if (isset($inputData['role']) || isset($inputData['status'])) {
            systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to change their own role or status, which is not allowed", $userData['id']);
            Response::error("Forbidden: You cannot change your own role or status", 403);
            
        }
    } 
    // SCENARIO B: An Admin is editing someone else's account
    else if ($userData['role'] === 'Administrator' && $userData['status'] === 'Verified') {
        
        $validRoles = ['Administrator', 'Staff', 'Guest'];
        if (isset($inputData['role']) && in_array($inputData['role'], $validRoles)) {
            $updateFields[] = "role = :role";
            $queryParams[':role'] = $inputData['role'];
        }

        $isVerifying = false; // Our flag to track if we need to send an SMS
        $validStatuses = ['Verified', 'Unverified'];
        if (isset($inputData['status']) && in_array($inputData['status'], $validStatuses)) {
            $updateFields[] = "status = :status";
            $queryParams[':status'] = $inputData['status'];
            if ($inputData['status'] === 'Verified') {
                $isVerifying = true;
            }
        }
    } 
    // SCENARIO C: Not the account owner, and not an admin
    else {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to edit user with ID $targetId without permission", $userData['id']);
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

            if (isset($isVerifying) && $isVerifying) {
                
                // We need to fetch the target user's phone number and name from the database
                $phoneStmt = $pdo->prepare("SELECT phone_number, name FROM users WHERE id = :id");
                $phoneStmt->execute([':id' => $targetId]);
                $targetUser = $phoneStmt->fetch(PDO::FETCH_ASSOC);
                
                // Ensure the user actually has a phone number on file before trying to send
                if ($targetUser && !empty($targetUser['phone_number'])) {
                    
                    $smsMessage = "Hello {$targetUser['name']}, your Memoria account has been Verified by an Administrator. You now have log-in  to the system.";
                    
                    // CALL YOUR TEXTBEE SMS FUNCTION HERE
                    $smsStatus = sendSmsViaTextBee($targetUser['phone_number'], $smsMessage);
    
                    // Optional: If the SMS fails, we can log it to the server so the Admin knows
                    if (!$smsStatus['success']) {
                        systemLog($userData['name'] . " (" . $userData['username'] . ") had their verification SMS fail to send to user with ID $targetId", $userData['id']);
                        error_log("Failed to send verification SMS to User ID {$targetId}: " . $smsStatus['error']);
                    }
                }
            }


            Response::success("User updated successfully");
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to edit user with ID $targetId but caused a conflict with existing email or username", $userData['id']);
                Response::error("Conflict: Email or Username is already in use", 409);
            } else {
                error_log($e->getMessage());
                systemLog($userData['name'] . " (" . $userData['username'] . ") encountered a database error while trying to edit user with ID $targetId", $userData['id']);
                Response::error("Database error: Could not update user", 500);
            }
        }
    } else {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to edit user with ID $targetId but provided no valid fields to update", $userData['id']);
        Response::error("Bad Request: No valid fields provided to update", 400);
    }

    
}

// --- 4. FORGOT PASSWORD (PUBLIC ENDPOINT) ---
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'forgot_password') {
    
    // Check if user is already logged in
    if ($userData) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to access forgot password while already logged in", $userData['id']);
        Response::error("Forbidden: You are already logged in", 403);
    }
    
    $inputData = json_decode(file_get_contents("php://input"), true);
    $email = isset($inputData['email']) ? trim($inputData['email']) : '';
    $username = isset($inputData['username']) ? trim($inputData['username']) : '';
    $name = isset($inputData['name']) ? trim($inputData['name']) : '';
    $phone_number = isset($inputData['phone_number']) ? trim($inputData['phone_number']) : '';
    $newPassword = isset($inputData['new_password']) ? $inputData['new_password'] : '';

    if (empty($email) || empty($newPassword) || strlen($newPassword) < 6 || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($username) || empty($name) || empty($phone_number)) {
        
        systemLog("Failed forgot password attempt for: " . $email . " " . $username . " " . $name . " " . $phone_number . " with missing or invalid fields", null);
        Response::error("Bad Request: All fields are required and must be valid", 400);
        
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    // FIXED: Changed commas to AND in the WHERE clause
    $sql = "UPDATE users 
            SET password_hash = :hash, 
                status = 'Unverified', 
                updated_at = NOW() 
            WHERE email = :email 
              AND username = :username 
              AND name = :name 
              AND phone_number = :phone_number";
            
    $stmt = $pdo->prepare($sql);
    
    // FIXED: Bound all parameters required by the WHERE clause
    $stmt->execute([
        ':hash' => $hashedPassword,
        ':email' => $email,
        ':username' => $username,
        ':name' => $name,
        ':phone_number' => $phone_number
    ]);

    if ($stmt->rowCount() > 0) {
        systemLog("Password reset successful for: " . $email . " " . $username . " " . $name . " " . $phone_number, null);
        Response::success("Password changed successfully. For security, your account is now Unverified. Please contact an Administrator to restore your access.");
    } else {
        systemLog("Failed password reset attempt for: " . $email . " " . $username . " " . $name . " " . $phone_number, null);
        Response::error("Not Found: Could not reset password for those credentials", 404);
    }
    
}

// --- 5. REGISTER NEW USER (PUBLIC ENDPOINT) ---
if ($method === 'POST' && $action === 'register') {
    
    // Check if someone logged in is trying to register a new account
    // (Optional: You might want Admins to be able to use this, but usually 
    // it's better to force them to log out to test registration)
    if ($userData) {
        systemLog($userData['name'] . " (" . $userData['username'] . ") attempted to access registration while already logged in", $userData['id']);
        Response::error("Forbidden: You are already logged in.", 403);
    }

    $inputData = json_decode(file_get_contents("php://input"), true);
    
    $username = isset($inputData['username']) ? trim($inputData['username']) : '';
    $email = isset($inputData['email']) ? trim($inputData['email']) : '';
    $name = isset($inputData['name']) ? trim($inputData['name']) : '';
    $phone_number = isset($inputData['phone_number']) ? trim($inputData['phone_number']) : '';
    $password = isset($inputData['password']) ? $inputData['password'] : '';

    // Validate all required fields
    if (empty($username) || empty($email) || empty($name) || empty($phone_number) || empty($password)) {
        systemLog("Failed registration attempt with missing fields: " . $username . " " . $email . " " . $name . " " . $phone_number, null);
        Response::error("Bad Request: All fields (username, email, name, phone_number, password) are required.", 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        systemLog("Failed registration attempt with invalid email: " . $email, null);
        Response::error("Bad Request: Invalid email format.", 400);
    }

    if (strlen($password) < 6) {
        systemLog("Failed registration attempt with weak password for username: " . $username, null);
        Response::error("Bad Request: Password must be at least 6 characters long.", 400);
    }

    // Hash the password securely
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Hardcode the default role and status directly into the SQL
    $sql = "INSERT INTO users (username, email, name, phone_number, password_hash, role, status, created_at, updated_at) 
            VALUES (:username, :email, :name, :phone_number, :hash, 'Staff', 'Unverified', NOW(), NOW())";

    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':name' => $name,
            ':phone_number' => $phone_number,
            ':hash' => $hashedPassword
        ]);

        // Optional: If you wanted to alert the Admin that a new user registered, 
        // you could trigger a TextBee SMS to the Admin's phone number here!
        systemLog("New user registered: " . $username . " (" . $email . ")", null);
        Response::success("Registration successful! Your account is currently Unverified. Please wait for an Administrator to approve your access.", null, 201); // 201 Created
        
    } catch (PDOException $e) {
        // Catch constraint violations (Error 23000) if the username or email already exists in the DB
        if ($e->getCode() == 23000) {
            systemLog("Failed registration attempt with duplicate username or email: " . $username . " (" . $email . ")", null);
            Response::error("Conflict: That Email or Username is already registered.", 409);
        } else {
            error_log($e->getMessage());
            systemLog("Database error during registration attempt for username: " . $username . " (" . $email . ") " . $e->getMessage(), null);
            Response::error("Database error: Could not create user.", 500);
        }
    }
}
// --- FALLBACK ---
Response::error("Method Not Allowed", 405);

?>