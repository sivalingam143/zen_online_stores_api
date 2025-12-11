<?php

include 'db/config.php';
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
$timestamp = date('Y-m-d H:i:s');

$method = $_SERVER['REQUEST_METHOD'];

// Input variables (derived from POST/PUT/DELETE payload or GET request)
$unit_name = isset($obj->unit_name) ? trim($obj->unit_name) : null;
$unit_code = isset($obj->unit_code) ? trim($obj->unit_code) : null;
$company_id = isset($obj->company_id) ? trim($obj->company_id) : null;
$unit_id = isset($obj->unit_id) ? $obj->unit_id : null; 
$user_id = isset($obj->user_id) ? $obj->user_id : null; 
$created_name = isset($obj->created_name) ? $obj->created_name : null; 

// Helper flags to distinguish actions
$is_read_action = isset($obj->fetch_all) || (isset($obj->unit_id) && !isset($obj->update_action) && !isset($obj->delete_action));
$is_create_action = $unit_name && $unit_code && $company_id && !isset($obj->unit_id) && !isset($obj->update_action) && !isset($obj->delete_action);
$is_update_action = $unit_id && $company_id && isset($obj->update_action);
$is_delete_action = $unit_id && $company_id && isset($obj->delete_action);

//List Units
if ($method === 'POST' && $is_read_action) {   
    if (empty($company_id)) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Company ID is required to fetch units.";
        goto end_script;
    }
    if (isset($obj->unit_id) && $obj->unit_id !== null) {

        $sql = "SELECT `id`, `unit_name`, `unit_code`, `company_id`, `created_date` 
                FROM `unit` 
                WHERE `id` = ? AND `company_id` = ? AND `deleted_at` = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $unit_id, $company_id);
    } else {
        // FETCH ALL UNITS for a company
        $sql = "SELECT `id`, `unit_name`, `unit_code`, `company_id`, `created_date` 
                FROM `unit` 
                WHERE `company_id` = ? AND `deleted_at` = 0 ORDER BY `unit_name` ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $company_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $units = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $units[] = $row;
        }
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Success";
        $output["body"]["units"] = $units;
    } else {
        $output["head"]["code"] = 404;
        $output["head"]["msg"] = "No units found.";
    }
    $stmt->close();

} 

//Create Unit
else if ($method === 'POST' && $is_create_action) {

    if (!empty($unit_name) && !empty($unit_code) && !empty($company_id)) {
        // 1. Check if Unit already exists (Duplicate prevention)
        $check_sql = "SELECT `id` FROM `unit` WHERE `unit_code` = ? AND `company_id` = ? AND `deleted_at` = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $unit_code, $company_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Unit with code '$unit_code' already exists for this company.";
            $check_stmt->close();
            goto end_script;
        }
        $check_stmt->close();
        // 2. Insert the new Unit record
        $insert_sql = "INSERT INTO `unit` (`unit_name`, `unit_code`, `company_id`, `created_by`, `created_name`) 
                       VALUES (?, ?, ?, ?, ?)";
        
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("sssis", 
            $unit_name, $unit_code, $company_id, $user_id, $created_name
        );

        if ($insert_stmt->execute()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Unit created successfully.";
            $output["body"]["new_id"] = $conn->insert_id;
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Failed to create unit. Error: " . $insert_stmt->error;
        }
        $insert_stmt->close();

    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Unit Name, Unit Code, and Company ID are required.";
    }
}

//Update Unit
else if (($method === 'POST' || $method === 'PUT') && $is_update_action) {
    if (!empty($unit_name) && !empty($unit_code)) {
        
        // 1. Check for duplicate unit code (if code is being changed)
        $check_sql = "SELECT `id` FROM `unit` WHERE `unit_code` = ? AND `company_id` = ? AND `id` != ? AND `deleted_at` = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ssi", $unit_code, $company_id, $unit_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Unit code '$unit_code' is already used by another unit.";
            $check_stmt->close();
            goto end_script;
        }
        $check_stmt->close();

        // 2. Update the Unit record
        $update_sql = "UPDATE `unit` SET `unit_name` = ?, `unit_code` = ? 
                       WHERE `id` = ? AND `company_id` = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssis", 
            $unit_name, $unit_code, $unit_id, $company_id
        );

        if ($update_stmt->execute()) {
            if ($update_stmt->affected_rows > 0) {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Unit updated successfully.";
            } else {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Unit updated successfully (No changes made).";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Failed to update unit. Error: " . $update_stmt->error;
        }
        $update_stmt->close();

    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Unit Name and Unit Code are required for update.";
    }

}

//Delete Unit
else if (($method === 'POST' || $method === 'DELETE') && $is_delete_action) {

    // Perform Soft Delete
    $delete_sql = "UPDATE `unit` SET `deleted_at` = 1 
                   WHERE `id` = ? AND `company_id` = ?";
    
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("is", $unit_id, $company_id);

    if ($delete_stmt->execute()) {
        if ($delete_stmt->affected_rows > 0) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Unit deleted successfully."; 
        } else {
            $output["head"]["code"] = 404;
            $output["head"]["msg"] = "Unit not found or already deleted.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to delete unit. Error: " . $delete_stmt->error;
    }
    $delete_stmt->close();

} 
// =========================================================================
// Mismatch / Fallback
// =========================================================================
else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter or request method is Mismatch for the operation requested.";
}

end_script:
// Close the database connection at the end
$conn->close();

echo json_encode($output, JSON_NUMERIC_CHECK);
?>