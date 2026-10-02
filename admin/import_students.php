<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student_validation.php';
require_once __DIR__ . '/../includes/student_id.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 6);

// CSV column order; the import reads columns by position, so this is also the template header.
const IMPORT_COLUMNS = [
    'student_no' => 'รหัสนักศึกษา',
    'prefix' => 'คำนำหน้า',
    'prefix_other' => 'ระบุคำนำหน้า (ถ้าเลือกอื่นๆ)',
    'full_name' => 'ชื่อ-นามสกุล',
    'age' => 'อายุ',
    'address' => 'ที่อยู่',
    'phone' => 'เบอร์โทรศัพท์',
    'line_id' => 'Line ID',
    'reference_person' => 'ผู้แนะนำ',
];
const IMPORT_MAX_BYTES = 2 * 1024 * 1024;

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="student_import_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens Thai text as UTF-8
    fputcsv($out, array_values(IMPORT_COLUMNS));
    fclose($out);
    exit;
}

$mysqli = getDbConnection();
$levels = $mysqli->query('SELECT id, name FROM class_levels ORDER BY sort_order')->fetch_all(MYSQLI_ASSOC);
$levelNames = array_column($levels, 'name', 'id');

$error = null;
$summary = null;
$skipped = [];
$selectedLevelId = (int) ($levels[0]['id'] ?? 0);

// Reads the uploaded file as UTF-8 text: strips a BOM, and converts from Windows-874 (Thai Excel's
// default "CSV" encoding) when the bytes aren't valid UTF-8. Returns null if conversion fails.
function readUploadedCsvText(string $path): ?string
{
    $content = file_get_contents($path);
    if ($content === false) {
        return null;
    }
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }
    if (preg_match('//u', $content) !== 1) {
        $content = iconv('CP874', 'UTF-8//IGNORE', $content);
        if ($content === false) {
            return null;
        }
    }

    return $content;
}

// Batch numbers (all but the last 3 digits of the student ID, DESIGN.md §5) referenced by the data
// rows. Rows whose ID is malformed are left for the per-row validation to report.
function importBatchNumbers(string $text): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $text);
    rewind($stream);

    $batchNos = [];
    $rowNumber = 0;
    while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if (++$rowNumber === 1) {
            continue; // header row
        }
        $studentNo = cleanCode((string) ($cells[0] ?? ''));
        if (preg_match('/^[0-9]{4,10}$/', $studentNo) && (int) substr($studentNo, 0, -3) >= 1) {
            $batchNos[(int) substr($studentNo, 0, -3)] = true;
        }
    }
    fclose($stream);

    return array_keys($batchNos);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $selectedLevelId = (int) ($_POST['class_level_id'] ?? 0);
    $upload = $_FILES['csv_file'] ?? null;

    if (!isset($levelNames[$selectedLevelId])) {
        $error = 'กรุณาเลือกชั้นเรียนเริ่มต้น';
    } elseif (!$upload || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        $error = 'กรุณาเลือกไฟล์ CSV ที่ต้องการนำเข้า';
    } elseif ($upload['size'] > IMPORT_MAX_BYTES) {
        $error = 'ไฟล์มีขนาดใหญ่เกิน 2 MB';
    } elseif (($text = readUploadedCsvText($upload['tmp_name'])) === null) {
        $error = 'ไม่สามารถอ่านไฟล์ได้ กรุณาบันทึกไฟล์เป็น CSV (UTF-8) แล้วลองใหม่';
    } else {
        // Batches must already exist: refuse the whole file if any referenced batch is missing.
        $batchIds = []; // batch_no => batches.id
        $missingBatches = [];
        $findBatch = $mysqli->prepare('SELECT id FROM batches WHERE batch_no = ?');
        foreach (importBatchNumbers($text) as $batchNo) {
            $findBatch->bind_param('i', $batchNo);
            $findBatch->execute();
            $batchRow = $findBatch->get_result()->fetch_assoc();
            if ($batchRow) {
                $batchIds[$batchNo] = (int) $batchRow['id'];
            } else {
                $missingBatches[] = $batchNo;
            }
        }
        $findBatch->close();

        if ($missingBatches !== []) {
            sort($missingBatches);
            $error = 'แฟ้มข้อมูลปรากฎรุ่นนักศึกษาที่ยังไม่มีในระบบ  กรุณาทำการสร้างรุ่นของนักศึกษาในระบบเสียก่อน'
                . ' (รุ่น: ' . implode(', ', $missingBatches) . ')';
        }
    }

    if ($error === null) {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $fieldKeys = array_keys(IMPORT_COLUMNS);
        $seenNos = [];
        $seenNames = [];
        $imported = 0;
        $rowNumber = 0;

        $findNo = $mysqli->prepare('SELECT 1 FROM students WHERE student_no = ?');
        $findName = $mysqli->prepare(
            "SELECT 1 FROM students WHERE full_name = ? AND status IN ('approved', 'pending')"
        );
        $insert = $mysqli->prepare(
            'INSERT INTO students (student_no, prefix, prefix_other, full_name, age, address, phone, line_id,
                                   reference_person, batch_id, current_class_level_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "approved")'
        );

        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rowNumber++;
            if ($rowNumber === 1) {
                continue; // header row
            }
            if (implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue; // blank line
            }

            $cells = array_pad(array_slice($cells, 0, count($fieldKeys)), count($fieldKeys), '');
            $raw = array_combine($fieldKeys, $cells);
            $studentNo = cleanCode((string) $raw['student_no']);

            [$student, $validationError] = validateStudentFields($raw);

            $reason = null;
            if (!preg_match('/^[0-9]{4,10}$/', $studentNo)) {
                $reason = 'รหัสนักศึกษาต้องเป็นตัวเลข 4-10 หลัก';
            } elseif ((int) substr($studentNo, 0, -3) < 1) {
                $reason = 'รหัสนักศึกษาไม่มีเลขรุ่น';
            } elseif ($validationError !== null) {
                $reason = $validationError;
            } elseif (isset($seenNos[$studentNo])) {
                $reason = "รหัสนักศึกษา $studentNo ซ้ำกับแถว " . $seenNos[$studentNo] . ' ในไฟล์';
            } elseif (isset($seenNames[$student['full_name']])) {
                $reason = 'ชื่อ-นามสกุลซ้ำกับแถว ' . $seenNames[$student['full_name']] . ' ในไฟล์';
            } else {
                $findNo->bind_param('s', $studentNo);
                $findNo->execute();
                if ($findNo->get_result()->fetch_row()) {
                    $reason = "รหัสนักศึกษา $studentNo มีอยู่ในระบบแล้ว";
                } else {
                    $findName->bind_param('s', $student['full_name']);
                    $findName->execute();
                    if ($findName->get_result()->fetch_row()) {
                        $reason = 'ชื่อ-นามสกุลนี้มีอยู่ในระบบแล้ว';
                    }
                }
            }

            if ($reason !== null) {
                $skipped[] = "แถว $rowNumber: $reason";
                continue;
            }

            $seenNos[$studentNo] = $rowNumber;
            $seenNames[$student['full_name']] = $rowNumber;

            // Batch = every digit except the last 3 (group digit + 2-digit sequence), see DESIGN.md §5.
            // All batches were confirmed to exist before the import started.
            $batchId = $batchIds[(int) substr($studentNo, 0, -3)];

            try {
                $insert->bind_param(
                    'ssssissssii',
                    $studentNo,
                    $student['prefix'],
                    $student['prefix_other'],
                    $student['full_name'],
                    $student['age'],
                    $student['address'],
                    $student['phone'],
                    $student['line_id'],
                    $student['reference_person'],
                    $batchId,
                    $selectedLevelId
                );
                $insert->execute();
            } catch (mysqli_sql_exception $e) {
                $skipped[] = "แถว $rowNumber: บันทึกไม่สำเร็จ (รหัสนักศึกษา $studentNo อาจมีผู้ใช้แล้ว)";
                continue;
            }

            noteImportedStudentNo($mysqli, $batchId, $studentNo);
            $imported++;
        }

        $findNo->close();
        $findName->close();
        $insert->close();
        fclose($stream);

        $summary = "นำเข้านักศึกษาสำเร็จ $imported คน ชั้น" . $levelNames[$selectedLevelId]
            . ($skipped !== [] ? ' ข้าม ' . count($skipped) . ' แถว' : '');
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>นำเข้ารายชื่อนักศึกษา - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>นำเข้ารายชื่อนักศึกษา (CSV)</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <a class="nav-logout" href="logout.php">ออกจากระบบ</a>
    </nav>

    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <?php if ($summary): ?><p class="success"><?= htmlspecialchars($summary) ?></p><?php endif; ?>
    <?php if ($skipped !== []): ?>
        <div class="error">
            <strong>แถวที่ไม่ได้นำเข้า:</strong>
            <ul>
                <?php foreach ($skipped as $line): ?>
                    <li><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <p>นักศึกษาที่นำเข้าจะได้รับการอนุมัติทันทีโดยใช้รหัสนักศึกษาตามไฟล์ รุ่นจะคำนวณจากรหัสนักศึกษา
           (ตัวเลขทั้งหมดยกเว้น 3 หลักสุดท้าย) หากยังไม่มีรุ่นนั้นในระบบ ระบบจะไม่นำเข้าไฟล์
           กรุณาสร้างรุ่นในหน้าการจัดการชั้นเรียนก่อน</p>
        <p>ไฟล์ต้องมีแถวหัวตารางในแถวแรก และเรียงคอลัมน์ดังนี้:
           <?= htmlspecialchars(implode(', ', IMPORT_COLUMNS)) ?></p>
        <p><a href="import_students.php?template=1">ดาวน์โหลดไฟล์ตัวอย่าง (CSV)</a></p>
    </div>

    <form action="import_students.php" method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <p class="form-note"><span class="required-mark">*</span> จำเป็นต้องกรอก</p>

        <label for="class_level_id">ชั้นเรียนเริ่มต้นของนักศึกษาทุกคนในไฟล์<span class="required-mark">*</span></label>
        <select id="class_level_id" name="class_level_id" required>
            <?php foreach ($levels as $level): ?>
                <option value="<?= (int) $level['id'] ?>" <?= (int) $level['id'] === $selectedLevelId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($level['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="csv_file">ไฟล์ CSV (ไม่เกิน 2 MB)<span class="required-mark">*</span></label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>

        <button type="submit">นำเข้า</button>
    </form>
</body>
</html>
