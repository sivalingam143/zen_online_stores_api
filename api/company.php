<?php

include 'db/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');



if (isset($obj->search_text)) {
    $sql = "SELECT `id`, `company_id`, `company_name`, `address`, `pincode`, `phone`, `mobile`, `gst_no`, `state`, `city`, `img`, `acc_number`, `acc_holder_name`, `bank_name`, `ifsc_code`, `bank_branch`, `deleted_at`, `created_by`, `created_name`, `created_date` FROM `company` WHERE 1";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        if ($row = $result->fetch_assoc()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["company"] = $row;
            $imgLink = null;
            if ($row["img"] != null && $row["img"] != 'null' && strlen($row["img"]) > 0) {
                $imgLink = "https://" . $_SERVER['SERVER_NAME'] . "/uploads/company/" . $row["img"];
                $output["body"]["company"]["img"] = $imgLink;
            } else {
                $output["body"]["company"]["img"] = $imgLink;
            }
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Company Details Not Found";
    }
} 
// <<<<<<<<<<===================== Update/Insert Company Details (UPSERT LOGIC) =====================>>>>>>>>>>
else if (isset($obj->company_name) && isset($obj->acc_holder_name) && isset($obj->company_profile_img) && isset($obj->address) && isset($obj->pincode) && isset($obj->city) && isset($obj->state) && isset($obj->phone_number) && isset($obj->mobile_number) && isset($obj->gst_number) && isset($obj->acc_number) && isset($obj->bank_name) && isset($obj->ifsc_code) && isset($obj->bank_branch)) {

    $company_name = $obj->company_name;
    $company_profile_img = $obj->company_profile_img;
    $address = $obj->address;
    $pincode = $obj->pincode;
    $city = $obj->city;
    $state = $obj->state;
    $phone_number = $obj->phone_number;
    $mobile_number = $obj->mobile_number;
    $gst_number = $obj->gst_number;
    $acc_number = $obj->acc_number;
    $acc_holder_name = $obj->acc_holder_name;
    $bank_name = $obj->bank_name;
    $ifsc_code = $obj->ifsc_code;
    $bank_branch = $obj->bank_branch;

    if (!empty($company_name) && !empty($address) && !empty($pincode) && !empty($phone_number) && !empty($city) && !empty($state)) {
        if (!preg_match('/[^a-zA-Z0-9., ]+/', $company_name)) {
            if (function_exists('numericCheck') && numericCheck($phone_number) && strlen($phone_number) == 10) {

                $edit_id = 1; 
                $sql = "";
                $profile_path = "";
                $is_insert = false;
                
                // --- A. Check if the company record (id=1) already exists ---
                $check_sql = "SELECT COUNT(`id`) AS count, `company_id` FROM `company` WHERE `id` = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("i", $edit_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $check_row = $check_result->fetch_assoc();
                $count = $check_row['count'];
                $company_id = $check_row['company_id'];
                $check_stmt->close();
                
                // --- B. Auto-Generate company_id if not present or being inserted ---
                if ($count == 0 || empty($company_id) || $company_id === null) {
                    // Generates a simple ID like COMP-000001 (based on the fixed ID of 1)
                    $company_id = 'COMP-' . str_pad($edit_id, 6, '0', STR_PAD_LEFT);
                }

                // --- C. Handle image path and define SQL (INSERT or UPDATE) ---
                if (!empty($company_profile_img) && function_exists('pngImageToWebP')) {
                    $outputFilePath = "../uploads/company/";
                    $profile_path = pngImageToWebP($company_profile_img, $outputFilePath);
                }

                if ($count == 0) {
                    $is_insert = true;
                    $sql = "INSERT INTO `company` (`id`, `company_id`, `company_name`, `img`, `address`, `pincode`, `city`, `state`, `phone`, `mobile`, `gst_no`, `acc_number`, `acc_holder_name`, `bank_name`, `ifsc_code`, `bank_branch`) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                } else if (!empty($company_profile_img) && function_exists('pngImageToWebP')) {
                    $sql = "UPDATE `company` SET `company_id`=?, `company_name`=?, `img`=?, `address`=?, `pincode`=?, `city`=?, `state`=?, `phone`=?, `mobile`=?, `gst_no`=?, `acc_number`=?, `acc_holder_name`=?, `bank_name`=?, `ifsc_code`=?, `bank_branch`=? WHERE `id`=?";
                } else {
                    $sql = "UPDATE `company` SET `company_id`=?, `company_name`=?, `address`=?, `pincode`=?, `city`=?, `state`=?, `phone`=?, `mobile`=?, `gst_no`=?, `acc_number`=?, `acc_holder_name`=?, `bank_name`=?, `ifsc_code`=?, `bank_branch`=? WHERE `id`=?";
                }
                $stmt = $conn->prepare($sql);
                if ($is_insert) {
                    $stmt->bind_param("isssssisssssssss", 
                        $edit_id, $company_id, $company_name, $profile_path, $address, $pincode, $city, $state, 
                        $phone_number, $mobile_number, $gst_number, $acc_number, $acc_holder_name, $bank_name, $ifsc_code, $bank_branch
                    );
                    $success_msg = "Successfully Company Details Created";
                }
                else if (!empty($company_profile_img) && function_exists('pngImageToWebP')) {
                    $stmt->bind_param("ssssissssssssssi", 
                        $company_id, $company_name, $profile_path, $address, $pincode, $city, $state, $phone_number, 
                        $mobile_number, $gst_number, $acc_number, $acc_holder_name, $bank_name, $ifsc_code, $bank_branch, 
                        $edit_id
                    );
                    $success_msg = "Successfully Company Details Updated";
                } else {
                    $stmt->bind_param("ssssissssssssssi", 
                        $company_id, $company_name, $address, $pincode, $city, $state, $phone_number, 
                        $mobile_number, $gst_number, $acc_number, $acc_holder_name, $bank_name, $ifsc_code, $bank_branch, 
                        $edit_id
                    );
                    $success_msg = "Successfully Company Details Updated";
                }

                if ($stmt->execute()) {
                    $output["head"]["code"] = 200;
                    $output["head"]["msg"] = $success_msg;
                } else {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Failed to process the request. Error: " . $stmt->error;
                }
                $stmt->close(); 
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Invalid Phone Number.";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Company name Should be Alphanumeric.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
}

// <<<<<<<<<<===================== Image Delete =====================>>>>>>>>>>

else if (isset($obj->image_delete)) {

    $image_delete = $obj->image_delete;

    if ($image_delete === true && function_exists('ImageRemove')) {

        $status = ImageRemove('company', 1);
        if ($status == "company Image Removed Successfully") {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "successfully company Image deleted !.";
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "faild to deleted.please try againg.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Parameter is Mismatch or ImageRemove function is missing.";
    }
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter is Mismatch";
}

// Close the database connection at the end
$conn->close();

echo json_encode($output, JSON_NUMERIC_CHECK);
?>