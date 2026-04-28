<?php
$filterDeviceId = $_GET['device_id'] ?? 'all';
$filterType     = $_GET['type']      ?? 'all';
$filterDate     = $_GET['date']      ?? '';
$pageNumber     = max(1, intval($_GET['p'] ?? 1));
$perPage        = 15;
$offset         = ($pageNumber - 1) * $perPage;

$whereConditions = [];
$params          = [];
$paramTypes      = [];

if ($filterDeviceId !== 'all') {
	$whereConditions[] = 'b.device_id = ?';
	$params[]     = $filterDeviceId;
	$paramTypes[] = SQLITE3_INTEGER;
}
if ($filterType !== 'all') {
	$whereConditions[] = 'b.type = ?';
	$params[]     = $filterType;
	$paramTypes[] = SQLITE3_TEXT;
}
if ($filterDate) {
	$whereConditions[] = "DATE(b.created_at) = ?";
	$params[]     = $filterDate;
	$paramTypes[] = SQLITE3_TEXT;
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM backups b $whereClause");
foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v, $paramTypes[$i]);
$totalBackups = $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
$totalPages   = ceil($totalBackups / $perPage);

$stmt = $db->prepare("SELECT b.*, d.name as device_name FROM backups b LEFT JOIN devices d ON b.device_id = d.id $whereClause ORDER BY b.created_at DESC LIMIT $perPage OFFSET $offset");
foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v, $paramTypes[$i]);
$backups = $stmt->execute();

$allDevices = $db->query('SELECT * FROM devices ORDER BY name');

$hasFilters = ($filterDeviceId !== 'all' || $filterType !== 'all' || $filterDate);
?>

<!-- Toolbar -->
<div class="backups-toolbar">
	<div style="display:flex;align-items:center;gap:0.625rem;">
		<?php if ($hasFilters): ?>
			<span class="badge badge-primary">Фильтр активен</span>
		<?php endif; ?>
		<span style="font-size:0.75rem;color:var(--text-muted);"><?= $totalBackups ?> <?= $totalBackups === 1 ? 'бэкап' : ($totalBackups < 5 ? 'бэкапа' : 'бэкапов') ?></span>
	</div>
	<button class="btn btn-primary" onclick="createMassBackup()">
		<span class="icon icon-mass-backup"></span>
		Массовый бэкап
	</button>
</div>

<!-- Filters -->
<div class="backups-filters">
	<select id="deviceFilter" class="filter-select" onchange="applyFilters()" style="flex:1;min-width:140px;max-width:220px;">
		<option value="all" <?= $filterDeviceId === 'all' ? 'selected':'' ?>>Все устройства</option>
		<?php $allDevices->reset(); while ($d = $allDevices->fetchArray(SQLITE3_ASSOC)): ?>
			<option value="<?= $d['id'] ?>" <?= $filterDeviceId == $d['id'] ? 'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
		<?php endwhile; ?>
	</select>

	<div class="type-tabs">
		<button class="type-tab <?= $filterType==='all'    ? 'active':'' ?>" onclick="setTypeFilter('all')">Все</button>
		<button class="type-tab <?= $filterType==='full'   ? 'active':'' ?>" onclick="setTypeFilter('full')">Бинарные</button>
		<button class="type-tab <?= $filterType==='config' ? 'active':'' ?>" onclick="setTypeFilter('config')">Экспорт</button>
	</div>

	<input type="date" id="dateFilter" class="date-input" value="<?= htmlspecialchars($filterDate) ?>" onchange="applyFilters()">

	<?php if ($hasFilters): ?>
		<button class="btn btn-outline btn-sm" onclick="clearAllFilters()">Сбросить</button>
	<?php endif; ?>
</div>

<!-- List -->
<?php if ($totalBackups == 0): ?>
	<div class="empty-state" style="margin-top:3rem;">
		<h4><?= $hasFilters ? 'Бэкапы не найдены' : 'Бэкапов пока нет' ?></h4>
		<p><?= $hasFilters ? 'Попробуйте изменить фильтры' : 'Создайте первый бэкап на странице «Устройства»' ?></p>
	</div>
<?php else: ?>
<div class="backups-list">
	<?php while ($backup = $backups->fetchArray(SQLITE3_ASSOC)):
		$isFull  = $backup['type'] === 'full';
		$dir     = $isFull ? 'backup/bkp/' : 'backup/rsc/';
		$exists  = file_exists($dir . $backup['filename']);
	?>
	<div class="backup-row">

		<!-- Type icon -->
		<div class="backup-row__type backup-row__type--<?= $isFull ? 'full' : 'config' ?>"
			 title="<?= $isFull ? 'Бинарный бэкап' : 'Экспорт конфигурации' ?>">
			<span class="icon <?= $isFull ? 'icon-backup' : 'icon-backups' ?>"
				  style="width:14px;height:14px;<?= $isFull ? 'background-color:var(--accent)' : 'background-color:var(--warning)' ?>"></span>
		</div>

		<!-- Main info -->
		<div class="backup-row__main">
			<div class="backup-row__device"><?= htmlspecialchars($backup['device_name'] ?? '—') ?></div>
			<div class="backup-row__file"><?= htmlspecialchars($backup['filename']) ?></div>
		</div>

		<!-- Badges -->
		<div class="backup-row__badges">
			<span class="badge <?= $isFull ? 'badge-primary' : 'badge-warning' ?>">
				<?= $isFull ? 'Бинарный' : 'Экспорт' ?>
			</span>
			<?php if (!empty($backup['ros_version'])): ?>
				<span class="badge badge-success">ROS <?= htmlspecialchars($backup['ros_version']) ?></span>
			<?php endif; ?>
			<?php if (!$exists): ?>
				<span class="badge badge-danger">Файл удалён</span>
			<?php endif; ?>
		</div>

		<!-- Time -->
		<div class="backup-row__time"><?= formatDbDateTime($backup['created_at']) ?></div>

		<!-- Actions -->
		<div class="backup-row__actions">
			<?php if ($exists): ?>
				<?php if (!$isFull): ?>
					<button class="btn btn-outline btn-sm"
							onclick="viewBackupContent(<?= $backup['id'] ?>, '<?= htmlspecialchars($backup['filename'], ENT_QUOTES) ?>')"
							title="Просмотр">
						<span class="icon icon-view"></span>
					</button>
				<?php endif; ?>
				<a href="download_backup.php?id=<?= $backup['id'] ?>"
				   class="btn btn-outline btn-sm" title="Скачать"
				   onclick="trackDownload(<?= $backup['id'] ?>, '<?= htmlspecialchars($backup['filename'], ENT_QUOTES) ?>')">
					<span class="icon icon-download"></span>
				</a>
			<?php endif; ?>
			<button class="btn btn-danger btn-sm"
					onclick="deleteBackup(<?= $backup['id'] ?>, '<?= htmlspecialchars($backup['filename'], ENT_QUOTES) ?>')"
					title="Удалить">
				<span class="icon icon-delete"></span>
			</button>
		</div>

	</div>
	<?php endwhile; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="backups-pagination">
	<div style="font-size:0.6875rem;color:var(--text-muted);">
		<?= min($perPage, $totalBackups - $offset) ?> из <?= $totalBackups ?>
	</div>
	<div class="pagination-controls">
		<button class="pagination-btn" onclick="changePage(1)" <?= $pageNumber<=1 ? 'disabled':'' ?>>«</button>
		<button class="pagination-btn" onclick="changePage(<?= $pageNumber-1 ?>)" <?= $pageNumber<=1 ? 'disabled':'' ?>>‹</button>
		<?php for ($i = max(1,$pageNumber-2); $i <= min($totalPages,$pageNumber+2); $i++): ?>
			<button class="pagination-page <?= $i==$pageNumber?'active':'' ?>" onclick="changePage(<?= $i ?>)"><?= $i ?></button>
		<?php endfor; ?>
		<button class="pagination-btn" onclick="changePage(<?= $pageNumber+1 ?>)" <?= $pageNumber>=$totalPages ? 'disabled':'' ?>>›</button>
		<button class="pagination-btn" onclick="changePage(<?= $totalPages ?>)" <?= $pageNumber>=$totalPages ? 'disabled':'' ?>>»</button>
	</div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- View modal -->
<div id="viewBackupModal" class="modal">
	<div class="modal-content modal-view-content">
		<div class="modal-header">
			<h3>Просмотр конфигурации</h3>
			<button class="modal-close" onclick="closeModal('viewBackupModal')">×</button>
		</div>
		<div class="file-info">
			<div class="file-info-content">
				<div class="file-details">
					<div class="file-name" id="viewFileName"></div>
					<div class="file-size" id="viewFileSize"></div>
				</div>
				<div class="file-actions">
					<div class="search-container">
						<input type="text" id="backupSearch" class="search-input" placeholder="Поиск..."
							   oninput="performSearch()" onkeydown="handleSearchKeydown(event)">
						<div class="search-controls">
							<button class="btn btn-outline btn-xs search-nav-btn" onclick="navigateSearch(-1)">←</button>
							<span class="search-counter" id="searchCounter">0/0</span>
							<button class="btn btn-outline btn-xs search-nav-btn" onclick="navigateSearch(1)">→</button>
							<button class="btn btn-outline btn-xs search-close-btn" onclick="clearSearch()">×</button>
						</div>
					</div>
					<button class="btn btn-primary btn-sm" onclick="copyBackupContent()">
						<span class="icon icon-copy"></span>Копировать
					</button>
				</div>
			</div>
		</div>
		<div class="file-content-wrapper">
			<div class="file-content-container">
				<div class="line-numbers" id="lineNumbers"></div>
				<pre id="backupContent" class="file-content"></pre>
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-outline btn-sm" onclick="closeModal('viewBackupModal')">Закрыть</button>
		</div>
	</div>
</div>

<style>
.backups-toolbar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: var(--spacing-md);
}

.backups-filters {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
	margin-bottom: var(--spacing-md);
	padding: 0.625rem var(--spacing-md);
	background: var(--bg-card);
	border: 1px solid var(--border);
	border-radius: var(--radius);
}

.type-tabs {
	display: flex;
	background: var(--bg-secondary);
	border: 1px solid var(--border-light);
	border-radius: var(--radius-xs);
	overflow: hidden;
}
.type-tab {
	padding: 0.3125rem 0.75rem;
	font-size: 0.75rem;
	font-weight: 500;
	color: var(--text-secondary);
	background: transparent;
	border: none;
	cursor: pointer;
	transition: all 0.12s ease;
	white-space: nowrap;
}
.type-tab:hover { color: var(--text-primary); background: var(--bg-tertiary); }
.type-tab.active { background: var(--accent); color: white; }

/* Backups list */
.backups-list {
	background: var(--bg-card);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
}

.backup-row {
	display: grid;
	grid-template-columns: 36px 1fr auto auto auto;
	gap: 0.75rem;
	align-items: center;
	padding: 0.625rem var(--spacing-md);
	border-bottom: 1px solid var(--border);
	transition: background 0.12s ease;
}
.backup-row:last-child { border-bottom: none; }
.backup-row:hover { background: var(--bg-secondary); }

.backup-row__type {
	width: 28px;
	height: 28px;
	border-radius: var(--radius-xs);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.backup-row__type--full   { background: var(--accent-icon-bg); }
.backup-row__type--config { background: rgba(230,126,34,.12); }

.backup-row__main { min-width: 0; }
.backup-row__device {
	font-size: 0.8125rem;
	font-weight: 600;
	color: var(--text-primary);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.backup-row__file {
	font-size: 0.6875rem;
	color: var(--text-muted);
	margin-top: 1px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.backup-row__badges {
	display: flex;
	gap: 0.25rem;
	flex-shrink: 0;
}

.backup-row__time {
	font-size: 0.6875rem;
	color: var(--text-muted);
	white-space: nowrap;
	flex-shrink: 0;
	min-width: 110px;
	text-align: right;
}

.backup-row__actions {
	display: flex;
	gap: 0.25rem;
	flex-shrink: 0;
}

.backups-pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: var(--spacing-md);
	padding: 0.5rem 0;
}

@media (max-width: 768px) {
	.backup-row {
		grid-template-columns: 28px 1fr auto;
		grid-template-rows: auto auto;
	}
	.backup-row__badges { display: none; }
	.backup-row__time   { display: none; }
	.backup-row__actions { grid-column: 3; grid-row: 1 / 3; }
}
</style>

<script>
let _typeFilter = '<?= $filterType ?>';

function setTypeFilter(type) {
	_typeFilter = type;
	document.querySelectorAll('.type-tab').forEach(b => b.classList.remove('active'));
	event.target.classList.add('active');
	applyFilters();
}

function applyFilters() {
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	const dev  = document.getElementById('deviceFilter').value;
	const date = document.getElementById('dateFilter').value;
	dev  !== 'all' ? url.searchParams.set('device_id', dev)   : url.searchParams.delete('device_id');
	_typeFilter !== 'all' ? url.searchParams.set('type', _typeFilter) : url.searchParams.delete('type');
	date ? url.searchParams.set('date', date) : url.searchParams.delete('date');
	url.searchParams.delete('p');
	window.location.href = url.toString();
}

function clearAllFilters() {
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	['device_id','type','date','p'].forEach(k => url.searchParams.delete(k));
	window.location.href = url.toString();
}

function changePage(page) {
	const url = new URL(window.location);
	url.searchParams.set('page', 'backups');
	url.searchParams.set('p', page);
	window.location.href = url.toString();
}

function formatFileSize(bytes) {
	if (!bytes) return '0 B';
	const k = 1024, s = ['B','KB','MB','GB'];
	const i = Math.floor(Math.log(bytes) / Math.log(k));
	return (bytes / Math.pow(k,i)).toFixed(1) + ' ' + s[i];
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

// ── View backup ──────────────────────────────────────────────────────────────
let _searchMatches = [], _searchIdx = -1;

function viewBackupContent(backupId, filename) {
	document.getElementById('viewFileName').textContent = filename;
	document.getElementById('viewFileSize').textContent = 'Загрузка...';
	document.getElementById('backupContent').textContent = 'Загрузка...';
	clearSearch();
	openModal('viewBackupModal');

	fetch(`view_backup.php?id=${backupId}`)
		.then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
		.then(data => {
			if (data.success) {
				document.getElementById('viewFileSize').textContent = 'Размер: ' + formatFileSize(data.size);
				document.getElementById('backupContent').textContent = data.content;
			} else {
				document.getElementById('backupContent').textContent = 'Ошибка: ' + data.error;
			}
			updateLineNumbers();
			const content = document.getElementById('backupContent');
			const lines   = document.getElementById('lineNumbers');
			content.onscroll = () => { lines.scrollTop = content.scrollTop; };
		})
		.catch(err => {
			document.getElementById('backupContent').textContent = 'Ошибка: ' + err;
			updateLineNumbers();
		});
}

function copyBackupContent() {
	const text = document.getElementById('backupContent').textContent;
	navigator.clipboard.writeText(text).then(() => {
		const btn = event.currentTarget;
		const orig = btn.innerHTML;
		btn.innerHTML = '<span class="icon icon-check"></span>';
		btn.disabled = true;
		setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 1500);
	});
}

function updateLineNumbers() {
	const content = document.getElementById('backupContent').textContent;
	const count   = content.split('\n').length;
	document.getElementById('lineNumbers').innerHTML =
		Array.from({length: count}, (_, i) => `<div class="line-number">${i+1}</div>`).join('');
}

function performSearch() {
	const term = document.getElementById('backupSearch').value.trim();
	if (!term) { clearSearch(); return; }
	clearHighlights();
	const content = document.getElementById('backupContent').textContent;
	const lines   = content.split('\n');
	_searchMatches = [];
	lines.forEach((line, li) => {
		let p = 0, lower = line.toLowerCase(), t = term.toLowerCase();
		while ((p = lower.indexOf(t, p)) !== -1) {
			_searchMatches.push({line: li, start: p, end: p + term.length});
			p += term.length;
		}
	});
	if (_searchMatches.length) { highlightMatches(); _searchIdx = -1; navigateSearch(1); }
	else document.getElementById('searchCounter').textContent = '0/0';
}

function highlightMatches() {
	const content = document.getElementById('backupContent').textContent;
	const lines   = content.split('\n');
	const html    = lines.map((line, li) => {
		const matches = _searchMatches.filter(m => m.line === li).sort((a,b) => a.start - b.start);
		let out = '', last = 0;
		matches.forEach(m => {
			out += esc(line.slice(last, m.start));
			out += `<mark class="search-match">${esc(line.slice(m.start, m.end))}</mark>`;
			last = m.end;
		});
		return out + esc(line.slice(last));
	}).join('\n');
	document.getElementById('backupContent').innerHTML = html;
	updateLineNumbers();
}

function clearHighlights() {
	const el = document.getElementById('backupContent');
	el.textContent = el.textContent;
	updateLineNumbers();
}

function navigateSearch(dir) {
	if (!_searchMatches.length) return;
	document.querySelectorAll('.search-match').forEach(m => m.classList.remove('active'));
	_searchIdx = (_searchIdx + dir + _searchMatches.length) % _searchMatches.length;
	const marks = document.querySelectorAll('.search-match');
	if (marks[_searchIdx]) {
		marks[_searchIdx].classList.add('active');
		marks[_searchIdx].scrollIntoView({behavior:'smooth', block:'center'});
	}
	document.getElementById('searchCounter').textContent =
		(_searchIdx + 1) + '/' + _searchMatches.length;
}

function clearSearch() {
	document.getElementById('backupSearch').value = '';
	_searchMatches = []; _searchIdx = -1;
	document.getElementById('searchCounter').textContent = '0/0';
	clearHighlights();
}

function handleSearchKeydown(e) {
	if (e.key === 'Enter') { e.preventDefault(); navigateSearch(e.shiftKey ? -1 : 1); }
	if (e.key === 'Escape') { clearSearch(); e.target.blur(); }
}

function esc(t) {
	const d = document.createElement('div');
	d.textContent = t;
	return d.innerHTML;
}
</script>
