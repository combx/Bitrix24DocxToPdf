<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Configuration
$host = getenv('RABBITMQ_HOST') ?: 'rabbit';
$port = getenv('RABBITMQ_PORT') ?: 5672;
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';
$queue = getenv('QUEUE') ?: 'documentgenerator_create';
$securityToken = getenv('SECURITY_TOKEN');

// Security Check
if ($securityToken) {
    $requestToken = $_GET['token'] ?? '';
    if ($requestToken !== $securityToken) {
        // Log attempt
        logMsg("Access denied. Invalid token from " . $_SERVER['REMOTE_ADDR']);
        jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
    }
}

// Response helper
function jsonResponse($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Log helper
function logMsg($msg)
{
    // Write to stderr so it shows in docker logs
    file_put_contents('php://stderr', date('Y-m-d H:i:s') . " [API] " . $msg . PHP_EOL);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    $postData = $_POST;

    // Log incoming request
    logMsg("New request received");

    // Basic Validation
    if (empty($postData['QUEUE'])) {
        // Some Bitrix versions use slightly different payload structures, but let's assume compatibility with the old script
        // The old script used $in = &$_POST; and checked $in['command']
    }

    // Connect to RabbitMQ
    $connection = new AMQPStreamConnection($host, $port, $user, $pass);
    $channel = $connection->channel();

    // Ensure queue exists
    $channel->queue_declare($queue, false, true, false, false, false, ['x-message-ttl' => ['I', 86400000]]);

    // The message is just the 'params' part of the payload typically, but old script kept structure.
    // Old script: $messageBody = json_encode($in['params'], 256);
    // We should be careful to maintain compatibility.
    // If 'params' exists, we assume standard Bitrix payload.

    if (isset($postData['params'])) {
        $payload = $postData['params'];
        // Also queue name might be dynamic from post data
        if (!empty($postData['QUEUE'])) {
            $queue = $postData['QUEUE'];
        }
    } else {
        // Fallback or raw payload
        $payload = $postData;
    }

    $msgBody = is_string($payload) ? $payload : json_encode($payload);

    $msg = new AMQPMessage($msgBody, ['content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
    $channel->basic_publish($msg, '', $queue); // Default exchange for simple queue

    $channel->close();
    $connection->close();

    logMsg("Task queued successfully to $queue");

    jsonResponse(['success' => true, 'status' => 'success', 'data' => []]);

} catch (Exception $e) {
    logMsg("Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
