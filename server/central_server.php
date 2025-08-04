
<?php
// Загрузка зависимостей Symfony для обработки HTTP-запросов
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;

// Инициализация объектов запроса и ответа
$request = Request::createFromGlobals();
$response = new Response();

// Конфигурация
$config = [
    'auth_token' => 'SECRET_TOKEN_123',				// Секретный ключ для авторизации API
    'storage_path' => __DIR__.'/storage',			// Директория для хранения видеофайлов
	'devices_file' => __DIR__.'/devices.json',		// Файл с информацией о зарегистрированных устройствах
    'commands_dir' => __DIR__.'/commands',			// Директория для временного хранения команд
    'log_file' => __DIR__.'/server.log'				// Файл для системного логгирования
];

// Функция логирования
/**
 * Записывает сообщение в лог-файл
 * @param string $message - Текст сообщения
 * @param string $level - Уровень важности (INFO, WARNING, ERROR)
 */
function serverLog($message, $level = 'INFO') {
    global $config;
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($config['log_file'], $logLine, FILE_APPEND);
    if (php_sapi_name() === 'cli') echo $logLine;
}

// Подготовка системных директорий
foreach ([$config['storage_path'], $config['commands_dir']] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        serverLog("Created directory: $dir");
    }
}

// Загрузка данных устройств
$devices = [];
if (file_exists($config['devices_file'])) {
    $devices = json_decode(file_get_contents($config['devices_file']), true);
    serverLog("Loaded " . count($devices) . " devices");
}

// Логирование входящего запроса
serverLog("Incoming request: " . $request->getMethod() . " " . $request->getPathInfo());

// Маршрутизация запросов
$path = $request->getPathInfo();

// Регистрация устройства
if ($path === '/register' && $request->isMethod('POST')) {
    $data = json_decode($request->getContent(), true);
    serverLog("Registration attempt: " . json_encode($data));
    
    // Валидация обязательного поля device_id
    if (empty($data['device_id'])) {
        $response->setStatusCode(400);
        $response->setContent(json_encode(['error' => 'Missing device_id']));
        serverLog("Registration failed: Missing device_id", "ERROR");
    } else {
        $deviceId = $data['device_id'];

        // Сохранение информации об устройстве
        $devices[$deviceId] = [
            'ip' => $request->getClientIp(),			// IP устройства
            'last_seen' => date('c'),					// Время последней активности
            'status' => 'online',						// Текущий статус
            'version' => $data['version'] ?? '1.0',		// Версия ПО агента
            'cameras' => $data['cameras'] ?? []			// Список подключенных камер
        ];

        // Сохранение обновленных данных
        file_put_contents($config['devices_file'], json_encode($devices));
        $response->setContent(json_encode(['status' => 'success']));
        serverLog("Device registered: $deviceId with " . count($devices[$deviceId]['cameras']) . " cameras");
    }
}

// Отправка команды устройству
elseif ($path === '/command' && $request->isMethod('POST')) {
    // Проверка авторизации по токену
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

        // Проверка существования устройства
        if (!isset($devices[$deviceId])) {
            $response->setStatusCode(404);
            $response->setContent(json_encode(['error' => 'Device not found']));
            serverLog("Command failed: Device $deviceId not found", "ERROR");
        } else {
            // Генерация уникального имени файла команды
            $timestamp = microtime(true) * 10000;
            $commandFile = $config['commands_dir']."/{$deviceId}_{$timestamp}.json";

            // Сохранение команды в файл
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
    // Поиск всех команд для указанного устройства
    $commandFiles = glob($config['commands_dir']."/{$deviceId}_*.json");
    
    foreach ($commandFiles as $file) {
        // Загрузка и добавление команды
        $commands[] = json_decode(file_get_contents($file), true);

        // Удаление обработанной команды
        unlink($file);
        serverLog("Command dispatched: $file");
    }

    // Отправка списка команд агенту
    $response->setContent(json_encode(['commands' => $commands]));
    serverLog("Sent " . count($commands) . " commands to $deviceId");
}

// Загрузка видеофайлов
elseif ($path === '/upload' && $request->isMethod('POST')) {
    $deviceId = $request->request->get('device_id');
    $cameraId = $request->request->get('camera_id', 'default');
    $sessionId = $request->request->get('session_id', 'unknown');
    serverLog("Upload from $deviceId/camera:$cameraId, session: $sessionId");

    // Получение загружаемого файла
    $file = $request->files->get('video');

    // Проверка валидности файла
    if (!$file || !$file->isValid()) {
        $errorMsg = $file ? $file->getErrorMessage() : 'No file';
        $response->setStatusCode(400);
        $response->setContent(json_encode(['error' => 'Invalid file upload: ' . $errorMsg]));
        serverLog("Upload failed: $errorMsg", "ERROR");
    } else {
        // Формирование пути для сохранения
        $targetDir = $config['storage_path'] . '/' . $deviceId . '/' . $cameraId;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
            serverLog("Created storage directory: $targetDir");
        }

        // Генерация уникального имени файла
        $filename = date('Ymd-His') . '_' . $sessionId . '.mp4';
        $targetPath = $targetDir . '/' . $filename;

        // Сохранение файла
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
elseif ($path === '/status') {\
    // Формирование информации о системе
    $status = [
        'status' => 'running',
        'devices' => array_keys($devices),
        'time' => date('c')
    ];

    // Добавление детальной информации по устройствам
    foreach ($devices as $id => $device) {
        $status['devices_info'][$id] = [
            'cameras' => count($device['cameras'] ?? []),
            'last_seen' => $device['last_seen']
        ];
    }
    
    $response->setContent(json_encode($status));
    serverLog("Status request served");
}

// Обработка неизвестных маршрутов
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
