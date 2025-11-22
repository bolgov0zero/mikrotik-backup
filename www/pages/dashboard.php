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
		<div class="stat-number"><?= date('H:i') ?></div>
		<div class="stat-label">Текущее время</div>
	</div>
</div>

<div class="table-container">
	<div class="table-header">
		<h3>Последние действия</h3>
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
							(strpos($activity['action_type'], 'delete') !== false ? 'badge-danger' : 'badge-primary')
						?>">
							<?= match($activity['action_type']) {
								'device_add' => 'Добавление',
								'device_delete' => 'Удаление',
								'backup_create' => 'Бэкап',
								'backup_delete' => 'Удаление',
								'connection_test' => 'Тест',
								'connection_error' => 'Ошибка',
								'password_change' => 'Безопасность',
								'user_add' => 'Добавление',
								'user_delete' => 'Удаление',
								'backup_error' => 'Ошибка',
								'scheduled_backup' => 'Автобэкап',
								'scheduled_backup_error' => 'Ошибка автобэкапа',
								'schedule_update' => 'Настройки',
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
				<h4>Активности пока нет</h4>
				<p>Здесь будут отображаться последние действия в системе</p>
			</div>
		<?php endif; ?>
	</div>
</div>