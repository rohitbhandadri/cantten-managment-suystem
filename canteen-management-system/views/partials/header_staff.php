<?php
$current = basename($_SERVER['PHP_SELF']);
?>
<aside class="admin-sidebar">
    <div class="admin-user">
        <div class="avatar-circle">👤</div>
        <div>
            <strong><?= e($_SESSION['name'] ?? 'Staff') ?></strong>
            <div class="muted small">Canteen Staff</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="<?= BASE_URL ?>/views/staff/dashboard.php" class="<?= $current === 'dashboard.php' ? 'active' : '' ?>">📋 Workspace</a>
        <a href="<?= BASE_URL ?>/views/staff/directory.php" class="<?= $current === 'directory.php' ? 'active' : '' ?>">👥 Staff Directory</a>
        <a href="<?= BASE_URL ?>/views/staff/profile.php" class="<?= $current === 'profile.php' ? 'active' : '' ?>">⭐ My Reviews</a>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/logout.php" class="logout-button">🚪 Logout</a>
    </div>
</aside>
