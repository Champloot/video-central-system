<?php
class VideoAgent {
    private $config;					// Конфигурация агента
    private $activeRecordings = [];		// Активные записи
    private $completedRecordings = [];	// Завершенные записи (ожидают загрузки)

    public function __construct() {
        $this->loadConfig(); // Инициализация конфигурации
    }

    // Загрузка конфигурации агента
    private function loadConfig() {
        $this->config = [
            'device_id' => 'AGENT-' . substr(md5(gethostname()), 0, 8),	// Уникальный ID на основе имени хоста
            'central_server' => 'http://10.8.0.1:8000',					// Адрес сервера управления
            'auth_token' => 'SECRET_TOKEN_123',							// Токен авторизации
            'cameras' => [												// Конфигурация камер
                'cam1' => 'rtsp://admin:password@192.168.1.101/stream1',
                'cam2' => 'rtsp://admin:password@192.168.1.102/stream1',
                'cam3' => 'rtsp://admin:password@192.168.1.103/stream1'
            ],
            'temp_dir' => __DIR__.'/tmp',				// Временная директория для видео
            'log_file' => __DIR__.'/agent.log',			// Файл логов
            'check_interval' => 5,						// Интервал проверки команд (сек)
            'version' => '1.1.0'						// Версия ПО агента
        ];

		// Создание необходимых директорий
        foreach ([$this->config['temp_dir'], dirname($this->config['log_file'])] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
                $this->log("Created directory: $dir");
            }
        }
    }

	// Основной метод запуска агента
    public function run() {
        $this->log("Starting agent: {$this->config['device_id']}");
        $this->log("Agent version: {$this->config['version']}");
        $this->log("Central server: {$this->config['central_server']}");
        $this->log("Cameras: " . implode(', ', array_keys($this->config['cameras'])));
        
		// Регистрация на центральном сервере
        if ($this->registerDevice()) {
            $this->log("Registered successfully");
            $this->mainLoop(); // Запуск основного цикла
        } else {
            $this->log("Registration failed. Exiting.", "ERROR");
            exit(1);
        }
    }

	// Регистрация устройства на центральном сервере
    private function registerDevice() {
        $url = $this->config['central_server'] . '/register';
        $data = [
            'device_id' => $this->config['device_id'],
            'version' => $this->config['version'],
            'capabilities' => ['video_recording', 'camera_control'],
            'cameras' => array_keys($this->config['cameras']) // Список доступных камер
        ];

        try {
            $this->log("Registering device...");
            $response = $this->httpRequest($url, 'POST', $data);
            return $response['status'] === 'success';
        } catch (Exception $e) {
            $this->log("Registration error: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

	// Основной рабочий цикл агента
    private function mainLoop() {
        $this->log("Entering main loop");
        
        while (true) {
            try {
				// Логирование текущего состояния
                $this->log("Active recordings: " . count($this->activeRecordings));
                $this->log("Completed recordings: " . count($this->completedRecordings));

				// Проверка состояния активных записей
                $this->checkActiveRecordings();

				// Обработка завершенных записей (загрузка на сервер)
                $this->processCompletedRecordings();

				// Проверка новых команд от сервера
                $this->log("Fetching commands...");
                $commands = $this->fetchCommands();

				// Обработка полученных команд
                if (!empty($commands)) {
                    $this->log("Received " . count($commands) . " commands");
                    $this->processCommands($commands);
                }

				// Ожидание перед следующей итерацией
                sleep($this->config['check_interval']);

            } catch (Exception $e) {
                $this->log("Main loop error: " . $e->getMessage(), "ERROR");
                sleep(10); // Увеличенная задержка при ошибке
            }
        }
    }

	// Проверка состояния активных записей
    private function checkActiveRecordings() {
        foreach ($this->activeRecordings as $sessionId => $recording) {
			// Проверка существования процесса
            $processRunning = posix_kill($recording['pid'], 0);

            if (!$processRunning) {
                $this->log("Recording finished: {$sessionId}");
                $this->completedRecordings[$sessionId] = $recording;
                unset($this->activeRecordings[$sessionId]);
                $this->completedRecordings[$sessionId]['status'] = 'completed';
            }
        }
    }

	// Обработка завершенных записей
    private function processCompletedRecordings() {
        $this->log("Processing completed recordings...");
        
        foreach ($this->completedRecordings as $sessionId => $recording) {
            $this->log("Processing recording: $sessionId, status: {$recording['status']}");

			// Записи готовые к загрузке (завершенные или остановленные)
            if (in_array($recording['status'], ['completed', 'stopped'])) {
                if (file_exists($recording['output_file'])) {
                    try {
                        $fileSize = filesize($recording['output_file']);
                        $this->log("Uploading file: {$recording['output_file']}, Size: " . round($fileSize / 1024 / 1024, 2) . " MB");
						
						// Загрузка файла на сервер
                        $this->uploadVideo(
                            $recording['output_file'], 
                            $sessionId, 
                            $recording['camera_id']
                        );
						
                        $this->completedRecordings[$sessionId]['status'] = 'uploaded';
                        $this->log("Upload successful: {$sessionId}");
                    } catch (Exception $e) {
                        $this->log("Upload failed for {$sessionId}: " . $e->getMessage(), "ERROR");
                    }
                } else {
                    $this->log("File missing for {$sessionId}", "ERROR");
                    $this->completedRecordings[$sessionId]['status'] = 'failed';
                }
            }
            
            // Очистка старых записей (старше 1 часа)
            if (time() - $recording['start_time'] > 3600) {
                $this->log("Removing old recording: {$sessionId}");
                unset($this->completedRecordings[$sessionId]);
            }
        }
    }

	// Получение команд от центрального сервера
    private function fetchCommands() {
        $url = $this->config['central_server'] . '/check-commands';
        $data = ['device_id' => $this->config['device_id']];
        
        try {
            $response = $this->httpRequest($url, 'POST', $data);
            return $response['commands'] ?? [];
        } catch (Exception $e) {
            $this->log("Fetch commands error: " . $e->getMessage(), "ERROR");
            return [];
        }
    }

	// Обработка полученных команд
    private function processCommands(array $commands) {
        $this->log("Processing " . count($commands) . " commands");
        
        foreach ($commands as $command) {
            try {
                $cameraId = $command['camera_id'] ?? 'default';
                $sessionId = $command['session_id'] ?? uniqid('rec_');
                $action = $command['action'] ?? 'unknown';
                
                $this->log("Command: $action for camera:$cameraId, session:$sessionId");

				// Обработка различных типов команд
                switch ($action) {
                    case 'start_recording':
                        $this->startRecording($cameraId, $sessionId, $command);
                        break;
                        
                    case 'stop_recording':
                        $this->stopRecording($sessionId);
                        break;
                        
                    default:
                        $this->log("Unknown command: $action", "WARNING");
                }
            } catch (Exception $e) {
                $this->log("Command processing failed: " . $e->getMessage(), "ERROR");
            }
        }
    }

	// Запуск записи видео
    private function startRecording($cameraId, $sessionId, $command) {
        // Проверка существования камеры
        if (!isset($this->config['cameras'][$cameraId])) {
            $this->log("Camera $cameraId not found", "ERROR");
            return;
        }
        
        $cameraUrl = $this->config['cameras'][$cameraId];
        $duration = $command['duration'] ?? 300; // Длительность записи по умолчанию
        $outputFile = "{$this->config['temp_dir']}/{$sessionId}.mp4";

		// Формирование команды ffmpeg
        $cmd = "ffmpeg -rtsp_transport tcp -i '{$cameraUrl}' -t {$duration} -c:v copy {$outputFile}";
        $this->log("Starting recording: $cmd");

		// Запуск процесса в фоновом режиме
        exec("nohup {$cmd} > /dev/null 2>&1 & echo $!", $output);
        $pid = (int)$output[0];

		// Сохранение информации о записи
        $this->activeRecordings[$sessionId] = [
            'pid' => $pid,
            'output_file' => $outputFile,
            'start_time' => time(),
            'camera_id' => $cameraId,
            'duration' => $duration
        ];
        
        $this->log("Recording started. Session: $sessionId, PID: $pid, Camera: $cameraId");
    }

	// Остановка записи видео
    private function stopRecording($sessionId) {
    	$this->log("Stop recording request for session: $sessionId");

		// Проверка существования активной записи
    	if (!isset($this->activeRecordings[$sessionId])) {
            $this->log("Recording session $sessionId not found", "ERROR");
            return;
    	}
    
    	$recording = $this->activeRecordings[$sessionId];
    	$pid = $recording['pid'];
    
		// Проверяем существует ли процесс
    	if (posix_kill($pid, 0)) {
            // Отправка сигнала остановки
            if (posix_kill($pid, SIGTERM)) {
            	unset($this->activeRecordings[$sessionId]);
            	$this->completedRecordings[$sessionId] = $recording;
            	$this->completedRecordings[$sessionId]['status'] = 'stopped';
            	$this->log("Recording stopped: $sessionId (PID: $pid)");
            } else {
           	 $this->log("Failed to send SIGTERM to process: $pid", "ERROR");
            }
    	} else {
			// Процесс уже завершен
            unset($this->activeRecordings[$sessionId]);
            $this->completedRecordings[$sessionId] = $recording;
            $this->completedRecordings[$sessionId]['status'] = 'completed';
            $this->log("Process already terminated: $sessionId (PID: $pid)");
    	}
    }

	// Загрузка видео на центральный сервер
    private function uploadVideo($filePath, $sessionId, $cameraId) {
        $this->log("Starting upload for session: $sessionId, camera: $cameraId");

		// Проверка существования файла
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }
        
        $url = $this->config['central_server'] . '/upload';

		// Подготовка данных для POST-запроса
        $postData = [
            'device_id' => $this->config['device_id'],
            'session_id' => $sessionId,
            'camera_id' => $cameraId,
            'video' => new CURLFile($filePath, 'video/mp4', 'recording.mp4')
        ];

		// Настройка cURL-запроса
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->config['auth_token']}" // Авторизация
            ],
            CURLOPT_TIMEOUT => 300 // Длительный таймаут для загрузки
        ]);

		// Выполнение запроса
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// Обработка ошибок HTTP
        if ($httpCode !== 200) {
            $error = curl_error($ch) ?: $response;
            throw new Exception("HTTP {$httpCode}: {$error}");
        }
        
        $this->log("Upload successful: $sessionId");

		// Удаление временного файла после успешной загрузки
        if (file_exists($filePath) && unlink($filePath)) {
            $this->log("Temporary file deleted: $filePath");
        }
    }

	// Выполнение HTTP-запросов
    private function httpRequest($url, $method = 'GET', $data = []) {
        $this->log("HTTP request: $method $url");
        
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->config['auth_token']}" // Авторизация
            ],
            CURLOPT_TIMEOUT => 10 // Таймаут запроса
        ];

		// Настройка для POST-запросов
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// Обработка HTTP ошибок
        if ($httpCode !== 200) {
            throw new Exception("HTTP {$httpCode}: " . substr($response, 0, 200));
        }
        
        return json_decode($response, true);
    }

	// Логирование событий
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}";
		// Запись в файл лога
        file_put_contents($this->config['log_file'], $logMessage . PHP_EOL, FILE_APPEND);
		// Вывод в консоль
        echo $logMessage . PHP_EOL;
    }
}

// Запуск агента
$agent = new VideoAgent();
$agent->run();
