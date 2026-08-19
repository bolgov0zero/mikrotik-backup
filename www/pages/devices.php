<?php
$deviceStatuses   = [];
$deviceLastBackup = [];
$deviceRosVersion = [];

while ($device = $devices->fetchArray(SQLITE3_ASSOC)) {
	$id = $device['id'];
	$deviceStatuses[$id]   = hasRecentBackup($db, $id);
	$deviceLastBackup[$id] = getDeviceLastBackupTime($db, $id);
	$deviceRosVersion[$id] = getDeviceLastRosVersion($db, $id);
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
		$rosVersion    = $deviceRosVersion[$id] ?? null;
		$rosVersionClean = $rosVersion ? trim(preg_replace('/\s*\(.*\)\s*$/', '', $rosVersion)) : null;
		$statusClass   = $hasBackup ? 'ok' : 'warn';
		$statusLabel   = $hasBackup ? 'Бэкап ОК' : ($lastBackup ? 'Устарел' : 'Нет бэкапа');
		$modelText     = !empty($device['model']) ? $device['model'] : '—';
	?>
	<div class="device-card">

		<!-- Header: (dot + name + model) | badge -->
		<div class="device-card__header">
			<div class="device-card__header-left">
				<div class="device-card__title-row">
					<span class="device-card__dot device-card__dot--<?= $statusClass ?>"></span>
					<span class="device-card__name"><?= htmlspecialchars($device['name']) ?></span>
				</div>
				<span class="device-card__model" title="<?= htmlspecialchars($modelText, ENT_QUOTES) ?>"><?= htmlspecialchars($modelText) ?></span>
			</div>
			<span class="device-card__badge device-card__badge--<?= $statusClass ?>"><?= $statusLabel ?></span>
		</div>

		<!-- Chips: version, ip:port, username -->
		<div class="device-card__chips">
			<?php if ($rosVersionClean): ?>
				<span class="device-chip font-mono"><?= htmlspecialchars($rosVersionClean) ?></span>
			<?php endif; ?>
			<span class="device-chip font-mono"><?= htmlspecialchars($device['ip']) ?>:<?= $device['port'] ?></span>
			<span class="device-chip"><?= htmlspecialchars($device['username']) ?></span>
		</div>

		<!-- Last backup line -->
		<div class="device-card__last-backup">
			Последний бэкап
			<?php if ($lastBackup): ?>
				<span class="device-card__last-backup-date"><?= formatDbDateTime($lastBackup) ?></span>
			<?php else: ?>
				<span class="device-card__last-backup-date device-card__last-backup-date--none">нет данных</span>
			<?php endif; ?>
		</div>

		<!-- Actions -->
		<div class="device-card__actions">
			<button class="device-btn device-btn--icon" onclick="testConnection(<?= $id ?>)" title="Проверить подключение">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
			</button>
			<button class="device-btn device-btn--icon" onclick="openUpdateModal(<?= $id ?>, '<?= htmlspecialchars($device['name'], ENT_QUOTES) ?>')" title="Обновление RouterOS / RouterBoard">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
			</button>
			<button class="device-btn device-btn--primary" onclick="openBackupModal(<?= $id ?>)" title="Создать бэкап">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
				Бэкап
			</button>
			<button class="device-btn device-btn--icon" onclick="editDevice(<?= $id ?>)" title="Редактировать">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
			</button>
			<button class="device-btn device-btn--icon device-btn--icon-danger" onclick="deleteDevice(<?= $id ?>, '<?= htmlspecialchars($device['name'], ENT_QUOTES) ?>')" title="Удалить">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
			</button>
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
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: var(--spacing-md);
}

/* ===== Card ===== */
.device-card {
	background: var(--bg-card);
	border: 1px solid var(--border-light);
	border-radius: 16px;
	padding: 18px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.device-card:hover {
	border-color: #333;
	box-shadow: var(--shadow);
}

/* ===== Header ===== */
.device-card__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 10px;
}
.device-card__header-left {
	min-width: 0;
	flex: 1;
}
.device-card__title-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 3px;
}
.device-card__dot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	flex-shrink: 0;
}
.device-card__dot--ok   { background: var(--success); }
.device-card__dot--warn { background: var(--warning); }
.device-card__name {
	color: var(--text-primary);
	font-size: 15px;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}
.device-card__model {
	display: block;
	font-size: 11px;
	color: var(--text-secondary);
	max-width: 220px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	padding-left: 15px;
}

/* ===== Badge ===== */
.device-card__badge {
	font-size: 10.5px;
	font-weight: 500;
	padding: 4px 9px;
	border-radius: 20px;
	flex-shrink: 0;
	line-height: 1;
}
.device-card__badge--ok   { background: var(--success-bg); color: var(--success); }
.device-card__badge--warn { background: var(--warning-bg); color: var(--warning); }

/* ===== Chips ===== */
.device-card__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}
.device-chip {
	font-size: 10.5px;
	padding: 4px 8px;
	border-radius: 6px;
	background: var(--bg-tertiary);
	color: var(--text-secondary);
	line-height: 1.4;
}

/* ===== Last backup ===== */
.device-card__last-backup {
	font-size: 11px;
	color: var(--text-muted);
}
.device-card__last-backup-date {
	color: var(--success);
}
.device-card__last-backup-date--none {
	color: var(--warning);
}

/* ===== Actions ===== */
.device-card__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-top: 2px;
}
.device-btn {
	height: 30px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border-radius: 8px;
	border: 1px solid var(--border-light);
	background: transparent;
	color: var(--text-secondary);
	cursor: pointer;
	font-family: inherit;
	transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
	padding: 0;
}
.device-btn--icon {
	width: 30px;
	flex-shrink: 0;
}
.device-btn--icon:hover {
	border-color: #444;
	color: var(--text-primary);
}
.device-btn--icon-danger { color: var(--text-muted); }
.device-btn--icon-danger:hover {
	border-color: var(--danger);
	color: var(--danger);
}
.device-btn--primary {
	flex: 1;
	gap: 6px;
	background: var(--accent);
	border: none;
	color: #ffffff;
	font-size: 12px;
	font-weight: 500;
	padding: 0 12px;
}
.device-btn--primary:hover {
	background: var(--accent-hover);
	color: #ffffff;
}

/* Empty state */
.empty-state-icon {
	display: flex;
	justify-content: center;
	margin-bottom: 0.75rem;
}

@media (max-width: 640px) {
	.devices-grid { grid-template-columns: 1fr; }
}
</style>
