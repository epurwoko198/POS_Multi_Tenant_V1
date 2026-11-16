<?php $title = $title ?? 'Nota'; ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="print-page">
<?= $content ?>
<script src="/assets/js/app.js"></script>
<?= $scripts ?? '' ?>
</body>
</html>
