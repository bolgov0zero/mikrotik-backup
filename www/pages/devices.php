<div class="table-container">
	<div class="table-header">
		<h3>Устройства</h3>
		<button class="btn btn-primary" onclick="openModal('addDeviceModal')">
			<span class="icon icon-add"></span>
			Добавить устройство
		</button>
	</div>
	<div class="table-content">
		<?php while ($device = $devices->fetchArray(SQLITE3_ASSOC)): ?>
			<div class="table-row">
				<div>
					<div style="font-weight: 600; margin-bottom: 0.25rem;">
						<?= htmlspecialchars($device['name']) ?>
					</div>
					<?php if (!empty($device['model'])): ?>
						<div style="color: var(--accent); font-size: 0.75rem; margin-bottom: 0.25rem;">
							<strong>Модель:</strong> <?= htmlspecialchars($device['model']) ?>
						</div>
					<?php endif; ?>
					<div style="color: var(--text-secondary); font-size: 0.8125rem;">
						<?= htmlspecialchars($device['ip']) ?>:<?= $device['port'] ?>
					</div>
				</div>
				<div style="color: var(--text-secondary);"><?= htmlspecialchars($device['username']) ?></div>
				<div style="color: var(--text-muted);">••••••••</div>
				<div style="color: var(--text-secondary); font-size: 0.8125rem;">
					<?= formatDbDateTime($device['created_at']) ?>
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