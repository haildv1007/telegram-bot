<?php
$page = 'partners';
$title = 'Đối tác xem báo cáo';
$breadcrumb = ['Cấu hình', 'Đối tác'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$allGroups = $db->query('SELECT id, name FROM channel_groups ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $passcode = trim($_POST['passcode'] ?? '');
        $selectedGroups = $_POST['group_ids'] ?? [];
        $groupIds = implode(',', array_map('intval', $selectedGroups));
        $active = isset($_POST['active']) ? 1 : 0;

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));

        if ($name === '' || $slug === '' || $passcode === '') {
            flash('error', 'Tên, slug và mã xác thực không được để trống');
            header('Location: partners.php');
            exit;
        }
        if (!preg_match('/^\d{4,6}$/', $passcode)) {
            flash('error', 'Mã xác thực phải là 4-6 chữ số');
            header('Location: partners.php');
            exit;
        }

        try {
            if ($id > 0) {
                $db->prepare('UPDATE partners SET name=?, slug=?, passcode=?, group_ids=?, active=? WHERE id=?')
                   ->execute([$name, $slug, $passcode, $groupIds, $active, $id]);
                flash('success', "Đã cập nhật đối tác \"$name\"");
            } else {
                $db->prepare('INSERT INTO partners (name, slug, passcode, group_ids, active) VALUES (?, ?, ?, ?, ?)')
                   ->execute([$name, $slug, $passcode, $groupIds, $active]);
                flash('success', "Đã tạo đối tác \"$name\"");
            }
        } catch (Exception $e) {
            flash('error', 'Lỗi: ' . $e->getMessage());
        }
        header('Location: partners.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM partners WHERE id = ?')->execute([$id]);
        flash('success', 'Đã xóa đối tác');
        header('Location: partners.php');
        exit;
    }
}

$partners = $db->query('SELECT * FROM partners ORDER BY name')->fetchAll();
$editPartner = null;
if (isset($_GET['edit'])) {
    $eq = $db->prepare('SELECT * FROM partners WHERE id = ?');
    $eq->execute([(int)$_GET['edit']]);
    $editPartner = $eq->fetch();
}

include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Đối tác xem báo cáo</h1>
    <div class="page-subtitle">Quản lý link báo cáo cho đối tác — mỗi đối tác nhập mã 6 số để xem</div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3><?= $editPartner ? 'Sửa đối tác' : 'Thêm đối tác mới' ?></h3>
  </div>
  <form method="POST" class="form-grid" style="padding:20px">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save">
    <?php if ($editPartner): ?>
      <input type="hidden" name="id" value="<?= $editPartner['id'] ?>">
    <?php endif; ?>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Tên đối tác</label>
        <input type="text" name="name" class="form-control" value="<?= h($editPartner['name'] ?? '') ?>" placeholder="Ví dụ: Xanh SM" required>
      </div>
      <div class="form-group">
        <label class="form-label">Slug (URL)</label>
        <input type="text" name="slug" class="form-control" value="<?= h($editPartner['slug'] ?? '') ?>" placeholder="xanhsm" pattern="[a-z0-9_-]+" required>
        <div class="text-muted" style="font-size:11px;margin-top:4px">URL: /partner/<strong>slug</strong></div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Mã xác thực (4-6 số)</label>
        <input type="text" name="passcode" class="form-control" value="<?= h($editPartner['passcode'] ?? '') ?>" placeholder="123456" pattern="\d{4,6}" maxlength="6" required>
      </div>
      <div class="form-group">
        <label class="form-label">Trạng thái</label>
        <label style="display:flex;align-items:center;gap:8px;margin-top:8px">
          <input type="checkbox" name="active" value="1" <?= (!$editPartner || $editPartner['active']) ? 'checked' : '' ?>>
          Kích hoạt
        </label>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Nhóm channel được xem</label>
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:8px">
        <?php
          $selectedIds = $editPartner ? array_map('intval', explode(',', $editPartner['group_ids'])) : [];
          foreach ($allGroups as $g):
        ?>
          <label style="display:flex;align-items:center;gap:6px">
            <input type="checkbox" name="group_ids[]" value="<?= $g['id'] ?>" <?= in_array($g['id'], $selectedIds) ? 'checked' : '' ?>>
            <?= h($g['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-actions" style="margin-top:16px">
      <button type="submit" class="btn btn-primary"><?= $editPartner ? 'Cập nhật' : 'Tạo đối tác' ?></button>
      <?php if ($editPartner): ?>
        <a href="partners.php" class="btn">Hủy</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if (!empty($partners)): ?>
<div class="card">
  <div class="card-header"><h3>Danh sách đối tác</h3></div>
  <table class="table">
    <thead>
      <tr>
        <th>Tên</th>
        <th>Link báo cáo</th>
        <th>Mã</th>
        <th>Nhóm channel</th>
        <th>Trạng thái</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($partners as $p):
        $pGroupIds = array_map('intval', array_filter(explode(',', $p['group_ids'])));
        $pGroupNames = [];
        foreach ($allGroups as $g) { if (in_array($g['id'], $pGroupIds)) $pGroupNames[] = $g['name']; }
      ?>
      <tr>
        <td><strong><?= h($p['name']) ?></strong></td>
        <td>
          <code class="mono" style="font-size:12px">/partner/<?= h($p['slug']) ?></code>
          <button type="button" class="btn btn-sm" onclick="navigator.clipboard.writeText(location.origin.replace('/admin','') + '/partner/<?= h($p['slug']) ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)" style="margin-left:8px">Copy</button>
        </td>
        <td class="mono"><?= h($p['passcode']) ?></td>
        <td><?= $pGroupNames ? implode(', ', array_map(fn($n) => '<span class="badge badge-accent">' . h($n) . '</span>', $pGroupNames)) : '<span class="text-dim">—</span>' ?></td>
        <td><?= $p['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Tắt</span>' ?></td>
        <td>
          <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm">Sửa</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Xóa đối tác này?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Xóa</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
