### Тестовый план для проверки работоспособности

#### 1. Подготовка окружения
```bash
# На центральном сервере
cd /opt/video-central
sudo systemctl restart video-central
tail -f server.log

# На устройстве-агенте
cd /opt/video-agent
sudo systemctl restart video-agent
tail -f agent.log
```

#### 2. Проверка регистрации агента
```bash
# На сервере (проверить статус)
curl http://localhost:8000/status

# В логах сервера должно появиться:
# [INFO] Device registered: AGENT-XXXXXX with 3 cameras
```

#### 3. Проверка получения команд агентом
```bash
# На сервере отправить тестовую команду
curl -X POST http://localhost:8000/command \
  -H "Authorization: Bearer SECRET_TOKEN_123" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "AGENT-XXXXXX",
    "camera_id": "cam1",
    "action": "test_command",
    "session_id": "test_001"
  }'

# В логах агента должно появиться:
# [INFO] Received 1 commands
# [WARNING] Unknown command: test_command
```

#### 4. Тестирование записи видео (одиночная камера)
```bash
# Запуск записи на 10 секунд
curl -X POST http://localhost:8000/command \
  -H "Authorization: Bearer SECRET_TOKEN_123" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "AGENT-XXXXXX",
    "camera_id": "cam1",
    "action": "start_recording",
    "duration": 10,
    "session_id": "single_cam_test"
  }'

# В логах агента должно появиться:
# [INFO] Starting recording: ffmpeg ... cam1
# [INFO] Recording started. Session: single_cam_test, PID: XXXX
# Через 10-15 секунд:
# [INFO] Recording finished: single_cam_test
# [INFO] Uploading file: ... Size: XX MB
# [INFO] Upload successful: single_cam_test

# Проверить наличие файла на сервере
find /opt/video-central/storage -name "*single_cam_test*"
```

#### 5. Тестирование остановки записи
```bash
# Запуск длительной записи
curl -X POST http://localhost:8000/command \
  -H "Authorization: Bearer SECRET_TOKEN_123" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "AGENT-XXXXXX",
    "camera_id": "cam2",
    "action": "start_recording",
    "duration": 600,
    "session_id": "stop_test"
  }'

# Подождать 5 секунд, затем остановить
curl -X POST http://localhost:8000/command \
  -H "Authorization: Bearer SECRET_TOKEN_123" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "AGENT-XXXXXX",
    "action": "stop_recording",
    "session_id": "stop_test"
  }'

# В логах агента должно появиться:
# [INFO] Stop recording request for session: stop_test
# [INFO] Recording stopped: stop_test
# [INFO] Uploading file: ... 
# [INFO] Upload successful: stop_test
```

#### 6. Тестирование параллельной записи с нескольких камер
```bash
# Запустить запись на 3 камерах одновременно
for camera in cam1 cam2 cam3; do
  curl -X POST http://localhost:8000/command \
    -H "Authorization: Bearer SECRET_TOKEN_123" \
    -H "Content-Type: application/json" \
    -d "{
      \"device_id\": \"AGENT-XXXXXX\",
      \"camera_id\": \"$camera\",
      \"action\": \"start_recording\",
      \"duration\": 15,
      \"session_id\": \"multi_cam_$camera\"
    }"
done

# В логах агента должно появиться:
# [INFO] Received 3 commands
# [INFO] Starting recording for camera:cam1...
# [INFO] Starting recording for camera:cam2...
# [INFO] Starting recording for camera:cam3...
# Через 15-20 секунд:
# [INFO] 3 uploads completed

# Проверить файлы на сервере
find /opt/video-central/storage -name "*multi_cam_*" | wc -l
# Должно быть 3 файла
```

#### 7. Тестирование обработки ошибок
```bash
# 1. Неверный токен авторизации
curl -X POST http://localhost:8000/command \
  -H "Authorization: Bearer WRONG_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"device_id": "AGENT-XXXXXX", "action": "start_recording"}'

# В логах сервера:
# [WARNING] Unauthorized command attempt

# 2. Несуществующее устройство
curl -X POST http://localhost:8000/command \
  -H "Authorization: Bearer SECRET_TOKEN_123" \
  -H "Content-Type: application/json" \
  -d '{"device_id": "UNKNOWN_DEVICE", "action": "start_recording"}'

# В логах сервера:
# [ERROR] Command failed: Device UNKNOWN_DEVICE not found

# 3. Несуществующая камера
curl -X POST http://localhost:8000/command \
  -H "Authorization: Bearer SECRET_TOKEN_123" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "AGENT-XXXXXX",
    "camera_id": "non_existent_cam",
    "action": "start_recording"
  }'

# В логах агента:
# [ERROR] Camera non_existent_cam not found
```

#### 8. Проверка системы команд
```bash
# Проверить очередь команд
curl -X POST http://localhost:8000/check-commands \
  -H "Content-Type: application/json" \
  -d '{"device_id": "AGENT-XXXXXX"}'

# Должен вернуть пустой массив, если команд нет

# Отправить несколько команд
for i in {1..3}; do
  curl -X POST http://localhost:8000/command \
    -H "Authorization: Bearer SECRET_TOKEN_123" \
    -H "Content-Type: application/json" \
    -d "{\"device_id\": \"AGENT-XXXXXX\", \"action\": \"test_$i\"}"
done

# Проверить очередь команд
curl -X POST http://localhost:8000/check-commands \
  -H "Content-Type: application/json" \
  -d '{"device_id": "AGENT-XXXXXX"}'

# Должен вернуть 3 команды
```

#### 9. Проверка загрузки больших файлов
```bash
# Запустить длительную запись (1-2 минуты)
curl -X POST http://localhost:8000/command \
  -H "Authorization: Bearer SECRET_TOKEN_123" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "AGENT-XXXXXX",
    "camera_id": "cam1",
    "action": "start_recording",
    "duration": 120,
    "session_id": "large_file_test"
  }'

# В логах:
# - Агент: должен показать процесс загрузки большого файла
# - Сервер: должен подтвердить успешную загрузку

# Проверить размер файла
find /opt/video-central/storage -name "*large_file_test*" -exec du -h {} \;
```

