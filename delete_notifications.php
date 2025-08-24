    <?php
    session_start();
    require_once 'db.php';

    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit();
    }

    $user_id = $_SESSION['user_id'];

    if (empty($_POST['ids']) || !is_array($_POST['ids'])) {
        echo json_encode(["status" => "error", "message" => "No notifications selected"]);
        exit();
    }

    $ids = array_map('intval', $_POST['ids']); // Sanitize IDs

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE FROM notifications WHERE user_id = ? AND id IN ($placeholders)";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => $conn->error]);
        exit();
    }

    $types = str_repeat('i', count($ids) + 1);
    $params = array_merge([$user_id], $ids);

    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "deleted_ids" => $ids]);
    } else {
        echo json_encode(["status" => "error", "message" => $stmt->error]);
    }
    ?>
