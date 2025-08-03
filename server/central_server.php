<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;

$request = Request::createFromGlobals();
$response = new Response();

// Конфигурация
$config = [
    'auth_token' => 'SECRET_TOKEN_123',
    'storage_path' => __DIR__.'/storage',
    'devices_file' => __DIR__.'/devices.json',
    'commands_dir' => __DIR__.'/commands',
    'log_file' => __DIR__.'/server.log'
];

// Функция логирования
function serverLog($message, $level = 'INFO') {
    global $config;
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($config['log_file'], $logLine, FILE_APPEND);
    if (php_sapi_name() === 'cli') echo $logLine;
}

// Создаем директории
foreach ([$config['storage_path'], $config['commands_dir']] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        serverLog("Created directory: $dir");
    }
}

// Загрузка данных
$devices = [];
if (file_exists($config['devices_file'])) {
    $devices = json_decode(file_get_contents($config['devices_file']), true);
    serverLog("Loaded " . count($devices) . " devices");
}

serverLog("Incoming request: " . $request->getMethod() . " " . $request->getPathInfo());

// Маршрутизация
$path = $request->getPathInfo();

// Регистрация устройства
if ($path === '/register' && $request->isMethod('POST')) {
    $data = json_decode($request->getContent(), true);
    serverLog("Registration attempt: " . json_encode($data));

    if (empty($data['device_id'])) {
        $response->setStatusCode(400);
        $response->setContent(json_encode(['error' => 'Missing device_id']));
        serverLog("Registration failed: Missing device_id", "ERROR");
    } else {
        $deviceId = $data['device_id'];
        $devices[$deviceId] = [
            'ip' => $request->getClientIp(),
            'last_seen' => date('c'),
            'status' => 'online',
            'version' => $data['version'] ?? '1.0',
            'cameras' => $data['cameras'] ?? [] // Сохраняем информацию о камерах
        ];
        
        file_put_contents($config['devices_file'], json_encode($devices));
        $response->setContent(json_encode(['status' => 'success']));
        serverLog("Device registered: $deviceId with " . count($devices[$deviceId]['cameras']) . " cameras");
    }
}

// Отправка команды устройству
elseif ($path === '/command' && $request->isMethod('POST')) {
    if ($request->headers->get('Authorization') !== 'Bearer '.$config['auth_token']) {
        $response->setStatusCode(401);
        $response->setContent(json_encode(['error' => 'Unauthorized']));
        serverLog("Unauthorized command attempt", "WARNING");
    } else {
        $command = json_decode($request->getContent(), true);
        $deviceId = $command['device_id'] ?? 'unknown';
        $cameraId = $command['camera_id'] ?? 'default';
        $action = $command['action'] ?? 'unknown';
        
        serverLog("Command for $deviceId/camera:$cameraId: $action");

        if (!isset($devices[$deviceId])) {
            $response->setStatusCode(404);
            $response->setContent(json_encode(['error' => 'Device not found']));
            serverLog("Command failed: Device $deviceId not found", "ERROR");
        } else {
            $timestamp = microtime(true) * 10000;
            $commandFile = $config['commands_dir']."/{$deviceId}_{$timestamp}.json";
            file_put_contents($commandFile, json_encode($command));
            $response->setContent(json_encode([
                'status' => 'queued',
                'command_id' => basename($commandFile)
            ]));
            serverLog("Command queued: $commandFile");
        }
    }
}

// Проверка команд для агента
elseif ($path === '/check-commands' && $request->isMethod('POST')) {
    $data = json_decode($request->getContent(), true);
    $deviceId = $data['device_id'] ?? 'unknown';
    serverLog("Command check from $deviceId");

    $commands = [];
    $commandFiles = glob($config['commands_dir']."/{$deviceId}_*.json");
    
    foreach ($commandFiles as $file) {
        $commands[] = json_decode(file_get_contents($file), true);
        unlink($file);
        serverLog("Command dispatched: $file");
    }
    
    $response->setContent(json_encode(['commands' => $commands]));
    serverLog("Sent " . count($commands) . " commands to $deviceId");
}

// Загрузка видео
elseif ($path === '/upload' && $request->isMethod('POST')) {
    $deviceId = $request->request->get('device_id');
    $cameraId = $request->request->get('camera_id', 'default');
    $sessionId = $request->request->get('session_id', 'unknown');
    serverLog("Upload from $deviceId/camera:$cameraId, session: $sessionId");

    $file = $request->files->get('video');

    if (!$file || !$file->isValid()) {
        $errorMsg = $file ? $file->getErrorMessage() : 'No file';
        $response->setStatusCode(400);
        $response->setContent(json_encode(['error' => 'Invalid file upload: ' . $errorMsg]));
        serverLog("Upload failed: $errorMsg", "ERROR");
    } else {
        $targetDir = $config['storage_path'] . '/' . $deviceId . '/' . $cameraId;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
            serverLog("Created storage directory: $targetDir");
        }
        
        $filename = date('Ymd-His') . '_' . $sessionId . '.mp4';
        $targetPath = $targetDir . '/' . $filename;
        
        if (move_uploaded_file($file->getPathname(), $targetPath)) {
            $fileSize = round(filesize($targetPath) / 1024 / 1024, 2);
            $response->setContent(json_encode([
                'status' => 'success',
                'path' => "{$deviceId}/{$cameraId}/{$filename}"
            ]));
            serverLog("Upload successful: {$filename} ({$fileSize} MB)");
        } else {
            $response->setStatusCode(500);
            $response->setContent(json_encode(['error' => 'File move failed']));
            serverLog("File move failed: $targetPath", "ERROR");
        }
    }
}

// Статус сервера
elseif ($path === '/status') {
    $status = [
        'status' => 'running',
        'devices' => array_keys($devices),
        'time' => date('c')
    ];
    
    foreach ($devices as $id => $device) {
        $status['devices_info'][$id] = [
            'cameras' => count($device['cameras'] ?? []),
            'last_seen' => $device['last_seen']
        ];
    }
    
    $response->setContent(json_encode($status));
    serverLog("Status request served");
}

// Неизвестный маршрут
else {
    $response->setStatusCode(404);
    $response->setContent(json_encode([
        'error' => 'Not found',
        'endpoints' => [
            'POST /register' => 'Device registration',
            'POST /command' => 'Send command to device',
            'POST /check-commands' => 'Check pending commands',
            'POST /upload' => 'Upload video file',
            'GET /status' => 'Server status'
        ]
    ]));
    serverLog("404 Not Found: " . $request->getPathInfo(), "WARNING");
}

// Отправка ответа
$response->headers->set('Content-Type', 'application/json');
$response->send();
serverLog("Response sent with status: " . $response->getStatusCode());
