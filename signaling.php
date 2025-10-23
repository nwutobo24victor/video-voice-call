<?php
/**
 * Professional WebRTC Signaling Server
 * Uses JSON file storage for cross-session communication
 * 
 * @version 2.0
 * @author Professional Standards
 */

// Configuration
define('STORAGE_DIR', __DIR__ . '/storage');
define('MESSAGES_FILE', STORAGE_DIR . '/messages.json');
define('USERS_FILE', STORAGE_DIR . '/active_users.json');
define('MESSAGE_TTL', 300); // 5 minutes
define('USER_TTL', 60); // 1 minute of inactivity
define('MAX_MESSAGES', 1000); // Prevent file bloat

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Initialize storage directory and files
 */
function initializeStorage() {
    if (!file_exists(STORAGE_DIR)) {
        if (!mkdir(STORAGE_DIR, 0755, true)) {
            error_log("Failed to create storage directory: " . STORAGE_DIR);
            return false;
        }
    }
    
    if (!file_exists(MESSAGES_FILE)) {
        file_put_contents(MESSAGES_FILE, json_encode([]));
    }
    
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode([]));
    }
    
    return true;
}

/**
 * Acquire file lock and read JSON data
 */
function readJsonFile($filepath) {
    if (!file_exists($filepath)) {
        return [];
    }
    
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        error_log("Failed to open file for reading: $filepath");
        return [];
    }
    
    if (flock($handle, LOCK_SH)) {
        $content = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    fclose($handle);
    return [];
}

/**
 * Acquire file lock and write JSON data
 */
function writeJsonFile($filepath, $data) {
    $handle = fopen($filepath, 'c');
    if (!$handle) {
        error_log("Failed to open file for writing: $filepath");
        return false;
    }
    
    if (flock($handle, LOCK_EX)) {
        ftruncate($handle, 0);
        rewind($handle);
        $result = fwrite($handle, json_encode($data, JSON_PRETTY_PRINT));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $result !== false;
    }
    
    fclose($handle);
    return false;
}

/**
 * Clean up expired messages
 */
function cleanupMessages($messages) {
    $now = time();
    $cleaned = array_filter($messages, function($msg) use ($now) {
        return ($now - ($msg['timestamp'] ?? 0)) < MESSAGE_TTL;
    });
    
    // Limit total messages to prevent bloat
    if (count($cleaned) > MAX_MESSAGES) {
        $cleaned = array_slice($cleaned, -MAX_MESSAGES);
    }
    
    return array_values($cleaned);
}

/**
 * Clean up inactive users
 */
function cleanupUsers($users) {
    $now = time();
    $active = array_filter($users, function($user) use ($now) {
        return ($now - ($user['last_seen'] ?? 0)) < USER_TTL;
    });
    return $active;
}

/**
 * Update user's last seen timestamp
 */
function updateUserActivity($userId) {
    $users = readJsonFile(USERS_FILE);
    $users = cleanupUsers($users);
    
    $users[$userId] = [
        'id' => $userId,
        'last_seen' => time(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    writeJsonFile(USERS_FILE, $users);
}

/**
 * Validate and sanitize input
 */
function validateInput($field, $value, $maxLength = 255) {
    if (empty($value)) {
        return ['valid' => false, 'error' => "Field '$field' is required"];
    }
    
    $sanitized = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    
    if (strlen($sanitized) > $maxLength) {
        return ['valid' => false, 'error' => "Field '$field' exceeds maximum length"];
    }
    
    return ['valid' => true, 'value' => $sanitized];
}

/**
 * Send error response
 */
function sendError($message, $code = 400, $details = []) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'details' => $details,
        'timestamp' => time()
    ]);
    exit;
}

/**
 * Send success response
 */
function sendSuccess($data = [], $message = 'Success') {
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ]);
    exit;
}

// Initialize storage
if (!initializeStorage()) {
    sendError('Storage initialization failed', 500);
}

// Get action
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ==================== SEND MESSAGE ====================
if ($action === 'send') {
    $from = validateInput('from', $_POST['from'] ?? '');
    $to = validateInput('to', $_POST['to'] ?? '');
    $type = validateInput('type', $_POST['type'] ?? '', 50);
    $data = $_POST['data'] ?? '';
    
    if (!$from['valid']) sendError($from['error']);
    if (!$to['valid']) sendError($to['error']);
    if (!$type['valid']) sendError($type['error']);
    
    // Validate JSON data
    $jsonData = json_decode($data);
    if ($jsonData === null && $data !== 'null') {
        sendError('Invalid JSON format in data field');
    }
    
    // Validate data size (prevent DoS)
    if (strlen($data) > 50000) { // 50KB limit
        sendError('Data payload too large (max 50KB)');
    }
    
    // Read current messages
    $messages = readJsonFile(MESSAGES_FILE);
    $messages = cleanupMessages($messages);
    
    // Add new message
    $messages[] = [
        'id' => uniqid('msg_', true),
        'from' => $from['value'],
        'to' => $to['value'],
        'type' => $type['value'],
        'data' => $data,
        'timestamp' => time(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    // Write messages
    if (writeJsonFile(MESSAGES_FILE, $messages)) {
        updateUserActivity($from['value']);
        sendSuccess([
            'message_count' => count($messages),
            'message_id' => end($messages)['id']
        ], 'Message sent successfully');
    } else {
        sendError('Failed to save message', 500);
    }
}

// ==================== RECEIVE MESSAGES ====================
elseif ($action === 'receive') {
    $to = validateInput('to', $_POST['to'] ?? $_GET['to'] ?? '');
    
    if (!$to['valid']) sendError($to['error']);
    
    $toValue = $to['value'];
    
    // Read messages
    $messages = readJsonFile(MESSAGES_FILE);
    $messages = cleanupMessages($messages);
    
    $receivedMessages = [];
    $remainingMessages = [];
    
    foreach ($messages as $msg) {
        if (isset($msg['to']) && $msg['to'] === $toValue) {
            // Remove sensitive data before sending
            unset($msg['ip']);
            unset($msg['timestamp']);
            $receivedMessages[] = $msg;
        } else {
            $remainingMessages[] = $msg;
        }
    }
    
    // Write back remaining messages
    writeJsonFile(MESSAGES_FILE, $remainingMessages);
    
    // Update user activity
    updateUserActivity($toValue);
    
    sendSuccess([
        'messages' => $receivedMessages,
        'count' => count($receivedMessages)
    ], 'Messages retrieved successfully');
}

// ==================== GET ACTIVE USERS ====================
elseif ($action === 'users') {
    $users = readJsonFile(USERS_FILE);
    $users = cleanupUsers($users);
    writeJsonFile(USERS_FILE, $users);
    
    // Remove sensitive data
    $publicUsers = array_map(function($user) {
        return [
            'id' => $user['id'],
            'last_seen' => $user['last_seen']
        ];
    }, array_values($users));
    
    sendSuccess([
        'users' => $publicUsers,
        'count' => count($publicUsers)
    ], 'Active users retrieved');
}

// ==================== REGISTER USER ====================
elseif ($action === 'register') {
    $userId = validateInput('userId', $_POST['userId'] ?? '');
    
    if (!$userId['valid']) sendError($userId['error']);
    
    updateUserActivity($userId['value']);
    
    sendSuccess([
        'userId' => $userId['value'],
        'registered' => true
    ], 'User registered successfully');
}

// ==================== SERVER STATUS ====================
elseif ($action === 'status') {
    $messages = readJsonFile(MESSAGES_FILE);
    $users = readJsonFile(USERS_FILE);
    
    $messages = cleanupMessages($messages);
    $users = cleanupUsers($users);
    
    writeJsonFile(MESSAGES_FILE, $messages);
    writeJsonFile(USERS_FILE, $users);
    
    sendSuccess([
        'server' => 'running',
        'storage_dir' => STORAGE_DIR,
        'messages_file' => basename(MESSAGES_FILE),
        'users_file' => basename(USERS_FILE),
        'message_count' => count($messages),
        'active_users' => count($users),
        'message_ttl' => MESSAGE_TTL,
        'user_ttl' => USER_TTL,
        'storage_writable' => is_writable(STORAGE_DIR),
        'php_version' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s')
    ], 'Server status OK');
}

// ==================== CLEANUP (Admin only - add auth in production) ====================
elseif ($action === 'cleanup') {
    $messages = readJsonFile(MESSAGES_FILE);
    $users = readJsonFile(USERS_FILE);
    
    $oldMessageCount = count($messages);
    $oldUserCount = count($users);
    
    $messages = cleanupMessages($messages);
    $users = cleanupUsers($users);
    
    writeJsonFile(MESSAGES_FILE, $messages);
    writeJsonFile(USERS_FILE, $users);
    
    sendSuccess([
        'messages_removed' => $oldMessageCount - count($messages),
        'users_removed' => $oldUserCount - count($users),
        'messages_remaining' => count($messages),
        'users_remaining' => count($users)
    ], 'Cleanup completed');
}

// ==================== CLEAR ALL (Development only) ====================
elseif ($action === 'clear') {
    // WARNING: Remove this in production or add authentication
    writeJsonFile(MESSAGES_FILE, []);
    writeJsonFile(USERS_FILE, []);
    
    sendSuccess([
        'messages_cleared' => true,
        'users_cleared' => true
    ], 'All data cleared');
}

// ==================== INVALID ACTION ====================
else {
    sendError('Invalid or missing action parameter', 400, [
        'valid_actions' => [
            'send' => 'Send a message to a peer',
            'receive' => 'Receive messages for a user',
            'users' => 'Get list of active users',
            'register' => 'Register/update user activity',
            'status' => 'Get server status',
            'cleanup' => 'Clean up expired data',
            'clear' => 'Clear all data (dev only)'
        ]
    ]);
}
?>