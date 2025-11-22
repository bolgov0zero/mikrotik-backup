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

			<!-- Кнопки управления -->
			<div class="filter-group">
				<label class="filter-label" style="opacity: 0;">Действия</label>
				<div class="filter-actions">
					<button type="button" class="btn btn-primary btn-sm" onclick="applyFilters()">
						Применить
					</button>
					<button type="button" class="btn btn-outline btn-sm" onclick="clearAllFilters()">
						Сбросить
					</button>
				</div>
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
						<a href="<?= $filePath ?>" download class="btn btn-primary btn-xs" title="Скачать">
							<span class="icon icon-download"></span>
						</a>
					<?php else: ?>
						<span style="color: var(--danger); font-size: 0.6875rem;" title="Файл отсутствует">⚠️</span>
					<?php endif; ?>
					<button class="btn btn-danger btn-xs" onclick="deleteBackup(<?= $backup['id'] ?>, '<?= htmlspecialchars($backup['filename']) ?>')" title="Удалить">
						<span class="icon icon-delete"></span>
					</button>
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

<script>
function setTypeFilter(type) {
	document.querySelectorAll('.btn-group .btn').forEach(btn => {
		btn.classList.remove('active');
	});
	event.target.classList.add('active');
	
	// Устанавливаем скрытое значение типа
	document.getElementById('typeFilter').value = type;
	applyFilters();
}

function applyFilters() {
	const deviceId = document.getElementById('deviceFilter').value;
	const date = document.getElementById('dateFilter').value;
	const type = document.getElementById('typeFilter')?.value || 'all';
	
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	
	// Устанавливаем или удаляем параметры фильтров
	if (deviceId === 'all') {
		url.searchParams.delete('device_id');
	} else {
		url.searchParams.set('device_id', deviceId);
	}
	
	if (type === 'all') {
		url.searchParams.delete('type');
	} else {
		url.searchParams.set('type', type);
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
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	url.searchParams.delete('device_id');
	url.searchParams.delete('type');
	url.searchParams.delete('date');
	url.searchParams.delete('p');
	window.location.href = url.toString();
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
			break;
		case 'type':
			url.searchParams.delete('type');
			break;
		case 'date':
			url.searchParams.delete('date');
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

// Скрытый элемент для хранения типа фильтра
document.write('<input type="hidden" id="typeFilter" value="<?= $filterType ?>">');
</script>