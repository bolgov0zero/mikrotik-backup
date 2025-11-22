<div class="table-container">
	<div class="table-header">
		<h3>Бэкапы</h3>
		<div style="display: flex; align-items: center; gap: 1.5rem;">
			<div style="display: flex; align-items: center; gap: 1rem;">
				<select id="deviceFilter" onchange="filterBackups(this.value)" class="filter-select">
					<option value="all" <?= $filterDeviceId === 'all' ? 'selected' : '' ?>>Все устройства</option>
					<?php
					$devicesForFilter = $db->query('SELECT * FROM devices ORDER BY name');
					while ($device = $devicesForFilter->fetchArray(SQLITE3_ASSOC)):
					?>
						<option value="<?= $device['id'] ?>" <?= $filterDeviceId == $device['id'] ? 'selected' : '' ?>>
							<?= htmlspecialchars($device['name']) ?>
						</option>
					<?php endwhile; ?>
				</select>
				
				<div class="backup-filters">
					<span class="filter-label">Тип:</span>
					<div class="btn-group">
						<button type="button" class="btn btn-sm <?= $filterType === 'all' ? 'active' : '' ?>" onclick="changeTypeFilter('all')">Все</button>
						<button type="button" class="btn btn-sm <?= $filterType === 'full' ? 'active' : '' ?>" onclick="changeTypeFilter('full')">Бинарные</button>
						<button type="button" class="btn btn-sm <?= $filterType === 'config' ? 'active' : '' ?>" onclick="changeTypeFilter('config')">Экспорт</button>
					</div>
				</div>

				<!-- Фильтр по дате -->
				<div class="date-range-picker">
					<input type="date" id="startDate" class="date-input" placeholder="Начальная дата" 
						   value="<?= $_GET['start_date'] ?? '' ?>">
					<span style="color: var(--text-secondary);">—</span>
					<input type="date" id="endDate" class="date-input" placeholder="Конечная дата"
						   value="<?= $_GET['end_date'] ?? '' ?>">
					<button type="button" class="btn btn-primary btn-sm" onclick="applyDateFilter()">
						Применить
					</button>
					<button type="button" class="btn btn-outline btn-sm" onclick="clearDateFilter()">
						Сбросить
					</button>
				</div>
			</div>
			
			<button class="btn btn-primary" onclick="createMassBackup()">
				<span class="icon icon-mass-backup"></span>
				Массовый бэкап
			</button>
		</div>
	</div>
	
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
				<h4>Бэкапы не созданы</h4>
				<p>Создайте первый бэкап для устройства или запустите массовое резервное копирование</p>
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
function filterBackups(deviceId) {
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	if (deviceId === 'all') {
		url.searchParams.delete('device_id');
	} else {
		url.searchParams.set('device_id', deviceId);
	}
	url.searchParams.delete('p');
	window.location.href = url.toString();
}

function changeTypeFilter(type) {
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	if (type === 'all') {
		url.searchParams.delete('type');
	} else {
		url.searchParams.set('type', type);
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
</script>