<?php

include 'db/config.php'; // Ensure this file has your database connection ($conn)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');

// =========================================================================
// Input Variables
// =========================================================================
$company_id         = isset($obj->company_id) ? trim($obj->company_id) : null;
$order_id           = isset($obj->order_id) ? trim($obj->order_id) : null;
// Input fields for update/create
$customer_details   = isset($obj->customer_details) ? $obj->customer_details : null;
$product_details    = isset($obj->product_details) ? $obj->product_details : null;

// Financial fields (must be float/decimal)
$sub_total          = isset($obj->sub_total) ? floatval($obj->sub_total) : 0.00;
$discount           = isset($obj->discount) ? floatval($obj->discount) : 0.00;
$other_charges      = isset($obj->other_charges) ? floatval($obj->other_charges) : 0.00;
$grand_total        = isset($obj->grand_total) ? floatval($obj->grand_total) : 0.00;

$status             = isset($obj->status) ? trim($obj->status) : '0'; 
$user_id            = isset($obj->user_id) ? $obj->user_id : null; 
$created_name       = isset($obj->created_name) ? $obj->created_name : null; 
$id                 = isset($obj->id) ? $obj->id : null; // Internal PK ID

// Helper flags
$method = $_SERVER['REQUEST_METHOD'];
$is_read_action = isset($obj->fetch_all) || (isset($obj->order_id) && !isset($obj->update_action) && !isset($obj->delete_action));
$is_create_action = $company_id && $customer_details && $product_details && !isset($obj->order_id) && !isset($obj->update_action) && !isset($obj->delete_action);
$is_update_action = $order_id && $company_id && isset($obj->update_action); 
$is_delete_action = $order_id && $company_id && isset($obj->delete_action);

// =========================================================================
// Helper Functions
// =========================================================================

function generateOrderId($conn) {
    $sql = "SELECT MAX(`id`) as max_id FROM `order`";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $next_id = ($row['max_id'] ?? 0) + 1;
    return 'ORDER-' . str_pad($next_id, 6, '0', STR_PAD_LEFT);
}

function generateOrderNo($conn) {
    $sql = "SELECT MAX(`id`) as max_id FROM `order`";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $next_id = ($row['max_id'] ?? 0) + 1;
    return 'ORD-' . str_pad($next_id, 6, '0', STR_PAD_LEFT);
}

// =========================================================================
// C - CREATE (Order)
// =========================================================================

if ($method === 'POST' && $is_create_action) {
    
    // 1. Validation 
    if (empty($company_id) || empty($customer_details) || empty($product_details)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Company ID, Customer Details, and Product Details are required to create an order.";
        goto end_script;
    }
    
    // 2. Generate unique IDs
    $new_order_id = generateOrderId($conn);
    $new_order_no = generateOrderNo($conn);

    // 3. Insert the new Order record
    $insert_sql = "INSERT INTO `order` (
                       `order_id`, `company_id`, `order_no`, 
                       `customer_details`, `product_details`, 
                       `sub_total`, `discount`, `other_charges`, `grand_total`, 
                       `status`, `created_by`, `created_name`
                   ) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insert_stmt = $conn->prepare($insert_sql);
    
    // Bind parameters: s s s s s d d d d s i s 
    $insert_stmt->bind_param("sssssddddsis", 
        $new_order_id, $company_id, $new_order_no, 
        $customer_details, $product_details, 
        $sub_total, $discount, $other_charges, $grand_total, 
        $status, $user_id, $created_name
    );

    if ($insert_stmt->execute()) {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Order created successfully.";
        $output["body"]["new_id"] = $conn->insert_id;
        $output["body"]["order_id"] = $new_order_id;
        $output["body"]["order_no"] = $new_order_no;
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to create order. Error: " . $insert_stmt->error;
    }
    $insert_stmt->close();

}
// =========================================================================
// R - READ (Order)
// =========================================================================
else if ($method === 'POST' && $is_read_action) {
    
    if (empty($company_id)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Company ID is required to fetch orders.";
        goto end_script;
    }

    $select_cols = "*";
    $from_table = "`order`";
    $where_clause = "`company_id` = ? AND `deleted_at` = 0";
    $bind_types = "s";
    $bind_params = [$company_id];

    if (!empty($order_id)) {
        // FETCH SINGLE ORDER by external order_id
        $sql = "SELECT {$select_cols} FROM {$from_table} WHERE {$where_clause} AND `order_id` = ?";
        $bind_types .= "s";
        $bind_params[] = $order_id;
    } else {
        // FETCH ALL ORDERS for a company
        $sql = "SELECT {$select_cols} FROM {$from_table} WHERE {$where_clause} ORDER BY `order_date` DESC";
    }

    $stmt = $conn->prepare($sql);
    
    // Use the splat operator (...) to pass the array of parameters to bind_param
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Success";
        $output["body"]["orders"] = $orders;
    } else {
        $output["head"]["code"] = 404;
        $output["head"]["msg"] = "No orders found.";
    }
    $stmt->close();
} 
// =========================================================================
// U - UPDATE (Order)
// =========================================================================
else if (($method === 'POST' || $method === 'PUT') && $is_update_action) {
    
    $target_order_id = $order_id;
    
    if (empty($target_order_id) || empty($company_id)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Order ID and Company ID are required for update.";
        goto end_script;
    }

    // Update query covers all non-autogenerated/non-audit fields
    $update_sql = "UPDATE `order` SET 
                   `customer_details`=?, `product_details`=?, 
                   `sub_total`=?, `discount`=?, `other_charges`=?, `grand_total`=?, 
                   `status`=?
                   WHERE `order_id` = ? AND `company_id` = ? AND `deleted_at` = 0";
    
    $update_stmt = $conn->prepare($update_sql);
    
    // Bind parameters: s s d d d d s s s (9 parameters)
    $update_stmt->bind_param("ssddddsss", 
        $customer_details, $product_details, 
        $sub_total, $discount, $other_charges, $grand_total, 
        $status, 
        $target_order_id, $company_id
    );

    if ($update_stmt->execute()) {
        if ($update_stmt->affected_rows > 0) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Order updated successfully.";
        } else {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Order updated successfully (No changes made or order not found).";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to update order. Error: " . $update_stmt->error;
    }
    $update_stmt->close();
}
// =========================================================================
// D - DELETE (Soft Delete Order)
// =========================================================================
else if (($method === 'POST' || $method === 'DELETE') && $is_delete_action) {
    
    $target_order_id = $order_id;
    
    if (empty($target_order_id) || empty($company_id)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Order ID and Company ID are required for delete.";
        goto end_script;
    }

    // Soft Delete query: set deleted_at = 1
    $delete_sql = "UPDATE `order` SET 
                   `deleted_at` = 1
                   WHERE `order_id` = ? AND `company_id` = ? AND `deleted_at` = 0";
    
    $delete_stmt = $conn->prepare($delete_sql);
    
    // Bind parameters: s s (order_id, company_id)
    $delete_stmt->bind_param("ss", 
        $target_order_id, $company_id
    );

    if ($delete_stmt->execute()) {
        if ($delete_stmt->affected_rows > 0) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Order deleted successfully.";
        } else {
            $output["head"]["code"] = 404;
            $output["head"]["msg"] = "Order not found or already deleted.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to soft-delete order. Error: " . $delete_stmt->error;
    }
    $delete_stmt->close();
}
// =========================================================================
// Fallback for unexpected actions
// =========================================================================
else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter or request method is Mismatch for the operation requested.";
}

end_script:
$conn->close();

echo json_encode($output, JSON_NUMERIC_CHECK);
?>