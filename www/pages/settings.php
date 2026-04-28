<?php
$telegramSettings = getTelegramSettings($db);
$emailSettings    = getEmailSettings($db);
?>

<div class="settings-page">

	<!-- Автоматизация -->
	<div class="settings-section-group">
		<div class="settings-section-label">Автоматизация</div>
		<div class="settings-card">
			<div class="settings-row">
				<div class="settings-row-icon">
					<span class="icon icon-schedule"></span>
				</div>
				<div class="settings-row-text">
					<div class="settings-row-title">Расписание бэкапов</div>
					<div class="settings-row-desc">Ежедневное автоматическое копирование</div>
				</div>
				<div class="settings-row-control">
					<form method="POST" style="display:flex; align-items:center; gap:0.5rem;">
						<input type="hidden" name="action" value="update_schedule">
						<input type="time" name="backup_time" class="form-control"
							   value="<?= htmlspecialchars($backupScheduleTime) ?>"
							   required style="width:110px;">
						<button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
					</form>
				</div>
			</div>
		</div>
	</div>

	<!-- Уведомления -->
	<div class="settings-section-group">
		<div class="settings-section-label">Уведомления</div>
		<div class="settings-card">
			<form method="POST" id="telegramForm">
				<input type="hidden" name="action" value="save_telegram">

				<div class="settings-row">
					<div class="settings-row-icon">
						<span class="icon icon-telegram"></span>
					</div>
					<div class="settings-row-text">
						<div class="settings-row-title">Telegram уведомления</div>
						<div class="settings-row-desc">Оповещения о бэкапах и ошибках</div>
					</div>
					<div class="settings-row-control">
						<label class="toggle">
							<input type="checkbox" name="enabled" value="1"
								   <?= $telegramSettings['enabled'] ? 'checked' : '' ?>>
							<span class="toggle-slider"></span>
						</label>
					</div>
				</div>

				<div class="settings-form-area">
					<div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.75rem;">
						<div class="form-group" style="margin-bottom:0;">
							<label>Токен бота</label>
							<input type="password" name="bot_token" class="form-control"
								   value="<?= htmlspecialchars($telegramSettings['bot_token']) ?>"
								   placeholder="1234567890:ABC..."
								   id="botTokenInput"
								   onfocus="this.type='text'"
								   onblur="this.type='password'">
						</div>
						<div class="form-group" style="margin-bottom:0;">
							<label>ID чата</label>
							<input type="text" name="chat_id" class="form-control"
								   value="<?= htmlspecialchars($telegramSettings['chat_id']) ?>"
								   placeholder="-100123456789">
						</div>
					</div>
					<div style="display:flex; gap:0.5rem;">
						<button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
						<button type="button" class="btn btn-outline btn-sm" onclick="testTelegramConnection()">Проверить</button>
					</div>
				</div>
			</form>
		</div>

	<!-- Email -->
	<div class="settings-card">
		<form method="POST" id="emailForm">
			<input type="hidden" name="action" value="save_email">

			<div class="settings-row">
				<div class="settings-row-icon">
					<span class="icon icon-email"></span>
				</div>
				<div class="settings-row-text">
					<div class="settings-row-title">Email уведомления</div>
					<div class="settings-row-desc">Отправка отчётов через SMTP</div>
				</div>
				<div class="settings-row-control">
					<label class="toggle">
						<input type="checkbox" name="email_enabled" value="1"
							   <?= $emailSettings['enabled'] ? 'checked' : '' ?>>
						<span class="toggle-slider"></span>
					</label>
				</div>
			</div>

			<div class="settings-form-area">
				<div style="display:grid;grid-template-columns:1fr 120px 100px;gap:0.5rem;margin-bottom:0.625rem;">
					<div class="form-group" style="margin-bottom:0;">
						<label>SMTP хост</label>
						<input type="text" name="email_host" class="form-control"
							   value="<?= htmlspecialchars($emailSettings['host']) ?>"
							   placeholder="smtp.gmail.com">
					</div>
					<div class="form-group" style="margin-bottom:0;">
						<label>Порт</label>
						<input type="number" name="email_port" class="form-control"
							   value="<?= (int)$emailSettings['port'] ?: 587 ?>"
							   min="1" max="65535">
					</div>
					<div class="form-group" style="margin-bottom:0;">
						<label>Шифрование</label>
						<select name="email_encryption" class="form-control">
							<option value="tls"  <?= $emailSettings['encryption']==='tls'  ? 'selected':'' ?>>TLS</option>
							<option value="ssl"  <?= $emailSettings['encryption']==='ssl'  ? 'selected':'' ?>>SSL</option>
							<option value="none" <?= $emailSettings['encryption']==='none' ? 'selected':'' ?>>Нет</option>
						</select>
					</div>
				</div>

				<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.625rem;">
					<div class="form-group" style="margin-bottom:0;">
						<label>Логин SMTP</label>
						<input type="text" name="email_username" class="form-control"
							   value="<?= htmlspecialchars($emailSettings['username']) ?>"
							   placeholder="user@example.com">
					</div>
					<div class="form-group" style="margin-bottom:0;">
						<label>Пароль SMTP</label>
						<input type="password" name="email_password" class="form-control"
							   value="<?= htmlspecialchars($emailSettings['password']) ?>"
							   placeholder="••••••••"
							   onfocus="this.type='text'"
							   onblur="this.type='password'">
					</div>
				</div>

				<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.625rem;">
					<div class="form-group" style="margin-bottom:0;">
						<label>От кого (email)</label>
						<input type="email" name="email_from_email" class="form-control"
							   value="<?= htmlspecialchars($emailSettings['from_email']) ?>"
							   placeholder="backup@example.com">
					</div>
					<div class="form-group" style="margin-bottom:0;">
						<label>От кого (имя)</label>
						<input type="text" name="email_from_name" class="form-control"
							   value="<?= htmlspecialchars($emailSettings['from_name'] ?: 'MikroTik Backup') ?>"
							   placeholder="MikroTik Backup">
					</div>
				</div>

				<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.75rem;">
					<div class="form-group" style="margin-bottom:0;">
						<label>Кому (получатель)</label>
						<input type="email" name="email_to" class="form-control"
							   value="<?= htmlspecialchars($emailSettings['to_email']) ?>"
							   placeholder="admin@example.com">
					</div>
					<div class="form-group" style="margin-bottom:0;">
						<label>Тема письма</label>
						<input type="text" name="email_subject" class="form-control"
							   value="<?= htmlspecialchars($emailSettings['subject'] ?: 'MikroTik Backup Report') ?>"
							   placeholder="MikroTik Backup Report">
					</div>
				</div>

				<div style="display:flex;gap:0.5rem;">
					<button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
					<button type="button" class="btn btn-outline btn-sm" onclick="testEmailConnection()">Проверить</button>
				</div>
			</div>
		</form>
	</div>
	</div><!-- /settings-section-group Уведомления -->

	<!-- Пользователи -->
	<div class="settings-section-group">
		<div class="settings-section-label">Пользователи</div>
		<div class="settings-card">

			<!-- Список существующих пользователей -->
			<?php
			$users = $db->query('SELECT * FROM users ORDER BY username');
			while ($user = $users->fetchArray(SQLITE3_ASSOC)):
				$isCurrentUser = $user['username'] === $_SESSION['username'];
				$initial = strtoupper(mb_substr($user['username'], 0, 1));
			?>
			<div class="settings-row">
				<div class="user-avatar" style="width:32px;height:32px;font-size:0.75rem;flex-shrink:0;">
					<?= $initial ?>
				</div>
				<div class="settings-row-text">
					<div class="settings-row-title"><?= htmlspecialchars($user['username']) ?></div>
					<?php if ($isCurrentUser): ?>
						<div class="settings-row-desc">Текущая сессия</div>
					<?php endif; ?>
				</div>
				<div class="settings-row-control">
					<?php if (!$isCurrentUser): ?>
						<button type="button" class="btn btn-danger btn-sm"
								onclick="deleteUser('<?= htmlspecialchars($user['username']) ?>')">
							Удалить
						</button>
					<?php endif; ?>
				</div>
			</div>
			<?php endwhile; ?>

			<!-- Добавить пользователя -->
			<div class="settings-form-area">
				<div class="subsection-title">Добавить пользователя</div>
				<form method="POST">
					<input type="hidden" name="action" value="add_user">
					<div style="display:grid; grid-template-columns:1fr 1fr auto; gap:0.5rem; align-items:end;">
						<div class="form-group" style="margin-bottom:0;">
							<label>Логин</label>
							<input type="text" name="username" class="form-control" placeholder="username" required>
						</div>
						<div class="form-group" style="margin-bottom:0;">
							<label>Пароль</label>
							<input type="password" name="password" class="form-control" placeholder="••••••••" required>
						</div>
						<button type="submit" class="btn btn-primary btn-sm" style="height:32px;">
							<span class="icon icon-add"></span>
							Добавить
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Безопасность -->
	<div class="settings-section-group">
		<div class="settings-section-label">Безопасность</div>
		<div class="settings-card">
			<div class="settings-row">
				<div class="settings-row-icon">
					<span class="icon icon-security"></span>
				</div>
				<div class="settings-row-text">
					<div class="settings-row-title">Сменить пароль</div>
					<div class="settings-row-desc">Пользователь: <?= htmlspecialchars($_SESSION['username']) ?></div>
				</div>
			</div>
			<div class="settings-form-area">
				<form method="POST" style="display:flex; gap:0.5rem; align-items:end;">
					<input type="hidden" name="action" value="change_password">
					<div class="form-group" style="margin-bottom:0; flex:1;">
						<label>Новый пароль</label>
						<input type="password" name="new_password" class="form-control"
							   placeholder="Введите новый пароль" required>
					</div>
					<button type="submit" class="btn btn-primary btn-sm" style="height:32px;">Сменить</button>
				</form>
			</div>
		</div>
	</div>

</div>

<script>
function testTelegramConnection() {
	const form = document.getElementById('telegramForm');
	const formData = new FormData(form);

	const testForm = document.createElement('form');
	testForm.method = 'POST';
	testForm.style.display = 'none';

	[['action','test_telegram'],['bot_token',formData.get('bot_token')],['chat_id',formData.get('chat_id')]].forEach(([n,v]) => {
		const i = document.createElement('input');
		i.name = n; i.value = v;
		testForm.appendChild(i);
	});

	document.body.appendChild(testForm);
	testForm.submit();
}

function testEmailConnection() {
	const form = document.getElementById('emailForm');
	const formData = new FormData(form);

	const testForm = document.createElement('form');
	testForm.method = 'POST';
	testForm.style.display = 'none';

	const fields = [
		['action',           'test_email'],
		['email_host',       formData.get('email_host')],
		['email_port',       formData.get('email_port')],
		['email_encryption', formData.get('email_encryption')],
		['email_username',   formData.get('email_username')],
		['email_password',   formData.get('email_password')],
		['email_from_email', formData.get('email_from_email')],
		['email_from_name',  formData.get('email_from_name')],
		['email_to',         formData.get('email_to')],
		['email_subject',    formData.get('email_subject')],
	];

	fields.forEach(([n, v]) => {
		const i = document.createElement('input');
		i.name = n; i.value = v || '';
		testForm.appendChild(i);
	});

	document.body.appendChild(testForm);
	testForm.submit();
}

function deleteUser(username) {
	if (!confirm(`Удалить пользователя "${username}"?`)) return;

	const form = document.createElement('form');
	form.method = 'POST';
	form.style.display = 'none';

	[['action','delete_user'],['username',username]].forEach(([n,v]) => {
		const i = document.createElement('input');
		i.name = n; i.value = v;
		form.appendChild(i);
	});

	document.body.appendChild(form);
	form.submit();
}
</script>
