<div class="settings-grid">
	<div class="setting-section">
		<h3>Планировщик бэкапов</h3>
		<form method="POST">
			<input type="hidden" name="action" value="update_schedule">
			<div class="form-group">
				<label>Время автоматического бэкапа</label>
				<input type="time" name="backup_time" class="form-control" value="<?= htmlspecialchars($backupScheduleTime) ?>" required>
				<div style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.5rem;">
					Бэкап будет выполняться один раз в день в указанное время
				</div>
			</div>
			<button type="submit" class="btn btn-primary">Сохранить расписание</button>
		</form>
	</div>

	<div class="setting-section">
		<h3>Управление пользователями</h3>
		
		<div style="margin-bottom: 1.5rem;">
			<h4 style="margin-bottom: 1rem; font-size: 0.875rem; color: var(--text-primary);">Добавить пользователя</h4>
			<form method="POST">
				<input type="hidden" name="action" value="add_user">
				<div class="form-group">
					<label>Логин</label>
					<input type="text" name="username" class="form-control" placeholder="Введите логин" required>
				</div>
				<div class="form-group">
					<label>Пароль</label>
					<input type="password" name="password" class="form-control" placeholder="Введите пароль" required>
				</div>
				<button type="submit" class="btn btn-primary">Добавить пользователя</button>
			</form>
		</div>

		<div style="border-top: 1px solid var(--border-light); padding-top: 1.5rem;">
			<h4 style="margin-bottom: 1rem; font-size: 0.875rem; color: var(--text-primary);">Существующие пользователи</h4>
			<div class="users-list">
				<?php
				$users = $db->query('SELECT * FROM users ORDER BY username');
				$hasUsers = false;
				while ($user = $users->fetchArray(SQLITE3_ASSOC)):
					$hasUsers = true;
					// Не показываем текущего пользователя в списке для удаления
					if ($user['username'] === $_SESSION['username']) continue;
				?>
					<div class="user-item">
						<div class="user-info">
							<div class="username"><?= htmlspecialchars($user['username']) ?></div>
							<div class="user-meta">Создан: <?= formatDbDateTime($user['created_at'] ?? '') ?></div>
						</div>
						<button 
							type="button" 
							class="btn btn-danger btn-sm" 
							onclick="deleteUser('<?= htmlspecialchars($user['username']) ?>')"
							title="Удалить пользователя"
						>
							<span class="icon icon-delete"></span>
							Удалить
						</button>
					</div>
				<?php endwhile; ?>
				
				<?php if (!$hasUsers): ?>
					<div class="empty-state-compact">
						<p>Нет других пользователей</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="setting-section">
		<h3>Безопасность</h3>
		<form method="POST">
			<input type="hidden" name="action" value="change_password">
			<div class="form-group">
				<label>Новый пароль</label>
				<input type="password" name="new_password" class="form-control" placeholder="Введите новый пароль" required>
			</div>
			<button type="submit" class="btn btn-primary">Сменить пароль</button>
		</form>
	</div>
</div>

<script>
function deleteUser(username) {
	if (!confirm(`Удалить пользователя "${username}"?`)) return;
	
	const form = document.createElement('form');
	form.method = 'POST';
	form.style.display = 'none';
	
	const actionInput = document.createElement('input');
	actionInput.name = 'action';
	actionInput.value = 'delete_user';
	form.appendChild(actionInput);
	
	const usernameInput = document.createElement('input');
	usernameInput.name = 'username';
	usernameInput.value = username;
	form.appendChild(usernameInput);
	
	document.body.appendChild(form);
	form.submit();
}
</script>