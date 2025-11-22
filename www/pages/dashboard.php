<?php
// Обработка фильтров для активности
$filterActivityType = $_GET['activity_type'] ?? 'all';
$filterActivityDate = $_GET['activity_date'] ?? '';

$pageNumber = max(1, intval($_GET['p'] ?? 1));
$perPage = 10;
$offset = ($pageNumber - 1) * $perPage;

// Строим запрос с фильтрами
$whereConditions = [];
$params = [];
$paramTypes = [];

if ($filterActivityType !== 'all') {
	$whereConditions[] = 'action_type = ?';
	$params[] = $filterActivityType;
	$paramTypes[] = SQLITE3_TEXT;
}

if ($filterActivityDate) {
	$whereConditions[] = "DATE(created_at) = ?";
	$params[] = $filterActivityDate;
	$paramTypes[] = SQLITE3_TEXT;
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Получаем общее количество записей для пагинации
$countQuery = "SELECT COUNT(*) FROM activity_logs $whereClause";
$stmt = $db->prepare($countQuery);
foreach ($params as $index => $value) {
	$stmt->bindValue($index + 1, $value, $paramTypes[$index]);
}
$totalActivities = $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
$totalPages = ceil($totalActivities / $perPage);

// Получаем активности с пагинацией
$query = "SELECT * FROM activity_logs $whereClause ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($query);
foreach ($params as $index => $value) {
	$stmt->bindValue($index + 1, $value, $paramTypes[$index]);
}
$recentActivities = $stmt->execute();

// Получаем уникальные типы действий для фильтра
$activityTypes = $db->query("SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type");
?>

<div class="stats-grid">
	<div class="stat-card">
		<div class="stat-number"><?= $deviceCount ?></div>
		<div class="stat-label">Устройств в системе</div>
	</div>
	<div class="stat-card">
		<div class="stat-number"><?= $backupCount ?></div>
		<div class="stat-label">Всего бэкапов</div>
	</div>
	<div class="stat-card">
		<div class="stat-number"><?= $totalActivities ?></div>
		<div class="stat-label">Записей в логе</div>
	</div>
	<div class="stat-card">
		<div class="stat-number"><?= date('H:i') ?></div>
		<div class="stat-label">Текущее время</div>
	</div>
</div>

<div class="table-container">
	<div class="table-header">
		<h3>Последние действия</h3>
	</div>

	<!-- Панель фильтров -->
	<div class="filters-panel">
		<div class="filters-row">
			<!-- Фильтр по типу события -->
			<div class="filter-group">
				<label class="filter-label">Тип события</label>
				<select id="activityTypeFilter" class="filter-select" onchange="applyActivityFilters()">
					<option value="all" <?= $filterActivityType === 'all' ? 'selected' : '' ?>>Все события</option>
					<?php while ($type = $activityTypes->fetchArray(SQLITE3_ASSOC)): ?>
						<option value="<?= $type['action_type'] ?>" <?= $filterActivityType == $type['action_type'] ? 'selected' : '' ?>>
							<?= match($type['action_type']) {
								'device_add' => 'Добавление устройства',
								'device_delete' => 'Удаление устройства',
								'backup_create' => 'Создание бэкапа',
								'backup_delete' => 'Удаление бэкапа',
								'backup_download' => 'Скачивание бэкапа',
								'connection_test' => 'Тест подключения',
								'connection_error' => 'Ошибка подключения',
								'password_change' => 'Смена пароля',
								'user_add' => 'Добавление пользователя',
								'user_delete' => 'Удаление пользователя',
								'backup_error' => 'Ошибка бэкапа',
								'scheduled_backup' => 'Автоматический бэкап',
								'scheduled_backup_error' => 'Ошибка автобэкапа',
								'schedule_update' => 'Обновление расписания',
								'mass_backup' => 'Массовый бэкап',
								default => $type['action_type']
							} ?>
						</option>
					<?php endwhile; ?>
				</select>
			</div>

			<!-- Фильтр по дате -->
			<div class="filter-group">
				<label class="filter-label">Дата</label>
				<div class="date-filter">
					<input type="date" id="activityDateFilter" class="date-input" 
						   value="<?= htmlspecialchars($filterActivityDate) ?>" 
						   onchange="applyActivityFilters()">
					<?php if ($filterActivityDate): ?>
						<button type="button" class="btn btn-outline btn-xs date-clear" onclick="clearActivityDateFilter()" title="Очистить дату">
							×
						</button>
					<?php endif; ?>
				</div>
			</div>

			<!-- Кнопка сброса -->
			<div class="filter-group">
				<label class="filter-label" style="opacity: 0;">Действия</label>
				<button type="button" class="btn btn-outline btn-sm" onclick="clearActivityFilters()" style="height: 40px; white-space: nowrap;">
					Сбросить все
				</button>
			</div>
		</div>

		<!-- Активные фильтры -->
		<?php if ($filterActivityType !== 'all' || $filterActivityDate): ?>
		<div class="active-filters">
			<div class="active-filters-label">Активные фильтры:</div>
			<div class="active-filters-list">
				<?php if ($filterActivityType !== 'all'): ?>
					<span class="active-filter">
						Тип: <?= match($filterActivityType) {
							'device_add' => 'Добавление устройства',
							'device_delete' => 'Удаление устройства',
							'backup_create' => 'Создание бэкапа',
							'backup_delete' => 'Удаление бэкапа',
							'backup_download' => 'Скачивание бэкапа',
							'connection_test' => 'Тест подключения',
							'connection_error' => 'Ошибка подключения',
							'password_change' => 'Смена пароля',
							'user_add' => 'Добавление пользователя',
							'user_delete' => 'Удаление пользователя',
							'backup_error' => 'Ошибка бэкапа',
							'scheduled_backup' => 'Автоматический бэкап',
							'scheduled_backup_error' => 'Ошибка автобэкапа',
							'schedule_update' => 'Обновление расписания',
							'mass_backup' => 'Массовый бэкап',
							default => $filterActivityType
						} ?>
						<button type="button" onclick="removeActivityFilter('type')">×</button>
					</span>
				<?php endif; ?>

				<?php if ($filterActivityDate): ?>
					<span class="active-filter">
						Дата: <?= htmlspecialchars($filterActivityDate) ?>
						<button type="button" onclick="removeActivityFilter('date')">×</button>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>
	</div>

	<div class="table-content">
		<?php if ($recentActivities->fetchArray()): ?>
			<?php $recentActivities->reset(); ?>
			<?php while ($activity = $recentActivities->fetchArray(SQLITE3_ASSOC)): ?>
				<div class="table-row" style="grid-template-columns: 2fr 1fr 1fr auto;">
					<div>
						<div style="font-weight: 500; color: var(--text-primary);">
							<?= htmlspecialchars($activity['description']) ?>
						</div>
						<?php if ($activity['device_name']): ?>
							<div style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.25rem;">
								Устройство: <?= htmlspecialchars($activity['device_name']) ?>
							</div>
						<?php endif; ?>
						<?php if ($activity['backup_filename']): ?>
							<div style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.25rem;">
								Файл: <?= htmlspecialchars($activity['backup_filename']) ?>
							</div>
						<?php endif; ?>
					</div>
					<div>
						<span class="badge <?= 
							strpos($activity['action_type'], 'error') !== false ? 'badge-warning' : 
							(strpos($activity['action_type'], 'delete') !== false ? 'badge-danger' : 
							(strpos($activity['action_type'], 'download') !== false ? 'badge-success' : 'badge-primary'))
						?>">
							<?= match($activity['action_type']) {
								'device_add' => 'Добавление устройства',
								'device_delete' => 'Удаление устройства',
								'backup_create' => 'Создание бэкапа',
								'backup_delete' => 'Удаление бэкапа',
								'backup_download' => 'Скачивание бэкапа',
								'connection_test' => 'Тест подключения',
								'connection_error' => 'Ошибка подключения',
								'password_change' => 'Смена пароля',
								'user_add' => 'Добавление пользователя',
								'user_delete' => 'Удаление пользователя',
								'backup_error' => 'Ошибка бэкапа',
								'scheduled_backup' => 'Автоматический бэкап',
								'scheduled_backup_error' => 'Ошибка автобэкапа',
								'schedule_update' => 'Обновление расписания',
								'mass_backup' => 'Массовый бэкап',
								default => $activity['action_type']
							} ?>
						</span>
					</div>
					<div style="color: var(--text-secondary); font-size: 0.875rem;">
						<?= htmlspecialchars($activity['user_id']) ?>
					</div>
					<div style="color: var(--text-muted); font-size: 0.75rem;">
						<?= formatDbDateTime($activity['created_at']) ?>
					</div>
				</div>
			<?php endwhile; ?>
		<?php else: ?>
			<div class="empty-state">
				<h4>Записи не найдены</h4>
				<p>Попробуйте изменить параметры фильтрации</p>
			</div>
		<?php endif; ?>
	</div>

	<?php if ($totalPages > 1): ?>
	<div class="pagination">
		<div class="pagination-info">
			Показано <?= min($perPage, $totalActivities - $offset) ?> из <?= $totalActivities ?> записей
		</div>
		<div class="pagination-controls">
			<button class="pagination-btn" onclick="changeActivityPage(1)" <?= $pageNumber <= 1 ? 'disabled' : '' ?>>
				Первая
			</button>
			<button class="pagination-btn" onclick="changeActivityPage(<?= $pageNumber - 1 ?>)" <?= $pageNumber <= 1 ? 'disabled' : '' ?>>
				Назад
			</button>
			
			<div class="pagination-pages">
				<?php
				$startPage = max(1, $pageNumber - 2);
				$endPage = min($totalPages, $pageNumber + 2);
				
				for ($i = $startPage; $i <= $endPage; $i++):
				?>
					<button class="pagination-page <?= $i == $pageNumber ? 'active' : '' ?>" onclick="changeActivityPage(<?= $i ?>)">
						<?= $i ?>
					</button>
				<?php endfor; ?>
			</div>
			
			<button class="pagination-btn" onclick="changeActivityPage(<?= $pageNumber + 1 ?>)" <?= $pageNumber >= $totalPages ? 'disabled' : '' ?>>
				Вперед
			</button>
			<button class="pagination-btn" onclick="changeActivityPage(<?= $totalPages ?>)" <?= $pageNumber >= $totalPages ? 'disabled' : '' ?>>
				Последняя
			</button>
		</div>
	</div>
	<?php endif; ?>
</div>

<script>
function applyActivityFilters() {
	const type = document.getElementById('activityTypeFilter').value;
	const date = document.getElementById('activityDateFilter').value;
	
	const url = new URL(window.location);
	url.searchParams.set('page', 'dashboard');
	
	// Устанавливаем или удаляем параметры фильтров
	if (type === 'all') {
		url.searchParams.delete('activity_type');
	} else {
		url.searchParams.set('activity_type', type);
	}
	
	if (date) {
		url.searchParams.set('activity_date', date);
	} else {
		url.searchParams.delete('activity_date');
	}
	
	// Сбрасываем пагинацию при изменении фильтров
	url.searchParams.delete('p');
	
	window.location.href = url.toString();
}

function clearActivityFilters() {
	// Сбрасываем UI элементы
	document.getElementById('activityTypeFilter').value = 'all';
	document.getElementById('activityDateFilter').value = '';
	
	applyActivityFilters();
}

function clearActivityDateFilter() {
	document.getElementById('activityDateFilter').value = '';
	applyActivityFilters();
}

function removeActivityFilter(filterType) {
	const url = new URL(window.location);
	url.searchParams.set('page', 'dashboard');
	
	switch (filterType) {
		case 'type':
			url.searchParams.delete('activity_type');
			// Сбрасываем селектор в UI
			document.getElementById('activityTypeFilter').value = 'all';
			break;
		case 'date':
			url.searchParams.delete('activity_date');
			// Сбрасываем поле даты
			document.getElementById('activityDateFilter').value = '';
			break;
	}
	
	url.searchParams.delete('p');
	window.location.href = url.toString();
}

function changeActivityPage(page) {
	const url = new URL(window.location);
	url.searchParams.set('page', 'dashboard');
	url.searchParams.set('p', page);
	window.location.href = url.toString();
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