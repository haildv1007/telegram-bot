<?php
$page = 'unmatched';
$title = 'Lead chưa map';
$breadcrumb = ['Vận hành', 'Lead chưa map'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'resolve') {
        $id = (int)$_POST['id'];
        $db->prepare('UPDATE unmatched_leads SET resolved=1 WHERE id=?')->execute([$id]);
        flash('success', 'Đã đánh dấu xử lý');
    }
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare('DELETE FROM unmatched_leads WHERE id=?')->execute([$id]);
        flash('success', 'Đã xóa');
    }
    header('Location: unmatched.php');
    exit;
}

$showResolved = isset($_GET['all']);
$sql = 'SELECT u.*, b.name AS bot_name FROM unmatched_leads u LEFT JOIN bots b ON b.id=u.bot_id';
if (!$showResolved) $sql .= ' WHERE u.resolved=0';
$sql .= ' ORDER BY u.id DESC LIMIT 200';
$rows = $db->query($sql)->fetchAll();

include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Lead chưa map</h1>
    <div class="page-subtitle">Tin nhận về nhưng không khớp rule nào — cần tạo rule mới</div>
  </div>
  <a href="?<?= $showResolved ? '' : 'all=1' ?>" class="btn btn-sm"><?= $showResolved ? 'Chỉ chưa xử lý' : 'Xem cả đã xử lý' ?></a>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="text-muted" style="text-align:center;padding:24px;">Không có tin chưa map. 👍</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>#</th><th>Nhận lúc</th><th>Bot</th><th>Chat ID</th><th>Nội dung tin (rút gọn)</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="mono text-dim">#<?= $r['id'] ?></td>
          <td class="mono"><?= h(date('d/m H:i', strtotime($r['received_at']))) ?></td>
          <td><?= h($r['bot_name'] ?: '—') ?></td>
          <td class="mono"><?= h($r['chat_id']) ?></td>
          <td>
            <details>
              <summary style="cursor:pointer"><?= h(mb_substr($r['raw_text'], 0, 80)) ?>…</summary>
              <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:4px;margin-top:8px;font-size:12px;"><?= h($r['raw_text']) ?></pre>
            </details>
          </td>
          <td class="text-right">
            <a href="rules.php?new=1" class="btn btn-sm">Tạo rule</a>
            <?php if (!$r['resolved']): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="resolve">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-sm">✓ Xử lý</button>
            </form>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Xóa?')">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-sm btn-danger">Xóa</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/layout_end.php'; ?>
