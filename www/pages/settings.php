<div class="settings-grid">
	<!-- Секция планировщика -->
	<div class="setting-section">
		<h3>🕐 Планировщик бэкапов</h3>
		<form method="POST">
			<input type="hidden" name="action" value="update_schedule">
			<div class="form-group">
				<label>Время автоматического бэкапа</label>
				<input type="time" name="backup_time" class="form-control" value="<?= htmlspecialchars($backupScheduleTime) ?>" required>
				<div style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.5rem;">
					Ежедневное автоматическое резервное копирование в указанное время
				</div>
			</div>
			<button type="submit" class="btn btn-primary">
				Сохранить расписание
			</button>
		</form>
	</div>

	<!-- Секция управления пользователями -->
	<div class="setting-section">
		<h3>👥 Управление пользователями</h3>
		
		<!-- Форма добавления пользователя -->
		<div class="subsection-title">Добавить пользователя</div>
		<form method="POST">
			<input type="hidden" name="action" value="add_user">
			<div class="form-group">
				<input type="text" name="username" class="form-control" placeholder="Логин пользователя" required>
			</div>
			<div class="form-group">
				<input type="password" name="password" class="form-control" placeholder="Пароль" required>
			</div>
			<button type="submit" class="btn btn-primary" style="width: 100%;">
				<span class="icon icon-add"></span>
				Добавить пользователя
			</button>
		</form>

		<!-- Список пользователей -->
		<div class="section-divider"></div>
		
		<div class="subsection-title">Существующие пользователи</div>
		<div class="users-list">
			<?php
			$users = $db->query('SELECT * FROM users ORDER BY username');
			$hasUsers = false;
			
			while ($user = $users->fetchArray(SQLITE3_ASSOC)):
				$hasUsers = true;
				$isCurrentUser = $user['username'] === $_SESSION['username'];
				$initial = strtoupper(mb_substr($user['username'], 0, 1));
			?>
				<div class="user-item <?= $isCurrentUser ? 'current-user' : '' ?>">
					<div class="user-info">
						<div class="user-avatar"><?= $initial ?></div>
						<div class="username">
							<?= htmlspecialchars($user['username']) ?>
							<?php if ($isCurrentUser): ?>
							<?php endif; ?>
						</div>
					</div>
					<?php if (!$isCurrentUser): ?>
						<button 
							type="button" 
							class="btn btn-danger btn-sm" 
							onclick="deleteUser('<?= htmlspecialchars($user['username']) ?>')"
							title="Удалить пользователя"
						>
							<span class="icon icon-delete"></span>
							Удалить
						</button>
					<?php else: ?>
						<div class="current-user-label">Текущий пользователь</div>
					<?php endif; ?>
				</div>
			<?php endwhile; ?>
			
			<?php if (!$hasUsers): ?>
				<div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
					Нет пользователей
				</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- Секция безопасности -->
	<div class="setting-section">
		<h3>🔐 Безопасность</h3>
		<form method="POST">
			<input type="hidden" name="action" value="change_password">
			<div class="form-group">
				<label>Новый пароль</label>
				<input type="password" name="new_password" class="form-control" placeholder="Введите новый пароль" required>
			</div>
			<button type="submit" class="btn btn-primary" style="width: 100%;">
				Сменить пароль
			</button>
		</form>
		
		<div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-primary); border-radius: var(--radius-sm); border: 1px solid var(--border-light);">
			<div style="font-size: 0.875rem; color: var(--text-secondary);">
				<strong>Текущий пользователь:</strong> <?= htmlspecialchars($_SESSION['username']) ?>
			</div>
		</div>
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