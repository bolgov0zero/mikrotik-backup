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
			
			logActivity($db, 'device_add', 'Добавлено новое устройство', $_POST['name']);
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
					$stmt = $db->prepare('INSERT INTO backups (device_id, type, filename) VALUES (?, ?, ?)');
					$stmt->bindValue(1, $deviceId, SQLITE3_INTEGER);
					$stmt->bindValue(2, $type, SQLITE3_TEXT);
					$stmt->bindValue(3, $backupResult['filename'], SQLITE3_TEXT);
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
			if ($massBackupResult['success']) {
				$_SESSION['backup_success'] = $massBackupResult['message'];
				logActivity($db, 'mass_backup', 'Массовое резервное копирование завершено.\nУспешно: ' . $massBackupResult['success_count'] . ', Ошибок: ' . $massBackupResult['error_count']);
			} else {
				$_SESSION['backup_error'] = 'Ошибка массового бэкапа: ' . $massBackupResult['error'];
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
	<title>Mikrotik Backup System</title>
	<link rel="stylesheet" href="style.css">
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
					<h1>Mikrotik</h1>
					<h2>Backup System</h2>
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
						2025 © bolgov0zero
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
							<div class="user-role">Администратор</div>
						</div>
					</div>
				</div>
			</div>

			<?php
			// Показываем уведомления
			if (isset($_SESSION['backup_success'])) {
				echo '<div class="success">' . $_SESSION['backup_success'] . '</div>';
				unset($_SESSION['backup_success']);
			}
			if (isset($_SESSION['backup_error'])) {
				echo '<div class="error">' . $_SESSION['backup_error'] . '</div>';
				unset($_SESSION['backup_error']);
			}
			if (isset($_SESSION['test_success'])) {
				echo '<div class="success">' . $_SESSION['test_success'] . '</div>';
				unset($_SESSION['test_success']);
			}
			if (isset($_SESSION['test_error'])) {
				echo '<div class="error">' . $_SESSION['test_error'] . '</div>';
				unset($_SESSION['test_error']);
			}
			if (isset($_SESSION['settings_success'])) {
				echo '<div class="success">' . $_SESSION['settings_success'] . '</div>';
				unset($_SESSION['settings_success']);
			}
			if (isset($_SESSION['settings_error'])) {
				echo '<div class="error">' . $_SESSION['settings_error'] . '</div>';
				unset($_SESSION['settings_error']);
			}

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
	</script>
</body>
</html>