<?php
// Устанавливаем временную зону сервера в самом начале
date_default_timezone_set('Europe/Moscow');

// Запускаем сессию только если она еще не запущена1
if (session_status() == PHP_SESSION_NONE) {
	session_start();
}

// Конфигурация базы данных
define('DB_FILE', '/var/www/html/db/database.db');

// Функция для корректного отображения времени из базы данных
function formatDbDateTime($dbTimestamp) {
	if (!$dbTimestamp) return 'N/A';
	
	// Создаем объект DateTime с указанием что время из базы в UTC
	$utcTime = new DateTime($dbTimestamp, new DateTimeZone('UTC'));
	// Конвертируем в локальную временную зону
	$localTime = $utcTime->setTimezone(new DateTimeZone('Europe/Moscow'));
	return $localTime->format('d.m.Y H:i');
}

// Функция для получения текущего времени в формате базы данных
function getCurrentDbTime() {
	return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

// Функция для инициализации базы данных с таймаутами
function initDatabase() {
	$db = new SQLite3(DB_FILE);
	
	// Устанавливаем таймауты для избежания блокировок
	$db->busyTimeout(30000); // 30 секунд - увеличиваем таймаут
	$db->exec('PRAGMA journal_mode=WAL;'); // Write-Ahead Logging для лучшей производительности
	$db->exec('PRAGMA synchronous=NORMAL;');
	$db->exec('PRAGMA cache_size=-64000;');
	$db->exec('PRAGMA foreign_keys=ON;');
	
	$db->exec('CREATE TABLE IF NOT EXISTS devices (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		ip TEXT NOT NULL,
		port INTEGER DEFAULT 22,
		username TEXT NOT NULL,
		password TEXT NOT NULL,
		model TEXT,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP
	)');
	
	$db->exec('CREATE TABLE IF NOT EXISTS backups (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		device_id INTEGER,
		type TEXT NOT NULL,
		filename TEXT NOT NULL,
		ros_version TEXT,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
	)');
	
	$db->exec('CREATE TABLE IF NOT EXISTS users (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		username TEXT UNIQUE NOT NULL,
		password TEXT NOT NULL,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP
	)');
	
	$db->exec('CREATE TABLE IF NOT EXISTS activity_logs (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		user_id INTEGER,
		action_type TEXT NOT NULL,
		description TEXT NOT NULL,
		device_name TEXT,
		backup_filename TEXT,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP
	)');
	
	$db->exec('CREATE TABLE IF NOT EXISTS settings (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		setting_key TEXT UNIQUE NOT NULL,
		setting_value TEXT NOT NULL,
		updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
	)');
	
	// Добавляем пользователя по умолчанию только если таблица пользователей пустая
	$userCount = $db->querySingle('SELECT COUNT(*) FROM users');
	if ($userCount == 0) {
		$stmt = $db->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
		$stmt->bindValue(1, 'admin', SQLITE3_TEXT);
		$stmt->bindValue(2, password_hash('admin', PASSWORD_DEFAULT), SQLITE3_TEXT);
		$stmt->execute();
	}
	
	// Добавляем настройки по умолчанию
	$defaultSettings = [
		'backup_schedule_time' => '02:00'
	];
	
	foreach ($defaultSettings as $key => $value) {
		$stmt = $db->prepare('INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
		$stmt->bindValue(1, $key, SQLITE3_TEXT);
		$stmt->bindValue(2, $value, SQLITE3_TEXT);
		$stmt->execute();
	}
	
	return $db;
}

// Функция для получения настройки
function getSetting($db, $key, $default = '') {
	$stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
	$stmt->bindValue(1, $key, SQLITE3_TEXT);
	$result = $stmt->execute();
	$row = $result->fetchArray(SQLITE3_ASSOC);
	
	return $row ? $row['setting_value'] : $default;
}

// Функция для сохранения настройки
function setSetting($db, $key, $value) {
	$stmt = $db->prepare('INSERT OR REPLACE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
	$stmt->bindValue(1, $key, SQLITE3_TEXT);
	$stmt->bindValue(2, $value, SQLITE3_TEXT);
	return $stmt->execute();
}

// Функция для проверки, был ли уже выполнен бэкап сегодня
function wasBackupDoneToday($db) {
	$today = date('Y-m-d');
	$stmt = $db->prepare('SELECT COUNT(*) FROM activity_logs WHERE action_type = ? AND DATE(created_at) = ?');
	$stmt->bindValue(1, 'scheduled_backup', SQLITE3_TEXT);
	$stmt->bindValue(2, $today, SQLITE3_TEXT);
	$result = $stmt->execute();
	$count = $result->fetchArray(SQLITE3_NUM)[0];
	
	return $count > 0;
}

// Функция для получения времени последнего бэкапа
function getLastBackupTime($db) {
	$stmt = $db->prepare('SELECT created_at FROM activity_logs WHERE action_type = ? ORDER BY created_at DESC LIMIT 1');
	$stmt->bindValue(1, 'scheduled_backup', SQLITE3_TEXT);
	$result = $stmt->execute();
	$row = $result->fetchArray(SQLITE3_ASSOC);
	
	return $row ? $row['created_at'] : null;
}

function isAuthenticated() {
	return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function redirectToLogin() {
	header('Location: auth.php');
	exit;
}

// Функция для логирования действий
function logActivity($db, $actionType, $description, $deviceName = null, $backupFilename = null) {
	$userId = $_SESSION['username'] ?? 'system';
	
	$stmt = $db->prepare('INSERT INTO activity_logs (user_id, action_type, description, device_name, backup_filename) VALUES (?, ?, ?, ?, ?)');
	$stmt->bindValue(1, $userId, SQLITE3_TEXT);
	$stmt->bindValue(2, $actionType, SQLITE3_TEXT);
	$stmt->bindValue(3, $description, SQLITE3_TEXT);
	$stmt->bindValue(4, $deviceName, SQLITE3_TEXT);
	$stmt->bindValue(5, $backupFilename, SQLITE3_TEXT);
	$stmt->execute();
	
	return true;
}

// Функция для получения последних действий
function getRecentActivities($db, $limit = 10) {
	$stmt = $db->prepare('SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT ?');
	$stmt->bindValue(1, $limit, SQLITE3_INTEGER);
	return $stmt->execute();
}

// Функция для SSH подключения
function sshConnect($device) {
	if (!function_exists('ssh2_connect')) {
		return ['success' => false, 'error' => 'SSH2 расширение не установлено'];
	}
	
	$connection = @ssh2_connect($device['ip'], $device['port']);
	if (!$connection) {
		return ['success' => false, 'error' => 'Не удалось подключиться к устройству'];
	}
	
	if (!@ssh2_auth_password($connection, $device['username'], $device['password'])) {
		return ['success' => false, 'error' => 'Неверные учетные данные SSH'];
	}
	
	return ['success' => true, 'connection' => $connection];
}

// Функция для выполнения команды через SSH
function sshExec($connection, $command) {
	$stream = ssh2_exec($connection, $command);
	if (!$stream) {
		return ['success' => false, 'error' => 'Не удалось выполнить команду'];
	}
	
	stream_set_blocking($stream, true);
	$output = stream_get_contents($stream);
	fclose($stream);
	
	return ['success' => true, 'output' => $output];
}

// Функция для получения информации об устройстве Mikrotik
function getMikrotikDeviceInfo($device) {
	if (!function_exists('ssh2_connect')) {
		return ['success' => false, 'error' => 'SSH2 расширение не установлено'];
	}
	
	$connection = @ssh2_connect($device['ip'], $device['port']);
	if (!$connection) {
		return ['success' => false, 'error' => 'Не удалось подключиться к устройству'];
	}
	
	if (!@ssh2_auth_password($connection, $device['username'], $device['password'])) {
		return ['success' => false, 'error' => 'Неверные учетные данные SSH'];
	}
	
	// Получаем модель устройства
	$modelResult = sshExec($connection, '/system resource print');
	$model = 'Unknown';
	if ($modelResult['success']) {
		if (preg_match('/board-name:\s*(.+)/i', $modelResult['output'], $matches)) {
			$model = trim($matches[1]);
		}
	}
	
	// Получаем версию ROS
	$versionResult = sshExec($connection, '/system resource print');
	$version = 'Unknown';
	if ($versionResult['success']) {
		if (preg_match('/version:\s*(.+)/i', $versionResult['output'], $matches)) {
			$version = trim($matches[1]);
		}
	}
	
	ssh2_disconnect($connection);
	
	return [
		'success' => true,
		'model' => $model,
		'version' => $version
	];
}

// Функция для получения только версии ROS
function getMikrotikVersion($device) {
	if (!function_exists('ssh2_connect')) {
		return ['success' => false, 'error' => 'SSH2 расширение не установлено'];
	}
	
	$connection = @ssh2_connect($device['ip'], $device['port']);
	if (!$connection) {
		return ['success' => false, 'error' => 'Не удалось подключиться к устройству'];
	}
	
	if (!@ssh2_auth_password($connection, $device['username'], $device['password'])) {
		return ['success' => false, 'error' => 'Неверные учетные данные SSH'];
	}
	
	// Получаем версию ROS
	$versionResult = sshExec($connection, '/system resource print');
	$version = 'Unknown';
	if ($versionResult['success']) {
		if (preg_match('/version:\s*(.+)/i', $versionResult['output'], $matches)) {
			$version = trim($matches[1]);
		}
	}
	
	ssh2_disconnect($connection);
	
	return [
		'success' => true,
		'version' => $version
	];
}

// Функция для создания бэкапа через SSH
function createMikrotikBackup($device, $type) {
	if (!function_exists('ssh2_connect')) {
		return ['success' => false, 'error' => 'Ошибка: расширение php-ssh2 не установлено'];
	}
	
	$ip = $device['ip'];
	$port = $device['port'];
	$login = $device['username'];
	$password = $device['password'];
	
	// Получаем версию ROS перед созданием бэкапа
	$versionInfo = getMikrotikVersion($device);
	$rosVersion = $versionInfo['success'] ? $versionInfo['version'] : 'Unknown';
	
	// Определяем файлы для бэкапа
	$remote_file = $type === 'full' ? 'webfig-backup.backup' : 'webfig-export.rsc';
	$backup_dir = $type === 'full' ? '/var/www/html/backup/bkp/' : '/var/www/html/backup/rsc/';
	$timestamp = date('Y-m-d_His');
	$local_file = $backup_dir . $device['name'] . '_' . $timestamp . 
				 ($type === 'full' ? '.backup' : '.rsc');
	
	// Создаем директорию если не существует
	if (!is_dir($backup_dir)) {
		mkdir($backup_dir, 0777, true);
	}
	
	// Подключаемся
	$connection = @ssh2_connect($ip, $port);
	if (!$connection) {
		return ['success' => false, 'error' => "Не удалось подключиться к $ip:$port"];
	}
	
	if (!@ssh2_auth_password($connection, $login, $password)) {
		return ['success' => false, 'error' => "Неверный логин или пароль"];
	}
	
	// Создаём файл на роутере
	if ($type === 'full') {
		$result = sshExec($connection, '/system backup save name='.$remote_file);
		if (!$result['success']) {
			ssh2_disconnect($connection);
			return ['success' => false, 'error' => 'Ошибка создания бэкапа на устройстве'];
		}
	} else {
		$result = sshExec($connection, '/export compact file='.$remote_file);
		if (!$result['success']) {
			ssh2_disconnect($connection);
			return ['success' => false, 'error' => 'Ошибка экспорта конфигурации на устройстве'];
		}
	}
	
	// Ждём появления файла (максимум 20 сек)
	$wait = 0;
	$fileExists = false;
	while ($wait < 20) {
		$result = sshExec($connection, '/file print count-only where name="'.$remote_file.'"');
		if ($result['success'] && trim($result['output']) === '1') {
			$fileExists = true;
			break;
		}
		sleep(1);
		$wait++;
	}
	
	if (!$fileExists) {
		ssh2_disconnect($connection);
		return ['success' => false, 'error' => "Таймаут: файл $remote_file не появился на роутере"];
	}
	
	// Скачиваем файл
	if (!@ssh2_scp_recv($connection, $remote_file, $local_file)) {
		ssh2_disconnect($connection);
		return ['success' => false, 'error' => "Ошибка скачивания файла с устройства"];
	}
	
	// Удаляем с роутера
	sshExec($connection, '/file remove '.$remote_file);
	ssh2_disconnect($connection);
	
	return [
		'success' => true, 
		'filename' => basename($local_file),
		'filepath' => $local_file,
		'ros_version' => $rosVersion,
		'message' => "Бэкап успешно создан: " . basename($local_file)
	];
}

// Функция для массового создания бэкапов (улучшенная версия)
function createMassBackup($db) {
	$devices = $db->query('SELECT * FROM devices');
	$results = [];
	$successCount = 0;
	$errorCount = 0;
	$processedDevices = [];
	
	while ($device = $devices->fetchArray(SQLITE3_ASSOC)) {
		$processedDevices[] = $device['name'];
		
		// Создаем полный бэкап
		$fullResult = createMikrotikBackup($device, 'full');
		if ($fullResult['success']) {
			$stmt = $db->prepare('INSERT INTO backups (device_id, type, filename, ros_version) VALUES (?, ?, ?, ?)');
			$stmt->bindValue(1, $device['id'], SQLITE3_INTEGER);
			$stmt->bindValue(2, 'full', SQLITE3_TEXT);
			$stmt->bindValue(3, $fullResult['filename'], SQLITE3_TEXT);
			$stmt->bindValue(4, $fullResult['ros_version'], SQLITE3_TEXT);
			$stmt->execute();
			$successCount++;
		} else {
			$errorCount++;
		}
		
		// Небольшая пауза между бэкапами
		sleep(1);
		
		// Создаем экспорт конфигурации
		$configResult = createMikrotikBackup($device, 'config');
		if ($configResult['success']) {
			$stmt = $db->prepare('INSERT INTO backups (device_id, type, filename, ros_version) VALUES (?, ?, ?, ?)');
			$stmt->bindValue(1, $device['id'], SQLITE3_INTEGER);
			$stmt->bindValue(2, 'config', SQLITE3_TEXT);
			$stmt->bindValue(3, $configResult['filename'], SQLITE3_TEXT);
			$stmt->bindValue(4, $configResult['ros_version'], SQLITE3_TEXT);
			$stmt->execute();
			$successCount++;
		} else {
			$errorCount++;
		}
		
		// Пауза между устройствами
		sleep(2);
	}
	
	return [
		'success' => ($successCount > 0),
		'message' => "Массовое резервное копирование завершено. Успешно: $successCount, Ошибок: $errorCount",
		'success_count' => $successCount,
		'error_count' => $errorCount,
		'processed_devices' => $processedDevices
	];
}

// Функция для логирования скачивания бэкапа
function logBackupDownload($db, $backupId, $username) {
	// Получаем информацию о бэкапе
	$stmt = $db->prepare('SELECT b.*, d.name as device_name FROM backups b LEFT JOIN devices d ON b.device_id = d.id WHERE b.id = ?');
	$stmt->bindValue(1, $backupId, SQLITE3_INTEGER);
	$backup = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
	
	if ($backup) {
		$description = "Скачан бэкап: {$backup['filename']}";
		logActivity($db, 'backup_download', $description, $backup['device_name'], $backup['filename']);
	}
}

// Функция для получения данных устройства по ID
function getDeviceById($db, $deviceId) {
	$stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
	$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
	$result = $stmt->execute();
	$device = $result->fetchArray(SQLITE3_ASSOC);
	
	if ($device) {
		// Не возвращаем пароль в целях безопасности
		unset($device['password']);
		return $device;
	}
	
	return null;
}

// Функция для проверки, был ли бэкап за последние 24 часа (для конкретного устройства)
function hasRecentBackup($db, $deviceId) {
	// Ищем любой успешный бэкап за последние 24 часа
	$stmt = $db->prepare('
		SELECT COUNT(*) as has_backup 
		FROM backups 
		WHERE device_id = ? 
		AND filename IS NOT NULL
		AND created_at > datetime("now", "-24 hours")
	');
	$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
	$result = $stmt->execute();
	$row = $result->fetchArray(SQLITE3_ASSOC);
	
	return ($row && $row['has_backup'] > 0);
}

// Функция для получения времени последнего бэкапа устройства
function getDeviceLastBackupTime($db, $deviceId) {
	$stmt = $db->prepare('
		SELECT created_at 
		FROM backups 
		WHERE device_id = ? 
		AND filename IS NOT NULL
		ORDER BY created_at DESC 
		LIMIT 1
	');
	$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
	$result = $stmt->execute();
	$row = $result->fetchArray(SQLITE3_ASSOC);
	
	return $row ? $row['created_at'] : null;
}

?>