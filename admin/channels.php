<?php
$page = 'channels';
$title = 'Channels báo cáo';
$breadcrumb = ['Cấu hình', 'Channels báo cáo'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

function normalize_campaigns($input): ?string {
    if (is_array($input)) {
        $ids = array_filter(array_map('trim', $input));
    } else {
        $ids = array_filter(array_map('trim', explode(',', (string)$input)));
    }
    $ids = array_filter($ids, fn($x) => preg_match('/^\d+$/', $x));
    return $ids ? implode(',', array_values($ids)) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $credIds = array_values(array_unique(array_filter(array_map('intval', $_POST['credential_ids'] ?? []))));
        $primaryCredId = $credIds[0] ?? null;
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'source_bot_id' => (int)($_POST['source_bot_id'] ?? 0) ?: null,
            'source_chat_id' => trim($_POST['source_chat_id'] ?? '') ?: null,
            'report_bot_id' => (int)($_POST['report_bot_id'] ?? 0) ?: null,
            'report_chat_id' => trim($_POST['report_chat_id'] ?? '') ?: null,
            'credential_id' => $primaryCredId,
            'platform_campaign_id' => normalize_campaigns($_POST['platform_campaign_id'] ?? ''),
            'report_scope' => empty($_POST['platform_campaign_id']) ? 'account' : 'campaign',
            'active' => isset($_POST['active']) ? 1 : 0,
        ];

        try {
            $db->beginTransaction();
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE ad_channels SET name=?, source_bot_id=?, source_chat_id=?, report_bot_id=?, report_chat_id=?, credential_id=?, platform_campaign_id=?, report_scope=?, active=? WHERE id=?');
                $stmt->execute([$data['name'], $data['source_bot_id'], $data['source_chat_id'], $data['report_bot_id'], $data['report_chat_id'], $data['credential_id'], $data['platform_campaign_id'], $data['report_scope'], $data['active'], $id]);
                $chId = $id;
            } else {
                $stmt = $db->prepare('INSERT INTO ad_channels (name, source_bot_id, source_chat_id, report_bot_id, report_chat_id, credential_id, platform_campaign_id, report_scope, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$data['name'], $data['source_bot_id'], $data['source_chat_id'], $data['report_bot_id'], $data['report_chat_id'], $data['credential_id'], $data['platform_campaign_id'], $data['report_scope'], $data['active']]);
                $chId = (int)$db->lastInsertId();
            }

            // channel_credentials — replace all
            $db->prepare('DELETE FROM channel_credentials WHERE channel_id=?')->execute([$chId]);
            $insCc = $db->prepare('INSERT INTO channel_credentials (channel_id, credential_id) VALUES (?, ?)');
            foreach ($credIds as $cid) $insCc->execute([$chId, $cid]);

            // Schedules: replace all
            $db->prepare('DELETE FROM channel_schedules WHERE channel_id=?')->execute([$chId]);
            $times = $_POST['sched_time'] ?? [];
            $types = $_POST['sched_type'] ?? [];
            $ins = $db->prepare('INSERT INTO channel_schedules (channel_id, report_time, report_type, active) VALUES (?, ?, ?, 1)');
            foreach ($times as $i => $t) {
                $t = trim($t);
                if ($t === '') continue;
                if (!preg_match('/^\d{1,2}:\d{2}$/', $t)) continue;
                $ins->execute([$chId, $t . ':00', $types[$i] ?? 'summary']);
            }

            // Rules: unlink old, link selected
            $db->prepare('UPDATE lead_rules SET channel_id = NULL WHERE channel_id = ?')->execute([$chId]);
            $ruleIds = array_map('intval', $_POST['rule_ids'] ?? []);
            if ($ruleIds) {
                $ph = implode(',', array_fill(0, count($ruleIds), '?'));
                $db->prepare("UPDATE lead_rules SET channel_id = ? WHERE id IN ($ph)")->execute(array_merge([$chId], $ruleIds));
            }

            $db->commit();
            flash('success', $id > 0 ? "Đã cập nhật channel #$id" : "Đã tạo channel #$chId");
        } catch (Exception $e) {
            $db->rollBack();
            flash('error', 'Lỗi: ' . $e->getMessage());
        }
        header('Location: channels.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare('DELETE FROM ad_channels WHERE id=?')->execute([$id]);
            flash('success', "Đã xóa channel #$id");
        } catch (Exception $e) {
            flash('error', 'Không xóa được: ' . $e->getMessage());
        }
        header('Location: channels.php');
        exit;
    }
}

$editing = null; $schedules = []; $selectedCampsMeta = []; $editingCredIds = []; $editingRules = [];
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT c.*, g.name AS group_name FROM ad_channels c LEFT JOIN channel_groups g ON g.id = c.group_id WHERE c.id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
    if ($editing) {
        $s = $db->prepare('SELECT * FROM channel_schedules WHERE channel_id=? ORDER BY report_time');
        $s->execute([$editing['id']]);
        $schedules = $s->fetchAll();

        // Credentials linked
        $q = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id=?');
        $q->execute([$editing['id']]);
        $editingCredIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

        // Rules cho channel này
        $r = $db->prepare('SELECT * FROM lead_rules WHERE channel_id=? ORDER BY priority DESC, id');
        $r->execute([$editing['id']]);
        $editingRules = $r->fetchAll();

        // Load tên camp đã chọn từ cache (mọi credential linked)
        if (!empty($editing['platform_campaign_id']) && !empty($editingCredIds)) {
            $ids = array_filter(array_map('trim', explode(',', $editing['platform_campaign_id'])));
            if ($ids) {
                $phC = implode(',', array_fill(0, count($editingCredIds), '?'));
                $phI = implode(',', array_fill(0, count($ids), '?'));
                $q = $db->prepare("SELECT platform_campaign_id AS id, name, status FROM campaigns
                                   WHERE credential_id IN ($phC) AND platform_campaign_id IN ($phI)");
                $q->execute(array_merge($editingCredIds, array_values($ids)));
                $selectedCampsMeta = $q->fetchAll();
            }
        }
    }
}
$isNew = isset($_GET['new']);
$showForm = $editing || $isNew;

$bots = $db->query('SELECT id, name, role FROM bots WHERE active=1 ORDER BY name')->fetchAll();
$creds = $db->query('SELECT id, platform, account_id, account_label FROM ads_credentials WHERE active=1 ORDER BY platform, id')->fetchAll();

$channels = $db->query("
  SELECT c.*,
    (SELECT GROUP_CONCAT(TIME_FORMAT(report_time, '%H:%i') ORDER BY report_time) FROM channel_schedules s WHERE s.channel_id=c.id AND s.active=1) AS sched,
    (SELECT GROUP_CONCAT(cr.account_label ORDER BY cr.id SEPARATOR ' + ')
       FROM channel_credentials cc JOIN ads_credentials cr ON cr.id = cc.credential_id
       WHERE cc.channel_id = c.id) AS cred_labels,
    (SELECT GROUP_CONCAT(DISTINCT cr.platform) FROM channel_credentials cc JOIN ads_credentials cr ON cr.id = cc.credential_id WHERE cc.channel_id = c.id) AS cred_platforms,
    (SELECT COUNT(*) FROM channel_credentials cc WHERE cc.channel_id = c.id) AS cred_count,
    (SELECT COUNT(*) FROM lead_rules lr WHERE lr.channel_id = c.id AND lr.active = 1) AS rule_count,
    br.name AS report_bot_name,
    bs.name AS source_bot_name,
    grp.name AS group_name
  FROM ad_channels c
  LEFT JOIN bots br ON br.id = c.report_bot_id
  LEFT JOIN bots bs ON bs.id = c.source_bot_id
  LEFT JOIN channel_groups grp ON grp.id = c.group_id
  ORDER BY COALESCE(grp.name, ''), c.id
")->fetchAll();

include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Channels báo cáo</h1>
    <div class="page-subtitle">Mỗi channel = 1 đơn vị báo cáo (đọc từ nhóm nào, gửi vào nhóm nào, tính spend từ camp/account nào)</div>
  </div>
  <?php if (!$showForm): ?>
    <a href="groups.php" class="btn">📦 Quản lý nhóm</a>
    <a href="?new=1" class="btn btn-primary">+ Thêm channel</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<div class="card">
  <div class="card-header">
    <h3><?= $editing ? 'Sửa channel #' . $editing['id'] : 'Tạo channel mới' ?></h3>
    <a href="channels.php" class="btn btn-sm">← Quay lại</a>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id'] ?? 0 ?>">

    <div class="form-group">
      <label class="form-label">Tên channel</label>
      <input type="text" name="name" class="form-control" required
             value="<?= h($editing['name'] ?? '') ?>" placeholder="VD: SPF Shipfood - Google Camp A">
    </div>

    <?php if ($editing): ?>
    <div class="form-group">
      <label class="form-label">Nhóm (gộp lũy kế tháng)</label>
      <div>
        <?php if (!empty($editing['group_name'])): ?>
          <span class="badge badge-accent"><?= h($editing['group_name']) ?></span>
        <?php else: ?>
          <span class="text-dim">Đứng độc lập, chưa thuộc nhóm nào</span>
        <?php endif; ?>
        <a href="groups.php" class="btn btn-sm" style="margin-left:8px">Quản lý nhóm</a>
      </div>
      <div class="form-help">Gán/gỡ channel khỏi nhóm được quản lý tập trung ở trang Nhóm channel.</div>
    </div>
    <?php endif; ?>

    <h4 style="margin-top:24px;margin-bottom:12px;font-size:13px;text-transform:uppercase;letter-spacing:0.6px;color:var(--text-muted);">Nguồn đọc lead</h4>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Bot đọc</label>
        <select name="source_bot_id" class="form-select">
          <option value="">— Chưa gán —</option>
          <?php foreach ($bots as $b): if (!in_array($b['role'], ['reader','both'])) continue; ?>
            <option value="<?= $b['id'] ?>" <?= ($editing['source_bot_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Chat ID nguồn</label>
        <input type="text" name="source_chat_id" class="form-control mono"
               value="<?= h($editing['source_chat_id'] ?? '') ?>" placeholder="-1001234567890">
      </div>
    </div>

    <h4 style="margin-top:24px;margin-bottom:12px;font-size:13px;text-transform:uppercase;letter-spacing:0.6px;color:var(--text-muted);">Nơi gửi báo cáo</h4>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Bot gửi báo cáo</label>
        <select name="report_bot_id" class="form-select">
          <option value="">— Dùng bot default —</option>
          <?php foreach ($bots as $b): if (!in_array($b['role'], ['reporter','both'])) continue; ?>
            <option value="<?= $b['id'] ?>" <?= ($editing['report_bot_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Chat ID đích</label>
        <input type="text" name="report_chat_id" class="form-control mono"
               value="<?= h($editing['report_chat_id'] ?? '') ?>" placeholder="-1001234567890">
      </div>
    </div>

    <h4 style="margin-top:24px;margin-bottom:12px;font-size:13px;text-transform:uppercase;letter-spacing:0.6px;color:var(--text-muted);">Nguồn spend (Ads)</h4>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Tài khoản Ads (tick nhiều để gộp)</label>
        <div style="background:var(--bg);border:1px solid var(--border-strong);border-radius:4px;padding:10px;max-height:180px;overflow-y:auto">
          <?php if (empty($creds)): ?>
            <div class="text-muted">Chưa có tài khoản nào. <a href="credentials.php?new=1">Thêm tài khoản Ads</a>.</div>
          <?php else: foreach ($creds as $c): ?>
            <label class="form-check" style="padding:5px 0">
              <input type="checkbox" name="credential_ids[]" value="<?= $c['id'] ?>"
                     <?= in_array((int)$c['id'], $editingCredIds, true) ? 'checked' : '' ?>
                     onchange="onCredChange()">
              <span>
                <span class="badge <?= $c['platform']==='google'?'badge-accent':'badge-warn' ?>"><?= strtoupper(substr($c['platform'],0,2)) ?></span>
                <?= h($c['account_label'] ?: $c['account_id']) ?>
                <span class="text-muted mono" style="font-size:11px">#<?= $c['id'] ?></span>
              </span>
            </label>
          <?php endforeach; endif; ?>
        </div>
        <div class="form-help">Để trống = không lấy spend. Tick ≥1 = gộp spend từ các tài khoản đã tick.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Campaigns</label>
        <div class="chip-input">
          <div id="chipBox" class="chip-box" onclick="openDropdown(event)">
            <span id="chipPlaceholder" class="chip-placeholder">— Cả tài khoản —</span>
          </div>
          <div id="chipDropdown" class="chip-dropdown" style="display:none"></div>
        </div>
        <div class="d-flex gap-2 mt-2">
          <button type="button" class="btn btn-sm" onclick="loadCampaigns(true)">Đồng bộ camps</button>
          <button type="button" class="btn btn-sm" onclick="clearAllChips()">Bỏ chọn tất cả</button>
        </div>
        <div class="form-help">Không chọn = báo cáo cả tài khoản. Bấm "Đồng bộ camps" để load danh sách rồi tick chọn.</div>
      </div>
    </div>

    <script>
    // ==== Campaign chip picker ====
    let CAMPS = <?= json_encode(array_map(fn($c) => ['id'=>(string)$c['id'], 'name'=>$c['name'] ?: ('#'.$c['id']), 'status'=>$c['status']], $selectedCampsMeta), JSON_UNESCAPED_UNICODE) ?>;
    const initial = <?= json_encode(!empty($editing['platform_campaign_id']) ? array_values(array_filter(explode(',', $editing['platform_campaign_id']))) : []) ?>;
    let SELECTED = new Set(initial.map(String));

    // Fallback: id chưa có trong cache
    initial.forEach(id => { if (!CAMPS.find(c=>String(c.id)===String(id))) CAMPS.push({id:String(id), name:'#'+id, status:''}); });

    function renderChips() {
      const box = document.getElementById('chipBox');
      const placeholder = document.getElementById('chipPlaceholder');
      // Xóa hết chip cũ + hidden inputs
      Array.from(box.querySelectorAll('.chip, input[name="platform_campaign_id[]"]')).forEach(n => n.remove());
      if (SELECTED.size === 0) {
        placeholder.style.display = '';
        return;
      }
      placeholder.style.display = 'none';
      for (const id of SELECTED) {
        const c = CAMPS.find(x => String(x.id) === String(id)) || {id, name:'#'+id};
        const chip = document.createElement('span');
        chip.className = 'chip';
        chip.innerHTML = '<span class="chip-name">' + escapeHtml(c.name) + '</span>' +
          '<button type="button" class="chip-x" onclick="removeChip(\'' + id + '\', event)">×</button>';
        box.appendChild(chip);
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'platform_campaign_id[]';
        inp.value = id;
        box.appendChild(inp);
      }
    }

    function removeChip(id, e) {
      e && e.stopPropagation();
      SELECTED.delete(String(id));
      renderChips();
      renderDropdown();
    }

    function clearAllChips() { SELECTED.clear(); renderChips(); renderDropdown(); }

    function getCheckedCredIds() {
      return Array.from(document.querySelectorAll('input[name="credential_ids[]"]:checked')).map(i => i.value);
    }
    function onCredChange() {
      // Khi đổi credential list — nếu dropdown đang mở thì reload
      const dd = document.getElementById('chipDropdown');
      if (dd.style.display === 'block') loadCampaigns(true);
    }

    async function loadCampaigns(force) {
      const cids = getCheckedCredIds();
      if (cids.length === 0) { alert('Tick ít nhất 1 Tài khoản Ads trước'); return; }
      const dd = document.getElementById('chipDropdown');
      dd.style.display = 'block';
      dd.innerHTML = '<div style="padding:12px" class="text-muted">Đang tải…</div>';
      const r = await fetch('api/list_campaigns.php?credential_ids=' + cids.join(','), { credentials:'same-origin' });
      const d = await r.json();
      if (!d.ok) { dd.innerHTML = '<div style="padding:12px" class="text-muted">Lỗi: ' + escapeHtml(d.error) + '</div>'; return; }
      CAMPS = (d.campaigns || []).map(c => ({id:String(c.id), name:c.name, status:c.status||'', acc:c.account_label||''}));
      // Merge selected còn không có trong list (đã xóa/paused sâu)
      for (const id of SELECTED) {
        if (!CAMPS.find(c => c.id === String(id))) CAMPS.push({id:String(id), name:'#'+id+' (không còn ENABLED/PAUSED)', status:''});
      }
      renderDropdown();
    }

    function renderDropdown() {
      const dd = document.getElementById('chipDropdown');
      if (dd.style.display === 'none') return;
      let html = '<div class="chip-search"><input type="text" id="chipSearch" placeholder="Tìm camp…" oninput="renderDropdown()" autofocus></div>';
      const q = (document.getElementById('chipSearch')?.value || '').toLowerCase();
      const list = CAMPS.filter(c => !q || c.name.toLowerCase().includes(q) || c.id.includes(q));
      if (!list.length) html += '<div style="padding:12px" class="text-muted">Không có camp nào.</div>';
      for (const c of list) {
        const isSel = SELECTED.has(String(c.id));
        const status = c.status ? '<span class="badge ' + (c.status==='ENABLED'?'badge-success':'badge-muted') + '" style="margin-right:6px">' + escapeHtml(c.status) + '</span>' : '';
        const acc = c.acc ? '<span class="text-muted" style="font-size:11px; margin-left:6px">(' + escapeHtml(c.acc) + ')</span>' : '';
        html += '<div class="chip-option' + (isSel?' selected':'') + '" onclick="toggleCamp(\'' + c.id + '\')">' +
          '<div class="name">' + status + escapeHtml(c.name) + acc + ' <span class="text-muted mono" style="font-size:11px">#' + c.id + '</span></div>' +
          '</div>';
      }
      // Preserve search value across renders
      const oldVal = document.getElementById('chipSearch')?.value || '';
      dd.innerHTML = html;
      if (oldVal) document.getElementById('chipSearch').value = oldVal;
    }

    function toggleCamp(id) {
      id = String(id);
      if (SELECTED.has(id)) SELECTED.delete(id);
      else SELECTED.add(id);
      renderChips();
      renderDropdown();
    }

    function openDropdown(e) {
      e && e.stopPropagation();
      if (CAMPS.length === 0) { loadCampaigns(); return; }
      const dd = document.getElementById('chipDropdown');
      dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
      if (dd.style.display === 'block') renderDropdown();
    }

    // Close on outside click
    document.addEventListener('click', (e) => {
      const wrap = document.querySelector('.chip-input');
      if (wrap && !wrap.contains(e.target)) {
        document.getElementById('chipDropdown').style.display = 'none';
      }
    });

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    renderChips();
    </script>

    <?php
    $allRules = $db->query('SELECT id, pattern, match_type, channel_id FROM lead_rules WHERE active = 1 ORDER BY id')->fetchAll();
    $editingRuleIds = array_column($editingRules, 'id');
    ?>
    <h4 style="margin-top:24px;margin-bottom:12px;font-size:13px;text-transform:uppercase;letter-spacing:0.6px;color:var(--text-muted);">Rule parse lead</h4>
    <div class="form-help" style="margin-bottom:10px">Chọn rule đã tạo sẵn để gắn vào channel này. <a href="rules.php?new=1">Tạo rule mới</a>.</div>
    <div style="background:var(--bg);border:1px solid var(--border-strong);border-radius:4px;padding:10px;max-height:220px;overflow-y:auto">
      <?php if (empty($allRules)): ?>
        <div class="text-muted">Chưa có rule nào. <a href="rules.php?new=1">Tạo rule đầu tiên</a>.</div>
      <?php else: foreach ($allRules as $rl):
        $taken = $rl['channel_id'] && !in_array((int)$rl['id'], $editingRuleIds) && (int)$rl['channel_id'] !== (int)($editing['id'] ?? 0);
      ?>
        <label class="form-check" style="padding:5px 0">
          <input type="checkbox" name="rule_ids[]" value="<?= $rl['id'] ?>"
                 <?= in_array((int)$rl['id'], $editingRuleIds) ? 'checked' : '' ?>
                 <?= $taken ? 'disabled' : '' ?>>
          <span>
            <span class="badge <?= $rl['match_type']==='regex'?'badge-warn':'badge-muted' ?>"><?= h($rl['match_type']) ?></span>
            <span class="mono"><?= h($rl['pattern']) ?></span>
            <?php if ($taken): ?>
              <span class="text-muted" style="font-size:11px">(đã gắn channel khác)</span>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; endif; ?>
    </div>

    <h4 style="margin-top:24px;margin-bottom:12px;font-size:13px;text-transform:uppercase;letter-spacing:0.6px;color:var(--text-muted);">Lịch báo cáo</h4>
    <div id="scheds">
      <?php if (empty($schedules)) { $schedules = [['report_time'=>'23:55:00','report_type'=>'summary']]; } ?>
      <?php foreach ($schedules as $s): ?>
      <div class="d-flex gap-2 mb-2 sched-row">
        <input type="time" name="sched_time[]" class="form-control" value="<?= h(substr($s['report_time'],0,5)) ?>" style="max-width:130px">
        <select name="sched_type[]" class="form-select" style="max-width:180px">
          <option value="progress" <?= $s['report_type']==='progress'?'selected':'' ?>>Tiến độ (giữa ngày)</option>
          <option value="summary" <?= $s['report_type']==='summary'?'selected':'' ?>>Tổng kết (cả ngày)</option>
        </select>
        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">Xóa</button>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm" onclick="addSched()">+ Thêm mốc</button>

    <div class="form-check mt-3">
      <input type="checkbox" name="active" id="act" <?= empty($editing) || !empty($editing['active']) ? 'checked' : '' ?>>
      <label for="act">Kích hoạt</label>
    </div>

    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Lưu thay đổi' : 'Tạo channel' ?></button>
      <a href="channels.php" class="btn">Hủy</a>
    </div>
  </form>
</div>

<script>
function addSched() {
  const wrap = document.getElementById('scheds');
  const row = document.createElement('div');
  row.className = 'd-flex gap-2 mb-2 sched-row';
  row.innerHTML = `
    <input type="time" name="sched_time[]" class="form-control" value="12:00" style="max-width:130px">
    <select name="sched_type[]" class="form-select" style="max-width:180px">
      <option value="progress">Tiến độ (giữa ngày)</option>
      <option value="summary">Tổng kết (cả ngày)</option>
    </select>
    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">Xóa</button>
  `;
  wrap.appendChild(row);
}
</script>

<?php else: ?>

<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>#</th><th>Tên</th><th>Nguồn đọc</th><th>Rules</th><th>Nơi báo cáo</th><th>Ads</th><th>Lịch</th><th>Trạng thái</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($channels as $c): ?>
      <tr>
        <td class="mono text-dim">#<?= $c['id'] ?></td>
        <td>
          <strong><?= h($c['name']) ?></strong>
          <?php if ($c['group_name']): ?>
            <div class="muted" style="font-size:11px">📦 nhóm: <?= h($c['group_name']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <div><?= h($c['source_bot_name'] ?: '—') ?></div>
          <div class="mono muted"><?= h($c['source_chat_id'] ?: '') ?></div>
        </td>
        <td>
          <?php if ((int)$c['rule_count'] > 0): ?>
            <span class="badge badge-accent"><?= $c['rule_count'] ?> rule<?= $c['rule_count']>1?'s':'' ?></span>
          <?php else: ?>
            <span class="text-dim">—</span>
          <?php endif; ?>
        </td>
        <td>
          <div><?= h($c['report_bot_name'] ?: '(default)') ?></div>
          <div class="mono muted"><?= h($c['report_chat_id'] ?: '—') ?></div>
        </td>
        <td>
          <?php if ((int)$c['cred_count'] > 0): ?>
            <?php foreach (explode(',', $c['cred_platforms']) as $p): ?>
              <span class="badge <?= $p==='google'?'badge-accent':'badge-warn' ?>"><?= strtoupper(substr($p,0,2)) ?></span>
            <?php endforeach; ?>
            <div class="muted" style="font-size:12px"><?= h($c['cred_labels'] ?: '') ?></div>
            <?php
              $camps = $c['platform_campaign_id'] ? explode(',', $c['platform_campaign_id']) : [];
              if ($camps) echo '<div class="mono muted">' . count($camps) . ' camp' . (count($camps)>1?'s':'') . '</div>';
              else echo '<div class="mono muted">cả ' . $c['cred_count'] . ' account</div>';
            ?>
          <?php else: ?>
            <span class="text-dim">—</span>
          <?php endif; ?>
        </td>
        <td class="mono"><?= h($c['sched'] ?: '—') ?></td>
        <td><?= $c['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Tắt</span>' ?></td>
        <td class="text-right">
          <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm">Sửa</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Xóa channel <?= h($c['name']) ?>? Rule và schedule sẽ bị xóa theo.')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm btn-danger">Xóa</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($channels)): ?>
      <tr><td colspan="9" class="text-muted" style="text-align:center;padding:24px;">Chưa có channel. <a href="?new=1">Tạo channel đầu tiên</a>.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
