<?php
// signal.php - Simple file-based signaling for WebRTC
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$file = 'signal.json';

// Initialize file if it doesn't exist
if (!file_exists($file)) {
    file_put_contents($file, json_encode([
        'offer' => null,
        'answer' => null,
        'candidates' => []
    ]));
}

// Handle POST requests (sending data)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? null;
    $data = $_POST['data'] ?? null;

    if (!$type) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing type']);
        exit;
    }

    // Handle reset
    if ($type === 'reset') {
        file_put_contents($file, json_encode([
            'offer' => null,
            'answer' => null,
            'candidates' => []
        ]));
        echo json_encode(['success' => true, 'message' => 'Reset successful']);
        exit;
    }

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing data']);
        exit;
    }

    // Read existing data with file locking
    $fp = fopen($file, 'c+');
    if (flock($fp, LOCK_EX)) {
        $content = fread($fp, filesize($file) ?: 1);
        $signals = $content ? json_decode($content, true) : [];

        if (!is_array($signals)) {
            $signals = ['offer' => null, 'answer' => null, 'candidates' => []];
        }

        // Store the signal
        if ($type === 'offer') {
            $signals['offer'] = json_decode($data, true);
            $signals['answer'] = null; // Reset answer for new offer
            error_log("Stored offer");
        } elseif ($type === 'answer') {
            $signals['answer'] = json_decode($data, true);
            error_log("Stored answer");
        } elseif ($type === 'candidate') {
            if (!isset($signals['candidates'])) {
                $signals['candidates'] = [];
            }
            $candidate = json_decode($data, true);
            $signals['candidates'][] = $candidate;
            error_log("Stored candidate: " . count($signals['candidates']) . " total");
        }

        // Write back
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($signals));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    echo json_encode(['success' => true]);
    exit;
}

// Handle GET requests (polling for data)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $signals = json_decode(file_get_contents($file), true);

    if (!is_array($signals)) {
        $signals = ['offer' => null, 'answer' => null, 'candidates' => []];
    }

    // Return the latest candidate (don't remove it, let client track what it's processed)
    $response = [
        'offer' => $signals['offer'] ?? null,
        'answer' => $signals['answer'] ?? null,
        'candidate' => null
    ];

    // Return the most recent unprocessed candidate
    if (!empty($signals['candidates'])) {
        $response['candidate'] = end($signals['candidates']);
    }

    echo json_encode($response);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>