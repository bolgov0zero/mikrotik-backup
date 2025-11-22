<?php
require_once 'config.php';

if (!isset($_GET['id'])) {
	die('ID бэкапа не указан');
}

$backupId = intval($_GET['id']);

try {
	$db = initDatabase();
	
	// Получаем информацию о бэкапе
	$stmt = $db->prepare('SELECT * FROM backups WHERE id = ?');
	$stmt->bindValue(1, $backupId, SQLITE3_INTEGER);
	$backup = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
	
	if (!$backup) {
		die('Бэкап не найден');
	}
	
	// Определяем путь к файлу
	$backupPath = $backup['type'] === 'full' ? 'backup/bkp/' : 'backup/rsc/';
	$filePath = $backupPath . $backup['filename'];
	
	if (!file_exists($filePath)) {
		die('Файл не найден на сервере');
	}
	
	// Логируем скачивание
	if (isset($_SESSION['username'])) {
		logBackupDownload($db, $backupId, $_SESSION['username']);
	}
	
	// Отправляем файл для скачивания
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Length: ' . filesize($filePath));
	
	readfile($filePath);
	exit;
	
} catch (Exception $e) {
	die('Ошибка: ' . $e->getMessage());
}
?>