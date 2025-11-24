<?php
// Обработка фильтров для бэкапов
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
?>

<div class="table-container">
	<div class="table-header">
		<h3>Бэкапы</h3>
		<button class="btn btn-primary" onclick="createMassBackup()">
			<span class="icon icon-mass-backup"></span>
			Массовый бэкап
		</button>
	</div>

	<!-- Компактная панель фильтров -->
	<div class="filters-panel">
		<div class="filters-row">
			<!-- Фильтр по устройству -->
			<div class="filter-group">
				<label class="filter-label">Устройство</label>
				<select id="deviceFilter" class="filter-select" onchange="applyFilters()">
					<option value="all" <?= $filterDeviceId === 'all' ? 'selected' : '' ?>>Все устройства</option>
					<?php 
					$allDevices->reset();
					while ($device = $allDevices->fetchArray(SQLITE3_ASSOC)): ?>
						<option value="<?= $device['id'] ?>" <?= $filterDeviceId == $device['id'] ? 'selected' : '' ?>>
							<?= htmlspecialchars($device['name']) ?>
						</option>
					<?php endwhile; ?>
				</select>
			</div>

			<!-- Фильтр по типу (взаимоисключающие кнопки) -->
			<div class="filter-group">
				<label class="filter-label">Тип бэкапа</label>
				<div class="btn-group">
					<button type="button" class="btn btn-sm <?= $filterType === 'all' ? 'active' : '' ?>" onclick="setTypeFilter('all')">Все</button>
					<button type="button" class="btn btn-sm <?= $filterType === 'full' ? 'active' : '' ?>" onclick="setTypeFilter('full')">Бинарные</button>
					<button type="button" class="btn btn-sm <?= $filterType === 'config' ? 'active' : '' ?>" onclick="setTypeFilter('config')">Экспорт</button>
				</div>
			</div>

			<!-- Фильтр по дате -->
			<div class="filter-group">
				<label class="filter-label">Дата</label>
				<div class="date-filter">
					<input type="date" id="dateFilter" class="date-input" 
						   value="<?= htmlspecialchars($filterDate) ?>" 
						   onchange="applyFilters()">
					<?php if ($filterDate): ?>
						<button type="button" class="btn btn-outline btn-xs date-clear" onclick="clearDateFilter()" title="Очистить дату">
							×
						</button>
					<?php endif; ?>
				</div>
			</div>

			<!-- Кнопка сброса -->
			<div class="filter-group">
				<label class="filter-label" style="opacity: 0;">Действия</label>
				<button type="button" class="btn btn-outline btn-sm" onclick="clearAllFilters()" style="height: 40px; white-space: nowrap;">
					Сбросить все
				</button>
			</div>
		</div>

		<!-- Активные фильтры -->
		<?php if ($filterDeviceId !== 'all' || $filterType !== 'all' || $filterDate): ?>
		<div class="active-filters">
			<div class="active-filters-label">Активные фильтры:</div>
			<div class="active-filters-list">
				<?php if ($filterDeviceId !== 'all'): ?>
					<?php
					$deviceStmt = $db->prepare('SELECT name FROM devices WHERE id = ?');
					$deviceStmt->bindValue(1, $filterDeviceId, SQLITE3_INTEGER);
					$deviceResult = $deviceStmt->execute();
					$deviceName = $deviceResult->fetchArray(SQLITE3_ASSOC)['name'] ?? 'Неизвестное устройство';
					?>
					<span class="active-filter">
						Устройство: <?= htmlspecialchars($deviceName) ?>
						<button type="button" onclick="removeFilter('device')">×</button>
					</span>
				<?php endif; ?>

				<?php if ($filterType !== 'all'): ?>
					<span class="active-filter">
						Тип: <?= $filterType === 'full' ? 'Бинарные' : 'Экспорт' ?>
						<button type="button" onclick="removeFilter('type')">×</button>
					</span>
				<?php endif; ?>

				<?php if ($filterDate): ?>
					<span class="active-filter">
						Дата: <?= htmlspecialchars($filterDate) ?>
						<button type="button" onclick="removeFilter('date')">×</button>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>
	</div>
	
<!-- Список бэкапов -->
	<div class="table-content" style="padding: 0;">
		<?php while ($backup = $backups->fetchArray(SQLITE3_ASSOC)): ?>
			<div class="backup-item">
				<div class="backup-info">
					<div class="backup-name">
						<?= htmlspecialchars($backup['device_name'] ?? 'Неизвестное устройство') ?>
					</div>
					<div class="backup-filename">
						<?= $backup['filename'] ?>
					</div>
				</div>
				
				<?php if (!empty($backup['ros_version'])): ?>
				<div>
					<span class="badge badge-success" title="Версия RouterOS">
						ROS <?= htmlspecialchars($backup['ros_version']) ?>
					</span>
				</div>
				<?php endif; ?>
				
				<div>
					<span class="badge <?= $backup['type'] === 'full' ? 'badge-primary' : 'badge-warning' ?>">
						<?= $backup['type'] === 'full' ? 'Бинарный' : 'Экспорт' ?>
					</span>
				</div>
				
				<div class="backup-meta">
					<div class="backup-time">
						<?= formatDbDateTime($backup['created_at']) ?>
					</div>
				</div>
				
				<div class="backup-actions">
					<?php
					$backupPath = $backup['type'] === 'full' ? 'backup/bkp/' : 'backup/rsc/';
					$filePath = $backupPath . $backup['filename'];
					if (file_exists($filePath)):
					?>
						<!-- Кнопка просмотра (только для экспортов) -->
						<?php if ($backup['type'] === 'config'): ?>
							<button class="btn btn-outline btn-xs" onclick="viewBackupContent(<?= $backup['id'] ?>, '<?= htmlspecialchars($backup['filename']) ?>')" title="Просмотр">
								<span class="icon icon-view"></span>
							</button>
						<?php else: ?>
							<!-- Заглушка для выравнивания -->
							<span style="display: inline-block; width: 40px; height: 1px;"></span>
						<?php endif; ?>
						
						<a href="download_backup.php?id=<?= $backup['id'] ?>" 
						   class="btn btn-primary btn-xs" 
						   title="Скачать"
						   onclick="trackDownload(<?= $backup['id'] ?>, '<?= htmlspecialchars($backup['filename']) ?>')">
							<span class="icon icon-download"></span>
						</a>
						<button class="btn btn-danger btn-xs" onclick="deleteBackup(<?= $backup['id'] ?>, '<?= htmlspecialchars($backup['filename']) ?>')" title="Удалить">
							<span class="icon icon-delete"></span>
						</button>
					<?php else: ?>
						<span style="color: var(--danger); font-size: 0.6875rem;" title="Файл отсутствует">⚠️</span>
					<?php endif; ?>
				</div>
			</div>
		<?php endwhile; ?>
		
		<?php 
		$backups->reset();
		if (!$backups->fetchArray()): 
		?>
			<div class="empty-state-compact">
				<h4>Бэкапы не найдены</h4>
				<p>Попробуйте изменить параметры фильтрации</p>
			</div>
		<?php endif; ?>
	</div>
	
	<?php if ($totalPages > 1): ?>
	<div class="pagination">
		<div class="pagination-info">
			Показано <?= min($perPage, $totalBackups - $offset) ?> из <?= $totalBackups ?> бэкапов
		</div>
		<div class="pagination-controls">
			<button class="pagination-btn" onclick="changePage(1)" <?= $pageNumber <= 1 ? 'disabled' : '' ?>>
				Первая
			</button>
			<button class="pagination-btn" onclick="changePage(<?= $pageNumber - 1 ?>)" <?= $pageNumber <= 1 ? 'disabled' : '' ?>>
				Назад
			</button>
			
			<div class="pagination-pages">
				<?php
				$startPage = max(1, $pageNumber - 2);
				$endPage = min($totalPages, $pageNumber + 2);
				
				for ($i = $startPage; $i <= $endPage; $i++):
				?>
					<button class="pagination-page <?= $i == $pageNumber ? 'active' : '' ?>" onclick="changePage(<?= $i ?>)">
						<?= $i ?>
					</button>
				<?php endfor; ?>
			</div>
			
			<button class="pagination-btn" onclick="changePage(<?= $pageNumber + 1 ?>)" <?= $pageNumber >= $totalPages ? 'disabled' : '' ?>>
				Вперед
			</button>
			<button class="pagination-btn" onclick="changePage(<?= $totalPages ?>)" <?= $pageNumber >= $totalPages ? 'disabled' : '' ?>>
				Последняя
			</button>
		</div>
	</div>
	<?php endif; ?>
</div>

<!-- Компактное модальное окно для просмотра содержимого -->
<div id="viewBackupModal" class="modal">
	<div class="modal-content modal-view-content">
		<div class="modal-header">
			<h3>Просмотр конфигурации</h3>
			<button class="modal-close" onclick="closeModal('viewBackupModal')">×</button>
		</div>
		
		<div class="file-info">
			<div class="file-info-content">
				<div class="file-details">
					<div class="file-name" id="viewFileName"></div>
					<div class="file-size" id="viewFileSize"></div>
				</div>
				<button class="btn btn-primary btn-sm" onclick="copyBackupContent()">
					<span class="icon icon-copy"></span>
					Копировать
				</button>
			</div>
		</div>
		
		<div class="file-content-wrapper">
			<pre id="backupContent" class="file-content"></pre>
		</div>
		
		<div class="modal-footer">
			<button class="btn btn-outline btn-sm" onclick="closeModal('viewBackupModal')">
				Закрыть
			</button>
		</div>
	</div>
</div>

<script>
function setTypeFilter(type) {
	document.querySelectorAll('.btn-group .btn').forEach(btn => {
		btn.classList.remove('active');
	});
	event.target.classList.add('active');
	applyFilters();
}

function applyFilters() {
	const deviceId = document.getElementById('deviceFilter').value;
	const date = document.getElementById('dateFilter').value;
	
	// Получаем активный тип из кнопок
	const activeTypeButton = document.querySelector('.btn-group .btn.active');
	const type = activeTypeButton ? activeTypeButton.textContent.toLowerCase() : 'all';
	const typeValue = type === 'все' ? 'all' : 
					 type === 'бинарные' ? 'full' : 
					 type === 'экспорт' ? 'config' : 'all';
	
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	
	// Устанавливаем или удаляем параметры фильтров
	if (deviceId === 'all') {
		url.searchParams.delete('device_id');
	} else {
		url.searchParams.set('device_id', deviceId);
	}
	
	if (typeValue === 'all') {
		url.searchParams.delete('type');
	} else {
		url.searchParams.set('type', typeValue);
	}
	
	if (date) {
		url.searchParams.set('date', date);
	} else {
		url.searchParams.delete('date');
	}
	
	// Сбрасываем пагинацию при изменении фильтров
	url.searchParams.delete('p');
	
	window.location.href = url.toString();
}

function clearAllFilters() {
	// Сбрасываем UI элементы
	document.getElementById('deviceFilter').value = 'all';
	document.getElementById('dateFilter').value = '';
	
	// Сбрасываем кнопки типа
	document.querySelectorAll('.btn-group .btn').forEach(btn => {
		btn.classList.remove('active');
	});
	document.querySelector('.btn-group .btn:first-child').classList.add('active');
	
	applyFilters();
}

function clearDateFilter() {
	document.getElementById('dateFilter').value = '';
	applyFilters();
}

function removeFilter(filterType) {
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	
	switch (filterType) {
		case 'device':
			url.searchParams.delete('device_id');
			// Сбрасываем селектор в UI
			document.getElementById('deviceFilter').value = 'all';
			break;
		case 'type':
			url.searchParams.delete('type');
			// Сбрасываем кнопки типа
			document.querySelectorAll('.btn-group .btn').forEach(btn => {
				btn.classList.remove('active');
			});
			document.querySelector('.btn-group .btn:first-child').classList.add('active');
			break;
		case 'date':
			url.searchParams.delete('date');
			// Сбрасываем поле даты
			document.getElementById('dateFilter').value = '';
			break;
	}
	
	url.searchParams.delete('p');
	window.location.href = url.toString();
}

function changePage(page) {
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	url.searchParams.set('p', page);
	window.location.href = url.toString();
}

// Функция для просмотра содержимого бэкапа
function viewBackupContent(backupId, filename) {
	// Показываем загрузку
	document.getElementById('viewFileName').textContent = filename;
	document.getElementById('viewFileSize').textContent = 'Загрузка...';
	document.getElementById('backupContent').textContent = 'Загрузка содержимого...';
	
	// Открываем модальное окно
	openModal('viewBackupModal');
	
	// Загружаем содержимое файла
	fetch(`view_backup.php?id=${backupId}`)
		.then(response => {
			if (!response.ok) {
				throw new Error('Ошибка загрузки файла');
			}
			return response.json();
		})
		.then(data => {
			if (data.success) {
				document.getElementById('viewFileSize').textContent = `Размер: ${formatFileSize(data.size)}`;
				document.getElementById('backupContent').textContent = data.content;
				
				// Авто-скролл к началу после загрузки
				setTimeout(() => {
					const contentElement = document.getElementById('backupContent');
					if (contentElement) {
						contentElement.scrollTop = 0;
					}
				}, 100);
			} else {
				document.getElementById('viewFileSize').textContent = 'Ошибка';
				document.getElementById('backupContent').textContent = 'Не удалось загрузить содержимое файла: ' + data.error;
			}
		})
		.catch(error => {
			document.getElementById('viewFileSize').textContent = 'Ошибка';
			document.getElementById('backupContent').textContent = 'Ошибка загрузки: ' + error.message;
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

function openModal(modalId) {
	document.getElementById(modalId).style.display = 'flex';
}

function closeModal(modalId) {
	document.getElementById(modalId).style.display = 'none';
}

// Функция для отслеживания скачивания и показа уведомления
function trackDownload(backupId, filename) {
	// Создаем уведомление о скачивании
	showDownloadNotification(filename);
	
	// Дополнительная логика может быть добавлена здесь
	// Например, отправка аналитики на сервер
	
	// Продолжаем стандартное скачивание
	return true;
}

// Функция для показа уведомления о скачивании
function showDownloadNotification(filename) {
	// Создаем элемент уведомления
	const notification = document.createElement('div');
	notification.className = 'download-notification';
	notification.innerHTML = `
		<div class="download-notification-content">
			<div class="download-notification-header">
				<span class="icon icon-download" style="background-color: var(--success);"></span>
				<span class="download-notification-title">Скачивание бэкапа</span>
				<button class="download-notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
			</div>
			<div class="download-notification-body">
				<div class="download-file-info">
					<strong>Файл:</strong> ${filename}
				</div>
				<div class="download-user-info">
					<strong>Пользователь:</strong> <?= htmlspecialchars($_SESSION['username']) ?>
				</div>
				<div class="download-time-info">
					<strong>Время:</strong> ${new Date().toLocaleTimeString()}
				</div>
			</div>
		</div>
	`;
	
	// Добавляем уведомление в контейнер
	const container = document.getElementById('downloadNotifications') || createDownloadNotificationsContainer();
	container.appendChild(notification);
	
	// Автоматически удаляем уведомление через 5 секунд
	setTimeout(() => {
		if (notification.parentElement) {
			notification.remove();
		}
	}, 5000);
}

// Функция для создания контейнера уведомлений
function createDownloadNotificationsContainer() {
	const container = document.createElement('div');
	container.id = 'downloadNotifications';
	container.className = 'download-notifications-container';
	document.body.appendChild(container);
	return container;
}
</script>