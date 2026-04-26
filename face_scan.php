<?php
/**
 * face_scan.php  –  PHP proxy to the Python Flask face-verification microservice on Render.
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/schema_helpers.php';
ensureUserIdentityVerificationColumns($conn);

// ── Replace with your actual Render service URL ───────────────────────────────
define('DEEPFACE_API_URL', 'https://deepface-api.onrender.com');
// ─────────────────────────────────────────────────────────────────────────────

// Guard: must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$userId = (int) $_SESSION['user_id'];

// ── Health check passthrough ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['health'])) {
    $ch = curl_init(DEEPFACE_API_URL . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        http_response_code(503);
        echo json_encode([
            'status' => 'error',
            'error'  => 'Face verification service unavailable.',
            'detail' => $curlError,
        ]);
        exit();
    }

    http_response_code($httpCode ?: 500);
    echo $response;
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// ── Check if user is already verified ────────────────────────────────────────
$statusStmt = $conn->prepare(
    "SELECT identity_verified,
            identity_verified_at,
            identity_verified_name,
            identity_verification_confidence
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$statusStmt->bind_param('i', $userId);
$statusStmt->execute();
$existingVerification = $statusStmt->get_result()->fetch_assoc() ?: [];
$statusStmt->close();

if (!empty($existingVerification['identity_verified'])) {
    echo json_encode([
        'status'       => 'already_verified',
        'matched_with' => $existingVerification['identity_verified_name'] ?: null,
        'confidence'   => $existingVerification['identity_verification_confidence'] !== null
            ? (float) $existingVerification['identity_verification_confidence']
            : null,
        'verified_at'  => !empty($existingVerification['identity_verified_at'])
            ? date('F d, Y h:i A', strtotime($existingVerification['identity_verified_at']))
            : null,
        'message'      => 'Your identity is already verified.',
    ]);
    exit();
}

// ── Check the user has a 2x2 photo on file ────────────────────────────────────
if (!userHas2x2PhotoDocument($conn, $userId)) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'missing_2x2',
        'message' => 'Please upload your 2x2 Photo in Documents before selfie verification becomes available.',
    ]);
    exit();
}

// ── Read incoming selfie ──────────────────────────────────────────────────────
$body    = file_get_contents('php://input');
$payload = json_decode($body, true);

if (empty($payload['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing image']);
    exit();
}

// ── Fetch the user's stored 2x2 photo from the database ──────────────────────
//    Adjust the query below to match your actual documents table/column names.
$photoStmt = $conn->prepare(
    "SELECT file_path, file_data, student_name
     FROM documents
     WHERE user_id   = ?
       AND doc_type  = '2x2_photo'
     ORDER BY uploaded_at DESC
     LIMIT 1"
);
$photoStmt->bind_param('i', $userId);
$photoStmt->execute();
$photoRow = $photoStmt->get_result()->fetch_assoc();
$photoStmt->close();

if (!$photoRow) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'missing_2x2',
        'message' => 'No 2x2 photo found for your account.',
    ]);
    exit();
}

// ── Convert stored photo to base64 ───────────────────────────────────────────
//    Two common storage strategies: file path on disk, or binary blob in DB.
$referenceBase64 = '';

if (!empty($photoRow['file_data'])) {
    // Strategy A: stored as binary blob in the database
    $referenceBase64 = base64_encode($photoRow['file_data']);
} elseif (!empty($photoRow['file_path'])) {
    // Strategy B: stored as a file path on disk
    $fullPath = __DIR__ . '/' . ltrim($photoRow['file_path'], '/');
    if (!file_exists($fullPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Stored 2x2 photo file not found on server.']);
        exit();
    }
    $referenceBase64 = base64_encode(file_get_contents($fullPath));
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Could not retrieve your stored 2x2 photo.']);
    exit();
}

// ── Build the name label for the match result ─────────────────────────────────
$referenceName = $photoRow['student_name'] ?? ('user_' . $userId);

// ── Build the payload for the Python API ─────────────────────────────────────
$pythonPayload = json_encode([
    'live_image'      => $payload['image'],          // base64 selfie from browser
    'reference_image' => $referenceBase64,           // base64 stored 2x2 photo
    'reference_name'  => $referenceName,
]);

// ── Call Render ───────────────────────────────────────────────────────────────
$ch = curl_init(DEEPFACE_API_URL . '/scan');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $pythonPayload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 90,   // DeepFace + Render cold start can be slow
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(503);
    echo json_encode([
        'error'  => 'Face verification service unavailable.',
        'detail' => $curlError,
    ]);
    exit();
}

// ── If matched, persist verification in the database ─────────────────────────
$decodedResponse = json_decode($response, true);

if (
    is_array($decodedResponse) &&
    ($decodedResponse['status'] ?? '') === 'matched'
) {
    $matchedName = trim((string) ($decodedResponse['matched_with'] ?? ''));
    $confidence  = isset($decodedResponse['confidence'])
        ? (float) $decodedResponse['confidence']
        : null;

    $stmt = $conn->prepare(
        "UPDATE users
         SET identity_verified                  = 1,
             identity_verified_at               = NOW(),
             identity_verified_name             = ?,
             identity_verification_confidence   = ?
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('sdi', $matchedName, $confidence, $userId);
        $stmt->execute();
        $stmt->close();
    }

    $decodedResponse['verified_at'] = date('F d, Y h:i A');
    $response = json_encode($decodedResponse);
}

http_response_code($httpCode ?: 500);
echo $response;