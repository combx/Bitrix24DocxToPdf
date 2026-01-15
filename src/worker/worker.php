<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Config
$host = getenv('RABBITMQ_HOST') ?: 'rabbit';
$port = getenv('RABBITMQ_PORT') ?: 5672;
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';
$queue = getenv('QUEUE') ?: 'documentgenerator_create';
$gotenbergUrl = getenv('GOTENBERG_URL') ?: 'http://gotenberg:3000';

function logMsg($msg)
{
    echo date('Y-m-d H:i:s') . " [WORKER] " . $msg . PHP_EOL;
}

logMsg("Starting worker... Waiting for RabbitMQ at $host:$port");

$connection = null;
while (true) {
    try {
        $connection = new AMQPStreamConnection($host, $port, $user, $pass);
        break;
    } catch (Exception $e) {
        logMsg("RabbitMQ unavailable, retrying in 5s...");
        sleep(5);
    }
}

$channel = $connection->channel();
$channel->queue_declare($queue, false, true, false, false, false, ['x-message-ttl' => ['I', 86400000]]);

logMsg("Connected. Listening on queue '$queue'...");

$httpClient = new Client(['timeout' => 300]);

$callback = function (AMQPMessage $msg) use ($httpClient, $gotenbergUrl) {
    logMsg("Processing message...");

    try {
        $data = json_decode($msg->body, true);
        if (!$data || empty($data['file'])) {
            throw new Exception("Invalid payload: " . substr($msg->body, 0, 100));
        }

        $sourceUrl = $data['file'];
        $backUrl = $data['back_url'] ?? null;

        // 1. Download File
        $tempFile = tempnam(sys_get_temp_dir(), 'doc');
        // Add extension usually needed by Gotenberg to detect type
        $inputFile = $tempFile . '.docx';
        rename($tempFile, $inputFile);

        logMsg("Downloading $sourceUrl...");
        $httpClient->request('GET', $sourceUrl, ['sink' => $inputFile]);

        // 2. Convert via Gotenberg
        // Endpoint: /forms/libreoffice/convert
        logMsg("Sending to Gotenberg at $gotenbergUrl...");

        $response = $httpClient->request('POST', "$gotenbergUrl/forms/libreoffice/convert", [
            'multipart' => [
                [
                    'name' => 'files',
                    'contents' => fopen($inputFile, 'r'),
                    'filename' => 'document.docx'
                ]
            ]
        ]);

        if ($response->getStatusCode() != 200) {
            throw new Exception("Gotenberg error: " . $response->getBody());
        }

        $pdfContent = $response->getBody()->getContents();
        $pdfPath = $inputFile . '.pdf';
        file_put_contents($pdfPath, $pdfContent);

        logMsg("Conversion successful. PDF size: " . strlen($pdfContent));

        // 3. Upload Result
        if ($backUrl) {
            logMsg("Uploading to $backUrl...");
            $uploadResp = $httpClient->request('POST', $backUrl, [
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($pdfPath, 'r'),
                        'filename' => 'document.pdf'
                    ],
                    // Assuming Bitrix only wants PDF, or we loop if multiple formats needed.
                    // Simplified for now to just PDF as it's the main goal.
                ]
            ]);
            logMsg("Upload status: " . $uploadResp->getStatusCode());
        }

        // Cleanup
        @unlink($inputFile);
        @unlink($pdfPath);

        $msg->ack();
        logMsg("Task finished.");

    } catch (Exception $e) {
        logMsg("Error: " . $e->getMessage());
        // $msg->nack(true); // Requeue? Or ack to discard? Let's ack to avoid loops for now
        $msg->ack();
    }
};

$channel->basic_qos(null, 1, null);
$channel->basic_consume($queue, '', false, false, false, false, $callback);

$maxJobs = 100;
$jobsProcessed = 0;

while ($channel->is_consuming()) {
    $channel->wait();
    $jobsProcessed++;
    if ($jobsProcessed >= $maxJobs) {
        logMsg("Processed $jobsProcessed jobs. Restarting to free memory...");
        break;
    }
}

$channel->close();
$connection->close();
