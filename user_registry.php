<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/auth_storage.php';

$users = authLoadUsers();
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $id = authSanitizeTelegramId($_POST['telegram_id'] ?? '');
        if ($id === '') {
            $error = 'Geçerli bir Telegram ID belirtin.';
        } else {
            if (authDeleteUser($id)) {
                $users = authLoadUsers();
                $notice = 'Kayıt silindi.';
            } else {
                $error = 'Kayıt bulunamadı.';
            }
        }
    } else {
        $name = authSanitizeName($_POST['name'] ?? '');
        $telegramId = authSanitizeTelegramId($_POST['telegram_id'] ?? '');

        if ($name === '') {
            $error = 'İsim alanı boş olamaz.';
        } elseif ($telegramId === '') {
            $error = 'Geçerli bir Telegram ID girin (sadece rakam).';
        } else {
            $result = authUpsertUser($telegramId, $name);
            if ($result === 'invalid') {
                $error = 'Kayıt oluşturulamadı. Lütfen alanları kontrol edin.';
            } else {
                $users = authLoadUsers();
                $notice = $result === 'created' ? 'Yeni kişi eklendi.' : 'Kayıt güncellendi.';
            }
        }
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yetkili Kullanıcı Kaydı</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f5f6fa; margin:0; padding:0; }
        .container { max-width:720px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 12px 32px rgba(15,23,42,0.12); padding:32px; }
        h1 { margin-top:0; font-size:28px; color:#1f2937; }
        p.lead { color:#4b5563; margin-bottom:24px; }
        form { display:flex; flex-wrap:wrap; gap:16px; margin-bottom:32px; }
        label { display:flex; flex-direction:column; font-weight:600; font-size:14px; color:#374151; flex:1 1 200px; }
        input[type="text"], input[type="number"] { margin-top:6px; padding:12px; border:1px solid #d1d5db; border-radius:10px; font-size:16px; }
        input[type="submit"] { padding:12px 24px; background:#2563eb; color:#fff; border:none; border-radius:10px; font-size:16px; font-weight:600; cursor:pointer; transition:background 0.2s; }
        input[type="submit"]:hover { background:#1d4ed8; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 16px; text-align:left; border-bottom:1px solid #e5e7eb; }
        th { color:#475569; font-size:13px; text-transform:uppercase; letter-spacing:0.05em; }
        td { color:#1f2937; font-size:15px; }
        .actions { display:flex; gap:8px; }
        .btn-delete { background:#ef4444; color:#fff; border:none; border-radius:8px; padding:8px 12px; font-size:14px; cursor:pointer; }
        .btn-delete:hover { background:#dc2626; }
        .status { padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; }
        .status.error { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
        .status.notice { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .empty { text-align:center; color:#6b7280; padding:24px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>Yetkili Kullanıcı Kaydı</h1>
    <p class="lead">Telegram botunu kullanmasına izin verilecek kişileri ekleyin veya güncelleyin.</p>

    <?php if ($error !== ''): ?>
        <div class="status error"><?php echo h($error); ?></div>
    <?php elseif ($notice !== ''): ?>
        <div class="status notice"><?php echo h($notice); ?></div>
    <?php endif; ?>

    <form method="post">
        <label>
            Kişi Adı
            <input type="text" name="name" required maxlength="80" placeholder="Örn. Ali Yılmaz">
        </label>
        <label>
            Telegram ID
            <input type="text" name="telegram_id" required pattern="\d+" placeholder="Sadece rakam">
        </label>
        <div style="display:flex; align-items:flex-end;">
            <input type="submit" value="Kaydı Kaydet">
        </div>
    </form>

    <table>
        <thead>
        <tr>
            <th>Kişi</th>
            <th>Telegram ID</th>
            <th>Eklenme Tarihi</th>
            <th>İşlem</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="4" class="empty">Henüz kayıtlı kişi yok.</td></tr>
        <?php else: ?>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo h($user['name']); ?></td>
                    <td><?php echo h($user['telegram_id']); ?></td>
                    <td><?php echo h($user['created_at'] ?? '-'); ?></td>
                    <td>
                        <form method="post" class="actions" onsubmit="return confirm('Bu kişiyi silmek istediğinize emin misiniz?');">
                            <input type="hidden" name="telegram_id" value="<?php echo h($user['telegram_id']); ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn-delete">Sil</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
