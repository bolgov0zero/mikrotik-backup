<?php
// Получаем статусы для всех устройств
$deviceStatuses = [];
$deviceLastBackup = [];

while ($device = $devices->fetchArray(SQLITE3_ASSOC)) {
	$deviceId = $device['id'];
	$deviceStatuses[$deviceId] = hasRecentBackup($db, $deviceId);
	$deviceLastBackup[$deviceId] = getDeviceLastBackupTime($db, $deviceId);
}
$devices->reset();
?>

<div class="table-container">
	<div class="table-header">
		<h3>Устройства</h3>
		<button class="btn btn-primary" onclick="openModal('addDeviceModal')">
			<span class="icon icon-add"></span>
			Добавить устройство
		</button>
	</div>
	<div class="table-content">
		<?php while ($device = $devices->fetchArray(SQLITE3_ASSOC)): 
			$deviceId = $device['id'];
			$hasRecentBackup = $deviceStatuses[$deviceId];
			$lastBackup = $deviceLastBackup[$deviceId];
		?>
			<div class="table-row" style="grid-template-columns: 2fr 1fr auto;">
				<div>
					<div style="display: flex; align-items: center; gap: 8px; margin-bottom: -0.6rem;">
						<div class="backup-status-indicator <?= $hasRecentBackup ? 'success pulse' : 'error pulse' ?>"
							 title="<?= 
								 $hasRecentBackup 
								 ? '✓ Бэкап выполнен менее 24 часов назад'
								 : ($lastBackup 
									? '✗ Последний бэкап: ' . formatDbDateTime($lastBackup)
									: '✗ Бэкапы не найдены')
							 ?>">
						</div>
						<div style="font-weight: 600; margin-top: -25px;">
							<?= htmlspecialchars($device['name']) ?>
						</div>
					</div>
					<?php if (!empty($device['model'])): ?>
						<div style="color: var(--text-secondary); font-size: 0.8125rem; margin-bottom: 0.25rem;">
							<strong>Модель:</strong> <?= htmlspecialchars($device['model']) ?>
						</div>
					<?php endif; ?>
					<div style="color: var(--text-secondary); font-size: 0.8125rem; margin-bottom: 0.25rem;">
						<strong>Пользователь:</strong> <?= htmlspecialchars($device['username']) ?>
					</div>
					<div style="color: var(--text-secondary); font-size: 0.8125rem;">
						<strong>Адрес:</strong> <?= htmlspecialchars($device['ip']) ?>:<?= $device['port'] ?>
					</div>
					<?php if ($lastBackup): ?>
						<div style="color: <?= $hasRecentBackup ? 'var(--success)' : 'var(--danger)' ?>; 
							 font-size: 0.75rem; margin-top: 0.25rem; font-weight: 500;">
							<?= $hasRecentBackup ? '✓ ' : '✗ ' ?>
							<?= formatDbDateTime($lastBackup) ?>
						</div>
					<?php else: ?>
						<div style="color: var(--danger); font-size: 0.75rem; margin-top: 0.25rem; font-weight: 500;">
							✗ Бэкапы не найдены
						</div>
					<?php endif; ?>
				</div>
				<div style="color: var(--text-secondary); font-size: 0.8125rem; display: flex; align-items: center;">
					Добавлено: <?= formatDbDateTime($device['created_at']) ?>
				</div>
				<div class="actions">
					<button class="btn btn-outline btn-sm" onclick="testConnection(<?= $device['id'] ?>)">
						<span class="icon icon-test"></span>
						Тест
					</button>
					<button class="btn btn-outline btn-sm" onclick="openBackupModal(<?= $device['id'] ?>)">
						<span class="icon icon-backup"></span>
						Бэкап
					</button>
					<button class="btn btn-outline btn-sm" onclick="editDevice(<?= $device['id'] ?>)">
						<span class="icon icon-edit"></span>
						Редакт.
					</button>
					<button class="btn btn-danger btn-sm" onclick="deleteDevice(<?= $device['id'] ?>, '<?= htmlspecialchars($device['name']) ?>')">
						<span class="icon icon-delete"></span>
						Удалить
					</button>
				</div>
			</div>
		<?php endwhile; ?>
		
		<?php if ($deviceCount == 0): ?>
			<div class="empty-state">
				<h4>Устройства не добавлены</h4>
				<p>Добавьте первое устройство для начала работы</p>
			</div>
		<?php endif; ?>
	</div>
</div>

<style>
.backup-status-indicator {
	width: 6px;
	height: 6px;
	border-radius: 50%;
	display: inline-block;
	position: relative;
	padding: 5px !important;
}

.backup-status-indicator.success {
	background-color: #10b981; /* Зеленый */
}

.backup-status-indicator.error {
	background-color: #ef4444; /* Красный */
}

/* Анимация мигания для обоих статусов */
.backup-status-indicator.pulse {
	animation: pulse-status 2s infinite;
}

@keyframes pulse-status {
	0% {
		transform: scale(1);
		opacity: 1;
	}
	50% {
		transform: scale(1.2);
		opacity: 0.8;
	}
	100% {
		transform: scale(1);
		opacity: 1;
	}
}

/* Для красной точки более интенсивная анимация */
.backup-status-indicator.error.pulse {
	animation: pulse-error 1.5s infinite;
}

@keyframes pulse-error {
	0%, 100% {
		box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
		transform: scale(1);
	}
	70% {
		box-shadow: 0 0 0 6px rgba(239, 68, 68, 0);
		transform: scale(1.1);
	}
	100% {
		box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
		transform: scale(1);
	}
}

/* Индикатор при наведении */
.backup-status-indicator:hover::before {
	content: attr(title);
	position: absolute;
	background: var(--bg-secondary);
	color: var(--text-primary);
	padding: 0.5rem;
	border-radius: var(--radius-sm);
	font-size: 0.75rem;
	white-space: nowrap;
	z-index: 1000;
	top: -40px;
	left: -10px;
	border: 1px solid var(--border);
	box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
</style>