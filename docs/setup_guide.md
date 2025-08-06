### **Часть 1: Настройка центрального сервера**

#### **1. Установка базового ПО**
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y php-cli php-curl git wireguard composer
```

#### **2. Настройка WireGuard VPN**
```bash
# Генерация ключей
wg genkey | sudo tee /etc/wireguard/privatekey | wg pubkey | sudo tee /etc/wireguard/publickey

# Конфиг сервера
sudo nano /etc/wireguard/wg0.conf
```

**Содержимое `/etc/wireguard/wg0.conf`:**
```ini
[Interface]
Address = 10.8.0.1/24
PrivateKey = <СЕРВЕР_ПРИВАТНЫЙ_КЛЮЧ>
ListenPort = 51820
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE

[Peer]
PublicKey = <ПУБЛИЧНЫЙ_КЛЮЧ_АГЕНТА>
AllowedIPs = 10.8.0.2/32
```

**Запустите VPN:**
```bash
sudo systemctl enable wg-quick@wg0
sudo systemctl start wg-quick@wg0
```

#### **3. Создание проекта**
```bash
sudo mkdir /opt/video-central
sudo chown $USER:$USER /opt/video-central
cd /opt/video-central
```

#### **4. Код центрального сервера (`/opt/video-central/central_server.php`)**

#### **5. Установка зависимостей**
```bash
composer require symfony/http-foundation
```

#### **6. Systemd сервис (`/etc/systemd/system/video-central.service`)**

#### **7. Запуск и проверка**
```bash
sudo systemctl daemon-reload
sudo systemctl enable video-central
sudo systemctl start video-central

# Проверка статуса
sudo systemctl status video-central
```

### **Часть 2: Настройка устройства-агента**

#### **1. Установка ПО**
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y php-cli ffmpeg git wireguard php-curl
```

#### **2. Настройка WireGuard**
```bash
# Генерация ключей
wg genkey | sudo tee /etc/wireguard/privatekey | wg pubkey | sudo tee /etc/wireguard/publickey

# Конфиг клиента
sudo nano /etc/wireguard/wg0.conf
```

**Содержимое `/etc/wireguard/wg0.conf`:**
```ini
[Interface]
PrivateKey = <АГЕНТ_ПРИВАТНЫЙ_КЛЮЧ>
Address = 10.8.0.2/24

[Peer]
PublicKey = <СЕРВЕР_ПУБЛИЧНЫЙ_КЛЮЧ>
Endpoint = <IP_СЕРВЕРА>:51820
AllowedIPs = 10.8.0.0/24
PersistentKeepalive = 25
```

**Запустите VPN:**
```bash
sudo systemctl enable wg-quick@wg0
sudo systemctl start wg-quick@wg0
```

#### **3. Создание проекта агента**
```bash
sudo mkdir /opt/video-agent
sudo chown $USER:$USER /opt/video-agent
cd /opt/video-agent
```

#### **4. Код агента (`/opt/video-agent/agent.php`)**

#### **5. Systemd сервис (`/etc/systemd/system/video-agent.service`)**

#### **6. Создание пользователя и запуск**
```bash
sudo useradd -r -s /usr/sbin/nologin agent-user
sudo chown -R agent-user:agent-user /opt/video-agent

sudo systemctl daemon-reload
sudo systemctl enable video-agent
sudo systemctl start video-agent
```
