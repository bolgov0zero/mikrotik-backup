<div class="settings-grid">
	<div class="setting-section">
		<h3>Планировщик</h3>
		<form method="POST">
			<input type="hidden" name="action" value="update_schedule">
			<div class="form-group">
				<label>Время автоматического бэкапа</label>
				<input type="time" name="backup_time" class="form-control" value="<?= htmlspecialchars($backupScheduleTime) ?>" required>
				<div style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.5rem;">
					Бэкап будет выполняться один раз в день в указанное время (или в ближайшие 5 минут)
				</div>
			</div>
			<button type="submit" class="btn btn-primary">Сохранить расписание</button>
		</form>
		<div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-primary); border-radius: var(--radius-sm);">
			<h4 style="margin-bottom: 0.5rem; font-size: 0.875rem;">Информация о планировщике</h4>
			<p style="color: var(--text-secondary); font-size: 0.75rem; margin-bottom: 0.5rem;">
				Для автоматического запуска бэкапов настройте cron задачу (запуск раз в минуту):
			</p>
			<code style="background: var(--bg-secondary); padding: 0.5rem; border-radius: var(--radius-sm); font-size: 0.75rem; display: block;">
				* * * * * php /путь/к/вашему/проекту/scheduled_backup.php
			</code>
			<p style="color: var(--text-secondary); font-size: 0.75rem; margin-top: 0.5rem;">
				Система сама определит нужное время и выполнит бэкап только один раз в день
			</p>
		</div>
	</div>

	<div class="setting-section">
		<h3>Смена пароля</h3>
		<form method="POST">
			<input type="hidden" name="action" value="change_password">
			<div class="form-group">
				<label>Новый пароль</label>
				<input type="password" name="new_password" class="form-control" placeholder="Введите новый пароль" required>
			</div>
			<button type="submit" class="btn btn-primary">Сменить пароль</button>
		</form>
	</div>

	<div class="setting-section">
		<h3>Добавить пользователя</h3>
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
</div>