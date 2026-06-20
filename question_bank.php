<?php
$page_title = 'คลังข้อสอบ';
require_once 'auth_guard.php';
require_role(['teacher', 'admin']);
require_once 'config/db.php';

$teacher_id = $_SESSION['user_id'];
$alert_msg = ""; // เพิ่มตัวแปรสำหรับแสดงแจ้งเตือน

// ---------------------------------------------------------
// 1. ระบบ Export ชุดข้อสอบเป็นไฟล์ JSON
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'export_json' && isset($_GET['exam_id'])) {
    $target_exam_id = $_GET['exam_id'];
    
    // ดึงข้อมูลการตั้งค่าสอบ (ตรวจสอบด้วยว่าเป็นของอาจารย์ท่านนี้จริงๆ)
    $stmt = $conn->prepare("SELECT * FROM exams WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$target_exam_id, $teacher_id]);
    $exam_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($exam_data) {
        // ดึงข้อมูลโจทย์ทั้งหมดที่อยู่ในชุดข้อสอบนี้
        $q_stmt = $conn->prepare("
            SELECT q.category, q.difficulty, q.question_type, q.question_text, 
                   q.option_a, q.option_b, q.option_c, q.option_d, q.correct_answer,
                   eq.assigned_points
            FROM exam_questions eq
            JOIN questions q ON eq.question_id = q.id
            WHERE eq.exam_id = ?
        ");
        $q_stmt->execute([$target_exam_id]);
        $questions_data = $q_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // จัดโครงสร้าง Array ก่อนแปลงเป็น JSON
        $export_data = [
            'exam_metadata' => [
                'course_code' => $exam_data['course_code'],
                'title' => $exam_data['title'],
                'start_time' => $exam_data['start_time'],
                'end_time' => $exam_data['end_time'],
                'time_limit_minutes' => $exam_data['time_limit_minutes'],
                'instructions' => $exam_data['instructions'],
                'room_number' => $exam_data['room_number'],
                'section' => $exam_data['section'],
                'exam_set' => $exam_data['exam_set'],
                'shuffle_questions' => $exam_data['shuffle_questions']
            ],
            'questions' => $questions_data
        ];
        
        // บังคับให้เบราว์เซอร์ดาวน์โหลดเป็นไฟล์ .json
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="exam_' . $exam_data['course_code'] . '_' . date('Ymd_His') . '.json"');
        echo json_encode($export_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
}

// ---------------------------------------------------------
// 2. ระบบ Import ชุดข้อสอบจากไฟล์ JSON
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'import_json') {
    if (isset($_FILES['json_file']) && $_FILES['json_file']['error'] == 0) {
        $json_content = file_get_contents($_FILES['json_file']['tmp_name']);
        $data = json_decode($json_content, true);
        
        // ตรวจสอบว่าโครงสร้าง JSON ถูกต้องไหม
        if ($data && isset($data['exam_metadata']) && isset($data['questions'])) {
            try {
                $conn->beginTransaction(); // เริ่ม Transaction ป้องกันข้อมูลเข้าไม่ครบ
                
                $meta = $data['exam_metadata'];
                $import_course_code = $meta['course_code'] ?? 'Unknown';

                // ตรวจสอบว่าอาจารย์มีสิทธิ์สอนวิชานี้หรือไม่ (ยกเว้นแอดมิน)
                if ($_SESSION['role'] !== 'admin') {
                    $check_course = $conn->prepare("SELECT 1 FROM courses c JOIN teacher_courses tc ON c.id = tc.course_id WHERE c.course_code = ? AND tc.teacher_id = ?");
                    $check_course->execute([$import_course_code, $teacher_id]);
                    if (!$check_course->fetchColumn()) {
                        throw new Exception("ระบบปฏิเสธ: คุณไม่มีสิทธิ์นำเข้าข้อสอบของรายวิชา " . htmlspecialchars($import_course_code));
                    }
                }

                $exam_pin = rand(100000, 999999); // สร้าง PIN ใหม่เสมอเพื่อไม่ให้ซ้ำของเดิม
                
                // 2.1 บันทึกโครงสร้างการสอบลงตาราง exams
                $stmt = $conn->prepare("INSERT INTO exams (teacher_id, course_code, title, start_time, end_time, time_limit_minutes, instructions, room_number, section, exam_set, shuffle_questions, exam_pin, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
                
                $stmt->execute([
                    $teacher_id, 
                    $meta['course_code'] ?? 'Unknown', 
                    $meta['title'] ?? 'Imported Exam',
                    $meta['start_time'] ?? null,
                    $meta['end_time'] ?? null,
                    $meta['time_limit_minutes'] ?? 60, 
                    $meta['instructions'] ?? null, 
                    $meta['room_number'] ?? null, 
                    $meta['section'] ?? null, 
                    $meta['exam_set'] ?? 'A', 
                    $meta['shuffle_questions'] ?? 1, 
                    $exam_pin
                ]);
                $new_exam_id = $conn->lastInsertId();
                
                // 2.2 วนลูปบันทึกโจทย์ลงตาราง questions และเชื่อมความสัมพันธ์ใน exam_questions
                $q_stmt = $conn->prepare("INSERT INTO questions (category, difficulty, question_type, question_text, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $eq_stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, question_id, assigned_points) VALUES (?, ?, ?)");
                
                $q_count = 0;
                foreach ($data['questions'] as $q) {
                    $q_stmt->execute([
                        $q['category'] ?? 'ทั่วไป',
                        $q['difficulty'] ?? 'medium',
                        $q['question_type'] ?? 'multiple_choice',
                        $q['question_text'],
                        $q['option_a'] ?? null,
                        $q['option_b'] ?? null,
                        $q['option_c'] ?? null,
                        $q['option_d'] ?? null,
                        $q['correct_answer']
                    ]);
                    $new_q_id = $conn->lastInsertId();
                    
                    $eq_stmt->execute([$new_exam_id, $new_q_id, $q['assigned_points'] ?? 1.00]);
                    $q_count++;
                }
                
                $conn->commit();
                $alert_msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> นำเข้าชุดข้อสอบสำเร็จ! ได้โจทย์ใหม่จำนวน <b>$q_count</b> ข้อ (PIN เข้าสอบใหม่คือ: <b>$exam_pin</b>)</div>";
            } catch (Exception $e) {
                $conn->rollBack();
                $alert_msg = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> เกิดข้อผิดพลาดในการนำเข้า: " . $e->getMessage() . "</div>";
            }
        } else {
            $alert_msg = "<div class='alert alert-warning'><i class='fas fa-exclamation-circle'></i> รูปแบบไฟล์ JSON ไม่ถูกต้อง หรือข้อมูลไม่ครบถ้วน</div>";
        }
    }
}

// 🟡 ระบบเปิด/ปิดการสอบ (Toggle Status) - เหมือนเดิม
if (isset($_GET['toggle_status_id'])) {
    $toggle_id = $_GET['toggle_status_id'];
    try {
        $check = $conn->prepare("SELECT is_active FROM exams WHERE id = :id AND teacher_id = :teacher_id");
        $check->execute([':id' => $toggle_id, ':teacher_id' => $teacher_id]);
        $current_status = $check->fetchColumn();

        if ($current_status !== false) {
            $new_status = $current_status == 1 ? 0 : 1;
            $update = $conn->prepare("UPDATE exams SET is_active = :status WHERE id = :id");
            $update->execute([':status' => $new_status, ':id' => $toggle_id]);
            header("Location: question_bank.php");
            exit();
        }
    } catch (PDOException $e) {
        $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
    }
}

// 🟢 ดึงข้อมูลชุดข้อสอบ
// 🟢 ดึงข้อมูลชุดข้อสอบ
$sql = "SELECT e.*, 
               (SELECT COUNT(eq.id) FROM exam_questions eq WHERE eq.exam_id = e.id) as total_q,
               (SELECT SUM(eq.assigned_points) FROM exam_questions eq WHERE eq.exam_id = e.id) as total_p
        FROM exams e 
        WHERE e.teacher_id = :teacher_id 
        ORDER BY e.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([':teacher_id' => $teacher_id]);
$exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>

        <div class="container-fluid">
            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-layer-group text-primary"></i> รายการชุดข้อสอบของคุณ</h1>
                <div>
                    <button class="btn btn-success shadow-sm mr-2" data-toggle="modal" data-target="#importJsonModal">
                        <i class="fas fa-file-import fa-sm text-white-50"></i> นำเข้าด้วย JSON
                    </button>
                    <a href="create_exam.php" class="btn btn-primary shadow-sm">
                        <i class="fas fa-plus-circle fa-sm text-white-50"></i> สร้างชุดข้อสอบใหม่
                    </a>
                </div>
            </div>

            <?= $alert_msg ?> <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">จัดการเซสชันการสอบและชุดข้อสอบ</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-dark" id="dataTable" width="100%" cellspacing="0">
                            <thead class="bg-light">
                                <tr class="text-center">
                                    <th>รายวิชา & Set</th>
                                    <th>หัวข้อการสอบ</th>
                                    <th>กำหนดการ</th>
                                    <th>กลุ่ม/สถานที่</th>
                                    <th>PIN</th>
                                    <th>โจทย์/คะแนน</th>
                                    <th>สถานะ</th>
                                    <th width="150">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($exams) > 0): ?>
                                    <?php foreach($exams as $row): ?>
                                        <tr>
                                            <td class="align-middle text-center">
                                                <span class="font-weight-bold text-primary"><?= htmlspecialchars($row['course_code']) ?></span>
                                                <br><small class="badge badge-secondary shadow-sm">Set: <?= htmlspecialchars($row['exam_set'] ?? 'A') ?></small>
                                            </td>
                                            <td class="align-middle">
                                                <div class="font-weight-bold"><?= htmlspecialchars($row['title']) ?></div>
                                                <small class="text-muted"><i class="fas fa-stopwatch"></i> <?= $row['time_limit_minutes'] ?> นาที</small>
                                            </td>
                                            <td class="small align-middle text-center">
                                                <?php if(!empty($row['start_time']) && !empty($row['end_time'])): ?>
                                                    <span class="text-success" title="เวลาเริ่มสอบ"><i class="fas fa-calendar-check"></i> <?= date('d/m/y H:i', strtotime($row['start_time'])) ?></span><br>
                                                    <span class="text-danger" title="เวลาสิ้นสุด"><i class="fas fa-calendar-times"></i> <?= date('d/m/y H:i', strtotime($row['end_time'])) ?></span>
                                                <?php else: ?>
                                                    <span class="text-primary font-weight-bold"><i class="fas fa-clock"></i> สอบแบบอิสระ</span><br>
                                                    <span class="text-muted">(จับเวลา <?= $row['time_limit_minutes'] ?> นาที)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small align-middle text-center">
                                                กลุ่ม: <?= htmlspecialchars($row['section'] ?? '-') ?><br>
                                                ห้อง: <?= htmlspecialchars($row['room_number'] ?? '-') ?>
                                            </td>
                                            <td class="text-center align-middle font-weight-bold text-primary h5 mb-0">
                                                <?= htmlspecialchars($row['exam_pin']) ?>
                                            </td>
                                            <td class="text-center align-middle">
                                                <div class="badge badge-primary badge-pill mb-1"><?= $row['total_q'] ?> ข้อ</div><br>
                                                <div class="badge badge-info badge-pill"><?= number_format($row['total_p'] ?? 0, 2) ?> คะแนน</div>
                                            </td>
                                            <td class="text-center align-middle">
                                                <?php if($row['is_active'] == 1): ?>
                                                    <span class="badge badge-success shadow-sm"><i class="fas fa-check-circle"></i> เปิดสอบ</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger shadow-sm"><i class="fas fa-lock"></i> ปิดสอบ</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center align-middle">
                                                <div class="btn-group-vertical w-100">
                                                    <a href="manage_questions.php?exam_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary mb-1 text-left py-2">
                                                        <i class="fas fa-tasks"></i> จัดการโจทย์
                                                    </a>
                                                    <a href="question_bank.php?action=export_json&exam_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-dark mb-1 text-left py-2">
                                                        <i class="fas fa-file-export"></i> ส่งออก JSON
                                                    </a>
                                                    <a href="exam_results.php?exam_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info mb-1 text-left py-2">
                                                        <i class="fas fa-poll"></i> ดูผลคะแนน
                                                    </a>
                                                    <a href="question_bank.php?toggle_status_id=<?= $row['id'] ?>" 
                                                       class="btn btn-sm <?= $row['is_active'] == 1 ? 'btn-outline-danger' : 'btn-outline-success' ?> text-left py-2"
                                                       onclick="return confirm('เปลี่ยนสถานะการเข้าสอบ?')">
                                                        <i class="fas <?= $row['is_active'] == 1 ? 'fa-lock' : 'fa-unlock' ?>"></i> 
                                                        <?= $row['is_active'] == 1 ? 'ปิดรับคำตอบ' : 'เปิดรับคำตอบ' ?>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center py-5 text-muted">ยังไม่มีชุดข้อสอบของคุณ</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="importJsonModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content text-dark">
                <form action="question_bank.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-file-import"></i> นำเข้าชุดข้อสอบ (JSON)</h5>
                        <button class="close text-white" type="button" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="import_json">
                        <div class="form-group">
                            <label class="font-weight-bold">เลือกไฟล์นามสกุล .json</label>
                            <input type="file" name="json_file" class="form-control-file" accept=".json" required>
                        </div>
                        <div class="alert alert-info small mb-0">
                            <strong>หมายเหตุ:</strong> ระบบจะสร้างชุดข้อสอบให้ใหม่ทั้งหมด (สุ่ม PIN ใหม่ให้ด้วย) โดยดึงข้อมูลทั้งการตั้งค่าเวลาและโจทย์ทุกข้อจากไฟล์ JSON
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" type="button" data-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> อัปโหลดและสร้างชุดข้อสอบ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>