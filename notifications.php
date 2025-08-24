<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Mark all unread notifications as read
$update = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
$update->bind_param("i", $user_id);
$update->execute();

// Fetch latest notifications
$sql = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Your Notifications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f6f8;
            padding: 20px;
        }
        .notification-container {
            max-width: 800px;
            margin: auto;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .notification-item:last-child {
            border-bottom: none;
        }
        .notification-item a {
            color: #333;
            text-decoration: none;
            font-weight: 500;
        }
        .notification-item a:hover {
            text-decoration: underline;
            color: #007bff;
        }
        .notification-time {
            font-size: 0.85rem;
            color: #888;
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        .no-notifications {
            text-align: center;
            color: #777;
            padding: 30px;
        }
    </style>
</head>
<body>
    <div class="notification-container">
        <h2>Notifications</h2>

        <!-- Hidden empty state -->
        <div id="no-notifications" class="no-notifications" style="display:none;">
            <i class="bi bi-bell-slash"></i> No notifications yet.
        </div>

        <?php if ($result->num_rows > 0): ?>
            <div class="mb-3 d-flex align-items-center gap-3">
                <label class="d-flex align-items-center gap-2 mb-0">
                    <input type="checkbox" id="select-all"> Select All
                </label>
                <button type="button" id="delete-selected" class="btn btn-danger btn-sm">
                    Delete Selected
                </button>
            </div>

            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="notification-item" id="notif-<?= $row['id'] ?>">
                    <div class="d-flex align-items-center gap-2">
                        <input type="checkbox" class="select-item" value="<?= $row['id'] ?>">
                        <a href="<?= htmlspecialchars($row['link']) ?>">
                            <?= htmlspecialchars($row['message']) ?>
                        </a>
                    </div>
                    <span class="notification-time">
                        <?= date("d M Y, h:i A", strtotime($row['created_at'])) ?>
                    </span>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-notifications">
                <i class="bi bi-bell-slash"></i> No notifications yet.
            </div>
        <?php endif; ?>
    </div>
    <br>

    <div class="text-center mb-4">
        <a href="dash.php" 
           class="btn" 
           style="
               background-color: #6c757d;
               color: white;
               padding: 10px 20px;
               border-radius: 50px;
               font-weight: 500;
               text-decoration: none;
               box-shadow: 0 4px 8px rgba(0,0,0,0.1);
               transition: all 0.2s ease-in-out;
           "
           onmouseover="this.style.backgroundColor='#5a6268'"
           onmouseout="this.style.backgroundColor='#6c757d'">
           ⬅ Back to Dashboard
        </a>
    </div>

    <script>
    const selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.select-item').forEach(cb => cb.checked = this.checked);
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('select-item')) {
            const items = Array.from(document.querySelectorAll('.select-item'));
            const allChecked = items.every(cb => cb.checked);
            const anyChecked = items.some(cb => cb.checked);
            if (selectAll) {
                selectAll.indeterminate = anyChecked && !allChecked;
                selectAll.checked = allChecked;
            }
        }
    });

    const deleteBtn = document.getElementById('delete-selected');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async function () {
            const checked = Array.from(document.querySelectorAll('.select-item:checked')).map(cb => cb.value);
            if (checked.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Selection',
                    text: 'Please select at least one notification.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            const confirmDelete = await Swal.fire({
                title: 'Are you sure?',
                text: "Delete the selected notifications?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete them'
            });

            if (!confirmDelete.isConfirmed) return;

            deleteBtn.disabled = true;

            try {
                const body = new URLSearchParams();
                checked.forEach(id => body.append('ids[]', id));

                const res = await fetch('delete_notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const data = await res.json();

                if (data.status === 'success') {
                    data.deleted_ids.forEach(id => {
                        const el = document.getElementById('notif-' + id);
                        if (el) {
                            el.style.opacity = '0';
                            el.style.transform = 'translateX(-20px)';
                            setTimeout(() => el.remove(), 300);
                        }
                    });
                    updateEmptyState();
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: 'Notifications have been deleted.',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to delete',
                        confirmButtonColor: '#3085d6'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Network Error',
                    text: 'Please try again.',
                    confirmButtonColor: '#3085d6'
                });
            } finally {
                deleteBtn.disabled = false;
                if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
            }
        });
    }

    function updateEmptyState() {
        const remaining = document.querySelectorAll('.notification-item').length;
        document.getElementById('no-notifications').style.display = remaining === 0 ? 'block' : 'none';
    }
    </script>
</body>
</html>
