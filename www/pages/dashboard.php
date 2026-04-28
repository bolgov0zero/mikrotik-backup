<?php
$filterActivityType = $_GET['activity_type'] ?? 'all';
$filterActivityDate = $_GET['activity_date'] ?? '';
$pageNumber = max(1, intval($_GET['p'] ?? 1));
$perPage    = 12;
$offset     = ($pageNumber - 1) * $perPage;

$whereConditions = [];
$params      = [];
$paramTypes  = [];

if ($filterActivityType !== 'all') {
	$whereConditions[] = 'action_type = ?';
	$params[]     = $filterActivityType;
	$paramTypes[] = SQLITE3_TEXT;
}
if ($filterActivityDate) {
	$whereConditions[] = "DATE(created_at) = ?";
	$params[]     = $filterActivityDate;
	$paramTypes[] = SQLITE3_TEXT;
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$countQuery = "SELECT COUNT(*) FROM activity_logs $whereClause";
$stmt = $db->prepare($countQuery);
foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v, $paramTypes[$i]);
$totalActivities = $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
$totalPages = ceil($totalActivities / $perPage);

$query = "SELECT * FROM activity_logs $whereClause ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($query);
foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v, $paramTypes[$i]);
$recentActivities = $stmt->execute();

$activityTypes = $db->query("SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type");

// Дополнительные данные для карточек
$backupToday  = $db->querySingle("SELECT COUNT(*) FROM backups WHERE DATE(created_at) = DATE('now')");
$deviceOnline = $db->querySingle("SELECT COUNT(*) FROM devices");
$lastBackup   = $db->querySingle("SELECT created_at FROM backups ORDER BY created_at DESC LIMIT 1");

function activityLabel($type) {
	return match($type) {
		'device_add'             => 'Устройство добавлено',
		'device_edit'            => 'Устройство изменено',
		'device_delete'          => 'Устройство удалено',
		'backup_create'          => 'Бэкап создан',
		'backup_delete'          => 'Бэкап удалён',
		'backup_download'        => 'Бэкап скачан',
		'connection_test'        => 'Тест подключения',
		'connection_error'       => 'Ошибка подключения',
		'password_change'        => 'Смена пароля',
		'user_add'               => 'Пользователь добавлен',
		'user_delete'            => 'Пользователь удалён',
		'backup_error'           => 'Ошибка бэкапа',
		'scheduled_backup'       => 'Авто-бэкап',
		'scheduled_backup_error' => 'Ошибка авто-бэкапа',
		'schedule_update'        => 'Расписание изменено',
		'mass_backup'            => 'Массовый бэкап',
		'telegram_save'          => 'Telegram настроен',
		'telegram_test'          => 'Telegram проверен',
		'email_save'             => 'Email настроен',
		'email_test'             => 'Email проверен',
		default                  => $type,
	};
}

function activityStyle($type) {
	if (str_contains($type, 'error'))    return ['danger',  'icon-activity-error'];
	if (str_contains($type, 'delete'))   return ['warning', 'icon-activity-delete'];
	if (str_contains($type, 'backup'))   return ['accent',  'icon-activity-backup'];
	if (str_contains($type, 'download')) return ['primary', 'icon-activity-download'];
	return ['success', 'icon-activity-default'];
}
?>

<!-- Stat cards -->
<div class="stats-grid">
	<div class="stat-card">
		<div class="stat-icon"><span class="icon icon-devices"></span></div>
		<div class="stat-body">
			<div class="stat-number"><?= $deviceCount ?></div>
			<div class="stat-label">Устройств</div>
		</div>
	</div>
	<div class="stat-card">
		<div class="stat-icon"><span class="icon icon-backups"></span></div>
		<div class="stat-body">
			<div class="stat-number"><?= $backupCount ?></div>
			<div class="stat-label">Бэкапов всего</div>
		</div>
	</div>
	<div class="stat-card">
		<div class="stat-icon"><span class="icon icon-activity"></span></div>
		<div class="stat-body">
			<div class="stat-number"><?= $totalActivities ?></div>
			<div class="stat-label">Записей в логе</div>
		</div>
	</div>
	<div class="stat-card">
		<div class="stat-icon"><span class="icon icon-clock"></span></div>
		<div class="stat-body">
			<div class="stat-number" id="liveClock"><?= date('H:i') ?></div>
			<div class="stat-label">Текущее время</div>
		</div>
	</div>
</div>

<!-- Activity log -->
<div class="table-container">

	<div class="table-header">
		<div style="display:flex;align-items:center;gap:0.625rem;">
			<h3>Журнал событий</h3>
			<?php if ($filterActivityType !== 'all' || $filterActivityDate): ?>
				<span class="badge badge-primary">Фильтр активен</span>
			<?php endif; ?>
		</div>
		<div style="display:flex;gap:0.5rem;align-items:center;">
			<select class="filter-select" style="height:30px;font-size:0.75rem;"
					id="activityTypeFilter" onchange="applyActivityFilters()">
				<option value="all" <?= $filterActivityType === 'all' ? 'selected' : '' ?>>Все события</option>
				<?php
				$activityTypesLoop = $db->query("SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type");
				while ($t = $activityTypesLoop->fetchArray(SQLITE3_ASSOC)):
				?>
					<option value="<?= $t['action_type'] ?>"
							<?= $filterActivityType === $t['action_type'] ? 'selected' : '' ?>>
						<?= activityLabel($t['action_type']) ?>
					</option>
				<?php endwhile; ?>
			</select>
			<input type="date" id="activityDateFilter" class="date-input"
				   style="height:30px;font-size:0.75rem;width:128px;"
				   value="<?= htmlspecialchars($filterActivityDate) ?>"
				   onchange="applyActivityFilters()">
			<?php if ($filterActivityType !== 'all' || $filterActivityDate): ?>
				<button class="btn btn-outline btn-sm" onclick="clearActivityFilters()">Сбросить</button>
			<?php endif; ?>
		</div>
	</div>

	<div class="activity-feed">
		<?php if ($recentActivities->fetchArray()): ?>
			<?php $recentActivities->reset(); ?>
			<?php while ($activity = $recentActivities->fetchArray(SQLITE3_ASSOC)):
				[$styleKey, $iconKey] = activityStyle($activity['action_type']);
			?>
			<div class="activity-row">
				<div class="activity-dot activity-dot--<?= $styleKey ?>"></div>
				<div class="activity-main">
					<div class="activity-desc"><?= htmlspecialchars($activity['description']) ?></div>
					<div class="activity-meta">
						<?php if ($activity['device_name']): ?>
							<span class="activity-meta-item">
								<span class="icon icon-devices" style="width:11px;height:11px;opacity:.5;"></span>
								<?= htmlspecialchars($activity['device_name']) ?>
							</span>
						<?php endif; ?>
						<?php if ($activity['backup_filename']): ?>
							<span class="activity-meta-item">
								<span class="icon icon-backups" style="width:11px;height:11px;opacity:.5;"></span>
								<?= htmlspecialchars($activity['backup_filename']) ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
				<div class="activity-badge">
					<span class="badge badge-<?= $styleKey === 'accent' ? 'primary' : $styleKey ?>"><?= activityLabel($activity['action_type']) ?></span>
				</div>
				<div class="activity-user"><?= htmlspecialchars($activity['user_id']) ?></div>
				<div class="activity-time"><?= formatDbDateTime($activity['created_at']) ?></div>
			</div>
			<?php endwhile; ?>
		<?php else: ?>
			<div class="empty-state">
				<h4>Событий не найдено</h4>
				<p>Попробуйте изменить параметры фильтра</p>
			</div>
		<?php endif; ?>
	</div>

	<?php if ($totalPages > 1): ?>
	<div class="pagination">
		<div class="pagination-info">
			<?= min($perPage, $totalActivities - $offset) ?> из <?= $totalActivities ?>
		</div>
		<div class="pagination-controls">
			<button class="pagination-btn" onclick="changeActivityPage(1)" <?= $pageNumber <= 1 ? 'disabled' : '' ?>>«</button>
			<button class="pagination-btn" onclick="changeActivityPage(<?= $pageNumber - 1 ?>)" <?= $pageNumber <= 1 ? 'disabled' : '' ?>>‹</button>
			<?php for ($i = max(1, $pageNumber-2); $i <= min($totalPages, $pageNumber+2); $i++): ?>
				<button class="pagination-page <?= $i == $pageNumber ? 'active' : '' ?>"
						onclick="changeActivityPage(<?= $i ?>)"><?= $i ?></button>
			<?php endfor; ?>
			<button class="pagination-btn" onclick="changeActivityPage(<?= $pageNumber + 1 ?>)" <?= $pageNumber >= $totalPages ? 'disabled' : '' ?>>›</button>
			<button class="pagination-btn" onclick="changeActivityPage(<?= $totalPages ?>)" <?= $pageNumber >= $totalPages ? 'disabled' : '' ?>>»</button>
		</div>
	</div>
	<?php endif; ?>
</div>

<style>
/* Activity feed */
.activity-feed { padding: 0; }

.activity-row {
	display: grid;
	grid-template-columns: 8px 1fr auto auto auto;
	gap: 0.75rem;
	align-items: center;
	padding: 0.625rem var(--spacing-lg);
	border-bottom: 1px solid var(--border);
	transition: background 0.12s ease;
}
.activity-row:last-child { border-bottom: none; }
.activity-row:hover { background: var(--bg-secondary); }

.activity-dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	flex-shrink: 0;
	justify-self: center;
}
.activity-dot--success  { background: var(--success); }
.activity-dot--danger   { background: var(--danger); }
.activity-dot--warning  { background: var(--warning); }
.activity-dot--accent   { background: var(--accent); }
.activity-dot--primary  { background: #3b82f6; }

.activity-main { min-width: 0; }
.activity-desc {
	font-size: 0.8125rem;
	font-weight: 500;
	color: var(--text-primary);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.activity-meta {
	display: flex;
	gap: 0.75rem;
	margin-top: 2px;
}
.activity-meta-item {
	display: flex;
	align-items: center;
	gap: 3px;
	font-size: 0.6875rem;
	color: var(--text-secondary);
}
.activity-badge { flex-shrink: 0; }
.activity-user  { font-size: 0.6875rem; color: var(--text-muted); min-width: 60px; text-align: right; }
.activity-time  { font-size: 0.6875rem; color: var(--text-muted); white-space: nowrap; min-width: 110px; text-align: right; }

/* Stat icon variants */
.stat-icon--success { background: rgba(39,174,96,.12); }
.stat-icon--success .icon { background-color: var(--success); }
</style>

<script>
function applyActivityFilters() {
	const url = new URL(window.location);
	url.searchParams.set('page', 'dashboard');
	const type = document.getElementById('activityTypeFilter').value;
	const date = document.getElementById('activityDateFilter').value;
	type === 'all' ? url.searchParams.delete('activity_type') : url.searchParams.set('activity_type', type);
	date           ? url.searchParams.set('activity_date', date) : url.searchParams.delete('activity_date');
	url.searchParams.delete('p');
	window.location.href = url.toString();
}
function clearActivityFilters() {
	const url = new URL(window.location);
	url.searchParams.set('page', 'dashboard');
	url.searchParams.delete('activity_type');
	url.searchParams.delete('activity_date');
	url.searchParams.delete('p');
	window.location.href = url.toString();
}
function changeActivityPage(page) {
	const url = new URL(window.location);
	url.searchParams.set('page', 'dashboard');
	url.searchParams.set('p', page);
	window.location.href = url.toString();
}
function showDownloadNotification(filename) {
	const n = document.createElement('div');
	n.className = 'download-notification';
	n.textContent = 'Скачан: ' + filename;
	const c = document.getElementById('notifications-container');
	if (c) c.appendChild(n);
	setTimeout(() => n.remove(), 4000);
}
function trackDownload(id, filename) { showDownloadNotification(filename); return true; }

// Живые часы
(function() {
	function pad(n) { return String(n).padStart(2, '0'); }
	function tick() {
		const d = new Date();
		const el = document.getElementById('liveClock');
		if (el) el.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes());
	}
	tick();
	setInterval(tick, 10000);
})();
</script>
