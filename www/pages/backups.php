<?php
// Обработка фильтров для бэкапов
$filterDeviceId = $_GET['device_id'] ?? 'all';
$filterType = $_GET['type'] ?? 'all';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

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

if ($filterStartDate && $filterEndDate) {
	$whereConditions[] = "DATE(b.created_at) BETWEEN ? AND ?";
	$params[] = $filterStartDate;
	$params[] = $filterEndDate;
	$paramTypes[] = SQLITE3_TEXT;
	$paramTypes[] = SQLITE3_TEXT;
} elseif ($filterStartDate) {
	$whereConditions[] = "DATE(b.created_at) >= ?";
	$params[] = $filterStartDate;
	$paramTypes[] = SQLITE3_TEXT;
} elseif ($filterEndDate) {
	$whereConditions[] = "DATE(b.created_at) <= ?";
	$params[] = $filterEndDate;
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

	<!-- Панель фильтров -->
	<div class="filters-panel">
		<div class="filters-header">
			<h4>Фильтры</h4>
			<button type="button" class="btn btn-outline btn-sm" onclick="clearAllFilters()">
				Сбросить все
			</button>
		</div>
		
		<div class="filters-grid">
			<!-- Фильтр по устройству -->
			<div class="filter-group">
				<label class="filter-label">Устройство</label>
				<select id="deviceFilter" class="filter-select" onchange="applyFilters()">
					<option value="all" <?= $filterDeviceId === 'all' ? 'selected' : '' ?>>Все устройства</option>
					<?php while ($device = $allDevices->fetchArray(SQLITE3_ASSOC)): ?>
						<option value="<?= $device['id'] ?>" <?= $filterDeviceId == $device['id'] ? 'selected' : '' ?>>
							<?= htmlspecialchars($device['name']) ?>
						</option>
					<?php endwhile; ?>
				</select>
			</div>

			<!-- Фильтр по типу -->
			<div class="filter-group">
				<label class="filter-label">Тип бэкапа</label>
				<select id="typeFilter" class="filter-select" onchange="applyFilters()">
					<option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>Все типы</option>
					<option value="full" <?= $filterType === 'full' ? 'selected' : '' ?>>Бинарные</option>
					<option value="config" <?= $filterType === 'config' ? 'selected' : '' ?>>Экспорт</option>
				</select>
			</div>

			<!-- Фильтр по дате -->
			<div class="filter-group">
				<label class="filter-label">Период</label>
				<div class="date-inputs">
					<input type="date" id="startDate" class="date-input" 
						   value="<?= htmlspecialchars($filterStartDate) ?>" 
						   placeholder="От">
					<span class="date-separator">—</span>
					<input type="date" id="endDate" class="date-input" 
						   value="<?= htmlspecialchars($filterEndDate) ?>" 
						   placeholder="До">
				</div>
			</div>

			<!-- Кнопка применения -->
			<div class="filter-group">
				<label class="filter-label" style="opacity: 0;">Применить</label>
				<button type="button" class="btn btn-primary btn-sm" onclick="applyFilters()" style="white-space: nowrap;">
					Применить фильтры
				</button>
			</div>
		</div>

		<!-- Информация о фильтрах -->
		<?php if ($filterDeviceId !== 'all' || $filterType !== 'all' || $filterStartDate || $filterEndDate): ?>
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

				<?php if ($filterStartDate): ?>
					<span class="active-filter">
						С: <?= htmlspecialchars($filterStartDate) ?>
						<button type="button" onclick="removeFilter('start_date')">×</button>
					</span>
				<?php endif; ?>

				<?php if ($filterEndDate): ?>
					<span class="active-filter">
						По: <?= htmlspecialchars($filterEndDate) ?>
						<button type="button" onclick="removeFilter('end_date')">×</button>
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
function applyFilters() {
	const deviceId = document.getElementById('deviceFilter').value;
	const type = document.getElementById('typeFilter').value;
	const startDate = document.getElementById('startDate').value;
	const endDate = document.getElementById('endDate').value;
	
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
	
	if (startDate) {
		url.searchParams.set('start_date', startDate);
	} else {
		url.searchParams.delete('start_date');
	}
	
	if (endDate) {
		url.searchParams.set('end_date', endDate);
	} else {
		url.searchParams.delete('end_date');
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
	url.searchParams.delete('start_date');
	url.searchParams.delete('end_date');
	url.searchParams.delete('p');
	window.location.href = url.toString();
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
		case 'start_date':
			url.searchParams.delete('start_date');
			break;
		case 'end_date':
			url.searchParams.delete('end_date');
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

// Автоматическое применение фильтра по дате при изменении
document.getElementById('startDate').addEventListener('change', function() {
	const endDate = document.getElementById('endDate').value;
	if (this.value && endDate) {
		applyFilters();
	}
});

document.getElementById('endDate').addEventListener('change', function() {
	const startDate = document.getElementById('startDate').value;
	if (this.value && startDate) {
		applyFilters();
	}
});
</script>