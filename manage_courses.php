<?php
$page_title = 'จัดการรายวิชาและผู้สอน';
require_once 'auth_guard.php';
require_once 'config/db.php';
require_role(['admin']); 

$alert_msg = "";

// ---------------------------------------------------------
// 🟢 API (AJAX) ดึงอาจารย์ที่ถูกมอบหมาย (เพื่อเซ็ตค่า Select2)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] == 'get_assigned_teachers') {
    header('Content-Type: application/json; charset=utf-8');
    $course_id = $_GET['course_id'] ?? 0;
    
    $stmt = $conn->prepare("SELECT teacher_id FROM teacher_courses WHERE course_id = :id");
    $stmt->execute([':id' => $course_id]);
    echo json_encode(['success' => true, 'teacher_ids' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    exit();
}

// ---------------------------------------------------------
// 🟢 ระบบ Export ข้อมูลรายวิชาเป็น CSV (พร้อมข้อมูลผู้สอน)
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=courses_export_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM กันภาษาไทยเพี้ยน
    
    // อัปเดต Header เป็น 4 คอลัมน์
    fputcsv($output, ['รหัสวิชา', 'ชื่อรายวิชา', 'สถานะ (1=เปิด, 0=ปิด)', 'Username อาจารย์ (คั่นด้วยลูกน้ำ)']);
    
    // ดึงข้อมูลวิชา และใช้ GROUP_CONCAT ดึง Username อาจารย์ทั้งหมดที่สอนวิชานั้นมาต่อกัน
    $stmt = $conn->query("
        SELECT c.course_code, c.course_name, c.is_active, 
               (SELECT GROUP_CONCAT(u.username SEPARATOR ', ') 
                FROM teacher_courses tc 
                JOIN users u ON tc.teacher_id = u.id 
                WHERE tc.course_id = c.id) as teacher_usernames
        FROM courses c 
        ORDER BY c.course_code ASC
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['course_code'], $row['course_name'], $row['is_active'], $row['teacher_usernames']]);
    }
    fclose($output);
    exit(); 
}

// ---------------------------------------------------------
// 🟢 ส่วนจัดการข้อมูล (POST) เพิ่ม/แก้ไข/กำหนดผู้สอน/Import CSV
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. ระบบ Import ข้อมูลจาก CSV แบบ Advanced (อัปเดตข้อมูลผู้สอนได้ด้วย)
    if ($_POST['action'] == 'import_csv') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            fgetcsv($handle, 1000, ","); // ข้าม Header
            
            $success = 0; $fail = 0;
            
            // เตรียมคำสั่ง SQL
            $chk_stmt = $conn->prepare("SELECT id FROM courses WHERE course_code = :c");
            $ins_stmt = $conn->prepare("INSERT INTO courses (course_code, course_name, is_active) VALUES (:c, :n, :a)");
            $upd_stmt = $conn->prepare("UPDATE courses SET course_name = :n, is_active = :a WHERE id = :id");
            $get_teacher_stmt = $conn->prepare("SELECT id FROM users WHERE username = :u AND role = 'teacher'");
            $ins_tc_stmt = $conn->prepare("INSERT INTO teacher_courses (teacher_id, course_id) VALUES (:tid, :cid)");

            $conn->beginTransaction(); // เริ่ม Transaction เพื่อความปลอดภัย
            
            try {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if(empty(array_filter($data))) continue; 
                    
                    $course_code = strtoupper(trim($data[0])); 
                    $course_name = trim($data[1]); 
                    $is_active = (isset($data[2]) && trim($data[2]) !== '') ? (int)trim($data[2]) : 1;
                    $teachers_csv = isset($data[3]) ? trim($data[3]) : ''; // Username ที่คั่นด้วยลูกน้ำ
                    
                    if(empty($course_code) || empty($course_name)) {
                        $fail++; continue;
                    }

                    $chk_stmt->execute([':c' => $course_code]);
                    $existing_course = $chk_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $course_id = null;

                    if (!$existing_course) {
                        // ไม่เคยมีวิชานี้ -> INSERT ใหม่
                        $ins_stmt->execute([':c' => $course_code, ':n' => $course_name, ':a' => $is_active]);
                        $course_id = $conn->lastInsertId();
                    } else {
                        // มีวิชานี้อยู่แล้ว -> อัปเดตชื่อและสถานะ (เสมือนการ Sync ข้อมูล)
                        $course_id = $existing_course['id'];
                        $upd_stmt->execute([':n' => $course_name, ':a' => $is_active, ':id' => $course_id]);
                    }

                    // จัดการข้อมูลผู้สอน ถ้ามีการกรอก Username มา
                    if (!empty($teachers_csv)) {
                        // ลบผู้สอนเดิมออกก่อน เพื่อลงข้อมูลใหม่ที่มาจาก CSV
                        $conn->prepare("DELETE FROM teacher_courses WHERE course_id = ?")->execute([$course_id]);
                        
                        $usernames = array_map('trim', explode(',', $teachers_csv));
                        foreach ($usernames as $uname) {
                            if(empty($uname)) continue;
                            
                            $get_teacher_stmt->execute([':u' => $uname]);
                            $teacher_id = $get_teacher_stmt->fetchColumn();
                            
                            // ถ้าเจอ Username นี้ในระบบว่าเป็นอาจารย์ ให้ผูกวิชาให้เลย
                            if ($teacher_id) {
                                $ins_tc_stmt->execute([':tid' => $teacher_id, ':cid' => $course_id]);
                            }
                        }
                    }
                    $success++;
                }
                $conn->commit();
                $alert_msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> นำเข้า/อัปเดต ข้อมูลสำเร็จ <b>$success</b> วิชา</div>";
            } catch (Exception $e) {
                $conn->rollBack();
                $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาดในการนำเข้า: " . $e->getMessage() . "</div>";
            }
            fclose($handle);
        } else {
            $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาดในการอัปโหลดไฟล์</div>";
        }
    }
    // 2. เพิ่มรายวิชาผ่านฟอร์มหน้าเว็บ
    elseif ($_POST['action'] == 'add_course') {
        $course_code = strtoupper(trim($_POST['course_code']));
        $course_name = trim($_POST['course_name']);
        
        try {
            $stmt = $conn->prepare("INSERT INTO courses (course_code, course_name, is_active) VALUES (:code, :name, 1)");
            $stmt->execute([':code' => $course_code, ':name' => $course_name]);
            $alert_msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> เพิ่มรายวิชาสำเร็จ!</div>";
        } catch (PDOException $e) {
            $alert_msg = "<div class='alert alert-danger'>ข้อผิดพลาด (รหัสวิชาอาจซ้ำ): " . $e->getMessage() . "</div>";
        }
    } 
    // 3. แก้ไขรายวิชาผ่านฟอร์มหน้าเว็บ
    elseif ($_POST['action'] == 'edit_course') {
        $course_id = $_POST['course_id'];
        $course_code = strtoupper(trim($_POST['course_code']));
        $course_name = trim($_POST['course_name']);
        
        try {
            $stmt = $conn->prepare("UPDATE courses SET course_code = :code, course_name = :name WHERE id = :id");
            $stmt->execute([':code' => $course_code, ':name' => $course_name, ':id' => $course_id]);
            $alert_msg = "<div class='alert alert-info'><i class='fas fa-check-circle'></i> อัปเดตข้อมูลวิชาสำเร็จ!</div>";
        } catch (PDOException $e) {
            $alert_msg = "<div class='alert alert-danger'>ข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    }
    // 4. กำหนดอาจารย์ผู้สอนผ่าน Modal
    elseif ($_POST['action'] == 'assign_teachers') {
        $course_id = $_POST['assign_course_id'];
        $teacher_ids = $_POST['teacher_ids'] ?? [];
        
        try {
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM teacher_courses WHERE course_id = ?")->execute([$course_id]);
            
            if (!empty($teacher_ids)) {
                $stmt_ins = $conn->prepare("INSERT INTO teacher_courses (teacher_id, course_id) VALUES (?, ?)");
                foreach ($teacher_ids as $tid) {
                    $stmt_ins->execute([$tid, $course_id]);
                }
            }
            $conn->commit();
            $alert_msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> อัปเดตผู้สอนประจำวิชาสำเร็จ!</div>";
        } catch (PDOException $e) {
            $conn->rollBack();
            $alert_msg = "<div class='alert alert-danger'>ข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    }
}

// ---------------------------------------------------------
// 🟢 ระบบ เปิด/ปิด การใช้งานรายวิชาผ่าน GET
// ---------------------------------------------------------
if (isset($_GET['toggle_id']) && isset($_GET['current_status'])) {
    $target_id = $_GET['toggle_id'];
    $new_status = ($_GET['current_status'] == 1) ? 0 : 1;
    $upd = $conn->prepare("UPDATE courses SET is_active = :s WHERE id = :id");
    $upd->execute([':s' => $new_status, ':id' => $target_id]);
    header("Location: manage_courses.php");
    exit();
}

// --- ดึงข้อมูลวิชาทั้งหมด ---
$courses = $conn->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM teacher_courses tc WHERE tc.course_id = c.id) as teacher_count 
    FROM courses c 
    ORDER BY c.course_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- ดึงข้อมูลอาจารย์ พร้อมคณะ/สาขา เพื่อให้แอดมินค้นหาง่ายขึ้น ---
$teachers = $conn->query("SELECT id, fullname, faculty, major FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY fullname ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>
        <div class="container-fluid">
            
            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-book text-primary"></i> จัดการรายวิชา</h1>
                <div>
                    <a href="manage_courses.php?action=export_csv" class="btn btn-info shadow-sm mr-2">
                        <i class="fas fa-file-download"></i> ส่งออก CSV
                    </a>
                    <button class="btn btn-success shadow-sm mr-2" data-toggle="modal" data-target="#importCourseModal">
                        <i class="fas fa-file-excel"></i> นำเข้าจาก CSV
                    </button>
                    <button class="btn btn-primary shadow-sm" data-toggle="modal" data-target="#courseModal" onclick="clearCourseForm()">
                        <i class="fas fa-plus"></i> เพิ่มรายวิชา
                    </button>
                </div>
            </div>
                        
            <?= $alert_msg ?>

            <div class="card shadow mb-4 mt-3">
                <div class="card-body text-dark">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-dark" id="dataTable" width="100%" cellspacing="0">
                            <thead class="bg-light">
                                <tr class="text-center">
                                    <th>รหัสวิชา</th>
                                    <th>ชื่อรายวิชา</th>
                                    <th>จำนวนผู้สอน</th>
                                    <th>สถานะ</th>
                                    <th width="180">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($courses as $c): ?>
                                <tr>
                                    <td class="font-weight-bold text-center text-primary"><?= htmlspecialchars($c['course_code']) ?></td>
                                    <td><?= htmlspecialchars($c['course_name']) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $c['teacher_count'] > 0 ? 'badge-info' : 'badge-secondary' ?> p-2">
                                            <i class="fas fa-chalkboard-teacher"></i> <?= $c['teacher_count'] ?> คน
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($c['is_active'] == 1): ?>
                                            <span class="badge badge-success badge-pill">เปิดใช้งาน</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger badge-pill">ปิดใช้งาน</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-info" onclick='openAssignModal(<?= $c['id'] ?>, "<?= htmlspecialchars($c['course_code']) ?>", "<?= htmlspecialchars($c['course_name']) ?>")' title="กำหนดอาจารย์ผู้สอน">
                                                <i class="fas fa-users-cog"></i> กำหนดผู้สอน
                                            </button>
                                            <button class="btn btn-sm btn-warning" onclick='editCourse(<?= json_encode($c) ?>)' title="แก้ไขข้อมูลวิชา">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="manage_courses.php?toggle_id=<?= $c['id'] ?>&current_status=<?= $c['is_active'] ?>" 
                                               class="btn btn-sm <?= $c['is_active'] == 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                               onclick="return confirm('ยืนยันการเปลี่ยนสถานะวิชานี้?')" title="เปิด/ปิดการใช้งาน">
                                                <i class="fas <?= $c['is_active'] == 1 ? 'fa-ban' : 'fa-check-circle' ?>"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </div>

    <div class="modal fade" id="courseModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content text-dark">
                <form action="manage_courses.php" method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="courseModalTitle">เพิ่มรายวิชาใหม่</h5>
                        <button class="close text-white" type="button" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="courseFormAction" value="add_course">
                        <input type="hidden" name="course_id" id="course_id">
                        
                        <div class="form-group">
                            <label>รหัสวิชา <span class="text-danger">*</span></label>
                            <input type="text" name="course_code" id="course_code" class="form-control text-uppercase" placeholder="เช่น CS101" required>
                        </div>
                        <div class="form-group">
                            <label>ชื่อรายวิชา <span class="text-danger">*</span></label>
                            <input type="text" name="course_name" id="course_name" class="form-control" placeholder="เช่น การเขียนโปรแกรมเบื้องต้น" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" type="button" data-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="importCourseModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content text-dark">
                <form action="manage_courses.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-file-excel"></i> นำเข้ารายวิชา (CSV)</h5>
                        <button class="close text-white" type="button" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="import_csv">
                        <div class="form-group">
                            <label class="font-weight-bold">เลือกไฟล์ CSV (UTF-8)</label>
                            <input type="file" name="csv_file" class="form-control-file" accept=".csv" required>
                        </div>
                        <div class="alert alert-info small mb-0">
                            <strong>คำแนะนำคอลัมน์ (ต้องมี Header 1 แถว):</strong><br>
                            1: รหัสวิชา (เช่น CS101)<br>
                            2: ชื่อวิชา<br>
                            3: สถานะ (1 = เปิดใช้งาน, 0 = ปิดใช้งาน)<br>
                            4: <span class="text-danger font-weight-bold">Username ของอาจารย์ (ถ้ามีหลายคนคั่นด้วยลูกน้ำ เช่น aj01,aj02)</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" type="button" data-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> อัปโหลดและนำเข้า</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="assignModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content text-dark">
                <form action="manage_courses.php" method="POST">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-users-cog"></i> กำหนดผู้สอน</h5>
                        <button class="close text-white" type="button" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="assign_teachers">
                        <input type="hidden" name="assign_course_id" id="assign_course_id">
                        
                        <div class="alert alert-info border-left-info text-dark">
                            <strong>วิชา:</strong> <span id="assign_course_text" class="font-weight-bold text-primary"></span>
                        </div>
                        
                        <div class="form-group">
                            <label class="font-weight-bold">เลือกอาจารย์ (ค้นหาได้ และเลือกได้หลายคน):</label>
                            <select name="teacher_ids[]" id="teacher_select" class="form-control select2-multiple" multiple="multiple" style="width: 100%;">
                                <?php foreach($teachers as $t): ?>
                                    <?php 
                                        $context = "";
                                        if(!empty($t['faculty']) || !empty($t['major'])) {
                                            $context = " (" . trim($t['faculty'] . " " . $t['major']) . ")";
                                        }
                                    ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['fullname'] . $context) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" type="button" data-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-info"><i class="fas fa-save"></i> บันทึกการมอบหมาย</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
function clearCourseForm() {
    document.getElementById('courseFormAction').value = 'add_course';
    document.getElementById('courseModalTitle').innerText = 'เพิ่มรายวิชาใหม่';
    document.getElementById('course_id').value = '';
    document.getElementById('course_code').value = '';
    document.getElementById('course_name').value = '';
}

function editCourse(data) {
    $('#courseModal').modal('show');
    document.getElementById('courseFormAction').value = 'edit_course';
    document.getElementById('courseModalTitle').innerText = 'แก้ไขวิชา: ' + data.course_code;
    
    document.getElementById('course_id').value = data.id;
    document.getElementById('course_code').value = data.course_code;
    document.getElementById('course_name').value = data.course_name;
}

function openAssignModal(courseId, courseCode, courseName) {
    document.getElementById('assign_course_id').value = courseId;
    document.getElementById('assign_course_text').innerText = courseCode + ' - ' + courseName;
    
    $('#teacher_select').val(null).trigger('change');
    
    fetch('manage_courses.php?action=get_assigned_teachers&course_id=' + courseId)
        .then(response => response.json())
        .then(data => {
            if(data.success && data.teacher_ids.length > 0) {
                $('#teacher_select').val(data.teacher_ids).trigger('change');
            }
        });
        
    $('#assignModal').modal('show');
}

document.addEventListener('DOMContentLoaded', function() {
    $('.select2-multiple').select2({
        placeholder: "คลิกเพื่อค้นหา หรือเลือกรายชื่ออาจารย์...",
        allowClear: true,
        language: {
            noResults: function() { return "ไม่พบรายชื่ออาจารย์"; }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>