<?php
use App\Core\Csrf;

$title = $title ?? 'Kitchen Display';
$flashes = consume_flash();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title>KDS · <?= e(\App\Core\Config::get('APP_NAME', 'Berkah POS')) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="kds-body">
<?= $content ?>
<script src="/assets/js/app.js"></script>
<?php if ($flashes): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  <?php foreach ($flashes as $f): ?>
    App.toast(<?= json_encode($f['message'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($f['type'] === 'error' ? 'error' : 'success') ?>);
  <?php endforeach; ?>
});
</script>
<?php endif; ?>
<?= $scripts ?? '' ?>
</body>
</html>
