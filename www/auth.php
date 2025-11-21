<?php
// Запускаем сессию только если она еще не запущена
if (session_status() == PHP_SESSION_NONE) {
	session_start();
}
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = $_POST['username'] ?? '';
	$password = $_POST['password'] ?? '';
	
	$db = initDatabase();
	$stmt = $db->prepare('SELECT password FROM users WHERE username = ?');
	$stmt->bindValue(1, $username, SQLITE3_TEXT);
	$result = $stmt->execute();
	$user = $result->fetchArray(SQLITE3_ASSOC);
	
	if ($user && password_verify($password, $user['password'])) {
		$_SESSION['authenticated'] = true;
		$_SESSION['username'] = $username;
		header('Location: index.php');
		exit;
	} else {
		$error = 'Неверные учетные данные';
	}
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Авторизация • Система бэкапов</title>
	<link rel="stylesheet" href="style.css">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
</head>
<body class="auth-body">
	<div class="auth-container">
		<div class="auth-card">
			<h1>Система бэкапов</h1>
			<?php if ($error): ?>
				<div class="error"><?= htmlspecialchars($error) ?></div>
			<?php endif; ?>
			<form method="POST">
				<div class="form-group">
					<label>Логин</label>
					<input type="text" name="username" class="form-control" placeholder="Введите логин" required value="">
				</div>
				<div class="form-group">
					<label>Пароль</label>
					<input type="password" name="password" class="form-control" placeholder="Введите пароль" required value="">
				</div>
				<button type="submit" class="btn btn-primary" style="width: 100%;">Войти в систему</button>
			</form>
		</div>
	</div>
</body>
</html>