<?php
class VideoAgent {
    private $config;			// Конфигурация агента
    private $activeRecordings = [];	// Активные записи
    private $completedRecordings = [];	// Завершенные записи (ожидают загрузки)

    public function __construct() {
        $this->loadConfig(); // Инициализация конфигурации
    }

    private function loadConfig() {
        $this->config = [
            'device_id' => 'AGENT-' . substr(md5(gethostname()), 0, 8),
            'central_server' => 'http://10.8.0.1:8000',
            'auth_token' => 'SECRET_TOKEN_123',
            'cameras' => [
                'cam1' => 'rtsp://admin:password@192.168.1.198/stream1',
                'cam2' => 'rtsp://admin:password@192.168.1.100/stream1',
                'cam3' => 'rtsp://admin:password@192.168.1.101/stream1'
            ],
            'temp_dir' => __DIR__.'/tmp',
            'log_file' => __DIR__.'/agent.log',
            'check_interval' => 5,
            'version' => '1.1.0'
        ];

        foreach ([$this->config['temp_dir'], dirname($this->config['log_file'])] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
                $this->log("Created directory: $dir");
            }
        }
    }

    public function run() {
        $this->log("Starting agent: {$this->config['device_id']}");
        $this->log("Agent version: {$this->config['version']}");
        $this->log("Central server: {$this->config['central_server']}");
        $this->log("Cameras: " . implode(', ', array_keys($this->config['cameras'])));

        if ($this->registerDevice()) {
            $this->log("Registered successfully");
            $this->mainLoop();
        } else {
            $this->log("Registration failed. Exiting.", "ERROR");
            exit(1);
        }
    }

    private function registerDevice() {
        $url = $this->config['central_server'] . '/register';
        $data = [
            'device_id' => $this->config['device_id'],
            'version' => $this->config['version'],
            'capabilities' => ['video_recording', 'camera_control'],
            'cameras' => array_keys($this->config['cameras']) // Передаем список камер
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

    private function mainLoop() {
        $this->log("Entering main loop");
        
        while (true) {
            try {
                $this->log("Active recordings: " . count($this->activeRecordings));
                $this->log("Completed recordings: " . count($this->completedRecordings));
                
                $this->checkActiveRecordings();
                $this->processCompletedRecordings();

                $this->log("Fetching commands...");
                $commands = $this->fetchCommands();
                
                if (!empty($commands)) {
                    $this->log("Received " . count($commands) . " commands");
                    $this->processCommands($commands);
                }

                sleep($this->config['check_interval']);

            } catch (Exception $e) {
                $this->log("Main loop error: " . $e->getMessage(), "ERROR");
                sleep(10);
            }
        }
    }

    private function checkActiveRecordings() {
        foreach ($this->activeRecordings as $sessionId => $recording) {
            $processRunning = posix_kill($recording['pid'], 0);

            if (!$processRunning) {
                $this->log("Recording finished: {$sessionId}");
                $this->completedRecordings[$sessionId] = $recording;
                unset($this->activeRecordings[$sessionId]);
                $this->completedRecordings[$sessionId]['status'] = 'completed';
            }
        }
    }

    private function processCompletedRecordings() {
        $this->log("Processing completed recordings...");
        
        foreach ($this->completedRecordings as $sessionId => $recording) {
            $this->log("Processing recording: $sessionId, status: {$recording['status']}");
            
            if (in_array($recording['status'], ['completed', 'stopped'])) {
                if (file_exists($recording['output_file'])) {
                    try {
                        $fileSize = filesize($recording['output_file']);
                        $this->log("Uploading file: {$recording['output_file']}, Size: " . round($fileSize / 1024 / 1024, 2) . " MB");
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
            
            // Очистка старых записей
            if (time() - $recording['start_time'] > 3600) {
                $this->log("Removing old recording: {$sessionId}");
                unset($this->completedRecordings[$sessionId]);
            }
        }
    }

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

    private function processCommands(array $commands) {
        $this->log("Processing " . count($commands) . " commands");
        
        foreach ($commands as $command) {
            try {
                $cameraId = $command['camera_id'] ?? 'default';
                $sessionId = $command['session_id'] ?? uniqid('rec_');
                $action = $command['action'] ?? 'unknown';
                
                $this->log("Command: $action for camera:$cameraId, session:$sessionId");
                
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

    private function startRecording($cameraId, $sessionId, $command) {
        // Проверка существования камеры
        if (!isset($this->config['cameras'][$cameraId])) {
            $this->log("Camera $cameraId not found", "ERROR");
            return;
        }
        
        $cameraUrl = $this->config['cameras'][$cameraId];
        $duration = $command['duration'] ?? 300;
        $outputFile = "{$this->config['temp_dir']}/{$sessionId}.mp4";
        
        $cmd = "ffmpeg -rtsp_transport tcp -i '{$cameraUrl}' -t {$duration} -c:v copy {$outputFile}";
        $this->log("Starting recording: $cmd");
        
        exec("nohup {$cmd} > /dev/null 2>&1 & echo $!", $output);
        $pid = (int)$output[0];
        
        $this->activeRecordings[$sessionId] = [
            'pid' => $pid,
            'output_file' => $outputFile,
            'start_time' => time(),
            'camera_id' => $cameraId,
            'duration' => $duration
        ];
        
        $this->log("Recording started. Session: $sessionId, PID: $pid, Camera: $cameraId");
    }

    private function stopRecording($sessionId) {
    	$this->log("Stop recording request for session: $sessionId");

    	if (!isset($this->activeRecordings[$sessionId])) {
            $this->log("Recording session $sessionId not found", "ERROR");
            return;
    	}
    
    	$recording = $this->activeRecordings[$sessionId];
    	$pid = $recording['pid'];
    
   	 // Проверяем существует ли процесс
    	if (posix_kill($pid, 0)) {
            // Процесс существует - останавливаем
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

    private function uploadVideo($filePath, $sessionId, $cameraId) {
        $this->log("Starting upload for session: $sessionId, camera: $cameraId");
        
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }
        
        $url = $this->config['central_server'] . '/upload';
        
        $postData = [
            'device_id' => $this->config['device_id'],
            'session_id' => $sessionId,
            'camera_id' => $cameraId,
            'video' => new CURLFile($filePath, 'video/mp4', 'recording.mp4')
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->config['auth_token']}"
            ],
            CURLOPT_TIMEOUT => 300
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            $error = curl_error($ch) ?: $response;
            throw new Exception("HTTP {$httpCode}: {$error}");
        }
        
        $this->log("Upload successful: $sessionId");
        
        if (file_exists($filePath) && unlink($filePath)) {
            $this->log("Temporary file deleted: $filePath");
        }
    }

    private function httpRequest($url, $method = 'GET', $data = []) {
        $this->log("HTTP request: $method $url");
        
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->config['auth_token']}"
            ],
            CURLOPT_TIMEOUT => 10
        ];
        
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP {$httpCode}: " . substr($response, 0, 200));
        }
        
        return json_decode($response, true);
    }

    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}";
        file_put_contents($this->config['log_file'], $logMessage . PHP_EOL, FILE_APPEND);
        echo $logMessage . PHP_EOL;
    }
}

// Запуск агента
$agent = new VideoAgent();
$agent->run();
