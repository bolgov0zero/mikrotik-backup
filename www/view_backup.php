<?php
require_once 'config.php';

if (!isset($_GET['id'])) {
	echo json_encode(['success' => false, 'error' => 'ID бэкапа не указан']);
	exit;
}

$backupId = intval($_GET['id']);

try {
	$db = initDatabase();
	
	// Получаем информацию о бэкапе
	$stmt = $db->prepare('SELECT b.*, d.name as device_name FROM backups b LEFT JOIN devices d ON b.device_id = d.id WHERE b.id = ?');
	$stmt->bindValue(1, $backupId, SQLITE3_INTEGER);
	$backup = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
	
	if (!$backup) {
		echo json_encode(['success' => false, 'error' => 'Бэкап не найден']);
		exit;
	}
	
	// Проверяем, что это экспорт (только для экспортов разрешен просмотр)
	if ($backup['type'] !== 'config') {
		echo json_encode(['success' => false, 'error' => 'Просмотр доступен только для экспортов конфигурации']);
		exit;
	}
	
	// Определяем путь к файлу
	$filePath = 'backup/rsc/' . $backup['filename'];
	
	if (!file_exists($filePath)) {
		echo json_encode(['success' => false, 'error' => 'Файл не найден на сервере']);
		exit;
	}
	
	// Читаем содержимое файла
	$content = file_get_contents($filePath);
	$size = filesize($filePath);
	
	if ($content === false) {
		echo json_encode(['success' => false, 'error' => 'Не удалось прочитать файл']);
		exit;
	}
	
	// Ограничиваем размер для очень больших файлов (например, 2MB)
	$maxSize = 2 * 1024 * 1024; // 2MB
	if ($size > $maxSize) {
		$content = "Файл слишком большой для просмотра (" . round($size / 1024 / 1024, 2) . " MB).\nМаксимальный размер для просмотра: 2 MB.\n\nПервые 50000 символов:\n\n" . substr($content, 0, 50000);
	}
	
	echo json_encode([
		'success' => true,
		'content' => $content,
		'size' => $size,
		'filename' => $backup['filename'],
		'device_name' => $backup['device_name']
	]);
	
} catch (Exception $e) {
	echo json_encode(['success' => false, 'error' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
?>