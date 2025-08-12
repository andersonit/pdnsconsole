<?php
/**
 * PDNS Console
 * Copyright (c) 2025 Neowyze LLC
 *
 * Licensed under the Business Source License 1.0.
 * You may use this file in compliance with the license terms.
 *
 * License details: https://github.com/andersonit/pdnsconsole/blob/main/LICENSE.md
 */

/**
 * PDNS Console - Delete DNS Record Handler
 */

// Get current user info
// Get classes (currentUser is already set by index.php)
$user = new User();
$records = new Records();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get domain and record IDs
$domainId = intval($_GET['domain_id'] ?? 0);
$recordId = intval($_POST['record_id'] ?? 0);

if (empty($domainId)) {
    header('Location: ?page=zones');
    exit;
}

// Get user's tenants for non-super admin users
$userTenants = [];
$tenantId = null;
if (!$isSuperAdmin) {
    $tenantData = $user->getUserTenants($currentUser['id']);
    $userTenants = array_column($tenantData, 'id');
    if (empty($userTenants)) {
        $_SESSION['error'] = 'No tenants assigned to your account. Please contact an administrator.';
        header("Location: ?page=records&domain_id=$domainId");
        exit;
    } else {
        $tenantId = $userTenants[0]; // Use first tenant for access check
    }
}

// Verify this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header("Location: ?page=records&domain_id=$domainId");
    exit;
}

try {
    // Get record info before deletion for audit log
    $recordInfo = $records->getRecordById($recordId, $tenantId);
    
    if (!$recordInfo) {
        $_SESSION['error'] = 'Record not found or access denied.';
        header("Location: ?page=records&domain_id=$domainId");
        exit;
    }
    
    // Verify the record belongs to the specified domain
    if ($recordInfo['domain_id'] != $domainId) {
        $_SESSION['error'] = 'Record does not belong to the specified domain.';
        header("Location: ?page=records&domain_id=$domainId");
        exit;
    }
    
    // Delete the record
    $records->deleteRecord($recordId, $tenantId);
    
    // Log the deletion
    $db = Database::getInstance();
    $db->execute(
        "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, ip_address) 
         VALUES (?, 'record_delete', 'records', ?, ?, ?)",
        [
            $currentUser['id'],
            $recordId,
            json_encode([
                'domain_id' => $recordInfo['domain_id'],
                'name' => $recordInfo['name'],
                'type' => $recordInfo['type'],
                'content' => $recordInfo['content'],
                'ttl' => $recordInfo['ttl'],
                'prio' => $recordInfo['prio']
            ]),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]
    );
    
    $_SESSION['success'] = 'DNS record deleted successfully.';
    
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

// Redirect back to records list
header("Location: ?page=records&domain_id=$domainId");
exit;
?>
