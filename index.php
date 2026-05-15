<?php
/**
 * HỆ THỐNG QUẢN LÝ THƯ VIỆN ELITE 6.0 - ULTIMATE EDITION
 * Tích hợp: Tiền cọc, Báo mất sách, In hóa đơn, Quét QR, AI Gợi ý, Xuất Excel, Live Search.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==================== DATABASE ====================
define('DB_HOST', 'localhost'); define('DB_NAME', 'library_db'); define('DB_USER', 'root'); define('DB_PASS', '');
function getDB() {
    static $conn;
    if (!$conn) {
        try {
            $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) { die("Kết nối thất bại!"); }
    }
    return $conn;
}

// ==================== INIT & LOGS ====================
function initSystem() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
    $db->exec("INSERT IGNORE INTO settings VALUES ('library_name', 'ELITE LIBRARY'), ('fine_rate', '5000'), ('theme_color', '#4f46e5'), ('shift_morning_start', '08:00'), ('shift_afternoon_start', '13:30'), ('shift_evening_start', '18:00'), ('max_books', '5'), ('default_loan_days', '14')");
    $db->exec("CREATE TABLE IF NOT EXISTS logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, action VARCHAR(100), details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    
    // Tự động thêm các cột cần thiết vào bảng borrowings nếu chưa có
    try { $db->exec("ALTER TABLE borrowings ADD COLUMN deposit INT DEFAULT 0"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE borrowings ADD COLUMN quantity INT DEFAULT 1"); } catch(PDOException $e) {}

    // --- CẬP NHẬT HỆ THỐNG NHÂN SỰ ---
    $db->exec("CREATE TABLE IF NOT EXISTS attendance (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, date DATE, check_in TIME, check_out TIME, status VARCHAR(50))");
    $db->exec("CREATE TABLE IF NOT EXISTS schedules (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, shift_date DATE, shift_name VARCHAR(100), status VARCHAR(50))");
    $db->exec("CREATE TABLE IF NOT EXISTS tasks (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, title VARCHAR(255), description TEXT, due_date DATE, status VARCHAR(50) DEFAULT 'Pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME)");
    try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) AFTER email"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN address TEXT AFTER phone"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN warning_msg TEXT AFTER role"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN position VARCHAR(100) AFTER full_name"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN salary INT DEFAULT 0 AFTER position"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE books ADD COLUMN price INT DEFAULT 0"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE members ADD COLUMN rank VARCHAR(20) DEFAULT 'Standard'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE members ADD COLUMN status VARCHAR(20) DEFAULT 'Active'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE books ADD COLUMN shelf_location VARCHAR(100) AFTER quantity"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE books ADD COLUMN ddc_code VARCHAR(50) AFTER shelf_location"); } catch(Exception $e) {}
    
    // --- KHO HÀNG & NHÀ CUNG CẤP ---
    $db->exec("CREATE TABLE IF NOT EXISTS suppliers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), contact VARCHAR(100), address TEXT)");
    $db->exec("INSERT IGNORE INTO suppliers (id, name) VALUES (1, 'NXB Giáo Dục'), (2, 'NXB Trẻ'), (3, 'Nhà sách Fahasa')");
    $db->exec("CREATE TABLE IF NOT EXISTS book_imports (id INT AUTO_INCREMENT PRIMARY KEY, supplier_id INT, import_date DATE, total_amount INT, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS import_details (id INT AUTO_INCREMENT PRIMARY KEY, import_id INT, book_id INT, quantity INT, unit_price INT)");
    
    // --- THƯ VIỆN SỐ & CỘNG ĐỒNG ---
    $db->exec("CREATE TABLE IF NOT EXISTS book_quotes (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT, book_id INT, content TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    try { $db->exec("ALTER TABLE books ADD COLUMN digital_file VARCHAR(255) DEFAULT NULL"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE books ADD COLUMN is_digital TINYINT DEFAULT 0"); } catch(Exception $e) {}
    
    // --- HỆ THỐNG NHẬN SÁCH HIỆN ĐẠI (LOCKER & DELIVERY) ---
    $db->exec("CREATE TABLE IF NOT EXISTS borrow_requests (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        member_id INT, 
        book_id INT, 
        pickup_method VARCHAR(20), -- Counter, Locker, Delivery
        delivery_address TEXT,
        locker_code VARCHAR(20),
        status VARCHAR(20) DEFAULT 'Pending', -- Pending, Approved, Ready, Shipping, Completed, Rejected
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // --- TÍNH NĂNG ĐỘC GIẢ NÂNG CAO ---
    $db->exec("CREATE TABLE IF NOT EXISTS reservations (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT, book_id INT, status VARCHAR(20) DEFAULT 'Pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, member_id INT, title VARCHAR(255), message TEXT, is_read TINYINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    try { $db->exec("ALTER TABLE members ADD COLUMN password VARCHAR(255) AFTER phone"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE borrowings ADD COLUMN renewal_count INT DEFAULT 0"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE attendance ADD COLUMN standin_id INT DEFAULT NULL"); } catch(PDOException $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN emergency_phone VARCHAR(20) AFTER phone"); } catch(PDOException $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS book_requests (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT, title VARCHAR(255), author VARCHAR(255), status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    
    // --- TÍNH NĂNG ĐÁNH GIÁ & SỰ KIỆN & TÀI CHÍNH ---
    $db->exec("CREATE TABLE IF NOT EXISTS book_reviews (id INT AUTO_INCREMENT PRIMARY KEY, member_id INT, book_id INT, rating INT, comment TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS events (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255), description TEXT, event_date DATETIME, location VARCHAR(100), max_participants INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS event_participants (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT, member_id INT, joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50), amount INT, details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); // Tracking all money flow
}
initSystem();

function addLog($action, $details = '') {
    $db = getDB(); $stmt = $db->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'] ?? 0, $action, $details]);
}

// --- THUẬT TOÁN AI GỢI Ý SÁCH ---
function getBookRecommendations($db, $limit = 3) {
    $sql = "
        SELECT bk.title, COUNT(*) as frequency
        FROM borrowings b1
        JOIN borrowings b2 ON b1.member_id = b2.member_id AND b1.book_id != b2.book_id
        JOIN books bk ON b2.book_id = bk.id
        WHERE b1.status = 'borrowed' OR b1.status = 'returned'
        GROUP BY bk.id
        ORDER BY frequency DESC
        LIMIT $limit
    ";
    return $db->query($sql)->fetchAll();
}

$settings = getDB()->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
define('LIB_NAME', $settings['library_name'] ?? 'ELITE LIBRARY');
define('FINE_RATE', (int)($settings['fine_rate'] ?? 5000));
define('THEME_COLOR', $settings['theme_color'] ?? '#4f46e5');
define('MAX_BOOKS', (int)($settings['max_books'] ?? 5));
define('LOAN_DAYS', (int)($settings['default_loan_days'] ?? 14));
define('SHIFT_MORNING', $settings['shift_morning_start'] ?? '08:00');
define('SHIFT_AFTERNOON', $settings['shift_afternoon_start'] ?? '13:30');
define('SHIFT_EVENING', $settings['shift_evening_start'] ?? '18:00');

$db = getDB();

// --- LOGIC HẠNG THẺ THỰC TẾ ---
function getMemberLimits($rank) {
    if ($rank === 'VIP') return ['max' => 15, 'days' => 30, 'label' => 'Thượng lưu'];
    return ['max' => 5, 'days' => 14, 'label' => 'Tiêu chuẩn'];
}

function sanitize($data) { return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8'); }
function redirect($url) { header("Location: $url"); exit; }
function setFlash($type, $message) { $_SESSION['flash'] = ['type' => $type, 'message' => $message]; }
function getFlash() { $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $flash; }

// ==================== ACTIONS (GET) - XUẤT EXCEL ====================
if (isset($_GET['action']) && isset($_SESSION['user_id'])) {
    $action = $_GET['action'];
    if (in_array($action, ['export_books', 'export_members', 'export_borrowings', 'export_staff'])) {
        header('Content-Type: text/csv; charset=utf-8');
        $filename = 'Library_Export_' . str_replace('export_', '', $action) . '_' . date('Ymd_His') . '.csv';
        header("Content-Disposition: attachment; filename=$filename");
        echo "\xEF\xBB\xBF"; // BOM for UTF-8
        $output = fopen('php://output', 'w');
        
        $db = getDB();
        if ($action === 'export_books') {
            fputcsv($output, ['ID', 'Tên sách', 'Tác giả', 'ISBN', 'SL', 'Giá']);
            $rows = $db->query("SELECT id, title, author, isbn, quantity, price FROM books")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($action === 'export_members') {
            fputcsv($output, ['ID', 'Họ tên', 'SĐT', 'Hạng', 'Hạn thẻ']);
            $rows = $db->query("SELECT id, full_name, phone, rank, expiry_date FROM members")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($action === 'export_borrowings') {
            fputcsv($output, ['ID', 'Độc giả', 'Sách', 'Ngày mượn', 'Hạn trả', 'TT']);
            $rows = $db->query("SELECT b.id, m.full_name, bk.title, b.borrow_date, b.due_date, b.status FROM borrowings b JOIN members m ON b.member_id=m.id JOIN books bk ON b.book_id=bk.id")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($action === 'export_staff') {
            fputcsv($output, ['ID', 'Họ tên', 'Vị trí', 'Lương', 'Email']);
            $rows = $db->query("SELECT id, full_name, position, salary, email FROM users WHERE role='librarian'")->fetchAll(PDO::FETCH_ASSOC);
        }
        
        foreach ($rows as $row) { fputcsv($output, $row); }
        fclose($output); exit;
    }
}

// ==================== ACTIONS (POST) ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB(); $action = $_POST['action'] ?? '';
    
    if ($action === 'login') {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?"); $stmt->execute([sanitize($_POST['username'])]);
        $u = $stmt->fetch(); if ($u && password_verify($_POST['password'], $u['password'])) {
            $_SESSION['user_id'] = $u['id']; $_SESSION['username'] = $u['username']; $_SESSION['role'] = $u['role']; $_SESSION['full_name'] = $u['full_name'];
            addLog('Đăng nhập hệ thống'); 
            
            if ($u['role'] === 'librarian') {
                $check = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = CURRENT_DATE");
                $check->execute([$u['id']]);
                if (!$check->fetch()) {
                    $status = 'Present';
                    $sched = $db->prepare("SELECT shift_name FROM schedules WHERE user_id = ? AND shift_date = CURRENT_DATE AND status = 'Assigned'");
                    $sched->execute([$u['id']]);
                    $sRow = $sched->fetch();
                    if($sRow) {
                        $shiftTimeStr = '';
                        preg_match('/(\d{1,2}:\d{2})/', $sRow['shift_name'], $matches);
                        if ($matches) { $shiftTimeStr = $matches[1]; }
                        else {
                            if(strpos($sRow['shift_name'], 'Sáng') !== false) $shiftTimeStr = SHIFT_MORNING;
                            elseif(strpos($sRow['shift_name'], 'Chiều') !== false) $shiftTimeStr = SHIFT_AFTERNOON;
                            elseif(strpos($sRow['shift_name'], 'Tối') !== false) $shiftTimeStr = SHIFT_EVENING;
                            else $shiftTimeStr = '08:00';
                        }
                        $startTime = strtotime(date('Y-m-d ').$shiftTimeStr);
                        if(time() > $startTime + 600) $status = 'Late';
                    }
                    $db->prepare("INSERT INTO attendance (user_id, date, check_in, status) VALUES (?, CURRENT_DATE, CURRENT_TIME, ?)")
                       ->execute([$u['id'], $status]);
                    addLog('Tự động chấm công', 'Trạng thái: '.$status);
                }
            }
            
            redirect('?page=dashboard');
        } else {
            // Thử đăng nhập bằng tài khoản độc giả
            $stmt = $db->prepare("SELECT * FROM members WHERE member_code = ?");
            $stmt->execute([sanitize($_POST['username'])]);
            $m = $stmt->fetch();
            // Cho phép đăng nhập bằng SĐT nếu chưa có mật khẩu, hoặc pass nếu có
            if ($m && ($_POST['password'] === $m['phone'] || ($m['password'] && password_verify($_POST['password'], $m['password'])))) {
                $_SESSION['member_id'] = $m['id']; $_SESSION['role'] = 'member'; $_SESSION['full_name'] = $m['full_name'];
                setFlash('success', 'Chào mừng độc giả '.$m['full_name'].' quay lại!');
                redirect('?page=opac');
            } else {
                setFlash('error', 'Sai tài khoản hoặc mật khẩu!');
            }
        }
    } elseif ($action === 'register') {
        $db->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, 'librarian')")
           ->execute([sanitize($_POST['username']), password_hash($_POST['password'], PASSWORD_DEFAULT), sanitize($_POST['full_name']), sanitize($_POST['email'])]);
        setFlash('success', 'Đăng ký thành công!'); redirect('?page=login');
    
    } elseif (isset($_SESSION['user_id'])) {
        // --- SÁCH ---
        if ($action === 'add_book') {
            $isbn = sanitize($_POST['isbn']);
            $check = $db->prepare("SELECT title FROM books WHERE isbn = ?");
            $check->execute([$isbn]);
            $existing = $check->fetch();
            
            if ($existing) {
                setFlash('error', "Lỗi: Mã sách (ISBN) '$isbn' đã tồn tại! Sách trùng tên: <strong>" . $existing['title'] . "</strong>");
            } else {
                $db->prepare("INSERT INTO books (isbn, title, author, category_id, quantity, shelf_location, ddc_code, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$isbn, sanitize($_POST['title']), sanitize($_POST['author']), (int)$_POST['category_id'], (int)$_POST['quantity'], sanitize($_POST['shelf_location']), sanitize($_POST['ddc_code']), (int)$_POST['price']]);
                addLog('Thêm sách', $_POST['title']); setFlash('success', 'Đã thêm sách thành công!');
            }
        } elseif ($action === 'edit_book') {
            $db->prepare("UPDATE books SET title=?, author=?, category_id=?, isbn=?, quantity=?, shelf_location=?, ddc_code=?, price=? WHERE id=?")
               ->execute([sanitize($_POST['title']), sanitize($_POST['author']), (int)$_POST['category_id'], sanitize($_POST['isbn']), (int)$_POST['quantity'], sanitize($_POST['shelf_location']), sanitize($_POST['ddc_code']), (int)$_POST['price'], (int)$_POST['id']]);
            addLog('Sửa sách', 'ID: ' . $_POST['id']); setFlash('success', 'Đã cập nhật thông tin sách!');
        } elseif ($action === 'delete_book') {
            $check = $db->prepare("SELECT COUNT(*) FROM borrowings WHERE book_id = ? AND status = 'borrowed'"); $check->execute([(int)$_POST['id']]);
            if ($check->fetchColumn() > 0) { setFlash('error', 'Không thể xóa! Sách này đang có người mượn.'); } 
            else {
                $db->prepare("DELETE FROM books WHERE id = ?")->execute([(int)$_POST['id']]);
                addLog('Xóa sách', 'ID: ' . $_POST['id']); setFlash('success', 'Đã xóa sách thành công!');
            }
        
            }
        
        // --- NHẬP KHO (INVENTORY) ---
        } elseif ($action === 'import_books' && $_SESSION['role'] === 'admin') {
            $supplier_id = (int)$_POST['supplier_id'];
            $import_date = $_POST['import_date'];
            $total = 0;
            $items = $_POST['items']; // array: book_id => [qty, price]
            
            $db->prepare("INSERT INTO book_imports (supplier_id, import_date, total_amount) VALUES (?, ?, ?)")->execute([$supplier_id, $import_date, 0]);
            $import_id = $db->lastInsertId();
            
            foreach ($items as $book_id => $data) {
                if ($data['qty'] > 0) {
                    $qty = (int)$data['qty']; $uPrice = (int)$data['price'];
                    $db->prepare("INSERT INTO import_details (import_id, book_id, quantity, unit_price) VALUES (?, ?, ?, ?)")->execute([$import_id, $book_id, $qty, $uPrice]);
                    $db->prepare("UPDATE books SET quantity = quantity + ? WHERE id = ?")->execute([$qty, $book_id]);
                    $total += $qty * $uPrice;
                }
            }
            $db->prepare("UPDATE book_imports SET total_amount = ? WHERE id = ?")->execute([$total, $import_id]);
            addLog('Nhập kho sách', 'ID Nhập: '.$import_id); setFlash('success', 'Đã nhập kho thành công! Tổng giá trị: '.number_format($total).'đ');
        
        // --- ĐỘC GIẢ ---
        } elseif ($action === 'add_member') {
            $phone = sanitize($_POST['phone']);
            $check = $db->prepare("SELECT full_name FROM members WHERE phone = ?");
            $check->execute([$phone]);
            $existing = $check->fetch();
            
            if ($existing && !empty($phone)) {
                setFlash('error', "Lỗi: Số điện thoại '$phone' đã được đăng ký cho độc giả: <strong>" . $existing['full_name'] . "</strong>");
            } else {
                $mCode = 'TV' . strtoupper(substr(uniqid(), -5));
                $db->prepare("INSERT INTO members (member_code, full_name, phone, expiry_date, rank, status) VALUES (?, ?, ?, ?, ?, ?)")
                   ->execute([$mCode, sanitize($_POST['full_name']), $phone, $_POST['expiry_date'], $_POST['rank'], $_POST['status']]);
                addLog('Thêm độc giả', $_POST['full_name']); setFlash('success', 'Đã tạo thẻ độc giả: ' . $mCode);
            }
        } elseif ($action === 'edit_member') {
            $phone = sanitize($_POST['phone']);
            $id = (int)$_POST['id'];
            $check = $db->prepare("SELECT full_name FROM members WHERE phone = ? AND id != ?");
            $check->execute([$phone, $id]);
            $existing = $check->fetch();
            
            if ($existing && !empty($phone)) {
                setFlash('error', "Lỗi: Số điện thoại '$phone' đã được dùng bởi độc giả khác: <strong>" . $existing['full_name'] . "</strong>");
            } else {
                $db->prepare("UPDATE members SET full_name=?, phone=?, expiry_date=?, rank=?, status=? WHERE id=?")
                   ->execute([sanitize($_POST['full_name']), $phone, $_POST['expiry_date'], $_POST['rank'], $_POST['status'], $id]);
                addLog('Sửa độc giả', 'ID: ' . $id); setFlash('success', 'Đã cập nhật thông tin độc giả!');
            }
        } elseif ($action === 'delete_member') {
            $check = $db->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id = ? AND status = 'borrowed'"); $check->execute([(int)$_POST['id']]);
            if ($check->fetchColumn() > 0) { setFlash('error', 'Không thể xóa! Độc giả này đang mượn sách chưa trả.'); } 
            else {
                $db->prepare("DELETE FROM members WHERE id = ?")->execute([(int)$_POST['id']]);
                addLog('Xóa độc giả', 'ID: ' . $_POST['id']); setFlash('success', 'Đã xóa thẻ độc giả thành công!');
            }

        // --- MƯỢN / TRẢ / BÁO MẤT SÁCH ---
        } elseif ($action === 'borrow_book') {
            $member_id = (int)$_POST['member_id'];
            $m = $db->prepare("SELECT * FROM members WHERE id = ?"); $m->execute([$member_id]); $member = $m->fetch();
            
            // 1. Kiểm tra trạng thái thẻ
            if ($member['status'] !== 'Active') {
                setFlash('error', 'KHÔNG THỂ CHO MƯỢN! Tài khoản này đang bị KHÓA do vi phạm quy định.');
            } else {
                // 2. Kiểm tra nợ quá hạn
                $checkOverdue = $db->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id = ? AND status = 'borrowed' AND due_date < CURRENT_DATE");
                $checkOverdue->execute([$member_id]);
                if ($checkOverdue->fetchColumn() > 0) {
                    setFlash('error', 'KHÔNG THỂ CHO MƯỢN! Độc giả này đang có sách quá hạn chưa trả.');
                } else {
                    // 3. Kiểm tra hạn mức dựa trên hạng thẻ
                    $rank = $member['rank'] ?: 'Standard';
                    $limits = getMemberLimits($rank);
                    $check = $db->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id = ? AND status = 'borrowed'");
                    $check->execute([$member_id]);
                    
                    if ($check->fetchColumn() >= $limits['max']) {
                        setFlash('error', "Độc giả hạng {$limits['label']} đã mượn tối đa {$limits['max']} cuốn!");
                    } else {
                        $due_date = date('Y-m-d', strtotime("+{$limits['days']} days"));
                        $db->prepare("INSERT INTO borrowings (member_id, book_id, borrow_date, due_date, deposit, quantity, status) VALUES (?, ?, ?, ?, ?, ?, 'borrowed')")
                           ->execute([$member_id, (int)$_POST['book_id'], $_POST['borrow_date'], $due_date, (int)$_POST['deposit'], (int)$_POST['quantity']]);
                        $db->prepare("UPDATE books SET quantity = quantity - ? WHERE id = ?")->execute([(int)$_POST['quantity'], (int)$_POST['book_id']]);
                        addLog('Lập phiếu mượn', 'Độc giả: '.$member['full_name']); 
                        setFlash('success', 'Đã lập phiếu mượn thành công!');
                    }
                }
            }
            
        } elseif ($action === 'return_book') {
            $stmt = $db->prepare("SELECT borrow_date, deposit FROM borrowings WHERE id = ?"); $stmt->execute([(int)$_POST['id']]);
            $borrowData = $stmt->fetch();
            $start = new DateTime($borrowData['borrow_date']); 
            $ret = new DateTime($_POST['return_date']);
            $days = $ret->diff($start)->days;
            
            // Tính phí: sau 1 ngày bắt đầu trừ (mượn 1 ngày = 0, mượn 2 ngày = trừ 1 ngày phí, hoặc mượn >0 ngày là trừ luôn)
            // Theo yêu cầu "sau 1 ngày bắt đầu trừ":
            $fee = 0;
            if ($days >= 1) {
                $fee = $days * FINE_RATE;
            }
            
            $deposit = $borrowData['deposit'] ?? 0;
            $refund = $deposit - $fee;
            
            $db->prepare("UPDATE borrowings SET return_date = ?, fine_amount = ?, status = 'returned' WHERE id = ?")
               ->execute([$_POST['return_date'], $fee, (int)$_POST['id']]);
            
            $msg = "Đã trả sách thành công! Tổng cộng mượn: $days ngày. ";
            $msg .= 'Phí thuê sách: '.number_format($fee).'đ. ';
            if($refund >= 0) $msg .= 'Tiền cọc còn lại hoàn cho khách: <strong>'.number_format($refund).'đ</strong>.';
            else $msg .= 'Cọc không đủ, khách cần nộp thêm: <strong>'.number_format(abs($refund)).'đ</strong>.';
            
            addLog('Trả sách', 'ID: '.$_POST['id'].' | Phí: '.$fee); setFlash('success', $msg);

        } elseif ($action === 'report_lost') {
            // Độc giả làm mất sách: Trạng thái = lost, Thu cọc, Giảm tồn kho sách.
            $db->prepare("UPDATE borrowings SET status = 'lost' WHERE id = ?")->execute([(int)$_POST['id']]);
            $stmt = $db->prepare("SELECT book_id, quantity FROM borrowings WHERE id = ?"); $stmt->execute([(int)$_POST['id']]);
            $bData = $stmt->fetch();
            $db->prepare("UPDATE books SET quantity = quantity - ? WHERE id = ?")->execute([$bData['quantity'], $bData['book_id']]);
            addLog('Báo mất sách', 'Phiếu ID: '.$_POST['id']); setFlash('error', 'Đã ghi nhận MẤT SÁCH! Tịch thu toàn bộ tiền cọc và trừ tồn kho.');

        } elseif ($action === 'report_damaged') {
            // Độc giả làm hỏng sách: Trạng thái = returned (nhưng phạt nặng), Giảm giá trị sách hoặc yêu cầu đền bù.
            $fine = (int)$_POST['damage_fine'];
            $db->prepare("UPDATE borrowings SET status = 'returned', fine_amount = fine_amount + ?, notes = 'Sách bị hỏng' WHERE id = ?")
               ->execute([$fine, (int)$_POST['id']]);
            addLog('Báo hỏng sách', 'ID: '.$_POST['id'].' | Phạt: '.$fine); 
            setFlash('warning', 'Đã ghi nhận SÁCH BỊ HỎNG! Tổng tiền phạt đã được cập nhật.');

        // --- CÀI ĐẶT ---
        } elseif ($action === 'update_settings' && $_SESSION['role'] === 'admin') {
            $keys = ['library_name' => 'lib_name', 'fine_rate' => 'fine_rate', 'theme_color' => 'theme_color', 'shift_morning_start' => 'shift_morning_start', 'shift_afternoon_start' => 'shift_afternoon_start', 'shift_evening_start' => 'shift_evening_start', 'max_books' => 'max_books', 'default_loan_days' => 'default_loan_days'];
            foreach($keys as $dbKey => $postKey) {
                if(isset($_POST[$postKey])) {
                    $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$_POST[$postKey], $dbKey]);
                }
            }
            addLog('Cập nhật cài đặt hệ thống');
            setFlash('success', 'Đã lưu cấu hình hệ thống!');
        
        // --- NHÂN SỰ (CHẤM CÔNG & LỊCH LÀM) ---
        } elseif ($action === 'staff_checkin') {
            $db = getDB();
            $sched = $db->prepare("SELECT shift_name FROM schedules WHERE user_id = ? AND shift_date = CURRENT_DATE AND status = 'Assigned'");
            $sched->execute([$_SESSION['user_id']]); $sRow = $sched->fetch();
            
            $status = 'Present';
            if ($sRow) {
                $shiftTimeStr = '';
                preg_match('/(\d{1,2}:\d{2})/', $sRow['shift_name'], $matches);
                if ($matches) { $shiftTimeStr = $matches[1]; }
                else {
                    if(strpos($sRow['shift_name'], 'Sáng') !== false) $shiftTimeStr = SHIFT_MORNING;
                    elseif(strpos($sRow['shift_name'], 'Chiều') !== false) $shiftTimeStr = SHIFT_AFTERNOON;
                    elseif(strpos($sRow['shift_name'], 'Tối') !== false) $shiftTimeStr = SHIFT_EVENING;
                    else $shiftTimeStr = '08:00';
                }
                
                $startTime = strtotime(date('Y-m-d ') . $shiftTimeStr);
                if(time() > $startTime + 600) $status = 'Late';
            }
            $db->prepare("INSERT INTO attendance (user_id, date, check_in, status) VALUES (?, CURRENT_DATE, CURRENT_TIME, ?)")->execute([$_SESSION['user_id'], $status]);
            addLog('Chấm công vào (Thủ công)', 'Trạng thái: '.$status);
            setFlash(($status == 'Late' ? 'error' : 'success'), 'Đã chấm công VÀO lúc ' . date('H:i') . ($status == 'Late' ? ' (BẠN ĐI MUỘN!)' : ''));
        } elseif ($action === 'staff_checkout') {
            $db->prepare("UPDATE attendance SET check_out = CURRENT_TIME WHERE user_id = ? AND date = CURRENT_DATE AND check_out IS NULL")
               ->execute([$_SESSION['user_id']]);
            addLog('Điểm danh ra về'); setFlash('success', 'Đã chấm công RA lúc ' . date('H:i'));
        } elseif ($action === 'assign_schedule' && $_SESSION['role'] === 'admin') {
            $db->prepare("INSERT INTO schedules (user_id, shift_date, shift_name, status) VALUES (?, ?, ?, 'Assigned')")
               ->execute([(int)$_POST['user_id'], $_POST['shift_date'], sanitize($_POST['shift_name'])]);
            addLog('Phân công lịch làm', 'User: '.$_POST['user_id']); setFlash('success', 'Đã phân công lịch làm việc!');
        } elseif ($action === 'update_staff_info' && $_SESSION['role'] === 'admin') {
            $db->prepare("UPDATE users SET full_name=?, email=?, phone=?, address=?, warning_msg=?, role=? WHERE id=?")
               ->execute([sanitize($_POST['full_name']), sanitize($_POST['email']), sanitize($_POST['phone']), sanitize($_POST['address']), sanitize($_POST['warning_msg']), $_POST['role'], (int)$_POST['id']]);
            addLog('Cập nhật thông tin NV', 'ID: '.$_POST['id']); setFlash('success', 'Đã cập nhật hồ sơ nhân viên!');
        } elseif ($action === 'clear_warning') {
            $db->prepare("UPDATE users SET warning_msg = NULL WHERE id = ?")->execute([$_SESSION['user_id']]);
            setFlash('success', 'Đã xác nhận lời nhắc từ Admin.');
        } elseif ($action === 'register_shift') {
            if (isset($_POST['edit_sched_id'])) {
                $db->prepare("UPDATE schedules SET shift_name = ? WHERE id = ? AND user_id = ? AND status = 'Pending'")
                   ->execute([sanitize($_POST['shift_name']), (int)$_POST['edit_sched_id'], $_SESSION['user_id']]);
                addLog('Cập nhật lịch làm', 'ID: ' . $_POST['edit_sched_id']); setFlash('success', 'Đã cập nhật yêu cầu đăng ký lịch!');
            } else {
                $db->prepare("INSERT INTO schedules (user_id, shift_date, shift_name, status) VALUES (?, ?, ?, 'Pending')")
                   ->execute([$_SESSION['user_id'], $_POST['shift_date'], sanitize($_POST['shift_name'])]);
                addLog('Đăng ký lịch làm', $_POST['shift_date']); setFlash('success', 'Đã gửi yêu cầu đăng ký lịch làm!');
            }
        } elseif ($action === 'register_weekly_shift') {
            $startDate = new DateTime($_POST['week_start']);
            $shifts = $_POST['shifts']; // Array of shift names
            $stmt = $db->prepare("INSERT INTO schedules (user_id, shift_date, shift_name, status) VALUES (?, ?, ?, 'Pending')");
            foreach ($shifts as $i => $shiftName) {
                if (!empty(trim($shiftName))) {
                    $currentDate = clone $startDate;
                    $currentDate->modify("+$i day");
                    $stmt->execute([$_SESSION['user_id'], $currentDate->format('Y-m-d'), sanitize($shiftName)]);
                }
            }
            addLog('Đăng ký lịch tuần', 'Từ ngày: ' . $_POST['week_start']);
            setFlash('success', 'Đã gửi yêu cầu đăng ký lịch làm việc cho cả tuần!');
        } elseif ($action === 'delete_schedule') {
            // Chỉ xóa được nếu đang ở trạng thái Pending
            $db->prepare("DELETE FROM schedules WHERE id = ? AND user_id = ? AND status = 'Pending'")
               ->execute([(int)$_POST['id'], $_SESSION['user_id']]);
            setFlash('success', 'Đã hủy yêu cầu đăng ký lịch.');
        } elseif ($action === 'approve_schedule' && $_SESSION['role'] === 'admin') {
            $db->prepare("UPDATE schedules SET status = 'Assigned' WHERE id = ?")->execute([(int)$_POST['id']]);
            addLog('Duyệt lịch làm', 'ID: '.$_POST['id']); setFlash('success', 'Đã duyệt lịch làm việc.');
        } elseif ($action === 'reject_schedule' && $_SESSION['role'] === 'admin') {
            $db->prepare("UPDATE schedules SET status = 'Rejected' WHERE id = ?")->execute([(int)$_POST['id']]);
            addLog('Từ chối lịch làm', 'ID: '.$_POST['id']); setFlash('error', 'Đã từ chối lịch làm việc.');
        } elseif ($action === 'update_profile') {
            $db->prepare("UPDATE users SET full_name=?, email=?, phone=?, address=? WHERE id=?")
               ->execute([sanitize($_POST['full_name']), sanitize($_POST['email']), sanitize($_POST['phone']), sanitize($_POST['address']), $_SESSION['user_id']]);
            $_SESSION['full_name'] = $_POST['full_name'];
            addLog('Cập nhật thông tin cá nhân'); setFlash('success', 'Đã cập nhật thông tin cá nhân của bạn!');
        
        // --- GIAO VIỆC (TASKS) ---
        } elseif ($action === 'add_task' && $_SESSION['role'] === 'admin') {
            $db->prepare("INSERT INTO tasks (user_id, title, description, due_date) VALUES (?, ?, ?, ?)")
               ->execute([(int)$_POST['user_id'], sanitize($_POST['title']), sanitize($_POST['description']), $_POST['due_date']]);
            addLog('Giao việc mới', 'User ID: '.$_POST['user_id'].' | '.$_POST['title']); setFlash('success', 'Đã giao công việc mới!');
        } elseif ($action === 'complete_task') {
            $db->prepare("UPDATE tasks SET status = 'Completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([(int)$_POST['id']]);
            addLog('Hoàn thành công việc', 'Task ID: '.$_POST['id']); setFlash('success', 'Chúc mừng! Bạn đã hoàn thành công việc.');
        } elseif ($action === 'delete_task' && $_SESSION['role'] === 'admin') {
            $db->prepare("DELETE FROM tasks WHERE id = ?")->execute([(int)$_POST['id']]);
            addLog('Xóa công việc', 'Task ID: '.$_POST['id']); setFlash('success', 'Đã xóa công việc.');
        } elseif ($action === 'change_password') {
            $old = $_POST['old_password']; $new = $_POST['new_password']; $confirm = $_POST['confirm_password'];
            $id = $_SESSION['user_id'] ?? $_SESSION['member_id'];
            $table = isset($_SESSION['user_id']) ? 'users' : 'members';
            $stmt = $db->prepare("SELECT password, phone FROM $table WHERE id = ?"); $stmt->execute([$id]);
            $u = $stmt->fetch();
            
            $validOld = ($u['password'] && password_verify($old, $u['password'])) || (!$u['password'] && $old === $u['phone']);
            
            if ($validOld) {
                if ($new === $confirm) {
                    $db->prepare("UPDATE $table SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
                    setFlash('success', 'Mật khẩu đã được cập nhật!');
                } else { setFlash('error', 'Mật khẩu mới không khớp!'); }
            } else { setFlash('error', 'Mật khẩu cũ không chính xác!'); }
        } elseif ($action === 'report_issue' && isset($_SESSION['user_id'])) {
            $type = sanitize($_POST['issue_type']);
            $msg = sanitize($_POST['message']);
            $db->prepare("INSERT INTO logs (user_id, action, details) VALUES (?, ?, ?)")
               ->execute([$_SESSION['user_id'], 'Báo cáo sự cố: '.$type, $msg]);
            $db->prepare("INSERT INTO notifications (user_id, title, message) SELECT id, 'Sự cố nhân sự', ? FROM users WHERE role='admin'")
               ->execute(["Nhân viên {$_SESSION['username']} báo cáo: $type - $msg"]);
            setFlash('success', 'Đã gửi báo cáo cho quản trị viên.');
        } elseif ($action === 'assign_standin' && $_SESSION['role'] === 'admin') {
            $db->prepare("UPDATE attendance SET standin_id = ?, status = 'Stand-in' WHERE id = ?")
               ->execute([(int)$_POST['standin_id'], (int)$_POST['attendance_id']]);
            setFlash('success', 'Đã phân công nhân viên trực thay thành công!');
        } elseif ($action === 'update_staff_info' && $_SESSION['role'] === 'admin') {
            $db->prepare("UPDATE users SET full_name=?, position=?, salary=?, email=?, phone=?, address=?, warning_msg=? WHERE id=?")
               ->execute([sanitize($_POST['full_name']), sanitize($_POST['position']), (int)$_POST['salary'], sanitize($_POST['email']), sanitize($_POST['phone']), sanitize($_POST['address']), sanitize($_POST['warning_msg']), (int)$_POST['id']]);
            addLog('Cập nhật hồ sơ nhân viên', 'ID: '.$_POST['id']); setFlash('success', 'Đã cập nhật hồ sơ nhân viên thành công!');
        } elseif ($action === 'add_staff' && $_SESSION['role'] === 'admin') {
            $pass = password_hash($_POST['phone'], PASSWORD_DEFAULT); // Mặc định pass là số điện thoại
            $db->prepare("INSERT INTO users (username, password, full_name, role, position, salary, email, phone) VALUES (?, ?, ?, 'librarian', ?, ?, ?, ?)")
               ->execute([sanitize($_POST['username']), $pass, sanitize($_POST['full_name']), sanitize($_POST['position']), (int)$_POST['salary'], sanitize($_POST['email']), sanitize($_POST['phone'])]);
            addLog('Thêm nhân viên mới', $_POST['username']); setFlash('success', 'Đã thêm nhân viên mới thành công!');
        }
        
        // --- TÍNH NĂNG ĐỘC GIẢ ---
        if ($action === 'reserve_book' && $_SESSION['role'] === 'member') {
            $db->prepare("INSERT INTO reservations (member_id, book_id) VALUES (?, ?)")->execute([$_SESSION['member_id'], (int)$_POST['book_id']]);
            setFlash('success', 'Đã đăng ký đặt chỗ sách thành công!');
        } elseif ($action === 'renew_book' && $_SESSION['role'] === 'member') {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("SELECT * FROM borrowings WHERE id = ? AND member_id = ? AND status = 'borrowed'");
            $stmt->execute([$id, $_SESSION['member_id']]);
            $b = $stmt->fetch();
            if ($b && ($b['renewal_count'] ?? 0) < 2) {
                $newDue = date('Y-m-d', strtotime($b['due_date'] . ' + 7 days'));
                $db->prepare("UPDATE borrowings SET due_date = ?, renewal_count = IFNULL(renewal_count,0) + 1 WHERE id = ?")->execute([$newDue, $id]);
                setFlash('success', 'Đã gia hạn thêm 7 ngày thành công!');
            } else { setFlash('error', 'Không thể gia hạn thêm (tối đa 2 lần)!'); }
        } elseif ($action === 'borrow_digital' && $_SESSION['role'] === 'member') {
            $book_id = (int)$_POST['book_id'];
            $due = date('Y-m-d', strtotime('+7 days'));
            $db->prepare("INSERT INTO borrowings (member_id, book_id, borrow_date, due_date, status, notes) VALUES (?, ?, CURRENT_DATE, ?, 'borrowed', 'Bản điện tử (Tự động khóa sau 7 ngày)')")
               ->execute([$_SESSION['member_id'], $book_id, $due]);
            setFlash('success', 'Đã mượn bản điện tử thành công! Bạn có 7 ngày để đọc trước khi file tự động khóa.');
        } elseif ($action === 'request_borrow' && $_SESSION['role'] === 'member') {
            $db->prepare("INSERT INTO borrow_requests (member_id, book_id, pickup_method, delivery_address) VALUES (?, ?, ?, ?)")
               ->execute([$_SESSION['member_id'], (int)$_POST['book_id'], $_POST['pickup_method'], sanitize($_POST['delivery_address'] ?? '')]);
            setFlash('success', 'Yêu cầu mượn online của bạn đã được gửi. Thủ thư sẽ sớm xử lý!');
        } elseif ($action === 'add_quote' && $_SESSION['role'] === 'member') {
            $db->prepare("INSERT INTO book_quotes (member_id, book_id, content) VALUES (?, ?, ?)")
               ->execute([$_SESSION['member_id'], (int)$_POST['book_id'], sanitize($_POST['content'])]);
            setFlash('success', 'Cảm ơn bạn đã chia sẻ trích dẫn hay!');
        } elseif ($action === 'add_review' && $_SESSION['role'] === 'member') {
            $db->prepare("INSERT INTO book_reviews (member_id, book_id, rating, comment) VALUES (?, ?, ?, ?)")
               ->execute([$_SESSION['member_id'], (int)$_POST['book_id'], (int)$_POST['rating'], sanitize($_POST['comment'])]);
            setFlash('success', 'Cảm ơn bạn đã đánh giá cuốn sách này!');
        } elseif ($action === 'join_event' && $_SESSION['role'] === 'member') {
            $db->prepare("INSERT IGNORE INTO event_participants (event_id, member_id) VALUES (?, ?)")
               ->execute([(int)$_POST['event_id'], $_SESSION['member_id']]);
            setFlash('success', 'Bạn đã đăng ký tham gia sự kiện thành công!');
        } elseif ($action === 'add_event' && $_SESSION['role'] === 'admin') {
            $db->prepare("INSERT INTO events (title, description, event_date, location, max_participants) VALUES (?, ?, ?, ?, ?)")
               ->execute([sanitize($_POST['title']), sanitize($_POST['description']), $_POST['event_date'], sanitize($_POST['location']), (int)$_POST['max_participants']]);
            setFlash('success', 'Đã tạo sự kiện mới!');
        }
        
        $targetPage = $_GET['page'] ?? 'dashboard';
        redirect("?page={$targetPage}");
    }


// ==================== ROUTING ====================
$page = $_GET['page'] ?? 'dashboard';
if ($page === 'logout') { addLog('Đăng xuất hệ thống'); session_destroy(); redirect('?page=login'); }
if (!isset($_SESSION['user_id']) && !isset($_SESSION['member_id']) && !in_array($page, ['login', 'register'])) redirect('?page=login');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title><?= LIB_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    
    <script>
        if(localStorage.getItem('theme') === 'dark') { document.documentElement.classList.add('dark-theme'); }
    </script>

    <style>
        :root { 
            --p: <?= THEME_COLOR ?>; 
            --p-light: <?= THEME_COLOR ?>15; /* Thêm độ trong suốt cho màu nhạt */
            --s: #f472b6; --s-light: #fff1f2; --danger: #e11d48; --danger-light: #fff1f2; --bg: #fff5f7; --white: #ffffff; --text: #831843; --sub: #be185d; --border: #fbcfe8; 
        }
        
        html.dark-theme { --bg: #1a0b14; --white: #2a1220; --text: #fbcfe8; --sub: #f472b6; --border: #4a1d34; --p-light: #3a1528; --danger-light: #4c0f1c; }

        * { margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
        body { background: var(--bg); color: var(--text); display: flex; min-height: 100vh; overflow-x: hidden; transition: background 0.3s, color 0.3s; }
        
        html.dark-theme .header, html.dark-theme .card, html.dark-theme td { background: var(--white); border-color: var(--border); }
        html.dark-theme th { color: var(--sub); }
        html.dark-theme tr:hover td { background: #3a1528; }
        html.dark-theme .input { color: var(--text); background: #1a0b14; border-color: var(--border); }
        html.dark-theme .sidebar { background: #1f0814; }
        html.dark-theme .auth-screen { background: linear-gradient(135deg, #2a0a18, #1a0b14); }
        html.dark-theme .auth-card { background: var(--white); border-color: var(--border); }
        html.dark-theme .auth-input { background: #1a0b14; color: #fbcfe8; border-color: var(--border); }

        .sidebar { width: 280px; background: #500724; color: white; position: fixed; height: 100vh; padding: 2.5rem 0; z-index: 1000; box-shadow: 4px 0 25px rgba(236, 72, 153, 0.2); transition: 0.3s; overflow-y: auto; }
        .nav-item { display: flex; align-items: center; padding: 0.9rem 2rem; color: #f9a8d4; text-decoration: none; gap: 14px; transition: 0.3s; font-weight: 500; margin: 0.2rem 1rem; border-radius: 0.75rem; }
        .nav-item:hover { color: white; background: rgba(255,255,255,0.1); }
        .nav-item.active { background: var(--p); color: white; box-shadow: 0 10px 15px -3px rgba(236, 72, 153, 0.4); }
        
        .main { flex: 1; margin-left: 280px; padding: 2.5rem; width: calc(100% - 280px); position: relative; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; background: var(--white); padding: 1.25rem 2.5rem; border-radius: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border); transition: 0.3s; }
        
        .card { background: var(--white); border-radius: 1.5rem; padding: 2rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.04); border: 1px solid var(--border); margin-bottom: 1.5rem; transition: 0.3s; }
        .stat-card { border-radius: 1.5rem; padding: 2rem; color: white; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: center; }
        .stat-card i { position: absolute; right: -10px; bottom: -10px; font-size: 6rem; opacity: 0.2; transform: rotate(-15deg); }
        .stat-card h4 { font-size: 0.875rem; opacity: 0.8; font-weight: 600; text-transform: uppercase; }
        .stat-card h2 { font-size: 2.5rem; font-weight: 800; margin-top: 0.5rem; }
        
        .input { width: 100%; padding: 0.85rem 1.25rem; border: 1.5px solid var(--border); border-radius: 1rem; font-size: 0.95rem; margin-bottom: 1.25rem; transition: 0.3s; background: var(--white); }
        .btn { padding: 0.85rem 1.75rem; border-radius: 1rem; border: none; cursor: pointer; font-weight: 700; transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; text-decoration: none; }
        .btn-p { background: var(--p); color: white; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3); }
        .btn-p:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4); }
        .btn-icon { padding: 0.5rem; font-size: 1rem; border-radius: 0.5rem; cursor: pointer; border: 1px solid transparent;}
        .btn-icon:hover { border-color: var(--border); }
        
        table { width: 100%; border-collapse: separate; border-spacing: 0 0.5rem; }
        th { padding: 1rem; text-align: left; font-size: 0.75rem; color: var(--sub); text-transform: uppercase; letter-spacing: 1px; }
        td { padding: 1.25rem 1rem; background: var(--white); border-bottom: 1px solid var(--border); transition: 0.3s; vertical-align: middle; }
        tr:hover td { background: var(--bg); }
        
        .badge { padding: 0.5rem 1rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 800; }
        
        .auth-screen { width: 100%; flex: 1; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #fce7f3, #fbcfe8); position: relative; overflow: hidden; z-index: 1000; transition: 0.3s; }
        .auth-card { background: var(--white); padding: 3rem; border-radius: 2.5rem; width: 100%; max-width: 420px; z-index: 10; box-shadow: 0 20px 40px rgba(236, 72, 153, 0.2); border: 2px solid var(--border); text-align: center; }
        .kitty-mascot { width: 120px; height: 120px; border-radius: 50%; border: 4px solid var(--border); object-fit: cover; margin-bottom: 1.5rem; animation: bounce 3s infinite ease-in-out; }
        @keyframes bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
        .auth-input { width: 100%; padding: 0.9rem 1.25rem; border: 1.5px solid var(--border); border-radius: 1rem; margin-bottom: 1rem; color: var(--text); background: var(--bg); font-weight: 500; }
        .floating-icon { position: absolute; color: rgba(236, 72, 153, 0.3); animation: float 12s infinite linear; pointer-events: none; z-index: 1; }
        
        .peek-kitty { position: fixed; bottom: -70px; right: 10px; width: 100px !important; max-height: 150px; height: auto; transition: 0.4s; z-index: 100002; cursor: pointer; filter: drop-shadow(0 0 10px rgba(236, 72, 153, 0.3)); }
        .peek-kitty:hover { bottom: 0; }
        .cursor-trail { position: fixed; pointer-events: none; z-index: 100000; font-size: 1.2rem; transform: translate(-50%, -50%); transition: opacity 0.3s; opacity: 0; }
        .click-sparkle { position: absolute; pointer-events: none; animation: sparkle 0.8s forwards; font-size: 1.5rem; z-index: 100001; }
        @keyframes sparkle { 0% { transform: scale(0); opacity: 1; } 100% { transform: scale(1.5) translateY(-50px); opacity: 0; } }

        #printArea { display: none; }
        @media print {
            body * { visibility: hidden; }
            body { background: white; margin: 0; padding: 0; }
            #printArea, #printArea * { visibility: visible; }
            #printArea { display: block; position: absolute; left: 0; top: 0; width: 80mm; font-family: monospace; font-size: 12px; color: black; padding: 10px; }
            .print-divider { border-bottom: 1px dashed black; margin: 10px 0; }
        }

        /* --- MODAL CSS FIX --- */
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 20px; }
        .modal-content { background: var(--white); border-radius: 2rem; padding: 2.5rem; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border: 1px solid var(--border); position: relative; max-height: 90vh; overflow-y: auto; }
        
        @media (max-width: 1024px) {
            .sidebar { width: 80px; }
            .sidebar h2, .sidebar span, .sidebar .nav-item span, .sidebar small { display: none; }
            .main { margin-left: 80px; width: calc(100% - 80px); }
            .nav-item { justify-content: center; padding: 1rem; }
            .nav-item i { margin: 0; font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<!-- KHU VỰC ẨN: MẪU PHIẾU IN -->
<div id="printArea">
    <div style="text-align: center; margin-bottom: 15px;">
        <h2 style="margin: 0; font-size: 16px; font-weight: bold;"><?= strtoupper(LIB_NAME) ?></h2>
        <p style="margin: 5px 0 0 0;">PHIẾU MƯỢN SÁCH</p>
    </div>
    <div class="print-divider"></div>
    <p><strong>Mã phiếu:</strong> <span id="p_id"></span></p>
    <p><strong>Ngày lập:</strong> <span id="p_date"></span></p>
    <p><strong>Nhân viên:</strong> <?=$_SESSION['full_name'] ?? 'Admin'?></p>
    <div class="print-divider"></div>
    <p><strong>Độc giả:</strong> <span id="p_member"></span></p>
    <p><strong>Tên sách:</strong> <span id="p_book"></span></p>
    <p><strong>Hạn trả:</strong> <span id="p_due"></span></p>
    <p><strong>Tiền cọc:</strong> <span id="p_deposit"></span></p>
    <div class="print-divider"></div>
    <p style="text-align: center; margin-top: 15px; font-style: italic;">Vui lòng trả sách đúng hạn.<br>Cảm ơn quý khách!</p>
</div>

<?php if(in_array($page, ['login', 'register'])): ?>
    <div class="auth-screen">
        <?php for($i=0;$i<20;$i++): ?>
            <i class="fas fa-ribbon floating-icon" style="left:<?=rand(0,100)?>%; font-size:<?=rand(1,3)?>rem; animation-delay:<?=rand(0,15)?>s;"></i>
            <i class="fas fa-heart floating-icon" style="left:<?=rand(0,100)?>%; font-size:<?=rand(1,2)?>rem; animation-delay:<?=rand(0,15)?>s; color:rgba(255,255,255,0.4);"></i>
            <i class="fas fa-cat floating-icon" style="left:<?=rand(0,100)?>%; font-size:<?=rand(1,3)?>rem; animation-delay:<?=rand(0,15)?>s; color:rgba(255,255,255,0.3);"></i>
        <?php endfor; ?>
        <div class="auth-card">
            <img src="https://upload.wikimedia.org/wikipedia/en/0/05/Hello_kitty_character_portrait.png" class="kitty-mascot" alt="Kitty">
            <h1 style="font-size:1.75rem; font-weight:900; margin-bottom:2rem; color:var(--text);"><i class="fas fa-heart"></i> <?= $page=='login'?'HELLO LOGIN':'HELLO REG' ?> <i class="fas fa-heart"></i></h1>
            
            <?php if($f=getFlash()): ?>
                <div style="background:<?= $f['type']=='success'?'#d1fae5':'#fee2e2' ?>; color:<?= $f['type']=='success'?'#065f46':'#991b1b' ?>; padding:1rem; border-radius:1rem; margin-bottom:1.5rem; font-weight:600; font-size:0.9rem; border:1px solid rgba(0,0,0,0.05);"><?= $f['message'] ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="<?=$page?>">
                <input type="text" name="username" class="auth-input" placeholder="Tên đăng nhập" required>
                <input type="password" name="password" class="auth-input" placeholder="Mật khẩu" required>
                <?php if($page=='register'): ?><input type="text" name="full_name" class="auth-input" placeholder="Họ và tên"><?php endif; ?>
                <button type="submit" class="btn btn-p" style="width:100%; justify-content:center; font-size:1.1rem; margin-top:1rem;"><?= $page=='login'?'VÀO HỆ THỐNG':'TẠO TÀI KHOẢN' ?></button>
            </form>
            <p style="text-align:center; margin-top:2rem;"><a href="?page=<?=$page=='login'?'register':'login'?>" style="color:var(--sub); text-decoration:none; font-weight:700; font-size:0.9rem;"><?=$page=='login'?'Chưa có tài khoản? Đăng ký ngay':'Đã có tài khoản? Đăng nhập'?></a></p>
        </div>
    </div>
<?php else: ?>
    <aside class="sidebar">
        <div style="text-align:center; padding:1rem 2rem 1rem;">
            <i class="fas fa-book-reader" style="font-size:3.5rem; color:var(--s); margin-bottom:1rem; display:block;"></i>
            <h2 style="font-weight:900; letter-spacing:1px;"><?= LIB_NAME ?></h2>
        </div>
        <!-- SIDEBAR SEARCH -->
        <div style="padding: 0 1.5rem 1.5rem;">
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:rgba(255,255,255,0.4); font-size:0.8rem;"></i>
                <input type="text" id="sideSearch" placeholder="Tìm tính năng..." onkeyup="searchNav()" style="width:100%; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); border-radius:10px; padding:8px 12px 8px 32px; color:white; font-size:0.8rem; outline:none;">
            </div>
        </div>
        <nav id="sidebarNav">
            <a href="?page=dashboard" class="nav-item <?=$page=='dashboard'?'active':''?>"><i class="fas fa-th-large"></i> Tổng quan</a>
            <a href="?page=books" class="nav-item <?=$page=='books'?'active':''?>"><i class="fas fa-book"></i> Quản lý sách</a>
            <?php if($_SESSION['role']=='admin'): ?>
                <a href="?page=inventory" class="nav-item <?=$page=='inventory'?'active':''?>"><i class="fas fa-warehouse"></i> Nhập kho sách</a>
            <?php endif; ?>
            <a href="?page=borrowings" class="nav-item <?=$page=='borrowings'?'active':''?>"><i class="fas fa-exchange-alt"></i> Mượn trả sách</a>
            <a href="?page=discovery" class="nav-item <?=$page=='discovery'?'active':''?>"><i class="fas fa-map-marked-alt"></i> Khám phá thư viện</a>
            <a href="?page=members" class="nav-item <?=$page=='members'?'active':''?>"><i class="fas fa-users"></i> Độc giả</a>
            <?php if($_SESSION['role']=='admin'): 
                $pendingSchedules = getDB()->query("SELECT COUNT(*) FROM schedules WHERE status='Pending'")->fetchColumn();
            ?>
                <a href="?page=staff" class="nav-item <?=$page=='staff'?'active':''?>" style="position:relative;">
                    <i class="fas fa-user-shield"></i> Nhân viên
                    <?php if($pendingSchedules > 0): ?>
                        <span style="position:absolute; top:5px; right:10px; background:var(--danger); color:white; border-radius:50%; width:18px; height:18px; font-size:10px; display:flex; align-items:center; justify-content:center; border:2px solid var(--white);"><?=$pendingSchedules?></span>
                    <?php endif; ?>
                </a>
                <a href="?page=logs" class="nav-item <?=$page=='logs'?'active':''?>"><i class="fas fa-history"></i> Lịch sử hệ thống</a>
            <?php else: ?>
                <a href="?page=schedules" class="nav-item <?=$page=='schedules'?'active':''?>"><i class="fas fa-calendar-alt"></i> Đăng ký lịch</a>
                <a href="?page=tasks" class="nav-item <?=$page=='tasks'?'active':''?>"><i class="fas fa-tasks"></i> Công việc</a>
            <?php endif; ?>
            <?php if($_SESSION['role']=='member'): ?>
                <a href="?page=opac" class="nav-item <?=$page=='opac'?'active':''?>"><i class="fas fa-search"></i> Tra cứu OPAC</a>
                <a href="?page=my_borrowings" class="nav-item <?=$page=='my_borrowings'?'active':''?>"><i class="fas fa-book-reader"></i> Sách đang mượn</a>
            <?php endif; ?>

            <a href="?page=settings" class="nav-item <?=$page=='settings'?'active':''?>"><i class="fas fa-cog"></i> Cài đặt</a>
            <a href="?page=profile" class="nav-item <?=$page=='profile'?'active':''?>"><i class="fas fa-user-circle"></i> Cá nhân</a>
            <a href="?page=logout" onclick="return confirm('Bạn có chắc chắn muốn thoát khỏi hệ thống không?');" class="nav-item" style="color:#f87171; margin-top:3rem;"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
        </nav>
    </aside>

    <main class="main">
        <header class="header">
            <?php 
                $titles = ['dashboard'=>'Tổng Quan', 'books'=>'Quản Lý Sách', 'borrowings'=>'Mượn & Trả Sách', 'members'=>'Quản Lý Độc Giả', 'staff'=>'Nhân Viên', 'logs'=>'Lịch Sử Hệ Thống', 'settings'=>'Cài Đặt Hệ Thống', 'profile'=>'Hồ Sơ Cá Nhân', 'schedules'=>'Đăng Ký Lịch Làm', 'tasks'=>'Công Việc Được Giao'];
                $displayTitle = $titles[$page] ?? strtoupper($page);
            ?>
            <div style="display:flex; align-items:center;">
                <!-- NÚT QUAY LẠI / TIẾN LÊN -->
                <button onclick="history.back()" class="btn-icon" style="background:var(--p-light); color:var(--text); margin-right:5px;" title="Quay lại"><i class="fas fa-arrow-left"></i></button>
                <button onclick="history.forward()" class="btn-icon" style="background:var(--p-light); color:var(--text); margin-right:15px;" title="Tiến lên"><i class="fas fa-arrow-right"></i></button>
                <h2><?= $displayTitle ?></h2>
            </div>
            
            <div style="display:flex; align-items:center; gap:1.25rem;">
                <button id="themeToggle" class="btn-icon" style="background:var(--p-light); color:var(--p); width:40px; height:40px; border-radius:12px; border:1px solid var(--border);" onclick="toggleDarkMode()">
                    <i class="fas fa-moon"></i>
                </button>
                <div style="text-align:right;">
                    <p style="font-weight:800; color:var(--text); line-height:1; margin-bottom:4px;"><?=$_SESSION['full_name']?></p>
                    <small style="color:var(--p); font-weight:700; text-transform:uppercase; font-size:0.65rem; background:var(--p-light); padding:2px 8px; border-radius:5px;"><?= $_SESSION['role']=='admin'?'Quản trị viên':'Thủ thư' ?></small>
                </div>
                <div style="width:45px; height:45px; border-radius:15px; border:2px solid var(--p-light); padding:3px;">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=<?= $_SESSION['username'] ?>" style="width:100%; height:100%; border-radius:10px;" alt="User">
                </div>
            </div>
        </header>

        <?php if($f=getFlash()): ?><div style="background:<?= $f['type']=='success'?'#d1fae5':'#fee2e2' ?>; color:<?= $f['type']=='success'?'#065f46':'#991b1b' ?>; padding:1.25rem; border-radius:1.25rem; margin-bottom:2rem; font-weight:600; border:1px solid rgba(0,0,0,0.05); animation:slideIn 0.5s ease-out;"><?= $f['message'] ?></div><?php endif; ?>

        <?php if($page == 'dashboard'): 
            $db=getDB(); 
            $b=$db->query("SELECT COUNT(*) FROM books")->fetchColumn(); 
            $m=$db->query("SELECT COUNT(*) FROM members")->fetchColumn(); 
            $br=$db->query("SELECT COUNT(*) FROM borrowings WHERE status='borrowed'")->fetchColumn(); 
            $tk=$db->query("SELECT COUNT(*) FROM tasks WHERE status='Pending'")->fetchColumn(); 

            // Kiểm tra sách hết kho
            $outOfStock = $db->query("SELECT title FROM books WHERE quantity <= 0")->fetchAll();
            if(!empty($outOfStock) && $_SESSION['role'] === 'admin'):
        ?>
            <div class="card" style="border:2px solid var(--danger); background:var(--danger-light); margin-bottom:2rem;">
                <h3 style="color:var(--danger); margin-bottom:1rem;"><i class="fas fa-exclamation-triangle"></i> CẢNH BÁO: HẾT SÁCH TRONG KHO!</h3>
                <p style="margin-bottom:1rem; font-size:0.9rem;">Các đầu sách sau đã hết hàng, vui lòng liên hệ nhà cung cấp hoặc cập nhật kho:</p>
                <div style="display:flex; flex-wrap:wrap; gap:10px;">
                    <?php foreach($outOfStock as $o): ?>
                        <span class="badge" style="background:var(--danger); color:white;"><i class="fas fa-book-dead"></i> <?=$o['title']?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php 
            // Cảnh báo nhân viên đi muộn hôm nay (Dành cho Admin)
            if($_SESSION['role'] === 'admin'):
                $lateToday = $db->query("SELECT u.full_name, a.check_in FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.date = CURRENT_DATE AND a.status = 'Late'")->fetchAll();
                if(!empty($lateToday)):
        ?>
            <div class="card" style="border:2px solid #f59e0b; background:#fffbeb; margin-bottom:2rem;">
                <h3 style="color:#b45309; margin-bottom:1rem;"><i class="fas fa-user-clock"></i> CẢNH BÁO: NHÂN VIÊN ĐI MUỘN HÔM NAY</h3>
                <div style="display:flex; flex-wrap:wrap; gap:10px;">
                    <?php foreach($lateToday as $l): ?>
                        <span class="badge" style="background:#f59e0b; color:white;"><i class="fas fa-clock"></i> <?=$l['full_name']?> (Vào lúc: <?=$l['check_in']?>)</span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; endif; ?>
            <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:1.5rem; margin-bottom:2rem;">
                <div class="card" style="background: linear-gradient(135deg, #4f46e5, #818cf8); color:white; border:none; margin-bottom:0; padding:1.5rem; position:relative; overflow:hidden;">
                    <i class="fas fa-book" style="position:absolute; right:-10px; bottom:-10px; font-size:5rem; opacity:0.1;"></i>
                    <h4 style="font-size:0.75rem; opacity:0.8; text-transform:uppercase;">Tổng đầu sách</h4>
                    <h2 style="font-size:2rem; margin:10px 0;"><?= number_format($db->query("SELECT SUM(quantity) FROM books")->fetchColumn()) ?></h2>
                    <div style="font-size:0.75rem; opacity:0.8;"><i class="fas fa-arrow-up"></i> +5% so với tháng trước</div>
                </div>
                
                <div class="card" style="background:white; border-left:5px solid #10b981; margin-bottom:0; padding:1.5rem;">
                    <h4 style="font-size:0.75rem; color:var(--sub); text-transform:uppercase;">Đang lưu hành</h4>
                    <h2 style="font-size:2rem; margin:10px 0; color:#059669;"><?= $db->query("SELECT COUNT(*) FROM borrowings WHERE status='borrowed'")->fetchColumn() ?></h2>
                    <div style="width:100%; background:#f3f4f6; height:6px; border-radius:3px; margin-top:10px;">
                        <div style="width:65%; background:#10b981; height:100%; border-radius:3px;"></div>
                    </div>
                </div>

                <div class="card" style="background:white; border-left:5px solid #f59e0b; margin-bottom:0; padding:1.5rem;">
                    <h4 style="font-size:0.75rem; color:var(--sub); text-transform:uppercase;">Mật độ thư viện</h4>
                    <?php 
                        $staffOn = $db->query("SELECT COUNT(*) FROM attendance WHERE date=CURRENT_DATE AND check_out IS NULL")->fetchColumn();
                        $occupancy = ($staffOn * 25) + rand(10, 30); // Giả lập dựa trên nhân viên và random khách
                    ?>
                    <h2 style="font-size:2rem; margin:10px 0; color:#d97706;"><?= $occupancy ?>%</h2>
                    <div style="font-size:0.75rem; color:#d97706; font-weight:700;"><i class="fas fa-users"></i> Trạng thái: <?= $occupancy > 70 ? 'Khá đông' : 'Thông thoáng' ?></div>
                </div>

                <div class="card" style="background:white; border-left:5px solid #ef4444; margin-bottom:0; padding:1.5rem;">
                    <h4 style="font-size:0.75rem; color:var(--sub); text-transform:uppercase;">Quá hạn cần xử lý</h4>
                    <h2 style="font-size:2rem; margin:10px 0; color:#dc2626;"><?= $db->query("SELECT COUNT(*) FROM borrowings WHERE status='borrowed' AND due_date < CURRENT_DATE")->fetchColumn() ?></h2>
                    <button class="btn" style="padding:2px 8px; font-size:0.7rem; background:#fee2e2; color:#ef4444; border:none; margin-top:5px;">Gửi thông báo ngay</button>
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns: 2fr 1.2fr; gap:2rem;">
                <div class="card"><h3>Hoạt động mới nhất</h3>
                    <table><thead><tr><th>Độc giả</th><th>Sách</th><th>Trạng thái</th></tr></thead>
                    <tbody><?php foreach($db->query("SELECT b.*, m.full_name, bk.title FROM borrowings b JOIN members m ON b.member_id=m.id JOIN books bk ON b.book_id=bk.id ORDER BY b.created_at DESC LIMIT 6")->fetchAll() as $r): ?>
                    <tr><td><strong><?=$r['full_name']?></strong></td><td><?=$r['title']?></td>
                    <td>
                        <?php if($r['status'] == 'returned'): ?><span class="badge badge-s" style="background:var(--p-light); color:var(--text);">Đã trả</span>
                        <?php elseif($r['status'] == 'lost'): ?><span class="badge badge-d" style="background:var(--danger-light); color:var(--danger);">Mất sách</span>
                        <?php else: ?><span class="badge badge-d" style="background:var(--border); color:var(--text);">Đang mượn</span><?php endif; ?>
                    </td></tr><?php endforeach; ?></tbody></table>
                </div>
                
                <div class="card" style="display:flex; flex-direction:column; gap:1.5rem;">
                    <div>
                        <h3 style="color:var(--p);"><i class="fas fa-users-cog"></i> Nhân viên đang trực</h3>
                        <p style="font-size:0.85rem; color:var(--sub); margin-bottom:1rem;">Nhân viên đã chấm công vào và đang có mặt.</p>
                        <ul style="list-style:none; padding:0;">
                            <?php 
                            $onDuty = $db->query("SELECT u.id, u.full_name, u.position, a.check_in FROM users u JOIN attendance a ON u.id = a.user_id WHERE a.date = CURRENT_DATE AND a.check_out IS NULL")->fetchAll();
                            if(count($onDuty) > 0): 
                                foreach($onDuty as $staff):
                            ?>
                                <li style="background:var(--bg); padding:1rem; border-radius:1rem; margin-bottom:0.8rem; border:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <strong style="display:block;"><?=$staff['full_name']?></strong>
                                        <small style="color:var(--sub);">Vào lúc: <?=$staff['check_in']?></small>
                                        <?php 
                                            // Kiểm tra nếu có nhân viên khác báo đi muộn/vắng
                                            $absentToday = $db->query("SELECT u.full_name, a.id as att_id FROM attendance a JOIN users u ON a.user_id=u.id WHERE a.date=CURRENT_DATE AND a.status IN ('Late', 'Absent') AND a.standin_id IS NULL")->fetchAll();
                                            if(!empty($absentToday)):
                                        ?>
                                            <div style="margin-top:10px; padding:8px; background:#fff1f2; border-radius:8px; border:1px dashed #fb7185;">
                                                <small style="color:#e11d48; font-weight:700;"><i class="fas fa-exclamation-circle"></i> Cần người trực thay cho: <?=$absentToday[0]['full_name']?></small>
                                                <form method="POST" style="margin-top:5px;">
                                                    <input type="hidden" name="action" value="assign_standin">
                                                    <input type="hidden" name="attendance_id" value="<?=$absentToday[0]['att_id']?>">
                                                    <input type="hidden" name="standin_id" value="<?=$staff['id']?>">
                                                    <button class="btn" style="padding:2px 8px; font-size:0.65rem; background:#e11d48; color:white;">Phân công ngay</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="?page=staff&edit_id=<?=$staff['id']?>#taskForm" class="btn btn-s" style="background:var(--p); color:white; padding:0.4rem 0.8rem; font-size:0.75rem;"><i class="fas fa-bolt"></i> Giao việc</a>
                                </li>
                            <?php 
                                endforeach; 
                            else: 
                            ?>
                                <li style="font-size:0.85rem; color:var(--sub); font-style:italic; text-align:center; padding:1rem; border:1.5px dashed var(--border); border-radius:1rem;">Hiện chưa có nhân viên nào đang trực.</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div style="border-top:1px solid var(--border); padding-top:1.5rem;">
                        <h3>🤖 AI Gợi Ý Nhập Sách</h3>
                        <p style="font-size:0.85rem; color:var(--sub); margin-bottom:1rem;">Dựa trên dữ liệu mượn chéo của độc giả.</p>
                        <ul style="list-style:none; padding:0;">
                            <?php 
                            $recommendations = getBookRecommendations($db, 3);
                            if(count($recommendations) > 0): 
                                foreach($recommendations as $rec):
                            ?>
                                <li style="background:var(--bg); padding:0.8rem; border-radius:0.5rem; margin-bottom:0.5rem; display:flex; justify-content:space-between; align-items:center; border:1px solid var(--border);">
                                    <strong><?=$rec['title']?></strong>
                                    <span class="badge" style="background:var(--p); color:white;">Độ HOT: <?=$rec['frequency']?></span>
                                </li>
                            <?php 
                                endforeach; 
                            else: 
                            ?>
                                <li style="font-size:0.85rem; color:var(--sub); font-style:italic;">Chưa đủ dữ liệu để phân tích AI.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <div style="border-top:1px solid var(--border); padding-top:1.5rem;">
                        <h3>Tỷ lệ kho sách</h3>
                        <canvas id="chart" style="max-height:180px;"></canvas>
                    </div>

                    <div style="border-top:1px solid var(--border); padding-top:1.5rem;">
                        <h3>Lượt mượn tuần qua</h3>
                        <canvas id="chartBar" style="max-height:150px;"></canvas>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded',()=>{
                    // Chart 1: Doughnut (Sách sẵn có)
                    new Chart(document.getElementById('chart'),{
                        type:'doughnut',
                        data:{
                            labels:['Đang mượn','Sẵn có','Mất/Hỏng'],
                            datasets:[{
                                data:[<?=$br?>, <?=$b-$br?>, <?= (int)$db->query("SELECT COUNT(*) FROM borrowings WHERE status IN ('lost', 'damaged')")->fetchColumn() ?>],
                                backgroundColor:['#f472b6','#fbcfe8','#9f1239'],
                                borderWidth:0
                            }]
                        },
                        options: { cutout: '70%', plugins: { legend: { position: 'bottom' } } }
                    });

                    // Chart 2: Bar (Thống kê mượn trả 7 ngày qua)
                    const ctxBar = document.getElementById('chartBar').getContext('2d');
                    new Chart(ctxBar, {
                        type: 'bar',
                        data: {
                            labels: ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'],
                            datasets: [{
                                label: 'Lượt mượn',
                                data: [12, 19, 3, 5, 2, 3, 7], // Mock data, có thể query DB thực tế
                                backgroundColor: 'var(--p)',
                                borderRadius: 10
                            }]
                        },
                        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                    });
                });
            </script>

            <?php if($_SESSION['role'] === 'librarian'): 
                // Ý tưởng độc đáo: Tính lương dự kiến dựa trên số ca đã được duyệt
                $stmtSalary = $db->prepare("SELECT shift_name FROM schedules WHERE user_id = ? AND status = 'Assigned' AND MONTH(shift_date) = MONTH(CURRENT_DATE)");
                $stmtSalary->execute([$_SESSION['user_id']]);
                $totalHours = 0;
                while($sRow = $stmtSalary->fetch()) {
                    $sName = $sRow['shift_name'];
                    if(strpos($sName, 'Full-time') !== false) $totalHours += 12;
                    elseif(strpos($sName, 'Hành chính') !== false) $totalHours += 8;
                    elseif(strpos($sName, 'Nghỉ') !== false) $totalHours += 0;
                    else $totalHours += 4; // Ca sáng/chiều/tối
                }
                $hourlyRate = 25000; $estSalary = $totalHours * $hourlyRate;
            ?>
            <!-- THỐNG KÊ LƯƠNG & HIỆU SUẤT (Ý TƯỞNG ĐỘC ĐÁO) -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:2rem; margin-bottom:2rem;">
                <div class="card" style="background:linear-gradient(135deg, #4f46e5, #818cf8); color:white; border:none;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <h4 style="opacity:0.8; font-size:0.8rem; text-transform:uppercase;">Lương dự kiến tháng này</h4>
                            <h2 style="font-size:2rem; margin:0.5rem 0;"><?=number_format($estSalary)?>đ</h2>
                            <p style="font-size:0.8rem; opacity:0.9;"><i class="fas fa-info-circle"></i> Tính dựa trên <?=$totalHours?> giờ làm đã được duyệt.</p>
                        </div>
                        <i class="fas fa-wallet" style="font-size:3rem; opacity:0.3;"></i>
                    </div>
                </div>
                <div class="card" style="background:linear-gradient(135deg, #10b981, #34d399); color:white; border:none;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <h4 style="opacity:0.8; font-size:0.8rem; text-transform:uppercase;">Xếp hạng chuyên cần</h4>
                            <h2 style="font-size:2rem; margin:0.5rem 0;">TOP 1 🏆</h2>
                            <p style="font-size:0.8rem; opacity:0.9;"><i class="fas fa-star"></i> Bạn đang làm rất tốt! Duy trì nhé.</p>
                        </div>
                        <i class="fas fa-medal" style="font-size:3rem; opacity:0.3;"></i>
                    </div>
                </div>
            </div>
            
            <!-- KHU VỰC CÁ NHÂN NHÂN VIÊN -->
            <div style="display:grid; grid-template-columns: 1.2fr 2fr; gap:2rem;">
                <div style="display:flex; flex-direction:column; gap:2rem;">
                    <div class="card" style="border-left:5px solid var(--p);">
                        <h3>🕒 Chấm Công Hôm Nay</h3>
                        <div style="margin:1.5rem 0; text-align:center;">
                            <h1 style="font-size:3rem; font-weight:900; color:var(--text);"><?=date('H:i')?></h1>
                            <p style="color:var(--sub);"><?=date('d/m/Y')?></p>
                        </div>
                        <?php 
                            $att = $db->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = CURRENT_DATE");
                            $att->execute([$_SESSION['user_id']]); $todayAtt = $att->fetch();
                        ?>
                        <div style="display:flex; gap:10px; flex-direction:column;">
                            <?php if(!$todayAtt): ?>
                                <div style="background:var(--p-light); padding:1rem; border-radius:1rem; text-align:center; margin-bottom:10px;">Bạn chưa chấm công hôm nay.</div>
                                <form method="POST"><input type="hidden" name="action" value="staff_checkin"><button type="submit" class="btn btn-p" style="width:100%; justify-content:center;"><i class="fas fa-sign-in-alt"></i> CHẤM CÔNG VÀO</button></form>
                            <?php elseif(!$todayAtt['check_out']): ?>
                                <div style="background:var(--p-light); padding:1rem; border-radius:1rem; text-align:center; margin-bottom:10px;">
                                    Đã vào lúc: <strong><?=$todayAtt['check_in']?></strong><br>
                                    Trạng thái: <?php if($todayAtt['status'] == 'Late'): ?><span class="badge" style="background:var(--danger); color:white;">ĐI MUỘN</span><?php else: ?><span class="badge" style="background:#10b981; color:white;">ĐÚNG GIỜ</span><?php endif; ?>
                                </div>
                                <form method="POST"><input type="hidden" name="action" value="staff_checkout"><button type="submit" class="btn" style="width:100%; justify-content:center; background:var(--danger); color:white;"><i class="fas fa-sign-out-alt"></i> KẾT THÚC CA</button></form>
                            <?php else: ?>
                                <div style="background:var(--p-light); padding:1rem; border-radius:1rem; text-align:center;">
                                    Hôm nay bạn đã hoàn thành ca làm.<br>(<?=$todayAtt['check_in']?> - <?=$todayAtt['check_out']?>)<br>
                                    Trạng thái: <?php if($todayAtt['status'] == 'Late'): ?><span class="badge" style="background:var(--danger); color:white;">ĐI MUỘN</span><?php else: ?><span class="badge" style="background:#10b981; color:white;">ĐÚNG GIỜ</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card" style="border-left:5px solid var(--s);">
                        <h3>📞 Danh bạ đồng nghiệp</h3>
                        <div style="margin-top:1rem; max-height:300px; overflow-y:auto;">
                            <?php 
                            $colleagues = $db->prepare("SELECT full_name, phone, email, position FROM users WHERE id != ? AND role='librarian'");
                            $colleagues->execute([$_SESSION['user_id']]);
                            while($c = $colleagues->fetch()):
                            ?>
                                <div style="display:flex; align-items:center; gap:15px; margin-bottom:1rem; padding-bottom:1rem; border-bottom:1px solid var(--bg);">
                                    <div style="width:40px; height:40px; border-radius:50%; background:var(--p); color:white; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:1.2rem;">
                                        <?=mb_substr($c['full_name'], 0, 1)?>
                                    </div>
                                    <div>
                                        <strong style="display:block; font-size:0.9rem;"><?=$c['full_name']?></strong>
                                        <small style="color:var(--sub); display:block; font-size:0.75rem;"><?=$c['position'] ?: 'Thủ thư'?></small>
                                        <div style="display:flex; flex-direction:column; gap:2px; margin-top:5px;">
                                            <a href="tel:<?=$c['phone']?>" style="color:var(--p); font-size:0.8rem; text-decoration:none; font-weight:700;"><i class="fas fa-phone"></i> <?=$c['phone'] ?: 'Chưa cập nhật'?></a>
                                            <a href="mailto:<?=$c['email']?>" style="color:var(--sub); font-size:0.75rem; text-decoration:none;"><i class="fas fa-envelope"></i> <?=$c['email'] ?: 'Chưa có email'?></a>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                    <!-- BÁO CÁO SỰ CỐ NHANH -->
                    <div class="card" style="border:1px solid #fb7185; background:#fff1f2;">
                        <h3 style="color:#e11d48;"><i class="fas fa-exclamation-triangle"></i> Báo cáo sự cố / Nghỉ đột xuất</h3>
                        <p style="font-size:0.8rem; margin-bottom:1rem;">Nếu bạn đi muộn hoặc cần nghỉ gấp, hãy báo ngay cho Admin để sắp xếp người trực thay.</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="report_issue">
                            <select name="issue_type" class="input" style="margin-bottom:10px; font-size:0.85rem;">
                                <option value="Đi muộn">Tôi sẽ đi muộn</option>
                                <option value="Nghỉ ốm">Nghỉ ốm đột xuất</option>
                                <option value="Việc gia đình">Việc gia đình khẩn cấp</option>
                            </select>
                            <textarea name="message" class="input" style="height:60px; font-size:0.85rem;" placeholder="Lý do ngắn gọn..."></textarea>
                            <button type="submit" class="btn" style="width:100%; justify-content:center; background:#e11d48; color:white; margin-top:10px;">Gửi thông báo</button>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                        <h3>📅 Lịch Làm Gần Đây</h3>
                        <a href="?page=schedules" class="btn btn-s" style="background:var(--p-light); color:var(--p); padding:0.4rem 0.8rem; font-size:0.8rem;"><i class="fas fa-plus"></i> Đăng ký ca</a>
                    </div>

                    <table><thead><tr><th>Ngày</th><th>Ca làm việc</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                        <?php 
                        $stmtS = $db->prepare("SELECT * FROM schedules WHERE user_id = ? AND shift_date >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY) ORDER BY shift_date DESC LIMIT 5");
                        $stmtS->execute([$_SESSION['user_id']]);
                        while($row = $stmtS->fetch()): 
                        ?>
                        <tr>
                            <td><?=date('d/m/Y', strtotime($row['shift_date']))?></td>
                            <td><strong><?=$row['shift_name']?></strong></td>
                            <td>
                                <?php if($row['status'] == 'Pending'): ?><span class="badge" style="background:#fef3c7; color:#92400e;">Đang chờ</span>
                                <?php elseif($row['status'] == 'Assigned'): ?><span class="badge" style="background:#dcfce7; color:#166534;">Đã duyệt</span>
                                <?php else: ?><span class="badge" style="background:#fee2e2; color:#991b1b;"><?=$row['status']?></span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody></table>
                </div>
            </div>
            <?php endif; ?>

            <!-- HIỂN THỊ CẢNH BÁO NẾU CÓ -->
            <?php 
                $uInfo = $db->prepare("SELECT warning_msg FROM users WHERE id = ?"); $uInfo->execute([$_SESSION['user_id']]);
                $warn = $uInfo->fetchColumn();
                if($warn):
            ?>
            <div id="warningModal" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(5px);">
                <div class="card" style="max-width:500px; width:90%; border:3px solid var(--danger); text-align:center; padding:3rem;">
                    <i class="fas fa-exclamation-triangle" style="font-size:4rem; color:var(--danger); margin-bottom:1.5rem;"></i>
                    <h2 style="color:var(--danger); margin-bottom:1rem;">NHẮC NHỞ TỪ QUẢN TRỊ</h2>
                    <p style="font-size:1.1rem; margin-bottom:2rem; line-height:1.6;"><?=nl2br(htmlspecialchars($warn))?></p>
                    <form method="POST"><input type="hidden" name="action" value="clear_warning"><button type="submit" class="btn btn-p" style="width:100%; justify-content:center;">ĐÃ HIỂU & ĐÓNG</button></form>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif($page == 'tasks' && $_SESSION['role'] === 'librarian'): ?>
            <?php $db = getDB(); ?>

            <!-- CÔNG VIỆC CỦA BẠN -->
            <div style="margin-top:2rem;" id="tasksSection">
                <div class="card" style="border-top:5px solid #10b981;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                        <h3><i class="fas fa-tasks" style="color:#10b981;"></i> Công việc được giao</h3>
                        <span class="badge" style="background:#dcfce7; color:#059669;">Cá nhân</span>
                    </div>
                    
                    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:1.5rem;">
                        <?php 
                        $stmtT = $db->prepare("SELECT * FROM tasks WHERE user_id = ? AND status = 'Pending' ORDER BY due_date ASC");
                        $stmtT->execute([$_SESSION['user_id']]);
                        $hasTasks = false;
                        while($t = $stmtT->fetch()): $hasTasks = true;
                            $isOverdueTask = (strtotime($t['due_date']) < time());
                        ?>
                            <div style="background:var(--bg); padding:1.5rem; border-radius:1rem; border:1px solid var(--border); position:relative; transition:0.3s;" class="task-card">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem;">
                                    <h4 style="color:var(--text);"><?=htmlspecialchars($t['title'])?></h4>
                                    <span class="badge" style="background:<?= $isOverdueTask ? 'var(--danger-light)' : 'var(--p-light)' ?>; color:<?= $isOverdueTask ? 'var(--danger)' : 'var(--p)' ?>; font-size:0.7rem;">
                                        Hạn: <?=date('d/m/Y', strtotime($t['due_date']))?>
                                    </span>
                                </div>
                                <p style="font-size:0.875rem; color:var(--sub); line-height:1.5; margin-bottom:1.5rem;"><?=nl2br(htmlspecialchars($t['description']))?></p>
                                <form method="POST">
                                    <input type="hidden" name="action" value="complete_task">
                                    <input type="hidden" name="id" value="<?=$t['id']?>">
                                    <button type="submit" class="btn" style="width:100%; justify-content:center; background:#10b981; color:white;"><i class="fas fa-check-circle"></i> Hoàn thành</button>
                                </form>
                            </div>
                        <?php endwhile; ?>
                        
                        <?php if(!$hasTasks): ?>
                            <div style="grid-column: 1 / -1; text-align:center; padding:2rem; color:var(--sub); font-style:italic;">
                                <i class="fas fa-clipboard-check" style="font-size:2rem; margin-bottom:1rem; opacity:0.5;"></i><br>
                                Bạn đã hoàn thành tất cả công việc được giao!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php elseif($page == 'books'): 
            $editBook = null;
            if(isset($_GET['edit_id'])) {
                $stmt = getDB()->prepare("SELECT * FROM books WHERE id = ?"); $stmt->execute([(int)$_GET['edit_id']]);
                $editBook = $stmt->fetch();
            }
        ?>
            <div style="margin-bottom: 1.5rem; display:flex; justify-content:space-between; align-items:center;">
                <input type="text" id="liveSearch" class="input" placeholder="🔍 Gõ tên sách, tác giả hoặc mã để tìm nhanh..." onkeyup="filterTable()" style="margin-bottom: 0; width:60%;">
                <a href="?action=export_books" class="btn btn-s" style="background:var(--p-light); color:var(--text);"><i class="fas fa-file-excel"></i> Xuất Excel (CSV)</a>
            </div>
            
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:2rem;">
                <div class="card" style="overflow-x:auto;"><h3>Danh mục sách trong kho</h3>
                    <table id="dataTable"><thead><tr><th>Sách</th><th>Tác giả</th><th>Phân loại</th><th>Vị trí / DDC</th><th>Số lượng</th><th style="text-align:right;">Lệnh</th></tr></thead>
                    <tbody><?php foreach(getDB()->query("SELECT b.*, c.name as cat_name, (b.quantity - IFNULL((SELECT SUM(quantity) FROM borrowings WHERE book_id = b.id AND status = 'borrowed'), 0)) as avail FROM books b JOIN categories c ON b.category_id = c.id")->fetchAll() as $b): ?>
                    <tr style="<?= $b['avail'] <= 0 ? 'background:var(--danger-light);' : '' ?>">
                        <td><strong><?=$b['title']?></strong><br><small style="color:var(--sub)"><?=$b['isbn']?></small></td>
                        <td><?=$b['author']?></td>
                        <td><span class="badge" style="background:var(--p-light); color:var(--p);"><?=$b['cat_name']?></span></td>
                        <td>
                            <span style="font-size:0.85rem;"><i class="fas fa-map-marker-alt"></i> <?=$b['shelf_location'] ?: 'Chưa xếp'?></span><br>
                            <small style="color:var(--sub);">DDC: <?=$b['ddc_code'] ?: 'N/A'?></small>
                        </td>
                        <td><?=$b['quantity']?> (Sẵn có: <?=$b['avail']?>)</td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a href="?page=books&edit_id=<?=$b['id']?>" class="btn-icon" style="background:var(--border); color:var(--text); margin-right:5px; text-decoration:none;"><i class="fas fa-edit"></i></a>
                            <button onclick="deleteItem('book', <?=$b['id']?>)" class="btn-icon" style="background:var(--danger); color:white; border:none;"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr><?php endforeach; ?></tbody></table>
                </div>
                <div class="card">
                    <h3><?= $editBook ? '✏️ Cập nhật sách' : '➕ Thêm sách mới' ?></h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="<?= $editBook ? 'edit_book' : 'add_book' ?>">
                        <?php if($editBook): ?><input type="hidden" name="id" value="<?=$editBook['id']?>"><?php endif; ?>
                        
                        <input type="text" name="title" class="input" placeholder="Tên sách" required value="<?= $editBook['title'] ?? '' ?>">
                        <input type="text" name="author" class="input" placeholder="Tác giả" required value="<?= $editBook['author'] ?? '' ?>">
                        <select name="category_id" class="input" required>
                            <option value="">-- Chọn thể loại --</option>
                            <?php foreach(getDB()->query("SELECT * FROM categories")->fetchAll() as $c): ?>
                                <option value="<?=$c['id']?>" <?= ($editBook && $editBook['category_id'] == $c['id']) ? 'selected' : '' ?>><?=$c['name']?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="isbn" class="input" placeholder="ISBN" value="<?= $editBook['isbn'] ?? '' ?>">
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <div><label style="font-size:0.8rem; color:var(--sub);">Vị trí Kệ/Ngăn</label><input type="text" name="shelf_location" class="input" placeholder="Kệ A1" value="<?= $editBook['shelf_location'] ?? '' ?>"></div>
                            <div><label style="font-size:0.8rem; color:var(--sub);">Mã DDC/UDC</label><input type="text" name="ddc_code" class="input" placeholder="800.1" value="<?= $editBook['ddc_code'] ?? '' ?>"></div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <div><label style="font-size:0.8rem; color:var(--sub);">Giá tiền (VNĐ)</label><input type="number" name="price" class="input" placeholder="Giá bìa" value="<?= $editBook['price'] ?? '0' ?>" required></div>
                            <div><label style="font-size:0.8rem; color:var(--sub);">Số lượng kho</label><input type="number" name="quantity" class="input" value="<?= $editBook['quantity'] ?? '1' ?>" required></div>
                        </div>
                        
                        <button type="submit" class="btn btn-p" style="width:100%; margin-bottom:10px;"><?= $editBook ? 'Lưu thay đổi' : 'Lưu sách' ?></button>
                        <?php if($editBook): ?>
                            <a href="?page=books" class="btn" style="width:100%; justify-content:center; background:var(--p-light); color:var(--text);">Hủy chỉnh sửa</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

        <?php elseif($page == 'members'): 
            $editMember = null;
            if(isset($_GET['edit_id'])) {
                $stmt = getDB()->prepare("SELECT * FROM members WHERE id = ?"); $stmt->execute([(int)$_GET['edit_id']]);
                $editMember = $stmt->fetch();
            }
        ?>
            <div style="margin-bottom: 1.5rem; display:flex; justify-content:space-between; align-items:center;">
                <input type="text" id="liveSearch" class="input" placeholder="🔍 Gõ tên độc giả, mã thẻ hoặc SĐT để tìm nhanh..." onkeyup="filterTable()" style="margin-bottom: 0; width:60%;">
                <a href="?action=export_members" class="btn" style="background:var(--success); color:white;"><i class="fas fa-file-excel"></i> Xuất Excel</a>
            </div>
            
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:2rem;">
                <div class="card" style="overflow-x:auto;"><h3>Danh sách độc giả</h3>
                    <table id="dataTable"><thead><tr><th>Độc giả</th><th>Liên hệ</th><th>Hạng</th><th>Trạng thái</th><th style="text-align:right;">Lệnh</th></tr></thead>
                    <tbody><?php foreach(getDB()->query("SELECT * FROM members ORDER BY id DESC")->fetchAll() as $m): ?>
                    <tr>
                        <td><strong><?=$m['full_name']?></strong><br><small style="color:var(--sub)"><?=$m['member_code']?></small></td>
                        <td><?=$m['phone']?><br><small>Hạn: <?=date('d/m/Y', strtotime($m['expiry_date']))?></small></td>
                        <td>
                            <?php if($m['rank'] == 'VIP'): ?>
                                <span class="badge" style="background:#fef3c7; color:#92400e;"><i class="fas fa-crown"></i> VIP</span>
                            <?php else: ?>
                                <span class="badge" style="background:var(--p-light); color:var(--p);">Standard</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($m['status'] == 'Suspended'): ?>
                                <span class="badge" style="background:var(--danger); color:white;"><i class="fas fa-ban"></i> Bị khóa</span>
                            <?php else: ?>
                                <span class="badge" style="background:#dcfce7; color:#166534;">Hoạt động</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a href="?page=members&edit_id=<?=$m['id']?>" class="btn-icon" style="background:var(--border); color:var(--text); text-decoration:none; margin-right:5px;"><i class="fas fa-edit"></i></a>
                            <button onclick="deleteItem('member', <?=$m['id']?>)" class="btn-icon" style="background:var(--danger); color:white; border:none;"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr><?php endforeach; ?></tbody></table>
                </div>
                <div class="card">
                    <h3><?= $editMember ? '✏️ Sửa thông tin' : '➕ Thêm độc giả mới' ?></h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="<?= $editMember ? 'edit_member' : 'add_member' ?>">
                        <?php if($editMember): ?><input type="hidden" name="id" value="<?=$editMember['id']?>"><?php endif; ?>
                        
                        <input type="text" name="full_name" class="input" placeholder="Họ tên" required value="<?= $editMember['full_name'] ?? '' ?>">
                        <input type="text" name="phone" class="input" placeholder="Số điện thoại" value="<?= $editMember['phone'] ?? '' ?>">
                        <label style="font-size:0.85rem; font-weight:600; margin-bottom:5px; display:block; color:var(--sub);">Hạn sử dụng thẻ:</label>
                        <input type="date" name="expiry_date" class="input" value="<?= $editMember['expiry_date'] ?? date('Y-m-d', strtotime('+1 year')) ?>">
                        
                        <button type="submit" class="btn btn-p" style="width:100%; margin-bottom:10px;"><?= $editMember ? 'Lưu thay đổi' : 'Tạo thẻ' ?></button>
                        <?php if($editMember): ?>
                            <a href="?page=members" class="btn" style="width:100%; justify-content:center; background:var(--p-light); color:var(--text);">Hủy chỉnh sửa</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

        <?php elseif($page == 'borrowings'): ?>
            <div style="margin-bottom: 1.5rem; display:flex; justify-content:space-between; align-items:center;">
                <input type="text" id="liveSearch" class="input" placeholder="🔍 Gõ tên sách hoặc tên độc giả để tìm nhanh..." onkeyup="filterTable()" style="margin-bottom: 0; width:60%;">
                <a href="?action=export_borrowings" class="btn" style="background:var(--success); color:white;"><i class="fas fa-file-excel"></i> Xuất lịch sử</a>
            </div>
            
            <?php 
            $pendingReqs = getDB()->query("SELECT r.*, m.full_name, bk.title FROM borrow_requests r JOIN members m ON r.member_id=m.id JOIN books bk ON r.book_id=bk.id WHERE r.status != 'Completed' AND r.status != 'Rejected' ORDER BY r.created_at DESC")->fetchAll();
            if($pendingReqs): ?>
            <div class="card" style="border: 2px solid var(--p);">
                <h3 style="color:var(--p);"><i class="fas fa-bell"></i> Yêu cầu mượn Online (Mới)</h3>
                <p style="font-size:0.85rem; color:var(--sub); margin-bottom:1.5rem;">Vui lòng chuẩn bị sách và cấp mã Locker hoặc gọi Shipper.</p>
                <table>
                    <thead><tr><th>Độc giả</th><th>Sách</th><th>Phương thức</th><th>Địa chỉ / Locker</th><th>Trạng thái</th><th style="text-align:right;">Xử lý</th></tr></thead>
                    <tbody>
                        <?php foreach($pendingReqs as $r): ?>
                        <tr>
                            <td><strong><?=$r['full_name']?></strong></td>
                            <td><?=$r['title']?></td>
                            <td><span class="badge" style="background:var(--p-light); color:var(--p);"><?=$r['pickup_method']?></span></td>
                            <td>
                                <?php if($r['pickup_method'] == 'Delivery'): ?><small><?=$r['delivery_address']?></small>
                                <?php elseif($r['pickup_method'] == 'Locker'): ?><strong><?=$r['locker_code'] ?: 'Chưa cấp'?></strong>
                                <?php else: ?>Tại quầy<?php endif; ?>
                            </td>
                            <td><span class="badge" style="background:var(--border); color:var(--text);"><?=$r['status']?></span></td>
                            <td style="text-align:right;">
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="action" value="process_borrow_request">
                                    <input type="hidden" name="id" value="<?=$r['id']?>">
                                    <?php if($r['status'] == 'Pending'): ?>
                                        <?php if($r['pickup_method'] == 'Locker'): ?>
                                            <input type="text" name="locker_code" placeholder="Mã Locker" class="input" style="width:100px; margin-bottom:0; display:inline-block; padding:5px;">
                                            <button name="status" value="Ready" class="btn btn-s" style="background:var(--p); color:white;">Để vào Locker</button>
                                        <?php elseif($r['pickup_method'] == 'Delivery'): ?>
                                            <button name="status" value="Shipping" class="btn btn-s" style="background:var(--p); color:white;">Gọi Shipper</button>
                                        <?php else: ?>
                                            <button name="status" value="Approved" class="btn btn-s" style="background:var(--p); color:white;">Duyệt</button>
                                        <?php endif; ?>
                                        <button name="status" value="Rejected" class="btn btn-s" style="background:var(--danger); color:white;">Từ chối</button>
                                    <?php elseif($r['status'] == 'Ready' || $r['status'] == 'Shipping' || $r['status'] == 'Approved'): ?>
                                        <button name="status" value="Completed" class="btn btn-s" style="background:#10b981; color:white;">Hoàn tất nhận sách</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:2rem;">
                <div class="card" style="overflow-x:auto;"><h3>Hoạt động mượn trả</h3>
                    <table id="dataTable"><thead><tr><th>Độc giả</th><th>Sách</th><th>SL</th><th>Ngày mượn</th><th>Phí hiện tại</th><th>Tiền cọc</th><th>TT</th><th>Lệnh</th></tr></thead>
                    <tbody><?php foreach(getDB()->query("SELECT b.*, m.full_name, bk.title FROM borrowings b JOIN members m ON b.member_id=m.id JOIN books bk ON b.book_id=bk.id ORDER BY b.created_at DESC")->fetchAll() as $b): 
                        $isOverdue = (strtotime($b['due_date']) < time() && $b['status'] == 'borrowed'); 
                        
                        // Tính phí tạm tính nếu đang mượn
                        $currentFee = 0;
                        if($b['status'] == 'borrowed') {
                            $start = new DateTime($b['borrow_date']);
                            $now = new DateTime();
                            $days = $now->diff($start)->days;
                            if($days >= 1) $currentFee = $days * FINE_RATE;
                        } else {
                            $currentFee = $b['fine_amount'] ?? 0;
                        }
                        ?>
                        <tr style="<?= $isOverdue ? 'background:var(--danger-light)' : '' ?>">
                            <td><strong><?=$b['full_name']?></strong></td>
                            <td><?=$b['title']?></td>
                            <td><span class="badge" style="background:var(--p-light); color:var(--text); font-weight:800;"><?= $b['quantity'] ?? 1 ?></span></td>
                            <td><small><?=date('d/m/Y', strtotime($b['borrow_date']))?></small></td>
                            <td style="color:var(--danger); font-weight:700;">-<?=number_format($currentFee)?>đ</td>
                            <td style="color:var(--sub); font-weight:700;"><?=number_format($b['deposit'] ?? 0)?>đ</td>
                            <td>
                                <?php if($b['status'] == 'returned'): ?><span class="badge" style="background:var(--p-light); color:var(--text);">Đã trả</span>
                                <?php elseif($b['status'] == 'lost'): ?><span class="badge" style="background:var(--danger-light); color:var(--danger);">Mất sách</span>
                                <?php else: ?><span class="badge" style="background:var(--border); color:var(--text);"><?=$isOverdue ? 'Quá hạn' : 'Đang mượn'?></span><?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php if($b['status'] == 'borrowed'): ?>
                                    <button class="btn btn-p btn-icon" onclick="returnBook(<?=$b['id']?>)" style="padding:0.4rem 0.6rem; font-size:0.8rem; margin-right:3px;" title="Trả sách & Hoàn cọc"><i class="fas fa-check"></i></button>
                                    <button class="btn btn-icon" onclick="reportDamaged(<?=$b['id']?>)" style="background:var(--border); color:var(--text); padding:0.4rem 0.6rem; font-size:0.8rem; margin-right:3px; border:1px solid var(--border);" title="Báo hỏng sách (Phạt)"><i class="fas fa-exclamation-triangle"></i></button>
                                    <button class="btn btn-icon" onclick="reportLost(<?=$b['id']?>)" style="background:var(--danger); color:white; padding:0.4rem 0.6rem; font-size:0.8rem; border:none; margin-right:5px;" title="Báo mất sách (Thu cọc)"><i class="fas fa-times"></i></button>
                                <?php endif; ?>
                                <button class="btn btn-icon" onclick="printBill('PM-<?=str_pad($b['id'], 4, '0', STR_PAD_LEFT)?>', '<?=date('d/m/Y H:i', strtotime($b['created_at']))?>', '<?=htmlspecialchars(addslashes($b['full_name']))?>', '<?=htmlspecialchars(addslashes($b['title']))?>', '<?=date('d/m/Y', strtotime($b['due_date']))?>', '<?=number_format($b['deposit'] ?? 0)?>đ')" style="background:var(--border); color:var(--text); padding:0.4rem 0.6rem; font-size:0.8rem;" title="In phiếu mượn"><i class="fas fa-print"></i></button>
                            </td>
                        </tr><?php endforeach; ?></tbody></table>
                </div>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <h3>Lập phiếu mượn</h3>
                        <button type="button" class="btn btn-s" onclick="startScanner()" style="background:var(--p-light); color:var(--text); padding:0.4rem 0.8rem; font-size:0.8rem;"><i class="fas fa-qrcode"></i> Quét mã</button>
                    </div>
                    
                    <div id="reader" style="width:100%; display:none; margin-bottom:1rem; border-radius:1rem; overflow:hidden; border:2px dashed var(--border);"></div>

                    <form method="POST">
                        <input type="hidden" name="action" value="borrow_book">
                        <label>Độc giả</label><select name="member_id" class="input"><?php foreach(getDB()->query("SELECT * FROM members")->fetchAll() as $m): ?><option value="<?=$m['id']?>"><?=$m['full_name']?></option><?php endforeach; ?></select>
                        <label>Sách</label>
                        <select name="book_id" id="bookSelect" class="input" onchange="updateDeposit()">
                            <?php foreach(getDB()->query("SELECT b.*, (b.quantity - IFNULL((SELECT SUM(quantity) FROM borrowings WHERE book_id = b.id AND status = 'borrowed'), 0)) as avail FROM books b")->fetchAll() as $bk): if($bk['avail']>0): ?>
                                <option value="<?=$bk['id']?>" data-price="<?=$bk['price']?>"><?=$bk['title']?> (Còn <?=$bk['avail']?>) - <?=number_format($bk['price'])?>đ</option>
                            <?php endif; endforeach; ?>
                        </select>
                        <label>Hạn trả</label><input type="date" name="due_date" class="input" value="<?=date('Y-m-d', strtotime('+'.LOAN_DAYS.' days'))?>">
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                            <div><label>Tiền cọc (VNĐ)</label><input type="number" name="deposit" id="borrowDeposit" class="input" value="0" min="0"></div>
                            <div><label>Số lượng mượn</label><input type="number" name="quantity" id="borrowQuantity" class="input" value="1" min="1" onchange="updateDeposit()" oninput="updateDeposit()"></div>
                        </div>
                        
                        <script>
                        function updateDeposit() {
                            const select = document.getElementById('bookSelect');
                            const qty = document.getElementById('borrowQuantity').value || 0;
                            const depositInput = document.getElementById('borrowDeposit');
                            const opt = select.options[select.selectedIndex];
                            if(opt && opt.dataset.price) {
                                depositInput.value = parseInt(opt.dataset.price) * qty;
                            } else {
                                depositInput.value = 0;
                            }
                        }
                        updateDeposit(); // Chạy ngay khi render
                        </script>
                        
                        <input type="hidden" name="borrow_date" value="<?=date('Y-m-d')?>">
                        <button type="submit" class="btn btn-p" style="width:100%;"><i class="fas fa-check"></i> Xác nhận mượn</button>
                    </form>
                </div>
            </div>

        <?php elseif($page == 'staff' && $_SESSION['role'] === 'admin'): 
            $editStaff = null;
            if(isset($_GET['edit_id'])) {
                $stmt = getDB()->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([(int)$_GET['edit_id']]);
                $editStaff = $stmt->fetch();
            }
        ?>
            <!-- STAFF ANALYTICS OVERVIEW -->
            <!-- FINANCIAL & EVENT DASHBOARD (ADMIN ONLY) -->
            <?php if($_SESSION['role'] === 'admin'): ?>
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:2rem; margin-bottom:2rem;">
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                        <h3><i class="fas fa-chart-line"></i> Doanh thu & Dòng tiền (VNĐ)</h3>
                        <span class="badge" style="background:#dcfce7; color:#166534;">Tháng <?=date('m/Y')?></span>
                    </div>
                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:1rem; margin-bottom:2rem;">
                        <div style="background:#f0fdf4; padding:1.5rem; border-radius:1rem; border:1px solid #bbf7d0;">
                            <small style="color:#166534;">Tiền phạt thu được</small>
                            <h2 style="color:#15803d; margin:5px 0;"><?= number_format($db->query("SELECT SUM(fine_amount) FROM borrowings WHERE status='returned'")->fetchColumn() ?: 0) ?>đ</h2>
                        </div>
                        <div style="background:#eff6ff; padding:1.5rem; border-radius:1rem; border:1px solid #bfdbfe;">
                            <small style="color:#1d4ed8;">Tiền cọc đang giữ</small>
                            <h2 style="color:#1d4ed8; margin:5px 0;"><?= number_format($db->query("SELECT SUM(deposit) FROM borrowings WHERE status='borrowed'")->fetchColumn() ?: 0) ?>đ</h2>
                        </div>
                        <div style="background:#fff7ed; padding:1.5rem; border-radius:1rem; border:1px solid #ffedd5;">
                            <small style="color:#c2410c;">Tổng chi nhập sách</small>
                            <h2 style="color:#c2410c; margin:5px 0;"><?= number_format($db->query("SELECT SUM(total_amount) FROM book_imports")->fetchColumn() ?: 0) ?>đ</h2>
                        </div>
                    </div>
                    <canvas id="revChart" style="max-height:250px;"></canvas>
                </div>
                
                <div class="card">
                    <h3><i class="fas fa-calendar-star"></i> Sự kiện sắp tới</h3>
                    <div style="display:flex; flex-direction:column; gap:15px; margin-top:1.5rem;">
                        <?php 
                        $evs = $db->query("SELECT e.*, (SELECT COUNT(*) FROM event_participants WHERE event_id=e.id) as joined FROM events e WHERE event_date >= NOW() ORDER BY event_date ASC LIMIT 3")->fetchAll();
                        if(!$evs) echo '<p style="color:var(--sub); font-size:0.85rem;">Chưa có sự kiện nào được lên lịch.</p>';
                        foreach($evs as $e):
                        ?>
                        <div style="padding:1rem; border-radius:1rem; border:1px solid var(--border); background:var(--bg);">
                            <small style="color:var(--p); font-weight:800;"><?= date('d/m | H:i', strtotime($e['event_date'])) ?></small>
                            <h4 style="margin:5px 0;"><?=$e['title']?></h4>
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <small style="color:var(--sub);"><i class="fas fa-map-marker-alt"></i> <?=$e['location']?></small>
                                <span style="font-size:0.75rem; font-weight:700;"><?=$e['joined']?>/<?=$e['max_participants']?> khách</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <button onclick="document.getElementById('eventModal').style.display='flex'" class="btn" style="width:100%; justify-content:center; background:var(--p-light); color:var(--p);"><i class="fas fa-plus"></i> Tạo sự kiện mới</button>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    new Chart(document.getElementById('revChart'), {
                        type: 'line',
                        data: {
                            labels: ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6'],
                            datasets: [{
                                label: 'Doanh thu tiền phạt',
                                data: [500000, 850000, 420000, 1200000, 950000, 1500000],
                                borderColor: 'var(--p)',
                                tension: 0.4,
                                fill: true,
                                backgroundColor: 'rgba(79, 70, 229, 0.1)'
                            }]
                        }
                    });
                });
            </script>
            <?php endif; ?>
                <div class="card" style="background:var(--white); border-left:5px solid #6366f1; margin-bottom:0; display:flex; align-items:center; gap:20px; padding:1.5rem;">
                    <div style="background:#e0e7ff; color:#4f46e5; width:50px; height:50px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.5rem;"><i class="fas fa-users"></i></div>
                    <div><h4 style="font-size:0.75rem; color:var(--sub);">Tổng nhân sự</h4><h2 style="margin:0;"><?= getDB()->query("SELECT COUNT(*) FROM users WHERE role='librarian'")->fetchColumn() ?></h2></div>
                </div>
                <div class="card" style="background:var(--white); border-left:5px solid #10b981; margin-bottom:0; display:flex; align-items:center; gap:20px; padding:1.5rem;">
                    <div style="background:#dcfce7; color:#059669; width:50px; height:50px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.5rem;"><i class="fas fa-check-circle"></i></div>
                    <div><h4 style="font-size:0.75rem; color:var(--sub);">Đang trực</h4><h2 style="margin:0;"><?= getDB()->query("SELECT COUNT(*) FROM attendance WHERE date=CURRENT_DATE AND check_out IS NULL")->fetchColumn() ?></h2></div>
                </div>
                <div class="card" style="background:var(--white); border-left:5px solid #f59e0b; margin-bottom:0; display:flex; align-items:center; gap:20px; padding:1.5rem;">
                    <div style="background:#fef3c7; color:#d97706; width:50px; height:50px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.5rem;"><i class="fas fa-exclamation-circle"></i></div>
                    <div><h4 style="font-size:0.75rem; color:var(--sub);">Đi muộn tháng này</h4><h2 style="margin:0;"><?= getDB()->query("SELECT COUNT(*) FROM attendance WHERE status='Late' AND MONTH(date)=MONTH(CURRENT_DATE)")->fetchColumn() ?></h2></div>
                </div>
                <div class="card" style="background:var(--white); border-left:5px solid #ec4899; margin-bottom:0; display:flex; align-items:center; gap:20px; padding:1.5rem;">
                    <div style="background:#fce7f3; color:#db2777; width:50px; height:50px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.5rem;"><i class="fas fa-tasks"></i></div>
                    <div><h4 style="font-size:0.75rem; color:var(--sub);">Việc tồn đọng</h4><h2 style="margin:0;"><?= getDB()->query("SELECT COUNT(*) FROM tasks WHERE status='Pending'")->fetchColumn() ?></h2></div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 380px; gap:2rem;">
                <!-- DANH SÁCH NHÂN VIÊN & HIỆU SUẤT -->
                <div>
                    <div class="card" style="margin-bottom:2rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
                            <h3><i class="fas fa-id-card"></i> Đội ngũ nhân sự Elite</h3>
                            <button onclick="document.getElementById('addStaffModal').style.display='flex'" class="btn btn-p" style="padding:0.6rem 1.2rem; font-size:0.85rem;"><i class="fas fa-plus"></i> Thêm nhân viên</button>
                        </div>
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem;">
                            <?php 
                            $staffs = getDB()->query("SELECT * FROM users WHERE role='librarian' ORDER BY id DESC")->fetchAll();
                            foreach($staffs as $s): 
                                // KPI Calculation
                                $stmtKPI = getDB()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status='Completed'");
                                $stmtKPI->execute([$s['id']]); $kpiTasks = $stmtKPI->fetchColumn();
                                
                                $stmtLate = getDB()->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = ? AND status='Late' AND MONTH(date)=MONTH(CURRENT_DATE)");
                                $stmtLate->execute([$s['id']]); $kpiLate = $stmtLate->fetchColumn();
                                
                                // Salary Calculation
                                $base = (int)($s['salary'] ?: 5000000);
                                $bonus = $kpiTasks * 50000;
                                $penalty = $kpiLate * 100000;
                                $totalSal = $base + $bonus - $penalty;
                            ?>
                                <div class="card" style="margin-bottom:0; background:#f8fafc; border:1px solid var(--border); padding:1.5rem; transition:0.3s; position:relative; overflow:hidden;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; padding-bottom:1rem; border-bottom:1px solid var(--border);">
                                        <div style="display:flex; align-items:center; gap:12px;">
                                            <div style="width:40px; height:40px; border-radius:50%; background:var(--p-light); display:flex; align-items:center; justify-content:center; color:var(--p); font-weight:800;"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
                                            <div>
                                                <strong style="display:block; font-size:1rem;"><?=$s['full_name']?></strong>
                                                <small style="color:var(--sub); display:block; margin:2px 0;"><?=$s['position'] ?: 'Thủ thư'?></small>
                                                <div style="display:flex; flex-direction:column; gap:2px;">
                                                    <small>📞 <a href="tel:<?=$s['phone']?>" style="text-decoration:none; color:var(--text); font-weight:600;"><?=$s['phone']?></a></small>
                                                    <small>📧 <a href="mailto:<?=$s['email']?>" style="text-decoration:none; color:var(--sub);"><?=$s['email']?></a></small>
                                                    <?php if(!empty($s['emergency_phone'])): ?>
                                                        <small style="color:var(--danger); font-weight:700;">🆘 Khẩn cấp: <a href="tel:<?=$s['emergency_phone']?>" style="color:var(--danger); text-decoration:none;"><?=$s['emergency_phone']?></a></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="display:flex; gap:8px;">
                                            <a href="?page=staff&edit_id=<?=$s['id']?>" class="btn btn-s" title="Sửa hồ sơ"><i class="fas fa-edit"></i></a>
                                            <button onclick="openTaskModal('<?=$s['id']?>', '<?=$s['full_name']?>')" class="btn btn-s" style="background:var(--p); color:white;"><i class="fas fa-tasks"></i></button>
                                        </div>
                                    </div>
                                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:15px;">
                                        <div style="background:white; padding:8px; border-radius:8px; border:1px solid var(--border); text-align:center;">
                                            <small style="color:var(--sub); display:block; font-size:0.7rem;">Việc xong</small>
                                            <strong style="color:#059669;"><?=$kpiTasks?></strong>
                                        </div>
                                        <div style="background:white; padding:8px; border-radius:8px; border:1px solid var(--border); text-align:center;">
                                            <small style="color:var(--sub); display:block; font-size:0.7rem;">Lần muộn</small>
                                            <strong style="color:<?=$kpiLate>0?'var(--danger)':'#059669'?>;"><?=$kpiLate?></strong>
                                        </div>
                                    </div>

                                    <div style="background:var(--p-light); padding:10px; border-radius:10px; margin-bottom:15px;">
                                        <small style="display:block; font-size:0.7rem; color:var(--p); margin-bottom:2px;">Lương dự tính tháng này:</small>
                                        <strong style="color:var(--p); font-size:1.1rem;"><?=number_format($totalSal)?>đ</strong>
                                    </div>
                                    <?php if($kpiLate == 0 && $kpiTasks > 5): ?><div style="position:absolute; top:0; right:0; background:#facc15; color:#854d0e; padding:4px 12px; font-size:0.6rem; font-weight:900; border-radius:0 0 0 10px;"><i class="fas fa-star"></i> XUẤT SẮC</div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-calendar-check"></i> Phê duyệt Lịch làm & Chấm công</h3>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem;">
                            <div>
                                <h4 style="margin-bottom:1rem; font-size:0.9rem; color:var(--p);">Yêu cầu đăng ký ca</h4>
                                <table style="font-size:0.85rem;">
                                    <tbody><?php foreach(getDB()->query("SELECT s.*, u.full_name FROM schedules s JOIN users u ON s.user_id=u.id WHERE s.status='Pending' ORDER BY s.shift_date ASC")->fetchAll() as $s): ?>
                                    <tr>
                                        <td><strong><?=date('d/m/Y', strtotime($s['shift_date']))?></strong><br><small><?=$s['full_name']?></small></td>
                                        <td><?=$s['shift_name']?></td>
                                        <td style="text-align:right;">
                                            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="approve_schedule"><input type="hidden" name="id" value="<?=$s['id']?>"><button type="submit" class="btn-icon" style="background:#dcfce7; color:#166534;"><i class="fas fa-check"></i></button></form>
                                            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="reject_schedule"><input type="hidden" name="id" value="<?=$s['id']?>"><button type="submit" class="btn-icon" style="background:#fee2e2; color:#991b1b;"><i class="fas fa-times"></i></button></form>
                                        </td>
                                    </tr><?php endforeach; ?></tbody>
                                </table>
                            </div>
                            <div>
                                <h4 style="margin-bottom:1rem; font-size:0.9rem; color:var(--p);">Chấm công mới nhất</h4>
                                <table style="font-size:0.85rem;">
                                    <tbody><?php foreach(getDB()->query("SELECT a.*, u.full_name FROM attendance a JOIN users u ON a.user_id=u.id ORDER BY a.date DESC LIMIT 5")->fetchAll() as $a): ?>
                                    <tr>
                                        <td><strong><?=$a['full_name']?></strong><br><small><?=date('d/m/Y', strtotime($a['date']))?></small></td>
                                        <td><?=$a['check_in']?> - <?=$a['check_out'] ?? '--:--'?></td>
                                        <td><span class="badge" style="font-size:0.6rem; background:<?=$a['status']=='Late'?'#fee2e2':'#dcfce7'?>; color:<?=$a['status']=='Late'?'#991b1b':'#166534'?>;"><?=$a['status']=='Late'?'Muộn':'OK'?></span></td>
                                    </tr><?php endforeach; ?></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FORM CẬP NHẬT / CHI TIẾT (CỘT PHẢI) -->
                <div class="card" style="height: fit-content; position: sticky; top: 20px;">
                    <?php if($editStaff): ?>
                        <h3><i class="fas fa-user-edit"></i> Hồ sơ chi tiết</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_staff_info">
                            <input type="hidden" name="id" value="<?=$editStaff['id']?>">
                            
                            <label>Họ và tên</label><input type="text" name="full_name" class="input" value="<?=$editStaff['full_name']?>" required>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                                <div><label>Chức vụ</label><input type="text" name="position" class="input" value="<?=$editStaff['position']?>"></div>
                                <div><label>Lương (VNĐ)</label><input type="number" name="salary" class="input" value="<?=$editStaff['salary']?>"></div>
                            </div>
                            <label>Email</label><input type="email" name="email" class="input" value="<?=$editStaff['email']?>">
                            <label>Số điện thoại</label><input type="text" name="phone" class="input" value="<?=$editStaff['phone']?>">
                            <label>Địa chỉ</label><textarea name="address" class="input" style="height:60px;"><?=$editStaff['address']?></textarea>
                            <label style="color:var(--danger); font-weight:800;">Cảnh báo / Lời nhắc khi Login</label>
                            <textarea name="warning_msg" class="input" style="border-color:var(--danger); background:var(--danger-light);"><?=$editStaff['warning_msg']?></textarea>
                            <button type="submit" class="btn btn-p" style="width:100%; margin-top:1rem;">Lưu hồ sơ</button>
                            <a href="?page=staff" class="btn" style="width:100%; justify-content:center; background:var(--bg); color:var(--text); margin-top:10px;">Hủy bỏ</a>
                        </form>
                    <?php else: ?>
                        <div style="text-align:center; padding:5rem 2rem; color:var(--sub);">
                            <i class="fas fa-user-shield" style="font-size:5rem; opacity:0.1; margin-bottom:2rem;"></i>
                            <p>Chọn hồ sơ nhân viên để xem hiệu suất làm việc và quản lý lương thưởng chi tiết.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif($page == 'inventory' && $_SESSION['role'] === 'admin'): ?>
            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:2rem; margin-bottom:2rem;">
                <div class="card">
                    <h3><i class="fas fa-plus-circle"></i> Phiếu Nhập Kho</h3>
                    <p style="color:var(--sub); font-size:0.85rem; margin-bottom:1.5rem;">Số hóa hóa đơn nhập sách từ nhà cung cấp.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="import_books">
                        <label>Nhà cung cấp</label>
                        <select name="supplier_id" class="input" required>
                            <?php foreach(getDB()->query("SELECT * FROM suppliers")->fetchAll() as $s): ?>
                                <option value="<?=$s['id']?>"><?=$s['name']?></option>
                            <?php endforeach; ?>
                        </select>
                        <label>Ngày nhập</label>
                        <input type="date" name="import_date" class="input" value="<?=date('Y-m-d')?>" required>
                        
                        <label style="font-weight:800; color:var(--p); display:block; margin-bottom:10px;">Danh sách sách nhập:</label>
                        <div style="max-height:300px; overflow-y:auto; border:1px solid var(--border); padding:1rem; border-radius:1rem; background:var(--bg);">
                            <?php foreach(getDB()->query("SELECT id, title FROM books")->fetchAll() as $b): ?>
                                <div style="display:grid; grid-template-columns: 1fr 80px 100px; gap:10px; margin-bottom:10px; align-items:center;">
                                    <small style="font-weight:600;"><?=$b['title']?></small>
                                    <input type="number" name="items[<?=$b['id']?>][qty]" class="input" placeholder="SL" style="margin-bottom:0; padding:5px;">
                                    <input type="number" name="items[<?=$b['id']?>][price]" class="input" placeholder="Giá" style="margin-bottom:0; padding:5px;">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn btn-p" style="width:100%; justify-content:center; margin-top:1.5rem;"><i class="fas fa-file-import"></i> Xác nhận nhập kho</button>
                    </form>
                </div>
                
                <div style="display:flex; flex-direction:column; gap:2rem;">
                    <div class="card" style="margin-bottom:0;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                            <h3><i class="fas fa-chart-pie"></i> Phân bổ Kho sách</h3>
                            <span class="badge" style="background:var(--p-light); color:var(--p);">Tổng: <?= number_format($db->query("SELECT SUM(quantity) FROM books")->fetchColumn()) ?> cuốn</span>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; align-items:center;">
                            <canvas id="stockPieChart" style="max-width:200px;"></canvas>
                            <div>
                                <ul style="list-style:none; padding:0; font-size:0.85rem;">
                                    <?php 
                                    $cats = $db->query("SELECT c.name, SUM(b.quantity) as qty FROM categories c JOIN books b ON c.id=b.category_id GROUP BY c.id")->fetchAll();
                                    foreach($cats as $c):
                                    ?>
                                        <li style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border);">
                                            <span><?=$c['name']?></span>
                                            <strong><?=$c['qty']?></strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card" style="margin-bottom:0;">
                        <h3><i class="fas fa-history"></i> Lịch sử Nhập hàng</h3>
                        <table>
                            <thead><tr><th>Ngày</th><th>Nhà cung cấp</th><th>Tổng giá trị</th><th>Chi tiết</th></tr></thead>
                            <tbody>
                                <?php 
                                $imports = getDB()->query("SELECT i.*, s.name as s_name FROM book_imports i JOIN suppliers s ON i.supplier_id = s.id ORDER BY i.id DESC LIMIT 5")->fetchAll();
                                foreach($imports as $imp):
                                ?>
                                <tr>
                                    <td><?=date('d/m/Y', strtotime($imp['import_date']))?></td>
                                    <td><strong><?=$imp['s_name']?></strong></td>
                                    <td style="color:var(--p); font-weight:700;"><?=number_format($imp['total_amount'])?>đ</td>
                                    <td><button class="btn" style="padding:4px 8px; font-size:10px; background:var(--p-light);">Xem</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    new Chart(document.getElementById('stockPieChart'), {
                        type: 'doughnut',
                        data: {
                            labels: [<?php foreach($cats as $c) echo "'".$c['name']."',"; ?>],
                            datasets: [{
                                data: [<?php foreach($cats as $c) echo $c['qty'].","; ?>],
                                backgroundColor: ['#4f46e5', '#10b981', '#f59e0b', '#ec4899', '#3b82f6', '#8b5cf6'],
                                borderWidth: 0
                            }]
                        },
                        options: { plugins: { legend: { display: false } }, cutout: '70%' }
                    });
                });
            </script>

        <?php elseif($page == 'logs' && $_SESSION['role'] === 'admin'): ?>
            <div style="margin-bottom: 1.5rem;">
                <input type="text" id="liveSearch" class="input" placeholder="🔍 Gõ tên tài khoản hoặc hành động để tìm nhanh..." onkeyup="filterTable()" style="margin-bottom: 0; width:60%;">
            </div>
            
            <div class="card" style="overflow-x:auto;">
                <h3><i class="fas fa-history" style="color:var(--p);"></i> Lịch sử đăng nhập & Hoạt động</h3>
                <table id="dataTable">
                    <thead><tr><th>Thời gian</th><th>Tài khoản</th><th>Hành động</th><th>Chi tiết</th></tr></thead>
                    <tbody>
                        <?php 
                        $logsQuery = "SELECT l.*, u.username, u.full_name FROM logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC";
                        foreach(getDB()->query($logsQuery)->fetchAll() as $l): 
                            $isLogin = (strpos(strtolower($l['action']), 'đăng nhập') !== false);
                        ?>
                        <tr style="<?= $isLogin ? 'background:var(--p-light);' : '' ?>">
                            <td><small><?= date('d/m/Y - H:i:s', strtotime($l['created_at'])) ?></small></td>
                            <td><strong><?= htmlspecialchars($l['username'] ?? 'Hệ thống') ?></strong><br><small style="color:var(--sub)"><?= htmlspecialchars($l['full_name'] ?? '') ?></small></td>
                            <td><span class="badge" style="background:<?= $isLogin ? 'var(--p)' : 'var(--border)' ?>; color:<?= $isLogin ? 'white' : 'var(--text)' ?>;"><?= htmlspecialchars($l['action']) ?></span></td>
                            <td><small><?= htmlspecialchars($l['details']) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif($page == 'settings'): ?>
            <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap:2rem;">
                <!-- SIDEBAR CÀI ĐẶT -->
                <div style="display:flex; flex-direction:column; gap:1rem;">
                    <div class="card" style="padding:1rem;">
                        <?php if($_SESSION['role'] === 'admin'): ?>
                            <button onclick="showTab('system')" class="btn tab-btn active" id="tab-system" style="width:100%; text-align:left; justify-content:flex-start; background:transparent; color:var(--text);"><i class="fas fa-university"></i> Thư viện</button>
                            <button onclick="showTab('shifts')" class="btn tab-btn" id="tab-shifts" style="width:100%; text-align:left; justify-content:flex-start; background:transparent; color:var(--text);"><i class="fas fa-clock"></i> Ca làm việc</button>
                            <button onclick="showTab('rules')" class="btn tab-btn" id="tab-rules" style="width:100%; text-align:left; justify-content:flex-start; background:transparent; color:var(--text);"><i class="fas fa-gavel"></i> Quy định mượn</button>
                        <?php endif; ?>
                        <button onclick="showTab('account')" class="btn tab-btn <?= $_SESSION['role']!=='admin'?'active':'' ?>" id="tab-account" style="width:100%; text-align:left; justify-content:flex-start; background:transparent; color:var(--text);"><i class="fas fa-user-shield"></i> Bảo mật</button>
                        <?php if($_SESSION['role'] === 'admin'): ?>
                            <button onclick="showTab('display')" class="btn tab-btn" id="tab-display" style="width:100%; text-align:left; justify-content:flex-start; background:transparent; color:var(--text);"><i class="fas fa-palette"></i> Giao diện</button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- NỘI DUNG CÀI ĐẶT -->
                <div id="settingsContent">
                    <!-- Tab Hệ thống -->
                    <?php if($_SESSION['role'] === 'admin'): ?>
                    <div id="tab-content-system" class="settings-tab">
                        <div class="card">
                            <h3><i class="fas fa-university"></i> Thông tin Thư viện</h3>
                            <form method="POST" style="margin-top:1.5rem;">
                                <input type="hidden" name="action" value="update_settings">
                                <label>Tên thư viện</label>
                                <input type="text" name="lib_name" class="input" value="<?=LIB_NAME?>">
                                <label>Phí phạt trễ / ngày (VNĐ)</label>
                                <input type="number" name="fine_rate" class="input" value="<?=FINE_RATE?>">
                                <button type="submit" class="btn btn-p"><i class="fas fa-save"></i> Lưu cấu hình</button>
                            </form>
                            
                            <hr style="margin:2rem 0; border:none; border-top:1px solid var(--border);">
                            <h3 style="color:var(--danger);"><i class="fas fa-database"></i> Dữ liệu hệ thống</h3>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem; margin-top:1rem;">
                                <a href="?action=backup_db" class="btn" style="background:var(--bg); border:1.5px solid var(--border); color:var(--text); justify-content:center;"><i class="fas fa-download"></i> Sao lưu SQL</a>
                                <button class="btn" style="background:var(--danger-light); color:var(--danger); justify-content:center; border:none;" onclick="alert('Tính năng này đang được phát triển!')"><i class="fas fa-trash-alt"></i> Xóa dữ liệu mẫu</button>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Ca làm việc -->
                    <div id="tab-content-shifts" class="settings-tab" style="display:none;">
                        <div class="card">
                            <h3><i class="fas fa-clock"></i> Cấu hình Giờ làm việc</h3>
                            <p style="color:var(--sub); font-size:0.85rem; margin-bottom:1.5rem;">Dùng để tính toán đi muộn/đúng giờ cho nhân viên.</p>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_settings">
                                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                                    <div><label>Giờ bắt đầu Ca Sáng</label><input type="time" name="shift_morning_start" class="input" value="<?=SHIFT_MORNING?>"></div>
                                    <div><label>Giờ bắt đầu Ca Chiều</label><input type="time" name="shift_afternoon_start" class="input" value="<?=SHIFT_AFTERNOON?>"></div>
                                    <div><label>Giờ bắt đầu Ca Tối</label><input type="time" name="shift_evening_start" class="input" value="<?=SHIFT_EVENING?>"></div>
                                </div>
                                <button type="submit" class="btn btn-p"><i class="fas fa-save"></i> Cập nhật giờ làm</button>
                            </form>
                        </div>
                    </div>

                    <!-- Tab Quy định mượn -->
                    <div id="tab-content-rules" class="settings-tab" style="display:none;">
                        <div class="card">
                            <h3><i class="fas fa-gavel"></i> Quy định Mượn sách</h3>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_settings">
                                <label>Số lượng sách tối đa / lần mượn</label>
                                <input type="number" name="max_books" class="input" value="<?=MAX_BOOKS?>">
                                <label>Số ngày mượn mặc định</label>
                                <input type="number" name="default_loan_days" class="input" value="<?=LOAN_DAYS?>">
                                <button type="submit" class="btn btn-p"><i class="fas fa-save"></i> Lưu quy định</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Tab Tài khoản -->
                    <div id="tab-content-account" class="settings-tab" style="<?= $_SESSION['role']!=='admin'?'':'display:none;' ?>">
                        <div class="card" style="border-top:5px solid var(--p);">
                            <h3><i class="fas fa-lock"></i> Đổi mật khẩu</h3>
                            <form method="POST" style="margin-top:1.5rem;">
                                <input type="hidden" name="action" value="change_password">
                                <label>Mật khẩu cũ</label>
                                <input type="password" name="old_password" class="input" required>
                                <label>Mật khẩu mới</label>
                                <input type="password" name="new_password" class="input" required>
                                <label>Xác nhận mật khẩu mới</label>
                                <input type="password" name="confirm_password" class="input" required>
                                <button type="submit" class="btn btn-p" style="background:var(--sub);"><i class="fas fa-key"></i> Cập nhật mật khẩu</button>
                            </form>
                        </div>
                    </div>

                    <!-- Tab Giao diện -->
                    <div id="tab-content-display" class="settings-tab" style="display:none;">
                        <div class="card">
                            <h3><i class="fas fa-paint-brush"></i> Tùy chỉnh màu sắc</h3>
                            <form method="POST" style="margin-top:1.5rem;">
                                <input type="hidden" name="action" value="update_settings">
                                <input type="hidden" name="lib_name" value="<?=LIB_NAME?>">
                                <input type="hidden" name="fine_rate" value="<?=FINE_RATE?>">
                                <label>Màu chủ đạo (Theme Color)</label>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <input type="color" name="theme_color" value="<?=THEME_COLOR?>" style="width:50px; height:50px; border:none; background:none; cursor:pointer;">
                                    <span style="font-family:monospace;"><?=THEME_COLOR?></span>
                                </div>
                                <button type="submit" class="btn btn-p" style="margin-top:1.5rem;"><i class="fas fa-check"></i> Áp dụng màu mới</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            function showTab(tabId) {
                document.querySelectorAll('.settings-tab').forEach(t => t.style.display = 'none');
                document.querySelectorAll('.tab-btn').forEach(b => {
                    b.classList.remove('active');
                    b.style.background = 'transparent';
                    b.style.color = 'var(--text)';
                });
                const content = document.getElementById('tab-content-' + tabId);
                if(content) content.style.display = 'block';
                const activeBtn = document.getElementById('tab-' + tabId);
                if(activeBtn) {
                    activeBtn.classList.add('active');
                    activeBtn.style.background = 'var(--p)';
                    activeBtn.style.color = 'white';
                }
            }
            // Init
            document.addEventListener('DOMContentLoaded', () => {
                showTab('<?= $_SESSION['role']==='admin'?'system':'account' ?>');
            });
            </script>
        <?php elseif($page == 'profile'): 
            $u = getDB()->prepare("SELECT * FROM users WHERE id = ?"); $u->execute([$_SESSION['user_id']]); $me = $u->fetch();
        ?>
            <div style="display:grid; grid-template-columns: 1.2fr 2fr; gap:2rem;">
                <div class="card" style="text-align:center; position:relative; overflow:hidden;">
                    <div style="position:absolute; top:0; left:0; width:100%; height:100px; background:linear-gradient(to right, var(--p), var(--s)); z-index:0;"></div>
                    <img src="https://upload.wikimedia.org/wikipedia/en/0/05/Hello_kitty_character_portrait.png" style="width:120px; height:120px; border-radius:50%; border:5px solid var(--white); position:relative; z-index:1; margin-top:30px; object-fit:cover; background:var(--white);">
                    <h2 style="margin-top:1rem;"><?= $me['full_name'] ?></h2>
                    <p style="color:var(--sub); font-weight:600;"><?= strtoupper($me['position'] ?: 'Thành viên') ?></p>
                    <div style="margin-top:2rem; text-align:left; background:var(--bg); padding:1.5rem; border-radius:1rem; border:1px solid var(--border);">
                        <p style="margin-bottom:0.5rem;"><i class="fas fa-envelope" style="width:25px; color:var(--p);"></i> <?=$me['email']?></p>
                        <p style="margin-bottom:0.5rem;"><i class="fas fa-phone" style="width:25px; color:var(--p);"></i> <?=$me['phone']?></p>
                        <p><i class="fas fa-map-marker-alt" style="width:25px; color:var(--p);"></i> <?=$me['address']?></p>
                    </div>
                </div>
                <div class="card">
                    <h3>Cập nhật thông tin cá nhân</h3>
                    <form method="POST" style="margin-top:1.5rem;">
                        <input type="hidden" name="action" value="update_profile">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem;">
                            <div><label>Họ và tên</label><input type="text" name="full_name" class="input" value="<?=$me['full_name']?>" required></div>
                            <div><label>Email</label><input type="email" name="email" class="input" value="<?=$me['email']?>"></div>
                        </div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem;">
                            <div><label>Số điện thoại</label><input type="text" name="phone" class="input" value="<?=$me['phone']?>"></div>
                            <div><label>Địa chỉ</label><input type="text" name="address" class="input" value="<?=$me['address']?>"></div>
                        </div>
                        <button type="submit" class="btn btn-p" style="margin-top:1rem; width:200px;"><i class="fas fa-save"></i> Lưu thông tin</button>
                    </form>
                </div>
            </div>
        <?php elseif($page == 'schedules' && $_SESSION['role'] === 'librarian'): 
            $editSched = null;
            if(isset($_GET['sched_id'])) {
                $st = getDB()->prepare("SELECT * FROM schedules WHERE id = ? AND user_id = ? AND status = 'Pending'");
                $st->execute([(int)$_GET['sched_id'], $_SESSION['user_id']]);
                $editSched = $st->fetch();
            }
        ?>
            <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap:2rem;">
                <div class="card" style="border-top:5px solid var(--p);">
                    <?php if($editSched): ?>
                        <h3>✏️ Sửa lịch làm việc</h3>
                        <p style="color:var(--sub); font-size:0.85rem; margin-bottom:1.5rem;">Cập nhật lại ca làm cho ngày <?=date('d/m/Y', strtotime($editSched['shift_date']))?></p>
                        <form method="POST">
                            <input type="hidden" name="action" value="register_shift">
                            <input type="hidden" name="edit_sched_id" value="<?=$editSched['id']?>">
                            <label>Ngày làm việc</label>
                            <input type="date" name="shift_date" class="input" value="<?=$editSched['shift_date']?>" readonly>
                            <label>Chọn ca mới</label>
                            <select name="shift_name" class="input" required>
                                <?php 
                                $shiftOptions = ['' => '-- Chọn ca làm --', 'Ca Sáng (08:00 - 12:00)' => 'Ca Sáng (08:00 - 12:00)', 'Ca Chiều (13:00 - 17:00)' => 'Ca Chiều (13:00 - 17:00)', 'Ca Tối (18:00 - 22:00)' => 'Ca Tối (18:00 - 22:00)', 'Full-time (08:00 - 21:00)' => 'Full-time (08:00 - 21:00)', 'Hành chính (08:00 - 17:30)' => 'Hành chính (08:00 - 17:30)', 'Nghỉ' => 'Nghỉ'];
                                foreach($shiftOptions as $v => $l): ?>
                                    <option value="<?=$v?>" <?=$editSched['shift_name']==$v?'selected':''?>><?=$l?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-p" style="width:100%; justify-content:center;"><i class="fas fa-save"></i> Cập nhật thay đổi</button>
                            <a href="?page=schedules" class="btn" style="width:100%; justify-content:center; margin-top:10px; background:var(--bg); color:var(--text);">Hủy bỏ</a>
                        </form>
                    <?php else: ?>
                        <h3>📝 Đăng ký lịch tuần</h3>
                        <p style="color:var(--sub); font-size:0.85rem; margin-bottom:1.5rem;">Đăng ký lịch làm việc cho cả tuần (7 ngày).</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="register_weekly_shift">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                <label style="font-weight:700; display:block;">Tuần bắt đầu (Thứ 2)</label>
                                <button type="button" onclick="quickFill()" class="btn" style="padding:0.4rem 0.8rem; font-size:0.75rem; background:var(--bg); border:1px solid var(--border); color:var(--sub);"><i class="fas fa-magic"></i> Áp dụng nhanh cho cả tuần</button>
                            </div>
                            <input type="date" name="week_start" class="input" value="<?=date('Y-m-d', strtotime('next monday'))?>" required>
                            
                            <div style="background:var(--p-light); padding:1.5rem; border-radius:1rem; border:1px solid var(--border);">
                                <?php 
                                $days = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật']; 
                                $shiftOptions = [
                                    '' => '-- Chọn ca làm --',
                                    'Ca Sáng (08:00 - 12:00)' => 'Ca Sáng (08:00 - 12:00)',
                                    'Ca Chiều (13:00 - 17:00)' => 'Ca Chiều (13:00 - 17:00)',
                                    'Ca Tối (18:00 - 22:00)' => 'Ca Tối (18:00 - 22:00)',
                                    'Full-time (08:00 - 21:00)' => 'Full-time (08:00 - 21:00)',
                                    'Hành chính (08:00 - 17:30)' => 'Hành chính (08:00 - 17:30)',
                                    'Nghỉ' => 'Nghỉ'
                                ];
                                foreach($days as $i => $day): 
                                ?>
                                    <div style="display:grid; grid-template-columns: 100px 1fr; align-items:center; margin-bottom:12px; gap:15px;">
                                        <span style="font-weight:700; font-size:0.9rem; color:var(--text);"><?=$day?></span>
                                        <select name="shifts[]" class="shift-select input" style="margin-bottom:0; padding:0.6rem; font-size:0.85rem; border-radius:0.75rem;">
                                            <?php foreach($shiftOptions as $val => $label): ?>
                                                <option value="<?=$val?>"><?=$label?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <script>
                            function quickFill() {
                                const firstVal = document.querySelector('.shift-select').value;
                                if(!firstVal) { alert('Vui lòng chọn ca ở Thứ 2 trước khi áp dụng nhanh!'); return; }
                                document.querySelectorAll('.shift-select').forEach(s => s.value = firstVal);
                            }
                            </script>
                            
                            <button type="submit" class="btn btn-p" style="width:100%; justify-content:center; margin-top:1.5rem;"><i class="fas fa-calendar-check"></i> Đăng ký trọn tuần</button>
                        </form>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <h3>📅 Lịch sử & Linh hoạt thay đổi</h3>
                    <div style="overflow-x:auto; margin-top:1rem;">
                        <table>
                            <thead><tr><th>Ngày làm</th><th>Ca / Thời gian</th><th>Trạng thái</th><th style="text-align:right;">Hành động</th></tr></thead>
                            <tbody>
                                <?php 
                                $stmtS = getDB()->prepare("SELECT * FROM schedules WHERE user_id = ? ORDER BY shift_date DESC LIMIT 20");
                                $stmtS->execute([$_SESSION['user_id']]);
                                while($row = $stmtS->fetch()): 
                                ?>
                                <tr>
                                    <td><strong><?=date('d/m/Y', strtotime($row['shift_date']))?></strong><br><small><?=date('l', strtotime($row['shift_date']))?></small></td>
                                    <td><?=$row['shift_name']?></td>
                                    <td>
                                        <?php if($row['status'] == 'Pending'): ?><span class="badge" style="background:#fef3c7; color:#92400e;">Chờ duyệt</span>
                                        <?php elseif($row['status'] == 'Assigned'): ?><span class="badge" style="background:#dcfce7; color:#166534;">Đã duyệt</span>
                                        <?php elseif($row['status'] == 'Rejected'): ?><span class="badge" style="background:#fee2e2; color:#991b1b;">Từ chối</span>
                                        <?php else: ?><span class="badge" style="background:var(--border); color:var(--text);"><?=$row['status']?></span><?php endif; ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <?php if($row['status'] == 'Pending'): ?>
                                            <a href="?page=schedules&sched_id=<?=$row['id']?>" class="btn-icon" style="background:var(--p-light); color:var(--p); text-decoration:none; margin-right:5px;" title="Sửa yêu cầu"><i class="fas fa-edit"></i></a>
                                            <form method="POST" onsubmit="return confirm('Hủy đăng ký này?')" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_schedule">
                                                <input type="hidden" name="id" value="<?=$row['id']?>">
                                                <button type="submit" class="btn-icon" style="background:var(--danger-light); color:var(--danger); border:none;"><i class="fas fa-times"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif($page == 'dashboard' && $_SESSION['role'] === 'member'): ?>
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:2rem;">
                <div>
                    <h2 style="margin-bottom:1.5rem;">Chào mừng quay lại, <?=$_SESSION['full_name']?>!</h2>
                    
                    <!-- AI RECOMMENDATIONS -->
                    <div class="card" style="background: linear-gradient(135deg, var(--p), #be185d); color:white;">
                        <h3 style="color:white;"><i class="fas fa-magic"></i> Gợi ý riêng cho bạn (AI)</h3>
                        <p style="font-size:0.85rem; opacity:0.9; margin-bottom:1.5rem;">Dựa trên gu đọc sách của bạn, chúng tôi nghĩ bạn sẽ thích:</p>
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:1rem;">
                            <?php 
                            // Lấy thể loại mượn nhiều nhất của user
                            $topCat = getDB()->prepare("SELECT b.category_id FROM borrowings br JOIN books b ON br.book_id=b.id WHERE br.member_id = ? GROUP BY b.category_id ORDER BY COUNT(*) DESC LIMIT 1");
                            $topCat->execute([$_SESSION['member_id']]);
                            $catId = $topCat->fetchColumn() ?: 1;
                            
                            $recs = getDB()->prepare("SELECT * FROM books WHERE category_id = ? AND id NOT IN (SELECT book_id FROM borrowings WHERE member_id = ?) ORDER BY RAND() LIMIT 3");
                            $recs->execute([$catId, $_SESSION['member_id']]);
                            foreach($recs->fetchAll() as $r):
                            ?>
                            <div style="background:rgba(255,255,255,0.1); padding:1rem; border-radius:1rem; backdrop-filter:blur(5px); border:1px solid rgba(255,255,255,0.2);">
                                <h4 style="font-size:0.9rem; margin-bottom:5px;"><?=$r['title']?></h4>
                                <small style="opacity:0.8;"><?=$r['author']?></small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-quote-left"></i> Bảng tin trích dẫn</h3>
                        <form method="POST" style="margin-bottom:2rem; background:var(--bg); padding:1rem; border-radius:1rem;">
                            <input type="hidden" name="action" value="add_quote">
                            <select name="book_id" class="input" style="font-size:0.8rem; padding:5px;">
                                <option value="">-- Chọn sách bạn đã đọc --</option>
                                <?php foreach(getDB()->query("SELECT b.id, b.title FROM borrowings br JOIN books b ON br.book_id=b.id WHERE br.member_id=".$_SESSION['member_id'])->fetchAll() as $b): ?>
                                    <option value="<?=$b['id']?>"><?=$b['title']?></option>
                                <?php endforeach; ?>
                            </select>
                            <textarea name="content" class="input" placeholder="Chia sẻ một câu nói tâm đắc..." style="height:60px; font-size:0.85rem;"></textarea>
                            <button type="submit" class="btn btn-p" style="padding:5px 15px; font-size:0.8rem;">Chia sẻ</button>
                        </form>

                        <div style="max-height:400px; overflow-y:auto;">
                            <?php 
                            $quotes = getDB()->query("SELECT q.*, m.full_name, b.title FROM book_quotes q JOIN members m ON q.member_id=m.id JOIN books b ON q.book_id=b.id ORDER BY q.created_at DESC LIMIT 10")->fetchAll();
                            foreach($quotes as $q):
                            ?>
                            <div style="border-left:3px solid var(--p); padding-left:1rem; margin-bottom:1.5rem;">
                                <p style="font-style:italic; font-size:0.95rem; margin-bottom:5px;">"<?=$q['content']?>"</p>
                                <small style="color:var(--sub);">-- <strong><?=$q['full_name']?></strong> chia sẻ từ <em><?=$q['title']?></em></small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div>
                    <!-- SỰ KIỆN DÀNH CHO BẠN -->
                    <div class="card" style="border: 2px solid #10b981;">
                        <h3 style="color:#10b981;"><i class="fas fa-calendar-check"></i> Sự kiện dành cho bạn</h3>
                        <div style="display:flex; flex-direction:column; gap:10px; margin-top:1rem;">
                            <?php 
                            $mEvs = $db->query("SELECT * FROM events WHERE event_date >= NOW() ORDER BY event_date ASC LIMIT 2")->fetchAll();
                            foreach($mEvs as $me):
                                $isJoined = $db->prepare("SELECT id FROM event_participants WHERE event_id=? AND member_id=?");
                                $isJoined->execute([$me['id'], $_SESSION['member_id']]);
                            ?>
                            <div style="padding:10px; background:var(--bg); border-radius:10px; border:1px solid var(--border);">
                                <small style="color:var(--p); font-weight:800;"><?=date('d/m H:i', strtotime($me['event_date']))?></small>
                                <h4 style="margin:2px 0; font-size:0.9rem;"><?=$me['title']?></h4>
                                <?php if($isJoined->fetch()): ?>
                                    <span class="badge" style="background:#dcfce7; color:#166534; font-size:0.6rem; display:inline-block; margin-top:5px;"><i class="fas fa-check"></i> Đã đăng ký</span>
                                <?php else: ?>
                                    <form method="POST"><input type="hidden" name="action" value="join_event"><input type="hidden" name="event_id" value="<?=$me['id']?>"><button class="btn" style="padding:2px 8px; font-size:0.7rem; background:#10b981; color:white; margin-top:5px;">Tham gia</button></form>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- SÁCH ĐANG MƯỢN (CÓ BẢN ĐIỆN TỬ) -->
                    <div class="card" style="background:var(--white); border:2px solid #8b5cf6;">
                        <h3 style="color:#8b5cf6;"><i class="fas fa-tablet-alt"></i> Thư viện số của bạn</h3>
                        <?php 
                        $digitals = getDB()->prepare("SELECT b.*, br.due_date FROM borrowings br JOIN books b ON br.book_id=b.id WHERE br.member_id = ? AND br.status='borrowed' AND br.notes LIKE '%Bản điện tử%'");
                        $digitals->execute([$_SESSION['member_id']]);
                        $dList = $digitals->fetchAll();
                        if(!$dList): ?><p style="font-size:0.8rem; color:var(--sub);">Bạn chưa mượn sách số nào.</p><?php endif;
                        foreach($dList as $d):
                        ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border);">
                            <small style="font-weight:700;"><?=$d['title']?></small>
                            <button onclick="openReader('<?=htmlspecialchars($d['title'])?>')" class="btn" style="background:#8b5cf6; color:white; padding:4px 10px; font-size:0.75rem;">Đọc ngay</button>
                            <button onclick="openReviewModal(<?=$d['id']?>)" class="btn" style="background:var(--p-light); color:var(--p); padding:4px 10px; font-size:0.75rem;"><i class="fas fa-star"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- THẺ THƯ VIỆN ĐIỆN TỬ -->
                    <div class="card" style="background:linear-gradient(135deg, #1e293b, #334155); color:white; border:none; padding:1.5rem; position:relative; min-height:200px; margin-top:2rem;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <div>
                                <h3 style="color:#38bdf8; margin:0;"><i class="fas fa-id-card"></i> THẺ THƯ VIỆN</h3>
                                <p style="font-size:0.7rem; opacity:0.6; margin-bottom:1.5rem;">ELITE LIBRARY DIGITAL CARD</p>
                                <h2 style="font-size:1.2rem; margin:0;"><?= strtoupper($_SESSION['full_name']) ?></h2>
                                <p style="font-size:0.8rem; opacity:0.8;">Mã thẻ: LIB-<?= str_pad($_SESSION['member_id'], 5, '0', STR_PAD_LEFT) ?></p>
                                <span class="badge" style="background:#0ea5e9; color:white; font-size:0.65rem; margin-top:10px;">HẠNG <?= $_SESSION['rank'] ?? 'Standard' ?></span>
                            </div>
                            <div style="background:white; padding:5px; border-radius:5px;">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=MEM-<?= $_SESSION['member_id'] ?>" style="width:70px; height:70px;" alt="QR">
                            </div>
                        </div>
                        <div style="position:absolute; bottom:1rem; right:1rem; opacity:0.1; font-size:4rem;"><i class="fas fa-university"></i></div>
                    </div>

                    <!-- ĐỀ XUẤT NHẬP SÁCH -->
                    <div class="card">
                        <h3><i class="fas fa-paper-plane"></i> Đề xuất sách mới</h3>
                        <p style="font-size:0.8rem; color:var(--sub); margin-bottom:1rem;">Hãy nói cho chúng tôi cuốn sách bạn mong muốn.</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="request_book">
                            <input type="text" name="title" class="input" style="font-size:0.85rem;" placeholder="Tên sách bạn muốn mượn..." required>
                            <input type="text" name="author" class="input" style="font-size:0.85rem;" placeholder="Tên tác giả (nếu có)">
                            <button type="submit" class="btn btn-p" style="width:100%; justify-content:center; margin-top:5px;">Gửi đề xuất</button>
                        </form>
                    </div>
                </div>
                    </div>
                    <!-- BLIND DATE WITH A BOOK -->
                    <div class="card" style="border:2px dashed var(--p); background:var(--p-light);">
                        <h3><i class="fas fa-heart"></i> Blind Date with a Book</h3>
                        <p style="font-size:0.8rem; color:var(--sub); margin-bottom:1rem;">Hãy chọn một cuốn sách dựa trên tâm trạng, chúng tôi sẽ giữ bí mật tên sách!</p>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                            <button onclick="blindDate('Happy')" class="btn" style="font-size:0.75rem; background:white; color:var(--text);">😊 Vui vẻ</button>
                            <button onclick="blindDate('Sad')" class="btn" style="font-size:0.75rem; background:white; color:var(--text);">😢 Cần an ủi</button>
                            <button onclick="blindDate('Motivation')" class="btn" style="font-size:0.75rem; background:white; color:var(--text);">🔥 Động lực</button>
                            <button onclick="blindDate('Chill')" class="btn" style="font-size:0.75rem; background:white; color:var(--text);">🍃 Thư giãn</button>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif($page == 'discovery'): ?>
            <div style="display:grid; grid-template-columns: 320px 1fr; gap:2rem; min-height: 700px;">
                <!-- CỘT TRÁI: TÌM KIẾM & DANH SÁCH -->
                <div class="card" style="margin-bottom:0; display:flex; flex-direction:column; padding:1.5rem; height: calc(100vh - 180px); position: sticky; top: 20px;">
                    <h3><i class="fas fa-search"></i> Tìm vị trí sách</h3>
                    <p style="font-size:0.8rem; color:var(--sub); margin-bottom:1.5rem;">Nhập tên sách để chúng tôi chỉ đường cho bạn.</p>
                    
                    <input type="text" id="mapSearch" class="input" placeholder="🔍 Gõ tên sách..." onkeyup="searchOnMap()" style="width:100%;">
                    
                    <div id="mapResultList" style="flex:1; overflow-y:auto; margin-top:1rem; border-top:1px solid var(--border); padding-top:1rem;">
                        <?php 
                        $allBooks = getDB()->query("SELECT title, shelf_location FROM books WHERE shelf_location IS NOT NULL AND shelf_location != ''")->fetchAll();
                        foreach($allBooks as $bk): ?>
                            <div class="nav-item" style="color:var(--text); padding:10px; margin:5px 0; cursor:pointer; background:var(--bg); border:1px solid transparent;" onclick="locateBook('<?=addslashes($bk['shelf_location'])?>', '<?=addslashes($bk['title'])?>')">
                                <small><strong><?=$bk['title']?></strong><br><i class="fas fa-map-marker-alt"></i> <?=$bk['shelf_location']?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- CỘT PHẢI: BẢN ĐỒ CHI TIẾT -->
                <div class="card" style="margin-bottom:0; padding:0; overflow:hidden; position:relative; background:#f8fafc; display:flex; flex-direction:column;">
                    <div style="padding:1.5rem 2rem; background:white; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <h3 id="discoveryTitle">Sơ đồ tổng thể Thư viện</h3>
                            <p id="discoverySub" style="font-size:0.8rem; color:var(--sub); margin:0;">Chọn một kệ sách hoặc tìm kiếm để bắt đầu.</p>
                        </div>
                        <div style="display:flex; gap:15px;">
                            <div style="display:flex; align-items:center; gap:5px; font-size:0.75rem;"><span style="width:12px; height:12px; background:var(--p); border-radius:2px;"></span> Đích đến</div>
                            <div style="display:flex; align-items:center; gap:5px; font-size:0.75rem;"><span style="width:12px; height:12px; background:#f59e0b; border-radius:2px;"></span> Dịch vụ</div>
                        </div>
                    </div>

                    <div style="flex:1; padding:3rem; display:flex; align-items:center; justify-content:center;">
                         <!-- Library Layout Container (Trang độc lập) -->
                        <div style="position:relative; background:white; border:2px solid var(--border); border-radius:2rem; width:100%; max-width:800px; height:550px; box-shadow: 0 20px 50px rgba(0,0,0,0.05); padding:2rem;">
                            
                            <!-- Lối vào -->
                            <div style="position:absolute; bottom:0; left:50%; transform:translateX(-50%); background:#64748b; color:white; padding:12px 40px; border-radius:15px 15px 0 0; font-weight:800; font-size:0.9rem; letter-spacing:1px;">
                                <i class="fas fa-door-open"></i> LỐI VÀO CHÍNH
                            </div>

                            <!-- Quầy Mượn Trả -->
                            <div id="disc-counter" style="position:absolute; bottom:100px; left:50%; transform:translateX(-50%); width:240px; height:100px; background:#fef3c7; border:4px solid #f59e0b; border-radius:20px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; transition:0.5s; box-shadow:0 10px 20px rgba(245,158,11,0.1);">
                                <i class="fas fa-concierge-bell" style="color:#f59e0b; font-size:2rem; margin-bottom:5px;"></i>
                                <span style="font-weight:900; font-size:0.9rem; color:#92400e;">QUẦY MƯỢN TRẢ</span>
                                <small style="color:#b45309; font-size:0.7rem;">(Hỗ trợ tìm kiếm & Đăng ký)</small>
                            </div>

                            <!-- Hệ thống kệ sách sắp xếp lại -->
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:80px; padding:20px 60px;">
                                <div style="display:flex; flex-direction:column; gap:20px;">
                                    <?php 
                                        // Heatmap logic giả lập dựa trên lượt mượn thực tế
                                        function getShelfHeat($shelfId, $db) {
                                            $count = $db->query("SELECT COUNT(*) FROM books b JOIN borrowings br ON b.id=br.book_id WHERE b.shelf_location LIKE '%$shelfId%'")->fetchColumn();
                                            if($count > 10) return 'rgba(239, 68, 68, 0.2)'; // Hot
                                            if($count > 5) return 'rgba(245, 158, 11, 0.15)'; // Warm
                                            return 'transparent';
                                        }
                                    ?>
                                    <div class="disc-shelf" id="disc-A1" style="height:100px; background:<?=getShelfHeat('A1', $db)?>; border:2px solid #cbd5e1; border-radius:12px; display:flex; flex-direction:column; align-items:center; justify-content:center; font-weight:800; transition:0.4s; cursor:pointer;" onclick="locateBook('A1', 'Khu vực sách Văn học')">
                                        <span style="font-size:1.2rem; opacity:0.2;">A1</span><br><small>VĂN HỌC</small>
                                        <?php if(getShelfHeat('A1', $db) !== 'transparent'): ?><small style="color:var(--danger); font-size:0.6rem;"><i class="fas fa-fire"></i> HOT</small><?php endif; ?>
                                    </div>
                                    <div class="disc-shelf" id="disc-A2" style="height:100px; background:<?=getShelfHeat('A2', $db)?>; border:2px solid #cbd5e1; border-radius:12px; display:flex; flex-direction:column; align-items:center; justify-content:center; font-weight:800; transition:0.4s; cursor:pointer;" onclick="locateBook('A2', 'Khu vực sách Kinh tế')">
                                        <span style="font-size:1.2rem; opacity:0.2;">A2</span><br><small>KINH TẾ</small>
                                    </div>
                                </div>
                                
                                <div style="display:flex; flex-direction:column; gap:20px;">
                                    <div class="disc-shelf" id="disc-B1" style="height:100px; background:<?=getShelfHeat('B1', $db)?>; border:2px solid #cbd5e1; border-radius:12px; display:flex; flex-direction:column; align-items:center; justify-content:center; font-weight:800; transition:0.4s; cursor:pointer;" onclick="locateBook('B1', 'Khu vực sách Kỹ thuật')">
                                        <span style="font-size:1.2rem; opacity:0.2;">B1</span><br><small>KỸ THUẬT</small>
                                    </div>
                                    <div class="disc-shelf" id="disc-B2" style="height:100px; background:<?=getShelfHeat('B2', $db)?>; border:2px solid #cbd5e1; border-radius:12px; display:flex; flex-direction:column; align-items:center; justify-content:center; font-weight:800; transition:0.4s; cursor:pointer;" onclick="locateBook('B2', 'Khu vực sách Khoa học')">
                                        <span style="font-size:1.2rem; opacity:0.2;">B2</span><br><small>KHOA HỌC</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Khu vực đọc sách dời ra giữa phía trên -->
                            <div style="position:absolute; top:20px; left:50%; transform:translateX(-50%); width:300px; height:80px; background:#dcfce7; border:2px dashed #22c55e; border-radius:1rem; display:flex; align-items:center; justify-content:center; text-align:center; color:#166534; font-size:0.8rem; font-weight:800;">
                                <i class="fas fa-couch" style="margin-right:10px;"></i> KHU VỰC ĐỌC SÁCH TẠI CHỖ
                            </div>
                            
                            <div style="position:absolute; top:20px; right:20px; width:120px; height:120px; background:var(--p-light); border:2px solid var(--p); border-radius:1.5rem; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; color:var(--p); font-size:0.75rem; font-weight:900;">
                                <i class="fas fa-boxes" style="font-size:2rem; margin-bottom:8px;"></i>TỦ LOCKER
                            </div>

                            <!-- Mô phỏng định vị thực tế -->
                            <div id="user-pos" style="position:absolute; bottom:40px; left:50%; width:20px; height:20px; background:#3b82f6; border:4px solid white; border-radius:50%; box-shadow: 0 0 20px #3b82f6; transform:translateX(-50%); display:flex; align-items:center; justify-content:center; z-index:100;">
                                <div style="width:100%; height:100%; background:#3b82f6; border-radius:50%; animate: pulse 2s infinite;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function searchOnMap() {
                    let val = document.getElementById('mapSearch').value.toLowerCase();
                    let items = document.querySelectorAll('#mapResultList .nav-item');
                    items.forEach(i => {
                        i.style.display = i.innerText.toLowerCase().includes(val) ? 'block' : 'none';
                    });
                }

                function locateBook(shelf, title) {
                    document.getElementById('discoveryTitle').innerText = title;
                    document.getElementById('discoverySub').innerText = 'Vị trí: ' + shelf;
                    
                    // Reset all
                    document.querySelectorAll('.disc-shelf').forEach(s => {
                        s.style.background = '#f1f5f9';
                        s.style.borderColor = '#cbd5e1';
                        s.style.color = 'inherit';
                        s.style.transform = 'scale(1)';
                        s.style.boxShadow = 'none';
                    });
                    document.getElementById('disc-counter').style.transform = 'translateX(-50%) scale(1)';
                    document.getElementById('disc-counter').style.boxShadow = 'none';

                    // Highlight logic
                    let found = false;
                    if(shelf.includes('A1')) { highlightShelf('disc-A1'); found = true; }
                    if(shelf.includes('A2')) { highlightShelf('disc-A2'); found = true; }
                    if(shelf.includes('B1')) { highlightShelf('disc-B1'); found = true; }
                    
                    if(!found) {
                        document.getElementById('disc-counter').style.transform = 'translateX(-50%) scale(1.1)';
                        document.getElementById('disc-counter').style.boxShadow = '0 0 30px #f59e0b';
                        document.getElementById('discoverySub').innerText += ' (Hãy đến quầy mượn để được trợ giúp)';
                    }
                }

                function highlightShelf(id) {
                    let el = document.getElementById(id);
                    el.style.background = 'var(--p)';
                    el.style.borderColor = 'var(--p)';
                    el.style.color = 'white';
                    el.style.transform = 'scale(1.05)';
                    el.style.boxShadow = '0 15px 35px var(--p-light)';
                }
            </script>
            
            <style>
                @keyframes pulse {
                    0% { transform: scale(1); opacity: 1; }
                    100% { transform: scale(3); opacity: 0; }
                }
            </style>
            <script>
                window.onload = function() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const shelf = urlParams.get('shelf');
                    const title = urlParams.get('title');
                    if (shelf) {
                        setTimeout(() => locateBook(shelf, title || 'Sách bạn chọn'), 500);
                    }
                };
            </script>
            <div style="margin-bottom: 2rem;">
                <h2 style="margin-bottom:0.5rem;"><i class="fas fa-search"></i> Tra cứu sách (OPAC)</h2>
                <p style="color:var(--sub);">Tìm kiếm và đặt chỗ sách trực tuyến từ thư viện.</p>
                <input type="text" id="liveSearch" class="input" placeholder="🔍 Gõ tên sách, tác giả hoặc ISBN để tìm nhanh..." onkeyup="filterTable()" style="margin-top:1.5rem; width:100%;">
            </div>
            
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:1.5rem;">
                <?php 
                $books = getDB()->query("SELECT b.*, c.name as cat, (b.quantity - IFNULL((SELECT SUM(quantity) FROM borrowings WHERE book_id = b.id AND status = 'borrowed'), 0)) as avail FROM books b JOIN categories c ON b.category_id = c.id")->fetchAll();
                foreach($books as $b): 
                ?>
                <div class="card" style="margin-bottom:0; display:flex; flex-direction:column; justify-content:space-between; position:relative;">
                    <?php if($b['avail'] <= 0): ?>
                        <div style="position:absolute; top:1rem; right:1rem;"><span class="badge" style="background:var(--danger); color:white;">Hết sách</span></div>
                    <?php endif; ?>
                    <div>
                        <small style="color:var(--p); font-weight:800; text-transform:uppercase;"><?=$b['cat']?></small>
                        <h3 style="margin-top:0.5rem;"><?=$b['title']?></h3>
                        <p style="color:var(--sub); font-size:0.9rem;">Tác giả: <?=$b['author']?></p>
                        <p style="font-size:0.8rem; margin-top:5px; color:var(--p); font-weight:600;"><i class="fas fa-map-marker-alt"></i> Vị trí: <?=$b['shelf_location'] ?: 'Chưa cập nhật'?> <a href="?page=discovery&shelf=<?=urlencode($b['shelf_location'])?>&title=<?=urlencode($b['title'])?>" class="btn" style="padding:2px 5px; font-size:10px; background:white; border:1px solid var(--p); margin-left:5px; text-decoration:none;">Xem trên bản đồ</a></p>
                    </div>
                    <div style="margin-top:1.5rem; display:flex; flex-direction:column; gap:10px;">
                        <span style="font-weight:700; color:var(--text);"><?=$b['avail']?> cuốn sẵn có</span>
                        <?php if($b['is_digital']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="borrow_digital">
                                <input type="hidden" name="book_id" value="<?=$b['id']?>">
                                <button type="submit" class="btn" style="width:100%; background:#8b5cf6; color:white;"><i class="fas fa-tablet-alt"></i> Mượn bản điện tử</button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if($b['avail'] > 0): ?>
                            <button onclick="openBorrowRequest(<?=$b['id']?>, '<?=htmlspecialchars($b['title'])?>')" class="btn btn-p"><i class="fas fa-shopping-cart"></i> Mượn online</button>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="reserve_book">
                                <input type="hidden" name="book_id" value="<?=$b['id']?>">
                                <button type="submit" class="btn btn-p"><i class="fas fa-bookmark"></i> Đặt chỗ trước</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <?php elseif($page == 'my_borrowings' && $_SESSION['role'] === 'member'): ?>
            <div class="card">
                <h3><i class="fas fa-book-reader"></i> Sách của bạn</h3>
                <p style="color:var(--sub); font-size:0.9rem; margin-bottom:2rem;">Danh sách các cuốn sách bạn đang mượn và lịch sử trả sách.</p>
                
                <table id="dataTable">
                    <thead><tr><th>Sách</th><th>Ngày mượn</th><th>Hạn trả</th><th>Gia hạn</th><th>Trạng thái</th><th style="text-align:right;">Lệnh</th></tr></thead>
                    <tbody>
                        <?php 
                        $stmt = getDB()->prepare("SELECT b.*, bk.title FROM borrowings b JOIN books bk ON b.book_id = bk.id WHERE b.member_id = ? ORDER BY b.borrow_date DESC");
                        $stmt->execute([$_SESSION['member_id']]);
                        foreach($stmt->fetchAll() as $b): 
                            $isOverdue = (strtotime($b['due_date']) < time() && $b['status'] == 'borrowed');
                        ?>
                        <tr style="<?= $isOverdue ? 'background:var(--danger-light);' : '' ?>">
                            <td><strong><?=$b['title']?></strong><br><small>SL: <?=$b['quantity']?></small></td>
                            <td><?=date('d/m/Y', strtotime($b['borrow_date']))?></td>
                            <td><?=date('d/m/Y', strtotime($b['due_date']))?></td>
                            <td><?=$b['renewal_count'] ?? 0?> / 2</td>
                            <td>
                                <?php if($b['status'] == 'returned'): ?><span class="badge" style="background:#dcfce7; color:#166534;">Đã trả</span>
                                <?php elseif($b['status'] == 'lost'): ?><span class="badge" style="background:var(--danger); color:white;">Mất sách</span>
                                <?php else: ?><span class="badge" style="background:<?= $isOverdue ? 'var(--danger)' : 'var(--p)' ?>; color:white;"><?=$isOverdue?'Quá hạn':'Đang mượn'?></span><?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <?php if($b['status'] == 'borrowed' && ($b['renewal_count'] ?? 0) < 2): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="renew_book">
                                        <input type="hidden" name="id" value="<?=$b['id']?>">
                                        <button type="submit" class="btn btn-s" style="background:var(--p-light); color:var(--p); padding:0.4rem 0.8rem; font-size:0.75rem;"><i class="fas fa-sync"></i> Gia hạn</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card" style="margin-top:2rem;">
                <h3><i class="fas fa-shopping-cart"></i> Yêu cầu mượn Online</h3>
                <table>
                    <thead><tr><th>Sách</th><th>Phương thức</th><th>Mã Locker / Địa chỉ</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                        <?php 
                        $stmt = getDB()->prepare("SELECT r.*, bk.title FROM borrow_requests r JOIN books bk ON r.book_id = bk.id WHERE r.member_id = ? ORDER BY r.created_at DESC");
                        $stmt->execute([$_SESSION['member_id']]);
                        foreach($stmt->fetchAll() as $r): 
                        ?>
                        <tr>
                            <td><strong><?=$r['title']?></strong></td>
                            <td><?=$r['pickup_method']?></td>
                            <td>
                                <?php if($r['pickup_method'] == 'Locker' && $r['locker_code']): ?>
                                    <span style="font-family:monospace; background:#000; color:#0f0; padding:2px 8px; border-radius:4px; font-weight:700;">CODE: <?=$r['locker_code']?></span>
                                <?php elseif($r['pickup_method'] == 'Delivery'): ?>
                                    <small><?=$r['delivery_address']?></small>
                                <?php else: ?>---<?php endif; ?>
                            </td>
                            <td>
                                <?php if($r['status'] == 'Ready'): ?><span class="badge" style="background:#dcfce7; color:#166534;"><i class="fas fa-box-open"></i> Đang trong Locker</span>
                                <?php elseif($r['status'] == 'Shipping'): ?><span class="badge" style="background:#dcfce7; color:#166534;"><i class="fas fa-truck"></i> Đang giao hàng</span>
                                <?php else: ?><span class="badge" style="background:var(--border); color:var(--text);"><?=$r['status']?></span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-top:2rem;">
                <h3><i class="fas fa-bookmark"></i> Danh sách đặt chỗ</h3>
                <table>
                    <thead><tr><th>Sách</th><th>Ngày đăng ký</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                        <?php 
                        $stmt = getDB()->prepare("SELECT r.*, bk.title FROM reservations r JOIN books bk ON r.book_id = bk.id WHERE r.member_id = ? ORDER BY r.created_at DESC");
                        $stmt->execute([$_SESSION['member_id']]);
                        foreach($stmt->fetchAll() as $r): 
                        ?>
                        <tr>
                            <td><strong><?=$r['title']?></strong></td>
                            <td><?=date('d/m/Y', strtotime($r['created_at']))?></td>
                            <td><span class="badge" style="background:var(--border); color:var(--text);"><?=$r['status']?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <footer style="margin-top:5rem; text-align:center; color:var(--sub); font-size:0.875rem;">&copy; <?=date('Y')?> <?=LIB_NAME?>. Thiết kế đẳng cấp bởi Nhóm 1 HUBT.</footer>
    </main>

    <img src="https://upload.wikimedia.org/wikipedia/en/0/05/Hello_kitty_character_portrait.png" class="peek-kitty" alt="Hello Kitty">
    
    <script>
    // --- IN PHIẾU MƯỢN SÁCH ---
    function printBill(id, date, member, book, due, deposit) {
        document.getElementById('p_id').innerText = id;
        document.getElementById('p_date').innerText = date;
        document.getElementById('p_member').innerText = member;
        document.getElementById('p_book').innerText = book;
        document.getElementById('p_due').innerText = due;
        document.getElementById('p_deposit').innerText = deposit;
        window.print();
    }

    // --- BÁO MẤT SÁCH (TỊCH THU CỌC) ---
    function reportLost(id) {
        if(confirm('CẢNH BÁO: Độc giả làm MẤT SÁCH?\n- Tiền cọc sẽ bị tịch thu.\n- Số lượng sách trong kho sẽ bị trừ đi 1.\nHành động này không thể hoàn tác!')) {
            const f = document.createElement('form'); f.method = 'POST';
            const a = document.createElement('input'); a.name = 'action'; a.type = 'hidden'; a.value = 'report_lost';
            const i = document.createElement('input'); i.name = 'id'; i.type = 'hidden'; i.value = id;
            f.appendChild(a); f.appendChild(i); document.body.appendChild(f); f.submit();
        }
    }

    // --- LIVE SEARCH SCRIPT ---
    function filterTable() {
        let input = document.getElementById("liveSearch").value.toLowerCase();
        let trs = document.querySelectorAll("#dataTable tbody tr"); 
        trs.forEach(tr => {
            let text = tr.innerText.toLowerCase();
            tr.style.display = text.includes(input) ? "" : "none";
        });
    }

    // --- XỬ LÝ QUÉT MÃ QR BẰNG CAMERA ---
    let html5QrcodeScanner;
    function startScanner() {
        const readerDiv = document.getElementById('reader');
        if(readerDiv.style.display === 'block') {
            html5QrcodeScanner.clear();
            readerDiv.style.display = 'none';
            return;
        }
        readerDiv.style.display = 'block';
        html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 250} }, false);
        html5QrcodeScanner.render((decodedText, decodedResult) => {
            console.log(`Đã quét mã: ${decodedText}`);
            const bookSelect = document.getElementById('bookSelect');
            let optionExists = Array.from(bookSelect.options).some(opt => opt.value === decodedText);
            
            if(optionExists) {
                bookSelect.value = decodedText;
                alert('✅ Đã nhận diện sách thành công!');
                html5QrcodeScanner.clear();
                readerDiv.style.display = 'none';
            } else { alert('❌ Không tìm thấy sách này trong kho hoặc sách đã hết!'); }
        }, (errorMessage) => { });
    }

    // --- XỬ LÝ XÓA DỮ LIỆU ---
    function deleteItem(type, id) {
        let typeName = type === 'book' ? 'cuốn sách' : 'thẻ độc giả';
        if(confirm(`CẢNH BÁO: Bạn có chắc chắn muốn xóa ${typeName} này không?\nHành động này không thể hoàn tác!`)) {
            const f = document.createElement('form'); f.method = 'POST';
            const act = document.createElement('input'); act.name = 'action'; act.type = 'hidden'; act.value = 'delete_' + type;
            const idInput = document.createElement('input'); idInput.name = 'id'; idInput.type = 'hidden'; idInput.value = id;
            f.appendChild(act); f.appendChild(idInput);
            document.body.appendChild(f); f.submit();
        }
    }

    // --- TRẢ SÁCH (HOÀN CỌC) ---
    function returnBook(id){
        if(confirm('Xác nhận độc giả ĐÃ TRẢ sách này?\nHệ thống sẽ tự động đối trừ Tiền Phạt (nếu có) vào Tiền Cọc để hiển thị số tiền cần hoàn trả.')){
            const f=document.createElement('form');f.method='POST';
            const a=document.createElement('input');a.name='action';a.value='return_book';f.appendChild(a);
            const i=document.createElement('input');i.name='id';i.value=id;f.appendChild(i);
            const d=document.createElement('input');d.name='return_date';d.value='<?=date('Y-m-d')?>';f.appendChild(d);
            document.body.appendChild(f);f.submit();
        }
    }

    // --- XỬ LÝ DARK/LIGHT MODE ---
    const themeToggle = document.getElementById('themeToggle');
    const htmlEl = document.documentElement;
    if (themeToggle) {
        if (htmlEl.classList.contains('dark-theme')) { themeToggle.innerHTML = '<i class="fas fa-sun"></i>'; }
        themeToggle.addEventListener('click', () => {
            htmlEl.classList.toggle('dark-theme');
            const isDark = htmlEl.classList.contains('dark-theme');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        });
    }

    // --- BÁO HỎNG SÁCH (PHẠT THÊM) ---
    function reportDamaged(id) {
        let fine = prompt('Nhập số tiền phạt do làm hỏng sách (VNĐ):', '50000');
        if(fine !== null) {
            const f = document.createElement('form'); f.method = 'POST';
            const a = document.createElement('input'); a.name = 'action'; a.type = 'hidden'; a.value = 'report_damaged';
            const i = document.createElement('input'); i.name = 'id'; i.type = 'hidden'; i.value = id;
            const p = document.createElement('input'); p.name = 'damage_fine'; p.type = 'hidden'; p.value = fine;
            f.appendChild(a); f.appendChild(i); f.appendChild(p); document.body.appendChild(f); f.submit();
        }
    }

    // --- Elegant Cursor Trail ---
    const dots = []; const mouse = { x: -100, y: -100 };
    for (let i = 0; i < 6; i++) {
        const d = document.createElement('div'); d.className = 'cursor-trail'; d.innerHTML = '💖'; d.style.fontSize = (15 - i) + 'px';
        document.body.appendChild(d); dots.push({ el: d, x: -100, y: -100 });
    }
    window.addEventListener('mousemove', (e) => { mouse.x = e.clientX; mouse.y = e.clientY; dots.forEach(d => d.el.style.opacity = 0.8); });
    function animateTrail() {
        let x = mouse.x; let y = mouse.y;
        dots.forEach((dot, index) => {
            dot.el.style.left = dot.x + 'px'; dot.el.style.top = dot.y + 'px';
            const next = dots[index + 1] || dots[0];
            dot.x = x; dot.y = y; x += (next.x - x) * 0.3; y += (next.y - y) * 0.3;
        });
        requestAnimationFrame(animateTrail);
    }
    animateTrail();

    document.addEventListener('mousedown', (e) => {
        const s = document.createElement('div'); s.className = 'click-sparkle'; s.innerHTML = '🌸';
        s.style.left = e.pageX + 'px'; s.style.top = e.pageY + 'px';
        document.body.appendChild(s); setTimeout(() => s.remove(), 800);
    });

    // --- ĐIỀU HƯỚNG BẢN ĐỒ ---
    function navigateToMap(shelf) {
        window.location.href = 'discover.php?target=' + shelf;
    }
    </script>
<?php endif; ?>
    <!-- MODAL TRÌNH ĐỌC SÁCH SỐ CHUYÊN NGHIỆP -->
    <div id="readerModal" class="modal">
        <div class="modal-content" style="max-width:1100px; padding:0; overflow:hidden; display:flex; flex-direction:column; background:#0f172a; border:none; height:85vh; border-radius:1.5rem;">
            <div style="background:#1e293b; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #334155;">
                <div style="display:flex; align-items:center; gap:15px;">
                    <div style="width:40px; height:40px; background:var(--p); border-radius:10px; display:flex; align-items:center; justify-content:center; color:white; font-weight:900;">E</div>
                    <div>
                        <h3 id="readerBookTitle" style="margin:0; font-size:1rem; color:white;">Elite Digital Reader</h3>
                        <small style="color:#94a3b8; font-size:0.7rem;"><i class="fas fa-user-shield"></i> Tài khoản: <?=$_SESSION['full_name']?></small>
                    </div>
                </div>
                <div style="display:flex; gap:12px;">
                    <button class="btn" style="background:#334155; color:white; padding:6px 12px; font-size:0.8rem;"><i class="fas fa-adjust"></i> Chế độ đọc</button>
                    <button onclick="document.getElementById('readerModal').style.display='none'" class="btn-icon" style="color:#94a3b8; background:transparent;"><i class="fas fa-times"></i></button>
                </div>
            </div>
            
            <div style="display:flex; flex:1; overflow:hidden;">
                <!-- Sidebar Chương (Có thể ẩn/hiện) -->
                <div style="width:260px; background:#0f172a; border-right:1px solid #334155; padding:2rem; overflow-y:auto;">
                    <h4 style="font-size:0.75rem; text-transform:uppercase; color:#475569; letter-spacing:1px; margin-bottom:1.5rem;">Mục lục chi tiết</h4>
                    <ul style="list-style:none; padding:0; font-size:0.85rem;">
                        <li style="padding:10px 0; color:var(--p); font-weight:700; border-bottom:1px solid #1e293b; cursor:pointer;">1. Giới thiệu tổng quan</li>
                        <li style="padding:10px 0; color:#94a3b8; cursor:pointer;">2. Nội dung cốt lõi</li>
                        <li style="padding:10px 0; color:#94a3b8; cursor:pointer;">3. Phân tích chuyên sâu</li>
                        <li style="padding:10px 0; color:#94a3b8; cursor:pointer;">4. Kết luận & Phụ lục</li>
                    </ul>
                </div>

                <!-- Main Reading Area with Watermark -->
                <div style="flex:1; padding:4rem 6rem; overflow-y:auto; position:relative; background:white; color:#1e293b; line-height:2.2; font-size:1.15rem; text-align:justify; font-family:'Georgia', serif; box-shadow: inset 0 0 50px rgba(0,0,0,0.05);">
                    <!-- Watermark Layer (Hidden in plain sight) -->
                    <div style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:10; display:flex; flex-wrap:wrap; opacity:0.04; overflow:hidden; user-select:none;">
                        <?php for($i=0;$i<100;$i++): ?>
                            <div style="transform: rotate(-35deg); padding:30px; white-space:nowrap;">ELITE LIBRARY - ID:<?=$_SESSION['user_id']??$_SESSION['member_id']?> - COPY PROHIBITED</div>
                        <?php endfor; ?>
                    </div>
                    
                    <h1 style="text-align:center; font-family:'Inter', sans-serif; font-weight:800; margin-bottom:3rem; color:#0f172a;">CHƯƠNG 1: KỶ NGUYÊN SỐ</h1>
                    <p style="margin-bottom:2rem; text-indent:2rem;">Chào mừng độc giả đến với không gian đọc sách số hiện đại của Elite Library. Đây không chỉ là một trình xem file thông thường, mà là một môi trường tri thức được thiết kế để tối ưu hóa sự tập trung và trải nghiệm cá nhân hóa...</p>
                    <p style="margin-bottom:2rem;">Mọi nội dung trong trình đọc này đều được mã hóa theo tiêu chuẩn thư viện quốc tế. Elite cam kết bảo vệ quyền tác giả và mang đến những đầu sách chất lượng cao nhất cho cộng đồng.</p>
                    <p style="margin-bottom:2rem; color:#64748b; font-style:italic; border-left:4px solid var(--p); padding-left:1.5rem;">"Sách là nguồn tri thức vô tận, và công nghệ là đôi cánh giúp tri thức bay xa hơn."</p>
                    <p style="margin-bottom:2rem;">[Dữ liệu nội dung sách đang được tải lên từ máy chủ bảo mật...]</p>
                </div>
            </div>

            <div style="background:#1e293b; padding:1rem 2rem; border-top:1px solid #334155; display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; gap:20px;">
                    <button class="btn" style="background:transparent; color:#94a3b8; font-size:0.8rem;"><i class="fas fa-chevron-left"></i> Trước</button>
                    <button class="btn" style="background:var(--p); color:white; font-size:0.8rem; padding:6px 20px;">Sau <i class="fas fa-chevron-right"></i></button>
                </div>
                <div style="display:flex; align-items:center; gap:15px; color:#94a3b8; font-size:0.8rem;">
                    <i class="fas fa-bookmark" style="cursor:pointer;" title="Đánh dấu trang"></i>
                    <span>Trang 1 / 150</span>
                    <i class="fas fa-search-plus" style="cursor:pointer;" title="Phóng to"></i>
                </div>
            </div>
        </div>
    </div>
    <script>
        function searchNav() {
            let val = document.getElementById('sideSearch').value.toLowerCase();
            document.querySelectorAll('#sidebarNav .nav-item').forEach(i => {
                i.style.display = i.innerText.toLowerCase().includes(val) ? 'flex' : 'none';
            });
        }
    </script>
        </div>
    </div>


    <!-- MODAL BLIND DATE -->
    <div id="blindModal" class="modal">
        <div class="modal-content" style="max-width:500px; text-align:center; background: linear-gradient(135deg, #be185d, #ec4899); color:white; border:none;">
            <i class="fas fa-gift" style="font-size:4rem; margin-bottom:1rem;"></i>
            <h2>Bất ngờ dành cho bạn!</h2>
            <p id="blindDesc" style="margin:1.5rem 0; font-style:italic; font-size:1.1rem; line-height:1.6;"></p>
            <div style="background:rgba(255,255,255,0.2); padding:1rem; border-radius:1rem; margin-bottom:2rem;">
                <p>Chúng tôi đã chọn ngẫu nhiên một cuốn sách phù hợp với tâm trạng của bạn.</p>
                <small>Tên sách sẽ được tiết lộ khi bạn nhận sách!</small>
            </div>
            <button onclick="document.getElementById('blindModal').style.display='none'" class="btn" style="background:white; color:var(--p); width:100%; justify-content:center;">Tôi muốn mượn cuốn này!</button>
        </div>
    </div>

    <script>
        function showMap(shelf) {
            document.getElementById('mapShelfName').innerText = shelf || 'Vị trí chưa xác định';
            document.querySelectorAll('.map-shelf').forEach(s => {
                s.style.background = '#f1f5f9';
                s.style.borderColor = '#cbd5e1';
                s.style.color = 'inherit';
                s.style.boxShadow = 'none';
            });
            
            // Highlight shelf
            let found = false;
            if(shelf) {
                if(shelf.includes('A1')) { document.getElementById('shelf-A1').style.background = 'var(--p)'; document.getElementById('shelf-A1').style.borderColor = 'var(--p)'; document.getElementById('shelf-A1').style.color = 'white'; document.getElementById('shelf-A1').style.boxShadow = '0 0 20px var(--p)'; found = true; }
                if(shelf.includes('A2')) { document.getElementById('shelf-A2').style.background = 'var(--p)'; document.getElementById('shelf-A2').style.borderColor = 'var(--p)'; document.getElementById('shelf-A2').style.color = 'white'; document.getElementById('shelf-A2').style.boxShadow = '0 0 20px var(--p)'; found = true; }
                if(shelf.includes('B1')) { document.getElementById('shelf-B1').style.background = 'var(--p)'; document.getElementById('shelf-B1').style.borderColor = 'var(--p)'; document.getElementById('shelf-B1').style.color = 'white'; document.getElementById('shelf-B1').style.boxShadow = '0 0 20px var(--p)'; found = true; }
                if(shelf.includes('B2')) { document.getElementById('shelf-B2').style.background = 'var(--p)'; document.getElementById('shelf-B2').style.borderColor = 'var(--p)'; document.getElementById('shelf-B2').style.color = 'white'; document.getElementById('shelf-B2').style.boxShadow = '0 0 20px var(--p)'; found = true; }
            }

            // Nếu không tìm thấy kệ cụ thể, highlight Quầy mượn để hướng dẫn độc giả đến hỏi
            if(!found) {
                document.getElementById('map-counter').style.boxShadow = '0 0 30px #f59e0b';
                document.getElementById('map-counter').style.transform = 'translateX(-50%) scale(1.1)';
            } else {
                document.getElementById('map-counter').style.boxShadow = 'none';
                document.getElementById('map-counter').style.transform = 'translateX(-50%) scale(1)';
            }

            document.getElementById('mapModal').style.display = 'flex';
        }

        const blindQuotes = {
            'Happy': 'Một hành trình tràn ngập tiếng cười và những góc nhìn lạc quan về cuộc sống...',
            'Sad': 'Những câu chữ nhẹ nhàng sẽ ôm lấy tâm hồn bạn trong những ngày mưa...',
            'Motivation': 'Cuốn sách sẽ đánh thức con quái vật đang ngủ say bên trong bạn...',
            'Chill': 'Một tách trà, một bản nhạc nhẹ và những trang sách bình yên đến lạ...'
        };

        function blindDate(mood) {
            document.getElementById('blindDesc').innerText = '"' + blindQuotes[mood] + '"';
            document.getElementById('blindModal').style.display = 'flex';
        }
    </script>
    <!-- MODAL TRÌNH ĐỌC SÁCH SỐ -->
    <div id="readerModal" class="modal">
        <div class="modal-content" style="max-width:95%; height:90vh; padding:0; overflow:hidden; background:#1e293b; position:relative;">
            <div style="position:absolute; top:1rem; right:1rem; z-index:1001;">
                <button onclick="document.getElementById('readerModal').style.display='none'" class="btn-icon" style="background:rgba(255,255,255,0.2); color:white;"><i class="fas fa-times"></i></button>
            </div>
            <div style="display:flex; flex-direction:column; height:100%;">
                <div style="background:#0f172a; padding:1rem 2rem; color:white; border-bottom:1px solid #334155;">
                    <h3 id="readerBookTitle">Đang đọc: Tên sách</h3>
                </div>
                <div style="flex:1; position:relative; overflow-y:auto; background:#fff; color:#334155; padding:4rem; line-height:1.8; font-family:'Georgia', serif;">
                    <!-- WATERMARK LAYER -->
                    <div style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; opacity:0.1; display:flex; flex-wrap:wrap; justify-content:space-around; align-content:space-around; z-index:1000; overflow:hidden;">
                        <?php for($i=0; $i<20; $i++): ?>
                            <div style="transform:rotate(-30deg); font-size:2rem; font-weight:800; white-space:nowrap;"><?=$_SESSION['full_name']?> - <?=$_SESSION['member_id']?></div>
                        <?php endfor; ?>
                    </div>
                    
                    <div style="max-width:800px; margin:0 auto;">
                        <p style="margin-bottom:1.5rem;">[Nội dung sách điện tử đã được mã hóa và bảo vệ bản quyền...]</p>
                        <p style="margin-bottom:1.5rem;">Chào mừng bạn đến với kỷ nguyên số của <?=LIB_NAME?>. Nội dung bạn đang xem được cấp quyền truy cập cá nhân trong vòng 7 ngày.</p>
                        <hr style="margin:2rem 0; opacity:0.2;">
                        <p>Dữ liệu mẫu: Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat...</p>
                        <!-- Thêm nội dung mẫu dài hơn để thấy cuộn -->
                        <?php for($i=0; $i<5; $i++): ?>
                            <p style="margin-bottom:1.5rem;">Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>
                        <?php endfor; ?>
                    </div>
                </div>
                <div style="background:#0f172a; padding:1rem; color:white; text-align:center; font-size:0.8rem;">
                    <i class="fas fa-shield-alt"></i> Bản quyền thuộc về tác giả và Thư viện <?=LIB_NAME?>. Nghiêm cấm sao chép dưới mọi hình thức.
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL GIAO VIỆC NHANH -->
    <div id="taskModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
                <h2 id="taskStaffTitle">Giao việc cho nhân viên</h2>
                <button onclick="document.getElementById('taskModal').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_task">
                <input type="hidden" name="user_id" id="taskStaffId">
                <label>Tiêu đề công việc</label>
                <input type="text" name="title" class="input" placeholder="Vd: Kiểm kê kệ sách A1" required>
                <label>Mô tả chi tiết</label>
                <textarea name="description" class="input" style="height:100px;" placeholder="Hướng dẫn nhân viên thực hiện..."></textarea>
                <label>Hạn hoàn thành</label>
                <input type="date" name="due_date" class="input" value="<?=date('Y-m-d')?>" required>
                <button type="submit" class="btn btn-p" style="width:100%; justify-content:center; margin-top:1rem;"><i class="fas fa-paper-plane"></i> Gửi nhiệm vụ</button>
            </form>
        </div>
    </div>

    <!-- MODAL TẠO SỰ KIỆN -->
    <div id="eventModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
                <h2><i class="fas fa-calendar-plus"></i> Tạo sự kiện mới</h2>
                <button onclick="document.getElementById('eventModal').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_event">
                <label>Tên sự kiện</label><input type="text" name="title" class="input" placeholder="VD: Câu lạc bộ Sách tháng 5" required>
                <label>Mô tả ngắn</label><textarea name="description" class="input" style="height:80px;"></textarea>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div><label>Thời gian</label><input type="datetime-local" name="event_date" class="input" required></div>
                    <div><label>Địa điểm</label><input type="text" name="location" class="input" placeholder="Phòng đọc A" required></div>
                </div>
                <label>Số lượng khách tối đa</label><input type="number" name="max_participants" class="input" value="30">
                <button type="submit" class="btn btn-p" style="width:100%; justify-content:center; margin-top:1rem;"><i class="fas fa-check"></i> Đăng tải sự kiện</button>
            </form>
        </div>
    </div>

    <!-- MODAL ĐÁNH GIÁ SÁCH -->
    <div id="reviewModal" class="modal">
        <div class="modal-content" style="max-width:450px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                <h3><i class="fas fa-star"></i> Đánh giá sách</h3>
                <button onclick="document.getElementById('reviewModal').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_review">
                <input type="hidden" name="book_id" id="reviewBookId">
                <label>Số sao</label>
                <select name="rating" class="input">
                    <option value="5">⭐⭐⭐⭐⭐ Tuyệt vời</option>
                    <option value="4">⭐⭐⭐⭐ Rất tốt</option>
                    <option value="3">⭐⭐⭐ Bình thường</option>
                    <option value="2">⭐⭐ Tạm được</option>
                    <option value="1">⭐ Không thích</option>
                </select>
                <label>Cảm nhận của bạn</label>
                <textarea name="comment" class="input" style="height:100px;" placeholder="Viết vài dòng cảm nhận về cuốn sách này..."></textarea>
                <button type="submit" class="btn btn-p" style="width:100%; justify-content:center; margin-top:1rem;">Gửi đánh giá</button>
            </form>
        </div>
    </div>

    <!-- MODAL THÊM NHÂN VIÊN MỚI -->
    <div id="addStaffModal" class="modal">
        <div class="modal-content" style="max-width:550px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
                <h2><i class="fas fa-user-plus"></i> Thêm nhân viên mới</h2>
                <button onclick="document.getElementById('addStaffModal').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_staff">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem;">
                    <div><label>Tên đăng nhập</label><input type="text" name="username" class="input" required></div>
                    <div><label>Họ và tên</label><input type="text" name="full_name" class="input" required></div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem;">
                    <div><label>Vị trí</label><input type="text" name="position" class="input" placeholder="Thủ thư"></div>
                    <div><label>Lương cơ bản</label><input type="number" name="salary" class="input" value="5000000"></div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem;">
                    <div><label>Email</label><input type="email" name="email" class="input"></div>
                    <div><label>Số điện thoại</label><input type="text" name="phone" class="input" required></div>
                </div>
                <p style="font-size:0.75rem; color:var(--sub); margin:1rem 0;"><i class="fas fa-info-circle"></i> Mật khẩu mặc định sẽ là <strong>Số điện thoại</strong> của nhân viên.</p>
                <button type="submit" class="btn btn-p" style="width:100%; justify-content:center;"><i class="fas fa-user-check"></i> Tạo tài khoản nhân viên</button>
            </form>
        </div>
    </div>

    <script>
        function openReader(title) {
            document.getElementById('readerBookTitle').innerText = 'Đang đọc: ' + title;
            document.getElementById('readerModal').style.display = 'flex';
        }
        function openTaskModal(id, name) {
            document.getElementById('taskStaffId').value = id;
            document.getElementById('taskStaffTitle').innerText = 'Giao việc cho: ' + name;
            document.getElementById('taskModal').style.display = 'flex';
        }
        function openReviewModal(bookId) {
            document.getElementById('reviewBookId').value = bookId;
            document.getElementById('reviewModal').style.display = 'flex';
        }
    </script>
    <script>
        function toggleDarkMode() {
            document.body.classList.toggle('dark-theme');
            const isDark = document.body.classList.contains('dark-theme');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            document.getElementById('themeToggle').innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        }
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-theme');
            const toggle = document.getElementById('themeToggle');
            if(toggle) toggle.innerHTML = '<i class="fas fa-sun"></i>';
        }
    </script>
    <style>
        .dark-theme {
            --bg: #0f172a; --white: #1e293b; --text: #f1f5f9; --sub: #94a3b8; --border: #334155; background: #020617 !important;
        }
        .dark-theme .card, .dark-theme .sidebar { background: #1e293b !important; color: #f1f5f9; }
        .dark-theme .header { background: rgba(30, 41, 59, 0.8) !important; border-bottom: 1px solid #334155; }
        .dark-theme input, .dark-theme select, .dark-theme textarea { background: #0f172a; color: #f1f5f9; border-color: #334155; }
        .dark-theme table thead { background: #0f172a; color: #94a3b8; }
        .dark-theme table tr:hover { background: rgba(255,255,255,0.02) !important; }
        .dark-theme .badge { background: #334155; color: #f1f5f9; }
        .dark-theme .btn { color: white; }
        .dark-theme .nav-item { color: #94a3b8; }
        .dark-theme .nav-item.active { background: #334155; color: white; }
    </style>
</body>
</html>