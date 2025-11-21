<?php
// Устанавливаем временную зону сервера
date_default_timezone_set('Europe/Moscow');

require_once 'config.php';

// Функция для логирования в файл (для cron)
function logToFile($message) {
	$logFile = '/var/www/html/backup_scheduler.log';
	$timestamp = date('Y-m-d H:i:s');
	$logMessage = "[{$timestamp}] {$message}\n";
	file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Функция для проверки нужно ли запускать бэкап
function shouldRunScheduledBackup() {
	try {
		$db = initDatabase();
		$backupTime = getSetting($db, 'backup_schedule_time', '02:00');
		$currentTime = date('H:i');
		$today = date('Y-m-d');
		
		logToFile("Проверка расписания: текущее время {$currentTime}, настроенное время {$backupTime}");
		
		// Проверяем, был ли уже выполнен бэкап сегодня
		if (wasBackupDoneToday($db)) {
			$lastBackupTime = getLastBackupTime($db);
			logToFile("Бэкап уже выполнялся сегодня: {$lastBackupTime}");
			$db->close();
			return false;
		}
		
		// Преобразуем время в timestamp для сравнения
		$backupTimestamp = strtotime($backupTime);
		$currentTimestamp = strtotime($currentTime);
		
		// Бэкап должен запуститься если текущее время >= настроенного времени
		$timeDiff = $currentTimestamp - $backupTimestamp;
		
		logToFile("Разница во времени: " . round($timeDiff / 60, 1) . " минут");
		
		$db->close();
		
		// Запускаем если текущее время в пределах 5 минут после настроенного времени
		if ($timeDiff >= 0 && $timeDiff <= 300) { // 5 минут в секундах
			logToFile("Время соответствует расписанию, запускаем бэкап");
			return true;
		}
		
		logToFile("Время не соответствует расписанию для выполнения бэкапа");
		return false;
		
	} catch (Exception $e) {
		logToFile("Ошибка при проверке расписания: " . $e->getMessage());
		return false;
	}
}

// Основная логика
try {
	logToFile("=== НАЧАЛО ПРОВЕРКИ РАСПИСАНИЯ БЭКАПА ===");
	
	// Проверяем нужно ли запускать бэкап
	if (shouldRunScheduledBackup()) {
		logToFile(">>> ЗАПУСК МАССОВОГО БЭКАПА <<<");
		
		// Получаем список устройств
		$db = initDatabase();
		$devices = $db->query('SELECT * FROM devices');
		$deviceCount = $db->querySingle('SELECT COUNT(*) FROM devices');
		
		logToFile("Обнаружено устройств: {$deviceCount}");
		
		if ($deviceCount == 0) {
			logToFile("Прерывание: нет устройств для бэкапа");
			$db->close();
			exit;
		}
		
		$successCount = 0;
		$errorCount = 0;
		$processedDevices = [];
		
		while ($device = $devices->fetchArray(SQLITE3_ASSOC)) {
			$deviceInfo = "{$device['name']} ({$device['ip']}:{$device['port']})";
			logToFile("Обработка устройства: {$deviceInfo}");
			$processedDevices[] = $device['name'];
			
			// Создаем полный бэкап
			logToFile("  Создание полного бэкапа...");
			$fullResult = createMikrotikBackup($device, 'full');
			
			if ($fullResult['success']) {
				$stmt = $db->prepare('INSERT INTO backups (device_id, type, filename) VALUES (?, ?, ?)');
				$stmt->bindValue(1, $device['id'], SQLITE3_INTEGER);
				$stmt->bindValue(2, 'full', SQLITE3_TEXT);
				$stmt->bindValue(3, $fullResult['filename'], SQLITE3_TEXT);
				
				if ($stmt->execute()) {
					$successCount++;
					logToFile("  ✓ Полный бэкап создан и записан в БД: {$fullResult['filename']}");
					
					// Проверим что запись действительно есть в БД
					$checkStmt = $db->prepare('SELECT COUNT(*) FROM backups WHERE filename = ?');
					$checkStmt->bindValue(1, $fullResult['filename'], SQLITE3_TEXT);
					$checkResult = $checkStmt->execute();
					$count = $checkResult->fetchArray(SQLITE3_NUM)[0];
					logToFile("  ✓ Проверка БД: найдено {$count} записей для файла {$fullResult['filename']}");
				} else {
					$errorCount++;
					logToFile("  ✗ Ошибка записи полного бэкапа в БД: {$fullResult['filename']}");
				}
			} else {
				$errorCount++;
				logToFile("  ✗ Ошибка полного бэкапа: {$fullResult['error']}");
			}
			
			// Небольшая пауза между бэкапами
			sleep(2);
			
			// Создаем экспорт конфигурации
			logToFile("  Создание экспорта конфигурации...");
			$configResult = createMikrotikBackup($device, 'config');
			
			if ($configResult['success']) {
				$stmt = $db->prepare('INSERT INTO backups (device_id, type, filename) VALUES (?, ?, ?)');
				$stmt->bindValue(1, $device['id'], SQLITE3_INTEGER);
				$stmt->bindValue(2, 'config', SQLITE3_TEXT);
				$stmt->bindValue(3, $configResult['filename'], SQLITE3_TEXT);
				
				if ($stmt->execute()) {
					$successCount++;
					logToFile("  ✓ Экспорт конфигурации создан и записан в БД: {$configResult['filename']}");
					
					// Проверим что запись действительно есть в БД
					$checkStmt = $db->prepare('SELECT COUNT(*) FROM backups WHERE filename = ?');
					$checkStmt->bindValue(1, $configResult['filename'], SQLITE3_TEXT);
					$checkResult = $checkStmt->execute();
					$count = $checkResult->fetchArray(SQLITE3_NUM)[0];
					logToFile("  ✓ Проверка БД: найдено {$count} записей для файла {$configResult['filename']}");
				} else {
					$errorCount++;
					logToFile("  ✗ Ошибка записи экспорта в БД: {$configResult['filename']}");
				}
			} else {
				$errorCount++;
				logToFile("  ✗ Ошибка экспорта конфигурации: {$configResult['error']}");
			}
			
			// Пауза между устройствами
			sleep(3);
		}
		
		$message = "Автоматический бэкап завершен. Устройства: " . implode(', ', $processedDevices) . ". Успешно: {$successCount}, Ошибок: {$errorCount}";
		logToFile(">>> РЕЗУЛЬТАТ БЭКАПА: {$message} <<<");
		
		// Логируем в базу для отображения в интерфейсе
		logActivity($db, 'scheduled_backup', $message);
		
		logToFile("=== ЗАПЛАНИРОВАННЫЙ БЭКАП УСПЕШНО ВЫПОЛНЕН ===");
		
		$db->close();
		
	} else {
		logToFile("=== ПРОВЕРКА ЗАВЕРШЕНА: БЭКАП НЕ ТРЕБУЕТСЯ ===");
	}
	
} catch (Exception $e) {
	$errorMsg = "КРИТИЧЕСКАЯ ОШИБКА: {$e->getMessage()}";
	logToFile($errorMsg);
	
	// Пытаемся записать ошибку в базу
	try {
		$db = initDatabase();
		logActivity($db, 'scheduled_backup_error', $errorMsg);
		$db->close();
	} catch (Exception $e2) {
		logToFile("Не удалось записать ошибку в базу: {$e2->getMessage()}");
	}
	
	logToFile("=== ПРОВЕРКА ЗАВЕРШЕНА С ОШИБКАМИ ===");
}
?>