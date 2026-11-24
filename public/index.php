<?php
// story: e03s01
declare(strict_types=1);

function canary_version(): string
{
    $path = dirname(__DIR__) . '/VERSION';
    if (is_readable($path)) {
        return trim((string) file_get_contents($path));
    }
    return 'dev';
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($uri === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'version' => canary_version()]);
    exit;
}
?>
<!doctype html>
<html lang="en">
  <head><meta charset="utf-8"><title>Appwrite Canaries — PHP</title></head>
  <body>
    <h1>Hello World</h1>
    <p>PHP canary on Appwrite Sites.</p>
    <section>
      <h2>Form slice</h2>
      <form method="get" action="">
        <label>Name <input name="name" type="text" /></label>
        <button type="submit">Send</button>
      </form>
      <?php if (!empty($_GET['name'])): ?>
        <p class="mono">Hello, <?= htmlspecialchars((string) $_GET['name'], ENT_QUOTES) ?></p>
      <?php endif; ?>
    </section>
    <footer class="app-footer">v<?= htmlspecialchars(canary_version(), ENT_QUOTES) ?></footer>
  </body>
</html>