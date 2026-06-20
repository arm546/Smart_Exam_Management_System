<?php
$page_title = 'จัดการผู้ใช้งานระดับสูง';
require_once 'auth_guard.php';
require_once 'config/db.php';
require_role(['admin']);

$alert_msg = "";

// ---------------------------------------------------------
// 🆕 ระบบ Reset Password (สุ่มรหัสผ่าน 8 หลัก) - รับค่าผ่าน AJAX
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] == 'reset_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    
    // รับค่า JSON จาก Fetch API
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = $data['user_id'] ?? null;

    if ($user_id) {
        // สุ่มรหัสผ่านใหม่ (ตัวอักษรพิมพ์เล็ก พิมพ์ใหญ่ และตัวเลข)
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $new_password = substr(str_shuffle($chars), 0, 8);
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        try {
            // อัปเดตรหัสผ่าน (ป้องกันการเผลอไปรีเซ็ตไอดี admin ด้วยกันเอง)
            $stmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id AND role != 'admin'");
            $stmt->execute([':password' => $hashed_password, ':id' => $user_id]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'new_password' => $new_password]);
            } else {
                echo json_encode(['success' => false, 'error' => 'ไม่สามารถรีเซ็ตรหัสผ่านได้ (หรือเป็นไอดี Admin)']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูลผู้ใช้']);
    }
    exit(); // สั่ง exit เพื่อให้จบการทำงานเฉพาะส่วน API ไม่ต้องโหลด HTML ต่อ
}

// ---------------------------------------------------------
// ระบบ Export ข้อมูลผู้ใช้เป็น CSV
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=users_export_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Username', 'ชื่อ-นามสกุล', 'ประเภท', 'รหัสนักศึกษา', 'คณะ', 'สาขาวิชา', 'ชั้นปี', 'สถานะ']);
    $stmt = $conn->query("SELECT username, fullname, role, student_code, faculty, major, year_level, is_active FROM users WHERE role != 'admin' ORDER BY role ASC, id DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = ($row['is_active'] == 1) ? 'ปกติ' : 'ระงับ';
        fputcsv($output, [
            $row['username'], $row['fullname'], $row['role'], $row['student_code'], 
            $row['faculty'], $row['major'], $row['year_level'], $status
        ]);
    }
    fclose($output);
    exit(); 
}

// --- 🟢 ส่วนจัดการข้อมูล (เพิ่ม/แก้ไข/Toggle/Import) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. ระบบ Import ข้อมูลจาก CSV
    if ($_POST['action'] == 'import_csv') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            fgetcsv($handle, 1000, ","); // ข้าม Header
            
            $success = 0; $fail = 0;
            $chk_stmt = $conn->prepare("SELECT id FROM users WHERE username = :u");
            $ins_stmt = $conn->prepare("INSERT INTO users (username, password, fullname, role, student_code, faculty, major, year_level, is_active) VALUES (:u, :p, :f, :r, :sc, :fac, :maj, :yl, 1)");

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if(empty(array_filter($data))) continue; 
                $u = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', trim($data[0])); 
                $p = password_hash(trim($data[1]), PASSWORD_DEFAULT);
                $f = trim($data[2]);
                $r = strtolower(trim($data[3])) == 'teacher' ? 'teacher' : 'student';
                $sc = !empty($data[4]) ? trim($data[4]) : null;
                $fac = !empty($data[5]) ? trim($data[5]) : null;
                $maj = !empty($data[6]) ? trim($data[6]) : null;
                $yl = !empty($data[7]) ? (int)trim($data[7]) : null;

                $chk_stmt->execute([':u' => $u]);
                if ($chk_stmt->rowCount() == 0 && !empty($u)) {
                    try {
                        $ins_stmt->execute([':u'=>$u, ':p'=>$p, ':f'=>$f, ':r'=>$r, ':sc'=>$sc, ':fac'=>$fac, ':maj'=>$maj, ':yl'=>$yl]);
                        $success++;
                    } catch (Exception $e) { $fail++; }
                } else { $fail++; }
            }
            fclose($handle);
            $alert_msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> นำเข้าข้อมูลสำเร็จ <b>$success</b> รายการ (ข้ามรายการซ้ำ/ผิดพลาด $fail รายการ)</div>";
        } else {
            $alert_msg = "<div class='alert alert-danger'>เกิดข้อผิดพลาดในการอัปโหลดไฟล์</div>";
        }
    } 
    // 2. ระบบเพิ่มและแก้ไขผู้ใช้ (ทีละคน)
    else {
        $role = $_POST['role'];
        $username = trim($_POST['username']);
        $fullname = trim($_POST['fullname']);
        $student_code = ($role == 'student') ? trim($_POST['student_code']) : null;
        $faculty = trim($_POST['faculty']);
        $major = trim($_POST['major']);
        $year_level = ($role == 'student') ? (int)$_POST['year_level'] : null;

        if ($_POST['action'] == 'add_user') {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            try {
                $sql = "INSERT INTO users (username, password, fullname, role, student_code, faculty, major, year_level, is_active) 
                        VALUES (:u, :p, :f, :r, :sc, :fac, :maj, :yl, 1)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':u'=>$username, ':p'=>$password, ':f'=>$fullname, ':r'=>$role, ':sc'=>$student_code, ':fac'=>$faculty, ':maj'=>$major, ':yl'=>$year_level]);
                $alert_msg = "<div class='alert alert-success'><i class='fas fa-check-circle'></i> เพิ่มผู้ใช้สำเร็จ!</div>";
            } catch (PDOException $e) { $alert_msg = "<div class='alert alert-danger'>ข้อผิดพลาด: ".$e->getMessage()."</div>"; }

        } elseif ($_POST['action'] == 'edit_user') {
            $user_id = $_POST['user_id'];
            try {
                $sql = "UPDATE users SET username=:u, fullname=:f, student_code=:sc, faculty=:fac, major=:maj, year_level=:yl, role=:r WHERE id=:id";
                $params = [':u'=>$username, ':f'=>$fullname, ':sc'=>$student_code, ':fac'=>$faculty, ':maj'=>$major, ':yl'=>$year_level, ':r'=>$role, ':id'=>$user_id];
                
                if (!empty($_POST['new_password'])) {
                    $sql = "UPDATE users SET username=:u, password=:p, fullname=:f, student_code=:sc, faculty=:fac, major=:maj, year_level=:yl, role=:r WHERE id=:id";
                    $params[':p'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                }
                
                $conn->prepare($sql)->execute($params);
                $alert_msg = "<div class='alert alert-info'><i class='fas fa-check-circle'></i> อัปเดตข้อมูลสำเร็จ!</div>";
            } catch (PDOException $e) { $alert_msg = "<div class='alert alert-danger'>ข้อผิดพลาด: ".$e->getMessage()."</div>"; }
        }
    }
}

// 3. ระบบ เปิด/ปิด การใช้งานไอดีผ่าน GET
if (isset($_GET['toggle_id']) && isset($_GET['current_status'])) {
    $target_id = $_GET['toggle_id'];
    $new_status = ($_GET['current_status'] == 1) ? 0 : 1;
    $upd = $conn->prepare("UPDATE users SET is_active = :s WHERE id = :id AND role != 'admin'");
    $upd->execute([':s' => $new_status, ':id' => $target_id]);
    header("Location: manage_users.php");
    exit();
}

$users = $conn->query("
    SELECT u.*, 
           (SELECT GROUP_CONCAT(c.course_code SEPARATOR ', ') 
            FROM teacher_courses tc 
            JOIN courses c ON tc.course_id = c.id 
            WHERE tc.teacher_id = u.id) as assigned_courses
    FROM users u 
    WHERE u.role != 'admin' 
    ORDER BY u.role ASC, u.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div id="content-wrapper" class="d-flex flex-column">
    <div id="content">
        <?php include 'includes/topbar.php'; ?>
        <div class="container-fluid">
            
            <div>
                <a href="manage_users.php?action=export_csv" class="btn btn-info shadow-sm mr-2">
                    <i class="fas fa-file-download"></i> ส่งออก CSV
                </a>
                
                <button class="btn btn-success shadow-sm mr-2" data-toggle="modal" data-target="#importModal">
                    <i class="fas fa-file-excel"></i> นำเข้าจาก CSV
                </button>
                <button class="btn btn-primary shadow-sm" data-toggle="modal" data-target="#userModal" onclick="clearForm()">
                    <i class="fas fa-plus"></i> เพิ่มผู้ใช้ใหม่
                </button>
            </div>
                        
            <?= $alert_msg ?>

            <div class="card shadow mb-4 mt-3">
                <div class="card-body text-xs text-dark">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-dark" id="dataTable" width="100%" cellspacing="0">
                            <thead class="bg-light">
                                <tr class="text-center">
                                    <th>Username</th>
                                    <th>ชื่อ-นามสกุล</th>
                                    <th>ประเภท</th>
                                    <th>รหัสนักศึกษา</th>
                                    <th>คณะ</th>
                                    <th>สาขาวิชา</th>
                                    <th>วิชาที่สอน</th>
                                    <th width="80">สถานะ</th>
                                    <th width="140">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($users as $u): ?>
                                <tr>
                                    <td class="font-weight-bold"><?= htmlspecialchars($u['username']) ?></td>
                                    <td><?= htmlspecialchars($u['fullname']) ?></td>
                                    <td class="text-center">
                                        <?php if($u['role'] == 'teacher'): ?>
                                            <span class="badge badge-info"><i class="fas fa-chalkboard-teacher"></i> อาจารย์</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary"><i class="fas fa-user-graduate"></i> นักศึกษา</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-primary font-weight-bold">
                                        <?= !empty($u['student_code']) ? htmlspecialchars($u['student_code']) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td><?= !empty($u['faculty']) ? htmlspecialchars($u['faculty']) : '<span class="text-muted">-</span>' ?></td>
                                    <td><?= !empty($u['major']) ? htmlspecialchars($u['major']) : '<span class="text-muted">-</span>' ?></td>
                                    <td class="text-center">
                                        <?php if($u['role'] == 'teacher'): ?>
                                            <?php if(!empty($u['assigned_courses'])): ?>
                                                <span class="badge badge-primary">
                                                    <?= str_replace(', ', '</span> <span class="badge badge-primary">', htmlspecialchars($u['assigned_courses'])) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted text-xs">ยังไม่กำหนด</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($u['is_active'] == 1): ?>
                                            <span class="badge badge-success badge-pill">ปกติ</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger badge-pill">ระงับ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-info" onclick="resetPassword(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')" title="รีเซ็ตรหัสผ่าน">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <button class="btn btn-sm btn-warning" onclick='editUser(<?= json_encode($u) ?>)' title="แก้ไขข้อมูล">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="manage_users.php?toggle_id=<?= $u['id'] ?>&current_status=<?= $u['is_active'] ?>" 
                                               class="btn btn-sm <?= $u['is_active'] == 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                               onclick="return confirm('ยืนยันการเปลี่ยนสถานะ?')" title="เปิด/ปิดการใช้งาน">
                                                <i class="fas <?= $u['is_active'] == 1 ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
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

    <div class="modal fade" id="userModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content text-dark">
                <form action="manage_users.php" method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="modalTitle">เพิ่มผู้ใช้ใหม่</h5>
                        <button class="close text-white" type="button" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add_user">
                        <input type="hidden" name="user_id" id="user_id">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Username <span class="text-danger">*</span></label>
                                <input type="text" name="username" id="username" class="form-control" required>
                            </div>
                            <div class="form-group col-md-6">
                                <label id="passLabel">รหัสผ่าน <span class="text-danger">*</span></label>
                                <input type="password" name="password" id="password" class="form-control">
                                <small id="passHelp" class="text-muted" style="display:none;">เว้นว่างหากไม่ต้องการเปลี่ยนรหัสผ่าน</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                                <input type="text" name="fullname" id="fullname" class="form-control" required>
                            </div>
                            <div class="form-group col-md-6">
                                <label>ประเภทผู้ใช้</label>
                                <select name="role" id="role" class="form-control" onchange="toggleStudentFields()">
                                    <option value="student">นักศึกษา</option>
                                    <option value="teacher">อาจารย์</option>
                                </select>
                            </div>
                        </div>
                        <div id="student_fields">
                            <div class="form-row text-primary">
                                <div class="form-group col-md-4"><label>รหัสนักศึกษา</label><input type="text" name="student_code" id="student_code" class="form-control border-left-primary"></div>
                                <div class="form-group col-md-4"><label>คณะ</label><input type="text" name="faculty" id="faculty" class="form-control border-left-primary"></div>
                                <div class="form-group col-md-4"><label>สาขาวิชา</label><input type="text" name="major" id="major" class="form-control border-left-primary"></div>
                            </div>
                            <div class="form-group"><label>ชั้นปีที่</label><input type="number" name="year_level" id="year_level" class="form-control border-left-primary" min="1" max="8"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" type="button" data-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content text-dark">
                <form action="manage_users.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-file-excel"></i> นำเข้าข้อมูลผู้ใช้งาน (CSV)</h5>
                        <button class="close text-white" type="button" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="import_csv">
                        <div class="form-group">
                            <label class="font-weight-bold">เลือกไฟล์ CSV (UTF-8)</label>
                            <input type="file" name="csv_file" class="form-control-file" accept=".csv" required>
                        </div>
                        <div class="alert alert-info small mb-0">
                            <strong>คำแนะนำคอลัมน์ (ต้องมี Header และเรียงตามลำดับดังนี้):</strong><br>
                            1. username<br>2. password<br>3. fullname<br>4. role (พิมพ์ student หรือ teacher)<br>5. student_code<br>6. faculty<br>7. major<br>8. year_level
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

<script>
// ---------------------------------------------------------
// 🆕 ฟังก์ชันสำหรับ Reset Password ผ่าน SweetAlert2 + AJAX
// ---------------------------------------------------------
function resetPassword(userId, username) {
    // ต้องตรวจสอบว่ามีการโหลด SweetAlert มาใน header/footer แล้ว
    if (typeof Swal === 'undefined') {
        alert("กรุณาติดตั้ง/เรียกใช้ SweetAlert2 เพื่อใช้งานฟีเจอร์นี้");
        return;
    }

    Swal.fire({
        icon: "warning",
        title: `รีเซ็ตรหัสผ่านของ<br><span class="text-primary">${username}</span> ?`,
        text: "ระบบจะทำการสุ่มรหัสผ่านใหม่ 8 หลัก",
        showCancelButton: true,
        confirmButtonText: "ยืนยันการรีเซ็ต",
        cancelButtonText: "ยกเลิก",
        confirmButtonColor: "#36b9cc"
    }).then(res => {
        if (!res.isConfirmed) return;

        Swal.showLoading();

        // ยิง Fetch API ไปที่ไฟล์ตัวเอง พร้อมส่งตัวแปร action=reset_ajax
        fetch("manage_users.php?action=reset_ajax", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({ user_id: userId })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                Swal.fire("ผิดพลาด", data.error, "error");
                return;
            }

            // แสดงหน้าจอสำเร็จพร้อมรหัสผ่านที่สุ่มได้ให้ Admin ก๊อปปี้
            Swal.fire({
                icon: "success",
                title: "รีเซ็ตรหัสผ่านสำเร็จ!",
                html: `
                    <div style="font-size:18px; margin-top:10px;">
                        รหัสผ่านใหม่คือ:<br>
                        <span style="font-size:28px; font-weight:bold; color:#e74a3b; background:#f8f9fc; padding:5px 15px; border-radius:5px; border:1px solid #ddd; display:inline-block; margin-top:10px;">
                            ${data.new_password}
                        </span>
                    </div>
                    <small class="text-muted d-block mt-3">กรุณาคัดลอกรหัสผ่านนี้ส่งให้ผู้ใช้งาน</small>
                `,
                confirmButtonText: "ตกลง"
            });
        })
        .catch(err => {
            Swal.fire("ผิดพลาด", "ไม่สามารถติดต่อเซิร์ฟเวอร์ได้", "error");
        });
    });
}

function toggleStudentFields() {
    const role = document.getElementById('role').value;
    document.getElementById('student_fields').style.display = (role === 'student') ? 'block' : 'none';
}

function clearForm() {
    document.getElementById('formAction').value = 'add_user';
    document.getElementById('modalTitle').innerText = 'เพิ่มผู้ใช้ใหม่';
    document.getElementById('password').required = true;
    document.getElementById('password').name = 'password';
    document.getElementById('passHelp').style.display = 'none';
    document.getElementById('username').readOnly = false;
    
    document.getElementById('user_id').value = '';
    document.getElementById('username').value = '';
    document.getElementById('password').value = '';
    document.getElementById('fullname').value = '';
    document.getElementById('role').value = 'student';
    document.getElementById('student_code').value = '';
    document.getElementById('faculty').value = '';
    document.getElementById('major').value = '';
    document.getElementById('year_level').value = '';
    
    toggleStudentFields();
}

function editUser(data) {
    $('#userModal').modal('show');
    document.getElementById('formAction').value = 'edit_user';
    document.getElementById('modalTitle').innerText = 'แก้ไขข้อมูลผู้ใช้: ' + data.username;
    
    document.getElementById('user_id').value = data.id;
    document.getElementById('username').value = data.username;
    document.getElementById('username').readOnly = true; 
    document.getElementById('fullname').value = data.fullname;
    document.getElementById('role').value = data.role;
    
    document.getElementById('student_code').value = data.student_code || '';
    document.getElementById('faculty').value = data.faculty || '';
    document.getElementById('major').value = data.major || '';
    document.getElementById('year_level').value = data.year_level || '';
    
    document.getElementById('password').required = false;
    document.getElementById('password').name = 'new_password';
    document.getElementById('password').value = ''; 
    document.getElementById('passHelp').style.display = 'block';
    
    toggleStudentFields();
}

window.onload = function() {
    toggleStudentFields();
};
</script>
<?php include 'includes/footer.php'; ?>