<?php
$deviceStatuses   = [];
$deviceLastBackup = [];

while ($device = $devices->fetchArray(SQLITE3_ASSOC)) {
	$id = $device['id'];
	$deviceStatuses[$id]   = hasRecentBackup($db, $id);
	$deviceLastBackup[$id] = getDeviceLastBackupTime($db, $id);
}
$devices->reset();
?>

<div class="devices-toolbar">
	<div class="devices-count">
		<?php if ($deviceCount > 0): ?>
			<span class="badge badge-primary"><?= $deviceCount ?> <?= $deviceCount === 1 ? 'устройство' : ($deviceCount < 5 ? 'устройства' : 'устройств') ?></span>
		<?php endif; ?>
	</div>
	<button class="btn btn-primary" onclick="openModal('addDeviceModal')">
		<span class="icon icon-add"></span>
		Добавить устройство
	</button>
</div>

<?php if ($deviceCount == 0): ?>
	<div class="empty-state" style="margin-top:3rem;">
		<div class="empty-state-icon">
			<span class="icon icon-devices" style="width:32px;height:32px;opacity:.3;"></span>
		</div>
		<h4>Устройства не добавлены</h4>
		<p>Добавьте первое устройство для начала работы</p>
		<button class="btn btn-primary" style="margin-top:1rem;" onclick="openModal('addDeviceModal')">
			<span class="icon icon-add"></span>
			Добавить устройство
		</button>
	</div>
<?php else: ?>
<div class="devices-grid">
	<?php while ($device = $devices->fetchArray(SQLITE3_ASSOC)):
		$id            = $device['id'];
		$hasBackup     = $deviceStatuses[$id];
		$lastBackup    = $deviceLastBackup[$id];
		$statusClass   = $hasBackup ? 'online' : 'offline';
		$statusLabel   = $hasBackup ? 'Бэкап OK' : ($lastBackup ? 'Устарел' : 'Нет бэкапа');
	?>
	<div class="device-card">

		<!-- Card header -->
		<div class="device-card__header">
			<div class="device-card__status-dot device-card__status-dot--<?= $statusClass ?>"></div>
			<div class="device-card__name"><?= htmlspecialchars($device['name']) ?></div>
			<div class="device-card__status-badge device-card__status-badge--<?= $statusClass ?>"><?= $statusLabel ?></div>
		</div>

		<!-- Model chip -->
		<?php if (!empty($device['model'])): ?>
		<div style="margin-bottom:0.625rem;">
			<span class="device-model-chip"><?= htmlspecialchars($device['model']) ?></span>
		</div>
		<?php endif; ?>

		<!-- Details -->
		<div class="device-card__details">
			<div class="device-card__detail">
				<span class="device-card__detail-label">Адрес</span>
				<span class="device-card__detail-value"><?= htmlspecialchars($device['ip']) ?>:<?= $device['port'] ?></span>
			</div>
			<div class="device-card__detail">
				<span class="device-card__detail-label">Пользователь</span>
				<span class="device-card__detail-value"><?= htmlspecialchars($device['username']) ?></span>
			</div>
			<div class="device-card__detail">
				<span class="device-card__detail-label">Последний бэкап</span>
				<span class="device-card__detail-value device-card__detail-value--<?= $hasBackup ? 'success' : 'danger' ?>">
					<?= $lastBackup ? formatDbDateTime($lastBackup) : 'Нет данных' ?>
				</span>
			</div>
			<div class="device-card__detail">
				<span class="device-card__detail-label">Добавлено</span>
				<span class="device-card__detail-value"><?= formatDbDateTime($device['created_at']) ?></span>
			</div>
		</div>

		<!-- Actions -->
		<div class="device-card__actions">
			<button class="btn btn-outline btn-sm" onclick="testConnection(<?= $id ?>)" title="Проверить подключение">
				<span class="icon icon-test"></span>
				Тест
			</button>
			<button class="btn btn-primary btn-sm" onclick="openBackupModal(<?= $id ?>)" title="Создать бэкап">
				<span class="icon icon-backup"></span>
				Бэкап
			</button>
			<div class="device-card__actions-right">
				<button class="btn btn-outline btn-sm" onclick="editDevice(<?= $id ?>)" title="Редактировать">
					<span class="icon icon-edit"></span>
				</button>
				<button class="btn btn-danger btn-sm" onclick="deleteDevice(<?= $id ?>, '<?= htmlspecialchars($device['name'], ENT_QUOTES) ?>')" title="Удалить">
					<span class="icon icon-delete"></span>
				</button>
			</div>
		</div>

	</div>
	<?php endwhile; ?>
</div>
<?php endif; ?>

<style>
.devices-toolbar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: var(--spacing-lg);
}
.devices-count { display: flex; align-items: center; gap: 0.5rem; }

.devices-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: var(--spacing-md);
}

.device-card {
	background: var(--bg-card);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--spacing-md) var(--spacing-lg);
	display: flex;
	flex-direction: column;
	gap: 0;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.device-card:hover {
	border-color: var(--border-light);
	box-shadow: var(--shadow);
}

.device-card__header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.625rem;
}
.device-card__status-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}
.device-card__status-dot--online  { background: var(--success); box-shadow: 0 0 0 2px rgba(39,174,96,.2); }
.device-card__status-dot--offline { background: var(--danger);  box-shadow: 0 0 0 2px rgba(231,76,60,.2); animation: pulse-dot 2s infinite; }

@keyframes pulse-dot {
	0%, 100% { box-shadow: 0 0 0 2px rgba(231,76,60,.2); }
	50%       { box-shadow: 0 0 0 4px rgba(231,76,60,.0); }
}

.device-card__name {
	font-size: 0.9375rem;
	font-weight: 700;
	color: var(--text-primary);
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.device-card__status-badge {
	font-size: 0.625rem;
	font-weight: 600;
	padding: 2px 7px;
	border-radius: 10px;
	flex-shrink: 0;
}
.device-card__status-badge--online  { background: var(--success-bg); color: var(--success); }
.device-card__status-badge--offline { background: var(--danger-bg);  color: var(--danger); }

.device-model-chip {
	display: inline-block;
	font-size: 0.6875rem;
	font-weight: 500;
	color: var(--text-secondary);
	background: var(--bg-tertiary);
	border: 1px solid var(--border-light);
	border-radius: var(--radius-xs);
	padding: 2px 8px;
}

.device-card__details {
	display: flex;
	flex-direction: column;
	gap: 0.3125rem;
	margin-bottom: var(--spacing-md);
	padding: 0.625rem 0;
	border-top: 1px solid var(--border);
	border-bottom: 1px solid var(--border);
}
.device-card__detail {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.5rem;
}
.device-card__detail-label {
	font-size: 0.6875rem;
	color: var(--text-muted);
	flex-shrink: 0;
}
.device-card__detail-value {
	font-size: 0.75rem;
	color: var(--text-secondary);
	font-weight: 500;
	text-align: right;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.device-card__detail-value--success { color: var(--success); }
.device-card__detail-value--danger  { color: var(--danger); }

.device-card__actions {
	display: flex;
	align-items: center;
	gap: 0.375rem;
}
.device-card__actions-right {
	display: flex;
	gap: 0.25rem;
	margin-left: auto;
}

/* Empty state icon */
.empty-state-icon {
	display: flex;
	justify-content: center;
	margin-bottom: 0.75rem;
}

@media (max-width: 640px) {
	.devices-grid { grid-template-columns: 1fr; }
}
</style>
