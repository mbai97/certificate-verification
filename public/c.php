<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
$certificate = preg_match('/^[a-f0-9]{64}$/i', $token) === 1
    ? get_certificate($db, $token)
    : null;

if (!$certificate) {
    log_verification($db, null, 'not_found');
    $status = 'not_found';
} else {
    $status = certificate_status($certificate);
    log_verification($db, $certificate, $status);
}

$title = 'Certificate Verification';
require __DIR__ . '/../includes/header.php';
?>
<div class="verify-shell">
    <div class="text-center mb-4">
        <div class="status-icon bg-<?= $status === 'valid' ? 'success' : ($status === 'expired' ? 'warning' : ($status === 'revoked' ? 'danger' : 'secondary')) ?> text-white">
            <?= $status === 'valid' ? '✓' : ($status === 'not_found' ? '?' : '!') ?>
        </div>
        <h1 class="fw-bold"><?= $status === 'valid' ? 'Certificate Verified' : 'Certificate Verification' ?></h1>
        <p class="text-muted"><?= e($config['issuer_name']) ?></p>
    </div>

    <?php if (!$certificate): ?>
        <div class="alert alert-secondary text-center">
            <h4 class="fw-bold">Certificate Not Found</h4>
            <p class="mb-0">This verification code does not match a certificate in the registry.</p>
        </div>
        <div class="text-center">
            <a class="btn btn-primary" href="<?= e(app_url($config)) ?>">Verify another certificate</a>
        </div>
    <?php else: ?>
        <div class="certificate-card">
            <div class="text-center mb-4"><?= status_badge($status) ?></div>
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="text-muted small">Certificate Number</div>
                    <div class="fw-bold"><?= e($certificate['certificate_number']) ?></div>
                </div>
                <div class="col-sm-6">
                    <div class="text-muted small">Certificate Type</div>
                    <div class="fw-bold"><?= e($certificate['certificate_type']) ?></div>
                </div>
                <?php if (!empty($certificate['learner_id'])): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small">Learner ID</div>
                        <div class="fw-semibold"><?= e($certificate['learner_id']) ?></div>
                    </div>
                <?php endif; ?>
                <div class="col-12">
                    <div class="text-muted small">Holder</div>
                    <div class="fs-4 fw-bold"><?= e($certificate['holder_name']) ?></div>
                </div>
                <div class="col-12">
                    <div class="text-muted small">Course / Training</div>
                    <div class="fw-semibold"><?= e($certificate['course_name']) ?></div>
                </div>
                <?php if (!empty($certificate['course_topics'])): ?>
                    <div class="col-12">
                        <div class="text-muted small">Topics Covered</div>
                        <div><?= nl2br(e($certificate['course_topics'])) ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($certificate['training_duration'])): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small">Training Duration</div>
                        <div><?= e($certificate['training_duration']) ?></div>
                    </div>
                <?php endif; ?>
                <div class="col-sm-6">
                    <div class="text-muted small">Date Issued</div>
                    <div><?= e(format_date($certificate['issue_date'])) ?></div>
                </div>
                <?php if (!empty($certificate['completion_date'])): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small">Completion Date</div>
                        <div><?= e(format_date($certificate['completion_date'])) ?></div>
                    </div>
                <?php endif; ?>
                <div class="col-sm-6">
                    <div class="text-muted small">Valid Until</div>
                    <div><?= e(format_date($certificate['expiry_date'])) ?></div>
                </div>
                <?php if (!empty($certificate['director_name'])): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small">Director</div>
                        <div><?= e($certificate['director_name']) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($status === 'revoked'): ?>
                <div class="alert alert-danger mt-4 mb-0">
                    This certificate was revoked by the issuing organization.
                    <?php if (!empty($certificate['revoked_reason'])): ?>
                        <div class="small mt-1">Reason: <?= e($certificate['revoked_reason']) ?></div>
                    <?php endif; ?>
                </div>
            <?php elseif ($status === 'expired'): ?>
                <div class="alert alert-warning mt-4 mb-0">This certificate is genuine but its validity period has expired.</div>
            <?php else: ?>
                <div class="alert alert-success mt-4 mb-0">This certificate is active in the official certificate registry.</div>
            <?php endif; ?>
        </div>

        <div class="text-center mt-4">
            <a href="<?= e(app_url($config)) ?>">Verify another certificate</a>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
