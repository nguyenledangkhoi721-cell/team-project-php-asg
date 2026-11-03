<?php
require dirname(__DIR__).'/src/bootstrap.php';
const APP_VERSION = '2.0.0';
$states = ['todo'=>'Chưa làm','doing'=>'Đang làm','done'=>'Hoàn thành'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403); exit('Phiên biểu mẫu không hợp lệ. Tải lại trang.');
    }
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_member') {
            $name = field('name', 100); $email = field('email', 150);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email không hợp lệ.');
            if (empty($_POST['id'])) $db->prepare('INSERT INTO members(name,email) VALUES (?,?)')->execute([$name,$email]);
            else $db->prepare('UPDATE members SET name=?,email=? WHERE id=?')->execute([$name,$email,positiveId($_POST['id'])]);
        } elseif ($action === 'delete_member') {
            $db->prepare('DELETE FROM members WHERE id=?')->execute([positiveId($_POST['id'] ?? null)]);
        } elseif ($action === 'save_task') {
            $title = field('title', 200); $description = field('description', 4000);
            $member = positiveId($_POST['member_id'] ?? null); $due = field('due_date', 10);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due);
            if (!$date || $date->format('Y-m-d') !== $due) throw new InvalidArgumentException('Ngày không hợp lệ.');
            $status = field('status', 10);
            if (!isset($states[$status])) throw new InvalidArgumentException('Trạng thái không hợp lệ.');
            $args = [$title,$description,$member,$due,$status];
            if (empty($_POST['id'])) $db->prepare('INSERT INTO tasks(title,description,member_id,due_date,status) VALUES (?,?,?,?,?)')->execute($args);
            else { $args[] = positiveId($_POST['id']); $db->prepare('UPDATE tasks SET title=?,description=?,member_id=?,due_date=?,status=? WHERE id=?')->execute($args); }
        } elseif ($action === 'delete_task') {
            $db->prepare('DELETE FROM tasks WHERE id=?')->execute([positiveId($_POST['id'] ?? null)]);
        } else throw new InvalidArgumentException('Thao tác không hợp lệ.');
        $_SESSION['message'] = 'Đã lưu thay đổi.'; redirect();
    } catch (InvalidArgumentException $ex) { $error = $ex->getMessage(); }
      catch (PDOException $ex) {
        error_log($ex->getMessage());
        $error = 'Không lưu được. Email có thể bị trùng, thành viên không tồn tại hoặc còn công việc được giao.';
    }
}
try {
    $members = $db->query('SELECT * FROM members ORDER BY name,id')->fetchAll();
    $filter = is_string($_GET['status'] ?? null) && isset($states[$_GET['status']]) ? $_GET['status'] : '';
    $query = 'SELECT t.*,m.name AS member_name FROM tasks t JOIN members m ON m.id=t.member_id';
    $stmt = $db->prepare($query.($filter ? ' WHERE t.status=?' : '').' ORDER BY t.due_date,t.id DESC');
    $stmt->execute($filter ? [$filter] : []); $tasks = $stmt->fetchAll();
    $counts = array_fill_keys(array_keys($states), 0);
    foreach ($db->query('SELECT status,COUNT(*) AS n FROM tasks GROUP BY status') as $row) $counts[$row['status']] = (int)$row['n'];
    $editTask = null; $editMember = null;
    if (isset($_GET['edit_task'])) { $s=$db->prepare('SELECT * FROM tasks WHERE id=?'); $s->execute([positiveId($_GET['edit_task'])]); $editTask=$s->fetch() ?: null; }
    if (isset($_GET['edit_member'])) { $s=$db->prepare('SELECT * FROM members WHERE id=?'); $s->execute([positiveId($_GET['edit_member'])]); $editMember=$s->fetch() ?: null; }
} catch (InvalidArgumentException $ex) { http_response_code(400); exit(e($ex->getMessage())); }
  catch (PDOException $ex) { error_log($ex->getMessage()); http_response_code(503); exit('CSDL chưa sẵn sàng. Kiểm tra đã nhập database/schema.sql.'); }
function csrf(): void { echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Công việc nhóm</title><link rel="stylesheet" href="assets/style.css"></head><body><main>
<header><div><small>BÀI LAB PHP VÀ MYSQL</small><h1>Công việc nhóm</h1><p>Project xây dựng website giới thiệu khoa</p></div><span>Phiên bản <?=e(APP_VERSION)?><br>Máy phục vụ <?=e(gethostname())?></span></header>
<p class="notice">Bản thực hành dùng chung, chưa có đăng nhập. Chỉ dùng dữ liệu giả và giới hạn truy cập theo IP.</p>
<?php if ($error): ?><p class="error" role="alert"><?=e($error)?></p><?php endif ?>
<?php if (isset($_SESSION['message'])): ?><p class="success"><?=e($_SESSION['message'])?></p><?php unset($_SESSION['message']); endif ?>
<section class="stats"><?php foreach ($states as $key=>$label): ?><div><strong><?=$counts[$key]?></strong> <?=e($label)?></div><?php endforeach ?></section>
<div class="columns"><section><h2><?=$editMember?'Sửa thành viên':'Thêm thành viên'?></h2>
<form method="post"><?php csrf() ?><input type="hidden" name="action" value="save_member"><input type="hidden" name="id" value="<?=e($editMember['id']??'')?>">
<label>Họ tên<input name="name" required maxlength="100" value="<?=e($editMember['name']??'')?>"></label>
<label>Email<input type="email" name="email" required maxlength="150" value="<?=e($editMember['email']??'')?>"></label><button>Lưu thành viên</button> <a href="index.php">Hủy sửa</a></form>
<h2>Thành viên</h2><?php foreach ($members as $m): ?><article><strong><?=e($m['name'])?></strong><p><?=e($m['email'])?></p><a href="?edit_member=<?=$m['id']?>">Sửa</a>
<form method="post" class="inline"><?php csrf() ?><input type="hidden" name="action" value="delete_member"><input type="hidden" name="id" value="<?=$m['id']?>"><button class="danger">Xóa</button></form></article><?php endforeach ?></section>
<section><h2><?=$editTask?'Sửa công việc':'Thêm công việc'?></h2><form method="post"><?php csrf() ?><input type="hidden" name="action" value="save_task"><input type="hidden" name="id" value="<?=e($editTask['id']??'')?>">
<label>Tên công việc<input name="title" required maxlength="200" value="<?=e($editTask['title']??'')?>"></label>
<label>Mô tả<textarea name="description" required maxlength="4000"><?=e($editTask['description']??'')?></textarea></label>
<label>Người thực hiện<select name="member_id" required><option value="">Chọn thành viên</option><?php foreach ($members as $m): ?><option value="<?=$m['id']?>" <?=($editTask['member_id']??'')==$m['id']?'selected':''?>><?=e($m['name'])?></option><?php endforeach ?></select></label>
<label>Hạn hoàn thành<input type="date" name="due_date" required value="<?=e($editTask['due_date']??date('Y-m-d'))?>"></label>
<label>Trạng thái<select name="status"><?php foreach ($states as $key=>$label): ?><option value="<?=$key?>" <?=($editTask['status']??'todo')===$key?'selected':''?>><?=e($label)?></option><?php endforeach ?></select></label>
<button <?=!$members?'disabled':''?>>Lưu công việc</button> <a href="index.php">Hủy sửa</a></form></section></div>
<section><h2>Danh sách công việc</h2><nav><a href="index.php">Tất cả</a><?php foreach ($states as $key=>$label): ?> <a href="?status=<?=$key?>"><?=e($label)?></a><?php endforeach ?></nav>
<div class="table-scroll"><table><thead><tr><th>Công việc</th><th>Thành viên</th><th>Hạn</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
<?php foreach ($tasks as $t): ?><tr><td><strong><?=e($t['title'])?></strong><p><?=nl2br(e($t['description']))?></p></td><td><?=e($t['member_name'])?></td><td><?=e($t['due_date'])?><?=$t['due_date']<date('Y-m-d')&&$t['status']!=='done'?' · Quá hạn':''?></td><td><?=e($states[$t['status']])?></td><td><a href="?edit_task=<?=$t['id']?>">Sửa</a><form method="post" class="inline"><?php csrf() ?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="danger">Xóa</button></form></td></tr><?php endforeach ?>
<?php if (!$tasks): ?><tr><td colspan="5">Chưa có công việc trong danh sách.</td></tr><?php endif ?></tbody></table></div></section>
<footer>PHP 8.3 · RDS MySQL · EC2 Auto Scaling · CloudWatch</footer></main></body></html>
