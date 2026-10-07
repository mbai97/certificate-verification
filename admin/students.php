<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$page_title = 'Student Enrollment';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_student') {
        $holder = trim((string)($_POST['holder_name'] ?? ''));
        $course = trim((string)($_POST['course_name'] ?? ''));
        $learnerId = trim((string)($_POST['learner_id'] ?? ''));

        if ($holder === '') {
            $errors[] = 'Student name is required.';
        }
        if ($course === '') {
            $errors[] = 'Course name is required.';
        }

        if (!$errors) {
            $stmt = $db->prepare('INSERT INTO students (learner_id, holder_name, course_name, enrollment_date, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $learnerId !== '' ? $learnerId : null,
                $holder,
                $course,
                date('Y-m-d'),
                'active',
            ]);
            $success = 'Student enrolled successfully.';
        }
    }

    if ($action === 'bulk_upload') {
        $file = $_FILES['csv_file'] ?? null;
        if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a valid CSV file.';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                $errors[] = 'Unable to read uploaded CSV.';
            } else {
                $count = 0;
                $header = fgetcsv($handle);
                if ($header === false) {
                    $errors[] = 'CSV file is empty.';
                } else {
                    while (($row = fgetcsv($handle)) !== false) {
                        if (count($row) < 3) {
                            continue;
                        }
                        [$learnerId, $studentName, $courseName] = array_map('trim', [
                            $row[0] ?? '',
                            $row[1] ?? '',
                            $row[2] ?? '',
                        ]);

                        if ($studentName === '' || $courseName === '') {
                            continue;
                        }

                        $stmt = $db->prepare('INSERT INTO students (learner_id, holder_name, course_name, enrollment_date, status) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE holder_name = VALUES(holder_name), course_name = VALUES(course_name), updated_at = CURRENT_TIMESTAMP');
                        $stmt->execute([
                            $learnerId !== '' ? $learnerId : null,
                            $studentName,
                            $courseName,
                            date('Y-m-d'),
                            'active',
                        ]);

                        $count++;
                    }
                }
                fclose($handle);
                $success = $count . ' students processed from CSV.';
            }
        }
    }

    if ($action === 'delete_student') {
        $id = (int)($_POST['student_id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('DELETE FROM students WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Student record deleted.';
        }
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$total = (int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn();
$stmt = $db->prepare('SELECT * FROM students ORDER BY enrollment_date DESC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll();

$title = 'Student Enrollment';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="portal-eyebrow">BeckynDS Registry</div>
        <h1 class="fw-bold mb-1">Student Enrollment</h1>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#single">Single Student</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#bulk">Bulk CSV</a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="single">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_student">
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Student Name</label>
                        <input type="text" name="holder_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Learner ID</label>
                        <input type="text" name="learner_id" class="form-control">
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <label class="form-label">Course</label>
                        <input type="text" name="course_name" class="form-control" required>
                    </div>
                </div>
                <button class="btn btn-primary mt-3">Enroll Student</button>
            </form>
        </div>

        <div class="tab-pane fade show" id="bulk">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bulk_upload">
                <div class="mb-3">
                    <label class="form-label">CSV file</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                </div>
                <div class="form-text mb-3">
                    Expected columns: learner_id, student_name, course_name
                </div>
                <button class="btn btn-primary">Upload Students</button>
            </form>
        </div>
    </div>
</div>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Enrolled Students</h5>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Learner ID</th>
                    <th>Course</th>
                    <th>Enrollment Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$students): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No students found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= e($student['holder_name']) ?></td>
                            <td><?= e($student['learner_id'] ?? '') ?></td>
                            <td><?= e($student['course_name']) ?></td>
                            <td><?= e($student['enrollment_date']) ?></td>
                            <td>
                                <span class="badge text-bg-<?= $student['status'] === 'active' ? 'success' : 'secondary' ?>">
                                    <?= e(strtoupper($student['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this student record?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php $totalPages = (int)ceil($total / $perPage); if ($totalPages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= (int)$i ?>"><?= (int)$i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
