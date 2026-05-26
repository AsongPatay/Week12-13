<?php
session_start();

const UPLOAD_DIR = __DIR__ . '/uploads/';
const DATA_FILE = __DIR__ . '/data/users.json';
const MAX_FILE_SIZE = 2 * 1024 * 1024;
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
if (!is_dir(dirname(DATA_FILE))) {
    mkdir(dirname(DATA_FILE), 0755, true);
}

if (!file_exists(DATA_FILE)) {
    $sampleUsers = [
        [
            'id' => 1,
            'name' => 'Rice Shower',
            'created' => '2026-05-25 15:34:25',
            'avatar' => 'default-avatar.svg',
        ],
        [
            'id' => 2,
            'name' => 'GoldShip',
            'created' => '2026-05-25 15:35:22',
            'avatar' => 'default-avatar.svg',
        ],
    ];
    file_put_contents(DATA_FILE, json_encode($sampleUsers, JSON_PRETTY_PRINT));
}

$users = json_decode(file_get_contents(DATA_FILE), true) ?: [];
$search = trim((string)($_GET['search'] ?? ''));
$errors = [];
$success = null;

function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $keyword = trim((string)($_POST['keyword'] ?? ''));
    if ($name === '') {
        $errors[] = 'Please enter a name for the profile.';
    }
    if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please select a valid image file for the avatar.';
    }

    if (empty($errors)) {
        $file = $_FILES['avatar'];
        if ($file['size'] > MAX_FILE_SIZE) {
            $errors[] = 'The avatar must be 2MB or smaller.';
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Allowed avatar file types are jpg, jpeg, png, and gif.';
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!preg_match('/^image\//', $mimeType)) {
            $errors[] = 'The uploaded file must be a valid image.';
        }

        if (empty($errors)) {
            $filename = bin2hex(random_bytes(12)) . '.' . $extension;
            $target = UPLOAD_DIR . $filename;
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                $errors[] = 'Unable to save the avatar file. Please try again.';
            } else {
                $newId = count($users) ? max(array_column($users, 'id')) + 1 : 1;
                $users[] = [
                    'id' => $newId,
                    'name' => $name,
                    'created' => date('Y-m-d H:i:s'),
                    'avatar' => $filename,
                ];
                file_put_contents(DATA_FILE, json_encode($users, JSON_PRETTY_PRINT));
                $success = 'Profile added successfully.';
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?search=' . urlencode($keyword));
                exit;
            }
        }
    }
}

$filteredUsers = array_filter($users, static function ($user) use ($search) {
    if ($search === '') {
        return true;
    }
    return stripos($user['name'], $search) !== false;
});

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = count($filteredUsers);
$lastPage = max(1, (int)ceil($total / $perPage));
$page = min($page, $lastPage);
$offset = ($page - 1) * $perPage;
$pagedUsers = array_slice($filteredUsers, $offset, $perPage);

$baseQuery = [];
if ($search !== '') {
    $baseQuery['search'] = $search;
}

function buildUrl(array $params = []): string
{
    $query = http_build_query($params);
    return '?' . $query;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profiles</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="page-shell">
    <main class="panel">
        <div class="top-bar">
            <div>
                <h1>Users</h1>
                <p class="debug">DB driver: File Storage<br>Total rows in query: <?= $total ?></p>
            </div>
            <button class="btn btn-primary btn-add" type="button" id="openForm">Add Profile</button>
        </div>

        <form class="search-form" method="get" action="">
            <label class="search-label">
                <span class="sr-only">Search users</span>
                <input name="search" type="search" value="<?= sanitize($search) ?>" placeholder="Search users...">
            </label>
            <button class="btn btn-search" type="submit">Search</button>
        </form>

        <?php if ($success): ?>
            <div class="message success"><?= sanitize($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="message error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= sanitize($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($pagedUsers)): ?>
            <div class="empty-state">No users found.</div>
        <?php else: ?>
            <div class="cards-grid">
                <?php foreach ($pagedUsers as $user): ?>
                    <article class="user-card">
                        <div class="avatar" style="background-image:url('uploads/<?= sanitize($user['avatar']) ?>')"></div>
                        <div class="user-info">
                            <strong><?= sanitize($user['name']) ?></strong>
                            <span><?= sanitize($user['created']) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <nav class="pagination">
            <?php for ($i = 1; $i <= $lastPage; $i++): ?>
                <?php $params = $baseQuery; $params['page'] = $i; ?>
                <a href="<?= sanitize(buildUrl($params)) ?>" class="page-link<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    </main>
</div>

<div class="modal" id="addModal" aria-hidden="true">
    <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-header">
            <h2 id="modalTitle">Add Profile</h2>
            <button type="button" class="btn-close" id="closeForm">×</button>
        </div>
        <form class="upload-form" method="post" enctype="multipart/form-data">
            <label>
                Name
                <input type="text" name="name" value="" required>
            </label>
            <label>
                Avatar image
                <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif" required>
            </label>
            <input type="hidden" name="keyword" value="<?= sanitize($search) ?>">
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Save profile</button>
                <button class="btn btn-secondary" type="button" id="closeForm2">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    const openBtn = document.getElementById('openForm');
    const closeBtns = [document.getElementById('closeForm'), document.getElementById('closeForm2')];
    const modal = document.getElementById('addModal');

    openBtn.addEventListener('click', () => {
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('open');
    });
    closeBtns.forEach(button => {
        button.addEventListener('click', () => {
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('open');
        });
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('open');
        }
    });
</script>
</body>
</html>
