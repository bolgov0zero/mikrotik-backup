<?php

date_default_timezone_set('Europe/Moscow');
require_once 'config.php';

// Обработка выхода ДО любого вывода
if (($_GET['action'] ?? '') === 'logout') {
	session_destroy();
	header('Location: auth.php');
	exit;
}

if (!isAuthenticated()) {
	redirectToLogin();
}

$db = initDatabase();
$page = $_GET['page'] ?? 'dashboard';

// Обработка AJAX запроса для получения данных устройства
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_device' && isset($_GET['id'])) {
	$deviceId = intval($_GET['id']);
	$device = getDeviceById($db, $deviceId);

	if ($device) {
		header('Content-Type: application/json');
		echo json_encode(['success' => true, 'device' => $device]);
	} else {
		header('Content-Type: application/json');
		echo json_encode(['success' => false, 'error' => 'Устройство не найдено']);
	}
	exit;
}

// AJAX: действия обновления RouterOS/RouterBoard
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_action') {
	header('Content-Type: application/json');

	$deviceId   = intval($_POST['device_id'] ?? 0);
	$actionType = $_POST['action_type'] ?? '';

	// Прямой SELECT (getDeviceById вырезает пароль ради безопасности JSON-ответа)
	$stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
	$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
	$device = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

	if (!$device) {
		echo json_encode(['success' => false, 'error' => 'Устройство не найдено']);
		exit;
	}

	set_time_limit(120);

	switch ($actionType) {
		case 'status':
			$result = getMikrotikSystemStatus($device);
			break;
		case 'check':
			$result = mikrotikCheckForUpdates($device);
			if ($result['success']) {
				logActivity($db, 'device_update_check', 'Проверка обновлений: ' . ($result['latest_version'] ?: 'нет данных'), $device['name']);
			}
			break;
		case 'update':
			$result = mikrotikDownloadUpdate($device);
			if ($result['success']) {
				logActivity($db, 'device_update_download', 'Скачано обновление RouterOS', $device['name']);
			}
			break;
		case 'upgrade_fw':
			$result = mikrotikUpgradeFirmware($device);
			if ($result['success']) {
				logActivity($db, 'device_update_fw', 'Запланирован апгрейд firmware RouterBoard', $device['name']);
			}
			break;
		case 'reboot':
			$result = mikrotikReboot($device);
			if ($result['success']) {
				logActivity($db, 'device_update_reboot', 'Отправлена команда перезагрузки', $device['name']);
			}
			break;
		default:
			$result = ['success' => false, 'error' => 'Неизвестное действие'];
	}

	echo json_encode($result);
	exit;
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	switch ($_POST['action'] ?? '') {
		case 'add_device':
			$port = !empty($_POST['port']) ? intval($_POST['port']) : 22;
			$stmt = $db->prepare('INSERT INTO devices (name, ip, port, username, password) VALUES (?, ?, ?, ?, ?)');
			$stmt->bindValue(1, $_POST['name'], SQLITE3_TEXT);
			$stmt->bindValue(2, $_POST['ip'], SQLITE3_TEXT);
			$stmt->bindValue(3, $port, SQLITE3_INTEGER);
			$stmt->bindValue(4, $_POST['username'], SQLITE3_TEXT);
			$stmt->bindValue(5, $_POST['password'], SQLITE3_TEXT);
			$stmt->execute();
			
			// Получаем ID добавленного устройства
			$deviceId = $db->lastInsertRowID();
			
			// Получаем информацию об устройстве для определения модели
			$deviceInfo = [
				'ip' => $_POST['ip'],
				'port' => $port,
				'username' => $_POST['username'],
				'password' => $_POST['password']
			];
			
			$modelInfo = getMikrotikDeviceInfo($deviceInfo);
			$model = $modelInfo['success'] ? $modelInfo['model'] : 'Unknown';
			
			// Обновляем устройство с информацией о модели
			$stmt = $db->prepare('UPDATE devices SET model = ? WHERE id = ?');
			$stmt->bindValue(1, $model, SQLITE3_TEXT);
			$stmt->bindValue(2, $deviceId, SQLITE3_INTEGER);
			$stmt->execute();
			
			logActivity($db, 'device_add', 'Добавлено новое устройство', $_POST['name']);
			break;
			
		case 'edit_device':
			$deviceId = $_POST['device_id'];
			$port = !empty($_POST['port']) ? intval($_POST['port']) : 22;
			
			// Получаем текущие данные устройства
			$stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
			$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
			$currentDevice = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
			
			if ($currentDevice) {
				// Если пароль не указан, используем старый
				$password = !empty($_POST['password']) ? $_POST['password'] : $currentDevice['password'];
				
				// Обновляем основные данные устройства
				$stmt = $db->prepare('UPDATE devices SET name = ?, ip = ?, port = ?, username = ?, password = ? WHERE id = ?');
				$stmt->bindValue(1, $_POST['name'], SQLITE3_TEXT);
				$stmt->bindValue(2, $_POST['ip'], SQLITE3_TEXT);
				$stmt->bindValue(3, $port, SQLITE3_INTEGER);
				$stmt->bindValue(4, $_POST['username'], SQLITE3_TEXT);
				$stmt->bindValue(5, $password, SQLITE3_TEXT);
				$stmt->bindValue(6, $deviceId, SQLITE3_INTEGER);
				$stmt->execute();
				
				// Получаем обновленную информацию об устройстве для определения модели
				$deviceInfo = [
					'ip' => $_POST['ip'],
					'port' => $port,
					'username' => $_POST['username'],
					'password' => $password
				];
				
				$modelInfo = getMikrotikDeviceInfo($deviceInfo);
				$model = $modelInfo['success'] ? $modelInfo['model'] : 'Unknown';
				
				// Обновляем модель устройства
				$stmt = $db->prepare('UPDATE devices SET model = ? WHERE id = ?');
				$stmt->bindValue(1, $model, SQLITE3_TEXT);
				$stmt->bindValue(2, $deviceId, SQLITE3_INTEGER);
				$stmt->execute();
				
				logActivity($db, 'device_edit', 'Устройство отредактировано', $_POST['name']);
				$_SESSION['settings_success'] = 'Устройство "' . $_POST['name'] . '" успешно обновлено';
			}
			break;
			
		case 'delete_device':
			$deviceId = $_POST['device_id'];
			
			// Получаем информацию об устройстве перед удалением
			$stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
			$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
			$device = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
			
			if ($device) {
				// Получаем информацию об устройстве для удаления связанных файлов бэкапов
				$stmt = $db->prepare('SELECT * FROM backups WHERE device_id = ?');
				$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
				$backups = $stmt->execute();
				
				while ($backup = $backups->fetchArray(SQLITE3_ASSOC)) {
					$backupPath = $backup['type'] === 'full' ? 'backup/bkp/' : 'backup/rsc/';
					$filePath = $backupPath . $backup['filename'];
					if (file_exists($filePath)) {
						unlink($filePath);
					}
				}
				
				// Удаляем устройство и каскадно удаляем бэкапы
				$stmt = $db->prepare('DELETE FROM devices WHERE id = ?');
				$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
				$stmt->execute();
				
				logActivity($db, 'device_delete', 'Устройство удалено', $device['name']);
			}
			break;
			
		case 'delete_backup':
			$backupId = $_POST['backup_id'];
			
			// Получаем информацию о бэкапе для удаления файла
			$stmt = $db->prepare('SELECT b.*, d.name as device_name FROM backups b LEFT JOIN devices d ON b.device_id = d.id WHERE b.id = ?');
			$stmt->bindValue(1, $backupId, SQLITE3_INTEGER);
			$backup = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
			
			if ($backup) {
				$backupPath = $backup['type'] === 'full' ? 'backup/bkp/' : 'backup/rsc/';
				$filePath = $backupPath . $backup['filename'];
				if (file_exists($filePath)) {
					unlink($filePath);
				}
				
				// Удаляем запись из базы
				$stmt = $db->prepare('DELETE FROM backups WHERE id = ?');
				$stmt->bindValue(1, $backupId, SQLITE3_INTEGER);
				$stmt->execute();
				
				logActivity($db, 'backup_delete', 'Бэкап удален', $backup['device_name'], $backup['filename']);
			}
			break;
			
		case 'change_password':
			$stmt = $db->prepare('UPDATE users SET password = ? WHERE username = ?');
			$stmt->bindValue(1, password_hash($_POST['new_password'], PASSWORD_DEFAULT), SQLITE3_TEXT);
			$stmt->bindValue(2, $_SESSION['username'], SQLITE3_TEXT);
			$stmt->execute();
			
			logActivity($db, 'password_change', 'Пароль пользователя изменен');
			break;
			
		case 'add_user':
			$stmt = $db->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
			$stmt->bindValue(1, $_POST['username'], SQLITE3_TEXT);
			$stmt->bindValue(2, password_hash($_POST['password'], PASSWORD_DEFAULT), SQLITE3_TEXT);
			$stmt->execute();
			
			logActivity($db, 'user_add', 'Добавлен новый пользователь: ' . $_POST['username']);
			break;

		case 'delete_user':
			$usernameToDelete = $_POST['username'];
			
			// Не позволяем удалить последнего пользователя
			$userCount = $db->querySingle('SELECT COUNT(*) FROM users');
			if ($userCount <= 1) {
				$_SESSION['settings_error'] = 'Нельзя удалить последнего пользователя';
				break;
			}
			
			$stmt = $db->prepare('DELETE FROM users WHERE username = ?');
			$stmt->bindValue(1, $usernameToDelete, SQLITE3_TEXT);
			$stmt->execute();
			
			logActivity($db, 'user_delete', 'Пользователь удален: ' . $usernameToDelete);
			$_SESSION['settings_success'] = 'Пользователь ' . $usernameToDelete . ' удален';
			break;
			
		case 'create_backup':
			$deviceId = $_POST['device_id'];
			$type = $_POST['backup_type'];
			
			// Получаем информацию об устройстве
			$stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
			$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
			$device = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
			
			if ($device) {
				// Используем вашу рабочую функцию бэкапирования
				$backupResult = createMikrotikBackup($device, $type);
				
				if ($backupResult['success']) {
					// Сохраняем информацию о бэкапе в базу
					$stmt = $db->prepare('INSERT INTO backups (device_id, type, filename, ros_version) VALUES (?, ?, ?, ?)');
					$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
					$stmt->bindValue(2, $type, SQLITE3_TEXT);
					$stmt->bindValue(3, $backupResult['filename'], SQLITE3_TEXT);
					$stmt->bindValue(4, $backupResult['ros_version'], SQLITE3_TEXT);
					$stmt->execute();
					
					$_SESSION['backup_success'] = $backupResult['message'];
					logActivity($db, 'backup_create', 'Создан новый бэкап', $device['name'], $backupResult['filename']);
				} else {
					$_SESSION['backup_error'] = 'Ошибка создания бэкапа: ' . $backupResult['error'];
					logActivity($db, 'backup_error', 'Ошибка создания бэкапа: ' . $backupResult['error'], $device['name']);
				}
			} else {
				$_SESSION['backup_error'] = 'Устройство не найдено';
			}
			break;
			
		case 'mass_backup':
			$massBackupResult = createMassBackup($db);
			$successCount  = $massBackupResult['success_count'];
			$errorCount    = $massBackupResult['error_count'];
			$deviceCount   = count($massBackupResult['processed_devices']);
			$failedDevices = $massBackupResult['failed_devices'] ?? [];

			if ($massBackupResult['success']) {
				$_SESSION['backup_success'] = $massBackupResult['message'];
				logActivity($db, 'mass_backup', 'Массовое резервное копирование завершено. Успешно: ' . $successCount . ', Ошибок: ' . $errorCount);
			} else {
				$_SESSION['backup_error'] = 'Ошибка массового бэкапа: ' . ($massBackupResult['error'] ?? '');
			}

			// Telegram
			$tgMsg  = "<b>Массовый бэкап MikroTik</b>\n";
			$tgMsg .= "<b>Время:</b> <i>" . date('Y-m-d H:i:s') . "</i>\n\n";
			$tgMsg .= "<blockquote><b>Успешно:</b> <i>{$successCount}</i>\n";
			$tgMsg .= "<b>Ошибок:</b> <i>{$errorCount}</i></blockquote>\n";
			if ($errorCount > 0 && !empty($failedDevices)) {
				$tgMsg .= "\n<b>ВНИМАНИЕ!</b> <i>Есть ошибки!</i>\n";
				$tgMsg .= "<blockquote>" . implode(', ', array_map(fn($d) => "<i>{$d}</i>", $failedDevices)) . "</blockquote>";
			}
			sendTelegramNotification($tgMsg);

			// Email
			$emailCfg  = getEmailSettings($db);
			$customTpl = getCustomTemplate($db);
			if ($emailCfg['enabled'] && !empty($emailCfg['host']) && !empty($emailCfg['to_email'])) {
				if ($customTpl['enabled'] && !empty($customTpl['body'])) {
					$emailHtml = applyCustomTemplate($customTpl['body'], $successCount, $errorCount, $deviceCount, $failedDevices, $customTpl['error_block']);
				} else {
					$emailHtml = buildBackupEmailBody($successCount, $errorCount, $failedDevices, $deviceCount);
				}
				smtpSend($emailCfg, $emailCfg['to_email'], $emailCfg['subject'] ?: 'MikroTik Backup Report', $emailHtml);
			}
			break;
			
		case 'update_schedule':
			$backupTime = $_POST['backup_time'];
			setSetting($db, 'backup_schedule_time', $backupTime);
			$_SESSION['settings_success'] = 'Время автоматического бэкапа обновлено: ' . $backupTime;
			logActivity($db, 'schedule_update', 'Обновлено время автоматического бэкапа: ' . $backupTime);
			break;
			
		case 'test_connection':
			$deviceId = $_POST['device_id'];
			$stmt = $db->prepare('SELECT * FROM devices WHERE id = ?');
			$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
			$device = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
			
			if ($device) {
				$ssh = sshConnect($device);
				if ($ssh['success']) {
					$_SESSION['test_success'] = 'Подключение успешно установлено';
					if (isset($ssh['connection'])) {
						ssh2_disconnect($ssh['connection']);
					}
					logActivity($db, 'connection_test', 'Тест подключения успешен', $device['name']);
				} else {
					$_SESSION['test_error'] = 'Ошибка подключения: ' . $ssh['error'];
					logActivity($db, 'connection_error', 'Ошибка подключения: ' . $ssh['error'], $device['name']);
				}
			}
			break;
		
		// Добавить эти case в существующий switch($_POST['action']):
		case 'save_telegram':
			$bot_token = trim($_POST['bot_token'] ?? '');
			$chat_id = trim($_POST['chat_id'] ?? '');
			$enabled = isset($_POST['enabled']) ? 1 : 0;
			
			saveTelegramSettings($db, $bot_token, $chat_id, $enabled);
			
			$_SESSION['settings_success'] = 'Настройки Telegram сохранены';
			logActivity($db, 'telegram_save', 'Настройки Telegram сохранены');
			break;
			
		case 'test_telegram':
			$bot_token = trim($_POST['bot_token'] ?? '');
			$chat_id = trim($_POST['chat_id'] ?? '');

			$result = testTelegramConnection($bot_token, $chat_id);

			$_SESSION['telegram_test_result'] = $result['message'];
			$_SESSION['telegram_test_success'] = $result['success'];
			$_SESSION['telegram_test_icon'] = $result['success'] ? '✅' : '❌';

			logActivity($db, 'telegram_test', 'Проверка подключения Telegram: ' . $result['message']);
			break;

		case 'save_email':
			$emailData = [
				'host'       => trim($_POST['email_host'] ?? ''),
				'port'       => intval($_POST['email_port'] ?? 587),
				'encryption' => $_POST['email_encryption'] ?? 'tls',
				'username'   => trim($_POST['email_username'] ?? ''),
				'password'   => $_POST['email_password'] ?? '',
				'from_email' => trim($_POST['email_from_email'] ?? ''),
				'from_name'  => trim($_POST['email_from_name'] ?? 'MikroTik Backup'),
				'to_email'   => trim($_POST['email_to'] ?? ''),
				'subject'    => trim($_POST['email_subject'] ?? 'MikroTik Backup Report'),
				'enabled'    => isset($_POST['email_enabled']) ? 1 : 0,
			];
			saveEmailSettings($db, $emailData);
			$_SESSION['settings_success'] = 'Настройки Email сохранены';
			logActivity($db, 'email_save', 'Настройки Email сохранены');
			break;

		case 'save_custom_template':
			saveCustomTemplate(
				$db,
				isset($_POST['custom_template_enabled']),
				$_POST['custom_template_body'] ?? '',
				$_POST['custom_template_error_block'] ?? ''
			);
			$_SESSION['settings_success'] = 'Кастомный шаблон сохранён';
			logActivity($db, 'custom_template_save', 'Кастомный шаблон сохранён');
			break;

		case 'test_email':
			$testCfg = [
				'host'       => trim($_POST['email_host'] ?? ''),
				'port'       => intval($_POST['email_port'] ?? 587),
				'encryption' => $_POST['email_encryption'] ?? 'tls',
				'username'   => trim($_POST['email_username'] ?? ''),
				'password'   => $_POST['email_password'] ?? '',
				'from_email' => trim($_POST['email_from_email'] ?? ''),
				'from_name'  => trim($_POST['email_from_name'] ?? 'MikroTik Backup'),
				'to_email'   => trim($_POST['email_to'] ?? ''),
				'subject'    => trim($_POST['email_subject'] ?? 'MikroTik Backup Report'),
				'enabled'    => 1,
			];
			$result = testEmailConnection($testCfg);
			$_SESSION['settings_success'] = $result['success']
				? '✅ Тестовое письмо успешно отправлено на ' . htmlspecialchars($testCfg['to_email'])
				: '❌ Ошибка отправки: ' . ($result['error'] ?? 'неизвестная ошибка');
			if (!$result['success']) {
				$_SESSION['settings_error'] = $_SESSION['settings_success'];
				unset($_SESSION['settings_success']);
			}
			logActivity($db, 'email_test', 'Проверка Email: ' . ($result['success'] ? 'успешно' : $result['error'] ?? 'ошибка'));
			break;
	}
	
	// Перенаправляем чтобы избежать повторной отправки формы
	header('Location: ' . $_SERVER['REQUEST_URI']);
	exit;
}

// Получение данных для статистики
$deviceCount = $db->querySingle('SELECT COUNT(*) FROM devices');
$backupCount = $db->querySingle('SELECT COUNT(*) FROM backups');
$devices = $db->query('SELECT * FROM devices ORDER BY created_at DESC');

// Получаем настройки
$backupScheduleTime = getSetting($db, 'backup_schedule_time', '02:00');

// Для главной страницы - пагинация и фильтры
if ($page === 'dashboard') {
	$filterActivityType = $_GET['activity_type'] ?? 'all';
	$filterActivityDate = $_GET['activity_date'] ?? '';
	$pageNumber = max(1, intval($_GET['p'] ?? 1));
}

// Для страницы бэкапов - фильтрация по устройству и типу
if ($page === 'backups') {
	$filterDeviceId = $_GET['device_id'] ?? 'all';
	$filterType = $_GET['type'] ?? 'all';
	$filterDate = $_GET['date'] ?? '';
	$pageNumber = max(1, intval($_GET['p'] ?? 1));
	$perPage = 10;
	$offset = ($pageNumber - 1) * $perPage;

	// Строим запрос с фильтрами
	$whereConditions = [];
	$params = [];
	$paramTypes = [];

	if ($filterDeviceId !== 'all') {
		$whereConditions[] = 'b.device_id = ?';
		$params[] = $filterDeviceId;
		$paramTypes[] = SQLITE3_INTEGER;
	}

	if ($filterType !== 'all') {
		$whereConditions[] = 'b.type = ?';
		$params[] = $filterType;
		$paramTypes[] = SQLITE3_TEXT;
	}

	if ($filterDate) {
		$whereConditions[] = "DATE(b.created_at) = ?";
		$params[] = $filterDate;
		$paramTypes[] = SQLITE3_TEXT;
	}

	$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

	// Получаем общее количество бэкапов для пагинации
	$countQuery = "SELECT COUNT(*) FROM backups b $whereClause";
	$stmt = $db->prepare($countQuery);
	foreach ($params as $index => $value) {
		$stmt->bindValue($index + 1, $value, $paramTypes[$index]);
	}
	$totalBackups = $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
	$totalPages = ceil($totalBackups / $perPage);

	// Получаем бэкапы с пагинацией
	$query = "SELECT b.*, d.name as device_name FROM backups b LEFT JOIN devices d ON b.device_id = d.id $whereClause ORDER BY b.created_at DESC LIMIT $perPage OFFSET $offset";
	$stmt = $db->prepare($query);
	foreach ($params as $index => $value) {
		$stmt->bindValue($index + 1, $value, $paramTypes[$index]);
	}
	$backups = $stmt->execute();

	// Получаем список устройств для фильтра
	$allDevices = $db->query('SELECT * FROM devices ORDER BY name');
}

// Получаем информацию о текущем пользователе
$currentUser = $_SESSION['username'];
$userInitial = strtoupper(mb_substr($currentUser, 0, 1));
?>

<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>MikroTik Backup System</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="main.css">
	<script>
		async function loadVersion() {
			try {
				const response = await fetch('version.json');
				if (!response.ok) throw new Error('Не удалось загрузить данные версии');
				const data = await response.json();
				document.getElementById('appVersion').textContent = data.version;
			} catch (err) {
				console.error('Ошибка загрузки версии:', err);
				document.getElementById('appVersion').textContent = 'Неизвестно';
			}
		}
		
		// Автоматически загружаем версию при загрузке скрипта
		loadVersion();
	</script>
</head>
<body>
	<div class="container">
		<!-- Боковая панель -->
		<aside class="sidebar">
			<div class="sidebar-content">
				<div class="logo">
					<div class="logo-icon">
						<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
							<path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.3l7.5 3.75L12 11.8 4.5 8.05 12 4.3zM3.5 9.1l8 4v7.6l-8-4V9.1zm9.5 11.6V13.1l8-4v7.6l-8 4z"/>
						</svg>
					</div>
					<div class="logo-text">
						<h1>MikroTik</h1>
						<h2>Backup System</h2>
					</div>
				</div>
				<nav>
					<ul class="nav-menu">
						<li class="nav-item">
							<a href="?page=dashboard" class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>">
								<span class="icon icon-dashboard"></span>
								Главная
							</a>
						</li>
						<li class="nav-item">
							<a href="?page=devices" class="nav-link <?= $page === 'devices' ? 'active' : '' ?>">
								<span class="icon icon-devices"></span>
								Устройства
							</a>
						</li>
						<li class="nav-item">
							<a href="?page=backups" class="nav-link <?= $page === 'backups' ? 'active' : '' ?>">
								<span class="icon icon-backups"></span>
								Бэкапы
							</a>
						</li>
						<li class="nav-item">
							<a href="?page=settings" class="nav-link <?= $page === 'settings' ? 'active' : '' ?>">
								<span class="icon icon-settings"></span>
								Настройки
							</a>
						</li>
						<li class="nav-item">
							<a href="?action=logout" class="nav-link">
								<span class="icon icon-logout"></span>
								Выход
							</a>
						</li>
					</ul>
				</nav>
			</div>
			
			<!-- Блок версии и копирайта -->
			<div class="sidebar-footer">
				<div class="footer-card">
					<div class="version-info">
						Версия: <span class="version-number" id="appVersion">Загрузка...</span>
					</div>
					<div class="copyright">
						2026 © bolgov0zero
					</div>
				</div>
			</div>
		</aside>

		<!-- Основной контент -->
		<main class="main-content">
			<div class="header">
				<h2>
					<?= match($page) {
						'dashboard' => 'Главная',
						'devices' => 'Устройства',
						'backups' => 'Бэкапы',
						'settings' => 'Настройки',
						default => 'Главная'
					} ?>
				</h2>
				<div class="user-info">
					<div class="user-badge">
						<div class="user-avatar"><?= $userInitial ?></div>
						<div class="user-details">
							<div class="username"><?= htmlspecialchars($currentUser) ?></div>
						</div>
					</div>
				</div>
			</div>

			<div id="notifications-container">
				<?php
				// Показываем уведомления с автоматическим скрытием
				if (isset($_SESSION['backup_success'])) {
					echo '<div class="success auto-hide">' . $_SESSION['backup_success'] . '</div>';
					unset($_SESSION['backup_success']);
				}
				if (isset($_SESSION['backup_error'])) {
					echo '<div class="error auto-hide">' . $_SESSION['backup_error'] . '</div>';
					unset($_SESSION['backup_error']);
				}
				if (isset($_SESSION['test_success'])) {
					echo '<div class="success auto-hide">' . $_SESSION['test_success'] . '</div>';
					unset($_SESSION['test_success']);
				}
				if (isset($_SESSION['test_error'])) {
					echo '<div class="error auto-hide">' . $_SESSION['test_error'] . '</div>';
					unset($_SESSION['test_error']);
				}
				if (isset($_SESSION['settings_success'])) {
					echo '<div class="success auto-hide">' . $_SESSION['settings_success'] . '</div>';
					unset($_SESSION['settings_success']);
				}
				if (isset($_SESSION['settings_error'])) {
					echo '<div class="error auto-hide">' . $_SESSION['settings_error'] . '</div>';
					unset($_SESSION['settings_error']);
				}
				if (isset($_SESSION['telegram_test_result'])) {
					$telegramClass = $_SESSION['telegram_test_success'] ? 'success' : 'error';
					$telegramIcon = $_SESSION['telegram_test_icon'] ?? '';
					echo '<div class="' . $telegramClass . ' auto-hide">' . $telegramIcon . ' ' . $_SESSION['telegram_test_result'] . '</div>';
					
					unset($_SESSION['telegram_test_result']);
					unset($_SESSION['telegram_test_success']);
					unset($_SESSION['telegram_test_icon']);
				}
				?>
			</div>

			<?php
			// Подключение страниц
			switch ($page) {
				case 'dashboard':
					include 'pages/dashboard.php';
					break;
				case 'devices':
					include 'pages/devices.php';
					break;
				case 'backups':
					include 'pages/backups.php';
					break;
				case 'settings':
					include 'pages/settings.php';
					break;
				default:
					include 'pages/dashboard.php';
			}
			?>
		</main>
	</div>

	<!-- Модальные окна -->
	<div id="addDeviceModal" class="modal">
		<div class="modal-content">
			<div class="modal-header">
				<h3>Добавить устройство</h3>
				<button class="modal-close" onclick="closeModal('addDeviceModal')">×</button>
			</div>
			<form method="POST">
				<input type="hidden" name="action" value="add_device">
				<div class="form-group">
					<label>Имя устройства</label>
					<input type="text" name="name" class="form-control" placeholder="Введите имя устройства" required>
				</div>
				<div class="form-group">
					<label>IP адрес</label>
					<input type="text" name="ip" class="form-control" placeholder="Введите IP адрес" required>
				</div>
				<div class="form-group">
					<label>Порт SSH</label>
					<input type="number" name="port" class="form-control" value="22" min="1" max="65535">
				</div>
				<div class="form-group">
					<label>Логин SSH</label>
					<input type="text" name="username" class="form-control" placeholder="Введите логин" required>
				</div>
				<div class="form-group">
					<label>Пароль SSH</label>
					<input type="password" name="password" class="form-control" placeholder="Введите пароль" required>
				</div>
				<div class="form-group">
					<button type="submit" class="btn btn-primary">
						<span class="icon icon-add"></span>
						Добавить устройство
					</button>
					<button type="button" class="btn btn-outline" onclick="closeModal('addDeviceModal')">Отмена</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Модальное окно редактирования устройства -->
	<div id="editDeviceModal" class="modal">
		<div class="modal-content">
			<div class="modal-header">
				<h3>Редактировать устройство</h3>
				<button class="modal-close" onclick="closeModal('editDeviceModal')">×</button>
			</div>
			<form method="POST">
				<input type="hidden" name="action" value="edit_device">
				<input type="hidden" name="device_id" id="edit_device_id">
				<div class="form-group">
					<label>Имя устройства</label>
					<input type="text" name="name" id="edit_name" class="form-control" placeholder="Введите имя устройства" required>
				</div>
				<div class="form-group">
					<label>IP адрес</label>
					<input type="text" name="ip" id="edit_ip" class="form-control" placeholder="Введите IP адрес" required>
				</div>
				<div class="form-group">
					<label>Порт SSH</label>
					<input type="number" name="port" id="edit_port" class="form-control" value="22" min="1" max="65535">
				</div>
				<div class="form-group">
					<label>Логин SSH</label>
					<input type="text" name="username" id="edit_username" class="form-control" placeholder="Введите логин" required>
				</div>
				<div class="form-group">
					<label>Пароль SSH</label>
					<input type="password" name="password" id="edit_password" class="form-control" placeholder="Введите пароль (оставьте пустым, если не меняется)">
					<div style="color: var(--text-secondary); font-size: 0.75rem; margin-top: 0.25rem;">
						Оставьте пустым, если не хотите менять пароль
					</div>
				</div>
				<div class="form-group">
					<button type="submit" class="btn btn-primary">
						<span class="icon icon-save"></span>
						Сохранить изменения
					</button>
					<button type="button" class="btn btn-outline" onclick="closeModal('editDeviceModal')">Отмена</button>
				</div>
			</form>
		</div>
	</div>

	<div id="backupModal" class="modal modal-compact">
		<div class="modal-content">
			<div class="modal-header">
				<h3>Создать бэкап</h3>
				<button class="modal-close" onclick="closeModal('backupModal')">×</button>
			</div>
			<form method="POST">
				<input type="hidden" name="action" value="create_backup">
				<input type="hidden" name="device_id" id="backup_device_id">
				
				<div class="form-group">
					<label style="margin-bottom: 0.75rem; display: block;">Тип бэкапа</label>
					<div class="radio-group">
						<label class="radio-item" onclick="selectBackupType('full')">
							<input type="radio" name="backup_type" value="full" class="radio-input" checked>
							<span class="radio-custom"></span>
							<div class="radio-label">
								<span class="radio-title">Полный бэкап</span>
								<span class="radio-description">Бинарный файл backup.backup со всей конфигурацией</span>
							</div>
						</label>
						
						<label class="radio-item" onclick="selectBackupType('config')">
							<input type="radio" name="backup_type" value="config" class="radio-input">
							<span class="radio-custom"></span>
							<div class="radio-label">
								<span class="radio-title">Экспорт конфигурации</span>
								<span class="radio-description">Текстовый файл export.rsc с настройками</span>
							</div>
						</label>
					</div>
				</div>
				
				<div class="form-group" style="margin-top: 1.5rem;">
					<button type="submit" class="btn btn-primary" style="width: 100%;">
						<span class="icon icon-backup"></span>
						Создать бэкап
					</button>
					<button type="button" class="btn btn-outline" onclick="closeModal('backupModal')" style="width: 100%; margin-top: 0.5rem;">
						Отмена
					</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Модальное окно обновления устройства -->
	<div id="updateModal" class="modal">
		<div class="modal-content">
			<div class="modal-header">
				<h3>Обновление устройства <span id="updateModalDeviceName"></span></h3>
				<button class="modal-close" onclick="closeUpdateModal()">×</button>
			</div>

			<div class="update-status-block">
				<div class="update-row">
					<span class="update-label">RouterOS:</span>
					<span class="update-value" id="updRosVersion">—</span>
				</div>
				<div class="update-row">
					<span class="update-label">Модель:</span>
					<span class="update-value" id="updBoard">—</span>
				</div>
				<div class="update-row">
					<span class="update-label">Firmware RouterBoard:</span>
					<span class="update-value" id="updCurrentFw">—</span>
				</div>
				<div class="update-row" id="updUpgradeFwRow" style="display:none;">
					<span class="update-label">Доступный firmware:</span>
					<span class="update-value update-value--warn" id="updUpgradeFw">—</span>
				</div>
				<div class="update-row">
					<span class="update-label">Канал обновлений:</span>
					<span class="update-value" id="updChannel">—</span>
				</div>
				<div class="update-row" id="updLatestRow" style="display:none;">
					<span class="update-label">Доступная версия RouterOS:</span>
					<span class="update-value update-value--warn" id="updLatestVersion">—</span>
				</div>
				<div class="update-row" id="updStatusRow" style="display:none;">
					<span class="update-label">Статус:</span>
					<span class="update-value" id="updStatus">—</span>
				</div>
			</div>

			<div class="update-actions">
				<button type="button" class="btn btn-outline" id="updBtnCheck" onclick="updateCheck()">
					<span class="icon icon-test"></span>
					Проверить обновления
				</button>
				<button type="button" class="btn btn-primary" id="updBtnUpdate" onclick="updateDownload()" disabled>
					<span class="icon icon-download"></span>
					Обновить RouterOS
				</button>
				<button type="button" class="btn btn-primary" id="updBtnUpgradeFw" onclick="updateUpgradeFw()" disabled>
					<span class="icon icon-update"></span>
					Апгрейд RouterBoard
				</button>
				<button type="button" class="btn btn-danger" id="updBtnReboot" onclick="updateReboot()">
					<span class="icon icon-refresh"></span>
					Перезагрузить
				</button>
			</div>

			<div class="update-log" id="updateLog"></div>
		</div>
	</div>

	<style>
	#updateModal .modal-content { max-width: 560px; }
	.update-status-block {
		background: var(--bg-tertiary);
		border: 1px solid var(--border);
		border-radius: var(--radius-xs);
		padding: 0.75rem 1rem;
		margin-bottom: 1rem;
	}
	.update-row {
		display: flex;
		justify-content: space-between;
		align-items: baseline;
		padding: 4px 0;
		font-size: 0.8125rem;
	}
	.update-label { color: var(--text-muted); }
	.update-value { color: var(--text-primary); font-weight: 600; }
	.update-value--warn { color: var(--warning, #e67e22); }
	.update-actions {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 0.5rem;
		margin-bottom: 1rem;
	}
	.update-actions .btn { width: 100%; }
	.update-log {
		max-height: 160px;
		overflow-y: auto;
		font-family: monospace;
		font-size: 0.75rem;
		background: var(--bg-secondary, #f8f9fa);
		border: 1px solid var(--border);
		border-radius: var(--radius-xs);
		padding: 0.5rem 0.75rem;
		color: var(--text-secondary);
		white-space: pre-wrap;
		min-height: 40px;
	}
	.update-log:empty::before {
		content: 'Лог операций появится здесь…';
		color: var(--text-muted);
		font-style: italic;
	}
	</style>

	<script>
		function openModal(modalId) {
			document.getElementById(modalId).style.display = 'flex';
		}
	
		function closeModal(modalId) {
			document.getElementById(modalId).style.display = 'none';
		}
	
		function openBackupModal(deviceId) {
			document.getElementById('backup_device_id').value = deviceId;
			openModal('backupModal');
		}

		function editDevice(deviceId) {
			// Загружаем данные устройства через AJAX
			fetch(`?ajax=get_device&id=${deviceId}`)
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						// Заполняем форму данными устройства
						document.getElementById('edit_device_id').value = data.device.id;
						document.getElementById('edit_name').value = data.device.name;
						document.getElementById('edit_ip').value = data.device.ip;
						document.getElementById('edit_port').value = data.device.port;
						document.getElementById('edit_username').value = data.device.username;
						document.getElementById('edit_password').value = '';
						
						// Открываем модальное окно
						openModal('editDeviceModal');
					} else {
						alert('Ошибка загрузки данных устройства: ' + data.error);
					}
				})
				.catch(error => {
					alert('Ошибка загрузки данных устройства: ' + error);
				});
		}
	
		function testConnection(deviceId) {
			if (!confirm('Тестировать подключение к устройству?')) return;
			
			const form = document.createElement('form');
			form.method = 'POST';
			form.style.display = 'none';
			
			const actionInput = document.createElement('input');
			actionInput.name = 'action';
			actionInput.value = 'test_connection';
			form.appendChild(actionInput);
			
			const deviceInput = document.createElement('input');
			deviceInput.name = 'device_id';
			deviceInput.value = deviceId;
			form.appendChild(deviceInput);
			
			document.body.appendChild(form);
			form.submit();
		}
	
		function deleteDevice(deviceId, deviceName) {
			if (!confirm(`Удалить устройство "${deviceName}"? Все связанные бэкапы также будут удалены.`)) return;
			
			const form = document.createElement('form');
			form.method = 'POST';
			form.style.display = 'none';
			
			const actionInput = document.createElement('input');
			actionInput.name = 'action';
			actionInput.value = 'delete_device';
			form.appendChild(actionInput);
			
			const deviceInput = document.createElement('input');
			deviceInput.name = 'device_id';
			deviceInput.value = deviceId;
			form.appendChild(deviceInput);
			
			document.body.appendChild(form);
			form.submit();
		}
	
		function deleteBackup(backupId, backupName) {
			if (!confirm(`Удалить бэкап "${backupName}"?`)) return;
			
			const form = document.createElement('form');
			form.method = 'POST';
			form.style.display = 'none';
			
			const actionInput = document.createElement('input');
			actionInput.name = 'action';
			actionInput.value = 'delete_backup';
			form.appendChild(actionInput);
			
			const backupInput = document.createElement('input');
			backupInput.name = 'backup_id';
			backupInput.value = backupId;
			form.appendChild(backupInput);
			
			document.body.appendChild(form);
			form.submit();
		}
	
		function createMassBackup() {
			if (!confirm('Запустить массовое резервное копирование для всех устройств? Будут созданы полные бэкапы и экспорты конфигураций.')) return;
			
			const form = document.createElement('form');
			form.method = 'POST';
			form.style.display = 'none';
			
			const actionInput = document.createElement('input');
			actionInput.name = 'action';
			actionInput.value = 'mass_backup';
			form.appendChild(actionInput);
			
			document.body.appendChild(form);
			form.submit();
		}
	
		function selectBackupType(type) {
			document.querySelectorAll('.radio-item').forEach(item => {
				item.classList.remove('selected');
			});
			
			const selectedItem = document.querySelector(`.radio-item input[value="${type}"]`).parentElement;
			selectedItem.classList.add('selected');
			
			document.querySelector(`input[name="backup_type"][value="${type}"]`).checked = true;
		}
		
		function openBackupModal(deviceId) {
			document.getElementById('backup_device_id').value = deviceId;

			setTimeout(() => {
				selectBackupType('full');
			}, 10);

			openModal('backupModal');
		}

		// ==== Обновление устройства ====
		let updateCurrentDeviceId = null;

		function updateLog(msg, type) {
			const log = document.getElementById('updateLog');
			const now = new Date().toLocaleTimeString('ru-RU');
			const prefix = type === 'err' ? '✗ ' : (type === 'ok' ? '✓ ' : '• ');
			log.textContent += `[${now}] ${prefix}${msg}\n`;
			log.scrollTop = log.scrollHeight;
		}

		function updateSetButtonsDisabled(disabled) {
			['updBtnCheck','updBtnUpdate','updBtnUpgradeFw','updBtnReboot'].forEach(id => {
				const b = document.getElementById(id);
				if (b) b.disabled = disabled;
			});
		}

		function updateAjax(actionType) {
			const fd = new FormData();
			fd.append('device_id', updateCurrentDeviceId);
			fd.append('action_type', actionType);
			return fetch('?ajax=update_action', { method: 'POST', body: fd })
				.then(r => r.json());
		}

		function updateApplyStatus(data) {
			if (data.ros_version)       document.getElementById('updRosVersion').textContent = data.ros_version;
			if (data.board)             document.getElementById('updBoard').textContent = data.board;
			if (data.current_fw)        document.getElementById('updCurrentFw').textContent = data.current_fw;
			if (data.channel)           document.getElementById('updChannel').textContent = data.channel;

			if (data.fw_upgrade_available) {
				document.getElementById('updUpgradeFwRow').style.display = 'flex';
				document.getElementById('updUpgradeFw').textContent = data.upgrade_fw;
				document.getElementById('updBtnUpgradeFw').disabled = false;
			}

			if (data.latest_version) {
				document.getElementById('updLatestRow').style.display = 'flex';
				document.getElementById('updLatestVersion').textContent = data.latest_version;
			}
			if (data.update_status) {
				document.getElementById('updStatusRow').style.display = 'flex';
				document.getElementById('updStatus').textContent = data.update_status;
			}
			if (data.ros_update_available) {
				document.getElementById('updBtnUpdate').disabled = false;
			}
		}

		function openUpdateModal(deviceId, deviceName) {
			updateCurrentDeviceId = deviceId;
			document.getElementById('updateModalDeviceName').textContent = deviceName ? '«' + deviceName + '»' : '';

			// Сброс
			['updRosVersion','updBoard','updCurrentFw','updChannel','updLatestVersion','updStatus','updUpgradeFw'].forEach(id => {
				document.getElementById(id).textContent = '—';
			});
			document.getElementById('updUpgradeFwRow').style.display = 'none';
			document.getElementById('updLatestRow').style.display = 'none';
			document.getElementById('updStatusRow').style.display = 'none';
			document.getElementById('updBtnUpdate').disabled = true;
			document.getElementById('updBtnUpgradeFw').disabled = true;
			document.getElementById('updateLog').textContent = '';

			openModal('updateModal');
			updateLog('Получение информации об устройстве…');
			updateSetButtonsDisabled(true);

			updateAjax('status').then(data => {
				updateSetButtonsDisabled(false);
				if (!data.success) {
					updateLog('Ошибка: ' + (data.error || 'неизвестная'), 'err');
					return;
				}
				updateApplyStatus(data);
				updateLog('Информация получена', 'ok');
				if (data.fw_upgrade_available) {
					updateLog('Доступен апгрейд firmware RouterBoard: ' + data.current_fw + ' → ' + data.upgrade_fw);
				}
			}).catch(e => {
				updateSetButtonsDisabled(false);
				updateLog('Ошибка запроса: ' + e, 'err');
			});
		}

		function closeUpdateModal() {
			closeModal('updateModal');
			updateCurrentDeviceId = null;
		}

		function updateCheck() {
			updateLog('Запуск проверки обновлений (это может занять до 10 сек)…');
			updateSetButtonsDisabled(true);
			updateAjax('check').then(data => {
				updateSetButtonsDisabled(false);
				if (!data.success) {
					updateLog('Ошибка: ' + (data.error || 'неизвестная'), 'err');
					return;
				}
				updateApplyStatus(data);
				if (data.ros_update_available) {
					updateLog('Доступна новая версия: ' + data.latest_version, 'ok');
				} else {
					updateLog('Установлена актуальная версия', 'ok');
				}
			}).catch(e => {
				updateSetButtonsDisabled(false);
				updateLog('Ошибка запроса: ' + e, 'err');
			});
		}

		function updateDownload() {
			if (!confirm('Скачать обновление RouterOS? Устройство скачает пакет, но перезагрузится только после нажатия «Перезагрузить».')) return;
			updateLog('Скачивание обновления (это может занять до минуты)…');
			updateSetButtonsDisabled(true);
			updateAjax('update').then(data => {
				updateSetButtonsDisabled(false);
				if (!data.success) {
					updateLog('Ошибка: ' + (data.error || 'неизвестная'), 'err');
					return;
				}
				if (data.status) {
					document.getElementById('updStatusRow').style.display = 'flex';
					document.getElementById('updStatus').textContent = data.status;
				}
				updateLog(data.message || 'Обновление скачано', 'ok');
			}).catch(e => {
				updateSetButtonsDisabled(false);
				updateLog('Ошибка запроса: ' + e, 'err');
			});
		}

		function updateUpgradeFw() {
			if (!confirm('Запланировать апгрейд firmware RouterBoard? Апгрейд применится при следующей перезагрузке.')) return;
			updateLog('Планирование апгрейда firmware…');
			updateSetButtonsDisabled(true);
			updateAjax('upgrade_fw').then(data => {
				updateSetButtonsDisabled(false);
				if (!data.success) {
					updateLog('Ошибка: ' + (data.error || 'неизвестная'), 'err');
					return;
				}
				updateLog(data.message || 'Апгрейд запланирован', 'ok');
			}).catch(e => {
				updateSetButtonsDisabled(false);
				updateLog('Ошибка запроса: ' + e, 'err');
			});
		}

		function updateReboot() {
			if (!confirm('Перезагрузить устройство? Оно будет недоступно 1–3 минуты.')) return;
			updateLog('Отправка команды перезагрузки…');
			updateSetButtonsDisabled(true);
			updateAjax('reboot').then(data => {
				if (!data.success) {
					updateSetButtonsDisabled(false);
					updateLog('Ошибка: ' + (data.error || 'неизвестная'), 'err');
					return;
				}
				updateLog(data.message || 'Команда отправлена', 'ok');
				updateLog('Кнопки заблокированы до закрытия окна', 'ok');
			}).catch(e => {
				updateSetButtonsDisabled(false);
				updateLog('Ошибка запроса: ' + e, 'err');
			});
		}

		// Функция для копирования содержимого
		function copyBackupContent() {
			const content = document.getElementById('backupContent').textContent;
			navigator.clipboard.writeText(content).then(() => {
				// Компактное уведомление об успешном копировании
				const copyBtn = event.target;
				const originalText = copyBtn.innerHTML;
				copyBtn.innerHTML = '<span class="icon icon-check"></span>';
				copyBtn.disabled = true;
				
				setTimeout(() => {
					copyBtn.innerHTML = originalText;
					copyBtn.disabled = false;
				}, 1500);
			}).catch(err => {
				alert('Не удалось скопировать содержимое: ' + err);
			});
		}

		// Функция для форматирования размера файла
		function formatFileSize(bytes) {
			if (bytes === 0) return '0 Bytes';
			const k = 1024;
			const sizes = ['Bytes', 'KB', 'MB', 'GB'];
			const i = Math.floor(Math.log(bytes) / Math.log(k));
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
		}

		// Функция для показа уведомления о скачивании
		function showDownloadNotification(filename) {
			const notificationsContainer = document.getElementById('notifications-container');
			const notification = document.createElement('div');
			notification.className = 'download-notification';
			notification.textContent = 'Бэкап успешно скачан';
			
			notificationsContainer.appendChild(notification);
			
			// Автоматически удаляем уведомление через 3 секунды
			setTimeout(() => {
				if (notification.parentElement) {
					notification.remove();
				}
			}, 3000);
		}

		// Функция для отслеживания скачивания и показа уведомления
		function trackDownload(backupId, filename) {
			// Создаем уведомление о скачивании
			showDownloadNotification(filename);
			
			// Продолжаем стандартное скачивание
			return true;
		}
	</script>
</body>
</html>