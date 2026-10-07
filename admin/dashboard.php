<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$stats = [
    'total' => (int)$db->query('SELECT COUNT(*) FROM certificates')->fetchColumn(),
    'valid' => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE status = 'valid' AND (expiry_date IS NULL OR expiry_date >= CURDATE())")->fetchColumn(),
    'expired' => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE status = 'valid' AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn(),
    'revoked' => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE status = 'revoked'")->fetchColumn(),
];

$recent = $db->query('SELECT * FROM certificates ORDER BY id DESC LIMIT 10')->fetchAll();

$title = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="portal-eyebrow">BeckynDS Certificate Registry</div>
        <h1 class="fw-bold mb-1">Certificate Dashboard</h1>
        <p class="text-muted mb-0">Issue and manage Beckyn certificates.</p>
    </div>
    <a class="btn btn-primary" href="create-certificate.php">+ Issue Certificate</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Total', $stats['total'], 'dark'],
        ['Valid', $stats['valid'], 'success'],
        ['Expired', $stats['expired'], 'warning'],
        ['Revoked', $stats['revoked'], 'danger'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-3">
            <div class="card p-3 border-start border-4 border-<?= $color ?>">
                <div class="text-muted"><?= $label ?></div>
                <div class="stat-number"><?= $value ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Recent Certificates</h5>
        <a href="certificates.php">View all</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Number</th><th>Holder</th><th>Type</th><th>Course</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recent as $cert): ?>
                <tr>
                    <td><?= e($cert['certificate_number']) ?></td>
                    <td><?= e($cert['holder_name']) ?></td>
                    <td><?= e($cert['certificate_type']) ?></td>
                    <td><?= e($cert['course_name']) ?></td>
                    <td><?= status_badge(certificate_status($cert)) ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="view-certificate.php?id=<?= (int)$cert['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="6" class="text-center text-muted py-4">No certificates have been issued yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
