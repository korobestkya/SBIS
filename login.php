<?php
session_start();
$secrets = require __DIR__ . '/secrets.php';

$correctPassword = $secrets['ADMIN_PASS']; // Задай свой пароль

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['password'] === $correctPassword) {
        $_SESSION['auth'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = "Неверный пароль";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Вход</title>
</head>
<body>
  <h2>Авторизация</h2>
  <?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>
  <form method="post">
    <input type="password" name="password" placeholder="Введите пароль">
    <button type="submit">Войти</button>
  </form>
</body>
</html>
