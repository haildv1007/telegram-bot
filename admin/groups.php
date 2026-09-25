<?php
$page = 'groups';
$title = 'Nhóm channel';
$breadcrumb = ['Cấu hình', 'Nhóm channel'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $reportBotId = (int)($_POST['report_bot_id'] ?? 0) ?: null;
        $reportChatId = trim($_POST['report_chat_id'] ?? '') ?: null;
        $digestSchedule = $_POST['digest_schedule'] ?? 'off';
        $digestTime = $_POST['digest_time'] ?? '07:00';
        $digestDay = in_array($digestSchedule, ['weekly', 'monthly']) ? (int)($_POST['digest_day'] ?? 1) : null;
        if ($name === '') {
            flash('error', 'Tên nhóm không được để trống');
            header('Location: groups.php');
            exit;
        }
        try {
            $db->beginTransaction();
            if ($id > 0) {
                $db->prepare('UPDATE channel_groups SET name=?, report_bot_id=?, report_chat_id=?, digest_schedule=?, digest_time=?, digest_day=? WHERE id=?')
                   ->execute([$name, $reportBotId, $reportChatId, $digestSchedule, $digestTime, $digestDay, $id]);
                $groupId = $id;
            } else {
                $db->prepare('INSERT INTO channel_groups (name, report_bot_id, report_chat_id, digest_schedule, digest_time, digest_day) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$name, $reportBotId, $reportChatId, $digestSchedule, $digestTime, $digestDay]);
                $groupId = (int)$db->lastInsertId();
            }

            // Cập nhật thành viên: chỉ áp dụng cho channel đang unassigned hoặc đang thuộc group này
            $memberIds = array_map('intval', $_POST['member_ids'] ?? []);
            $editable = $db->prepare('SELECT id FROM ad_channels WHERE group_id IS NULL OR group_id = ?');
            $editable->execute([$groupId]);
            $editableIds = array_map('intval', $editable->fetchAll(PDO::FETCH_COLUMN));

            foreach ($editableIds as $chId) {
                $shouldBeIn = in_array($chId, $memberIds, true);
                $db->prepare('UPDATE ad_channels SET group_id = ? WHERE id = ?')
                   ->execute([$shouldBeIn ? $groupId : null, $chId]);
            }

            $db->commit();
            flash('success', $id > 0 ? "Đã cập nhật nhóm #$groupId" : "Đã tạo nhóm #$groupId");
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Lỗi: ' . $e->getMessage());
        }
        header('Location: groups.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            // FK ON DELETE SET NULL sẽ tự gỡ group_id của channel con
            $db->prepare('DELETE FROM channel_groups WHERE id=?')->execute([$id]);
            flash('success', "Đã xóa nhóm #$id (các channel thành viên trở về độc lập)");
        } catch (Exception $e) {
            flash('error', 'Không xóa được: ' . $e->getMessage());
        }
        header('Location: groups.php');
        exit;
    }
}

$editing = null; $editingMemberIds = [];
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM channel_groups WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
    if ($editing) {
        $m = $db->prepare('SELECT id FROM ad_channels WHERE group_id = ?');
        $m->execute([$editing['id']]);
        $editingMemberIds = array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN));
    }
}
$isNew = isset($_GET['new']);
$showForm = $editing || $isNew;

// Channel khả dụng để add vào nhóm đang sửa: chưa thuộc nhóm nào, hoặc đang thuộc chính nhóm này
$groupIdForFilter = $editing['id'] ?? 0;
$editableChannels = $db->prepare('
  SELECT c.id, c.name, c.group_id, g.name AS current_group_name
  FROM ad_channels c
  LEFT JOIN channel_groups g ON g.id = c.group_id
  WHERE c.group_id IS NULL OR c.group_id = ?
  ORDER BY c.name
');
$editableChannels->execute([$groupIdForFilter]);
$editableChannels = $editableChannels->fetchAll();

// List nhóm + thành viên
$groups = $db->query('
  SELECT g.*,
    (SELECT COUNT(*) FROM ad_channels c WHERE c.group_id = g.id) AS member_count,
    (SELECT GROUP_CONCAT(c.name SEPARATOR ", ") FROM ad_channels c WHERE c.group_id = g.id) AS member_names,
    b.name AS report_bot_name
  FROM channel_groups g
  LEFT JOIN bots b ON b.id = g.report_bot_id
  ORDER BY g.name
')->fetchAll();

$ungroupedCount = (int) $db->query('SELECT COUNT(*) FROM ad_channels WHERE group_id IS NULL')->fetchColumn();
$reporterBots = $db->query("SELECT id, name FROM bots WHERE active=1 AND role IN ('reporter','both') ORDER BY name")->fetchAll();

include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Nhóm channel</h1>
    <div class="page-subtitle">Gộp nhiều channel (VD 2 domain cùng 1 sản phẩm) để tính lũy kế tháng chung. Channel vẫn báo cáo riêng hàng ngày, chỉ phần "Lũy kế tháng" được cộng gộp.</div>
  </div>
  <?php if (!$showForm): ?>
    <a href="?new=1" class="btn btn-primary">+ Tạo nhóm</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<div class="card">
  <div class="card-header">
    <h3><?= $editing ? 'Sửa nhóm #' . $editing['id'] : 'Tạo nhóm mới' ?></h3>
    <a href="groups.php" class="btn btn-sm">← Quay lại</a>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id'] ?? 0 ?>">

    <div class="form-group">
      <label class="form-label">Tên nhóm</label>
      <input type="text" name="name" class="form-control" required
             value="<?= h($editing['name'] ?? '') ?>" placeholder="VD: Xanh SM (gộp cả 2 domain)">
    </div>

    <h4 style="margin-top:20px;margin-bottom:12px;font-size:13px;text-transform:uppercase;letter-spacing:0.6px;color:var(--text-muted);">Gửi báo cáo lũy kế</h4>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Bot gửi</label>
        <select name="report_bot_id" class="form-select">
          <option value="">— Dùng bot default —</option>
          <?php foreach ($reporterBots as $b): ?>
            <option value="<?= $b['id'] ?>" <?= ($editing['report_bot_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Chat ID nhận báo cáo</label>
        <input type="text" name="report_chat_id" class="form-control mono"
               value="<?= h($editing['report_chat_id'] ?? '') ?>" placeholder="-1001234567890">
        <div class="form-help">Bắt buộc để nhóm này tự gửi digest. Bỏ trống = không gửi.</div>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Lịch gửi digest</label>
        <select name="digest_schedule" class="form-select" id="digestSchedule" onchange="toggleDigestDay()">
          <option value="off" <?= ($editing['digest_schedule'] ?? 'off') === 'off' ? 'selected' : '' ?>>Tắt</option>
          <option value="daily" <?= ($editing['digest_schedule'] ?? '') === 'daily' ? 'selected' : '' ?>>Hàng ngày</option>
          <option value="weekly" <?= ($editing['digest_schedule'] ?? '') === 'weekly' ? 'selected' : '' ?>>Hàng tuần</option>
          <option value="monthly" <?= ($editing['digest_schedule'] ?? '') === 'monthly' ? 'selected' : '' ?>>Đầu tháng</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Giờ gửi</label>
        <input type="time" name="digest_time" class="form-control" value="<?= h($editing['digest_time'] ?? '07:00') ?>">
      </div>
      <div class="form-group" id="digestDayGroup" style="display:none">
        <label class="form-label" id="digestDayLabel">Ngày</label>
        <select name="digest_day" class="form-select" id="digestDaySelect"></select>
      </div>
    </div>
    <script>
    function toggleDigestDay() {
      const s = document.getElementById('digestSchedule').value;
      const g = document.getElementById('digestDayGroup');
      const sel = document.getElementById('digestDaySelect');
      const lbl = document.getElementById('digestDayLabel');
      const cur = <?= (int)($editing['digest_day'] ?? 0) ?>;
      sel.innerHTML = '';
      if (s === 'weekly') {
        g.style.display = '';
        lbl.textContent = 'Thứ';
        ['Thứ 2','Thứ 3','Thứ 4','Thứ 5','Thứ 6','Thứ 7','Chủ nhật'].forEach((d,i) => {
          const o = document.createElement('option'); o.value = i+1; o.textContent = d;
          if (cur === i+1) o.selected = true;
          sel.appendChild(o);
        });
      } else if (s === 'monthly') {
        g.style.display = '';
        lbl.textContent = 'Ngày trong tháng';
        for (let i = 1; i <= 28; i++) {
          const o = document.createElement('option'); o.value = i; o.textContent = 'Ngày ' + i;
          if (cur === i) o.selected = true;
          sel.appendChild(o);
        }
      } else {
        g.style.display = 'none';
      }
    }
    toggleDigestDay();
    </script>

    <div class="form-group">
      <label class="form-label">Channel thành viên</label>
      <div style="background:var(--bg);border:1px solid var(--border-strong);border-radius:4px;padding:10px;max-height:280px;overflow-y:auto">
        <?php if (empty($editableChannels)): ?>
          <div class="text-muted">Không có channel nào khả dụng (tất cả đã thuộc nhóm khác).</div>
        <?php else: foreach ($editableChannels as $c): ?>
          <label class="form-check" style="padding:6px 0">
            <input type="checkbox" name="member_ids[]" value="<?= $c['id'] ?>"
                   <?= in_array((int)$c['id'], $editingMemberIds, true) ? 'checked' : '' ?>>
            <span>
              <?= h($c['name']) ?>
              <span class="text-muted mono" style="font-size:11px">#<?= $c['id'] ?></span>
            </span>
          </label>
        <?php endforeach; endif; ?>
      </div>
      <div class="form-help">
        Chỉ hiện channel chưa thuộc nhóm nào khác. Muốn chuyển channel từ nhóm khác sang đây,
        vào nhóm cũ gỡ ra trước.
      </div>
    </div>

    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Lưu thay đổi' : 'Tạo nhóm' ?></button>
      <a href="groups.php" class="btn">Hủy</a>
    </div>
  </form>
</div>
<?php else: ?>

<div class="card">
  <?php if (empty($groups)): ?>
    <p class="text-muted" style="text-align:center;padding:24px;">
      Chưa có nhóm nào. <?= $ungroupedCount ?> channel hiện đang đứng độc lập.
      <a href="?new=1">Tạo nhóm đầu tiên</a>.
    </p>
  <?php else: ?>
  <table class="table">
    <thead>
      <tr><th>#</th><th>Tên nhóm</th><th>Thành viên</th><th>Gửi báo cáo</th><th>Lịch digest</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($groups as $g): ?>
      <tr>
        <td class="mono text-dim">#<?= $g['id'] ?></td>
        <td><strong><?= h($g['name']) ?></strong></td>
        <td>
          <span class="badge badge-accent"><?= $g['member_count'] ?> channel</span>
          <div class="muted" style="font-size:12px;margin-top:4px"><?= h($g['member_names'] ?: '—') ?></div>
        </td>
        <td>
          <div><?= h($g['report_bot_name'] ?: '(default)') ?></div>
          <?php if ($g['report_chat_id']): ?>
            <div class="mono muted"><?= h($g['report_chat_id']) ?></div>
          <?php else: ?>
            <span class="badge badge-warn">Chưa cấu hình chat</span>
          <?php endif; ?>
        </td>
        <td>
          <?php
            $ds = $g['digest_schedule'] ?? 'off';
            $labels = ['off'=>'Tắt','daily'=>'Hàng ngày','weekly'=>'Hàng tuần','monthly'=>'Đầu tháng'];
            $dayNames = [1=>'T2',2=>'T3',3=>'T4',4=>'T5',5=>'T6',6=>'T7',7=>'CN'];
            if ($ds === 'off') {
              echo '<span class="badge badge-muted">Tắt</span>';
            } else {
              $t = substr($g['digest_time'] ?? '07:00', 0, 5);
              $extra = '';
              if ($ds === 'weekly') $extra = ' (' . ($dayNames[$g['digest_day']] ?? '') . ')';
              if ($ds === 'monthly') $extra = ' (ngày ' . ($g['digest_day'] ?? 1) . ')';
              echo '<span class="badge badge-success">' . h($labels[$ds]) . $extra . '</span>';
              echo '<div class="mono muted" style="font-size:12px;margin-top:2px">' . h($t) . '</div>';
            }
          ?>
        </td>
        <td class="text-right">
          <a href="?edit=<?= $g['id'] ?>" class="btn btn-sm">Sửa / Quản lý thành viên</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Xóa nhóm <?= h($g['name']) ?>? Các channel thành viên sẽ trở về độc lập (không bị xóa).')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $g['id'] ?>">
            <button class="btn btn-sm btn-danger">Xóa nhóm</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="text-muted mt-3" style="padding:0 4px"><?= $ungroupedCount ?> channel đang đứng độc lập (không thuộc nhóm nào).</div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
