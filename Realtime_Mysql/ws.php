<?php
require 'mailer.php';
require "termii.php";
include_once 'config.php';
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();
error_reporting(E_ALL);

// JWT Configuration
define('JWT_SECRET', $_ENV['JWT_SECRET']);
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRE_HOURS', 24);

// Set CORS headers to allow cross-origin requests from frontend
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Set content type for JSON responses
header('Content-Type: application/json');

if (php_sapi_name() === "cli") {
    // Parse CLI options into $_GET
    $options = getopt("", ["action:", "punchingcode:"]);
    $_GET = $options ?: [];
}

ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
while (ob_get_level()) {
    ob_end_flush();
}

//solution for No buffer to flush 
function safe_output_flush() {
    if (ob_get_level() > 0) {
        ob_flush();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    flush();
}

function validatePassword($password) {
    if (strlen($password) < 8) {
        return "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        return "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        return "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number";
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return "Password must contain at least one symbol";
    }
    
    return true;
}

function generateJWT($adminData) {
    $payload = [
        'iss' => 'haymini-iot',
        'aud' => 'haymini-iot-api',
        'iat' => time(),
        'exp' => time() + (JWT_EXPIRE_HOURS * 3600),
        'admin_id' => $adminData['id'],
        'username' => $adminData['username'],
        'email' => $adminData['email'],
        'role' => $adminData['role'],
        'organization_id' => $adminData['organization_id']
    ];
    
    return JWT::encode($payload, JWT_SECRET, JWT_ALGORITHM);
}

function validateJWT($token) {
    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, JWT_ALGORITHM));
        return (array) $decoded;
    } catch (Exception $e) {
        error_log("JWT validation error: " . $e->getMessage());
        return false;
    }
}

function requireAuth() {
    // Fallback for getallheaders() which might not work on all server configurations
    $headers = array();
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        // Fallback method for servers without getallheaders()
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $header = ucwords($header, '-');
                $headers[$header] = $value;
            }
        }
    }
    
    // Try multiple possible header formats
    $authHeader = $headers['Authorization'] ?? 
                  $headers['authorization'] ??
                  null;
    
    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {    
        http_response_code(401);
        echo json_encode(['error' => 'Authorization token required']);
        exit;
    }
    
    $token = $matches[1];
    $payload = validateJWT($token);
    
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }

    // Check organization status for non-super-admin users
    if ($payload['role'] !== 'super-admin' && !empty($payload['organization_id'])) {
        try {
            $pdoConn = getValidConnection();
            $stmt = $pdoConn->prepare("SELECT status FROM organizations WHERE id = ?");
            $stmt->execute([$payload['organization_id']]);
            $orgStatus = $stmt->fetchColumn();

            if ($orgStatus !== 'active') {
                http_response_code(403);
                echo json_encode(['error' => 'Organization access has been deactivated']);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Database error checking organization status: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
            exit;
        }
    }

    return $payload;
}

function requireSuperAdmin() {
    $user = requireAuth();
    
    if ($user['role'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Super admin access required']);
        exit;
    }
    
    return $user;
}

function requireOrgAccess($organizationId) {
    $user = requireAuth();
    
    if ($user['role'] === 'super_admin') {
        return $user;
    }
    
    if ($user['role'] === 'admin' && $user['organization_id'] != $organizationId) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this organization']);
        exit;
    }
    
    return $user;
}

function getCurrentUser() {
    $user = requireAuth();
    return $user;
}


// Set JSON response header
header('Content-Type: application/json');

// Parse the URL path for RESTful routing
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);
$query = parse_url($requestUri, PHP_URL_QUERY);

// Parse query parameters
parse_str($query ?? '', $queryParams);

// Get JSON input for POST/PUT requests
$jsonInput = null;
if (in_array($requestMethod, ['POST', 'PUT', 'PATCH'])) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonInput = json_decode($rawInput, true);
    }
}

// RESTful routing
switch ($requestMethod) {
    
    case 'GET':
        if (preg_match('/\/api\/auth\/me$/', $path)) {
            $user = getCurrentUser();
            echo json_encode([
                'status' => 'success',
                'user' => [
                    'id' => $user['admin_id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'organization_id' => $user['organization_id']
                ]
            ]);
            
        } elseif (preg_match('/\/api\/organizations\/(\d+)$/', $path, $matches)) {
            $organizationId = (int)$matches[1];
            echo json_encode(getOrganization($organizationId));
            
        } elseif (preg_match('/\/api\/organizations$/', $path)) {
            echo json_encode(getAllOrganizations());
            
        } elseif (preg_match('/\/api\/organizations\/(\d+)\/users$/', $path, $matches)) {
            $organizationId = (int)$matches[1];
            $userName = $queryParams['user_name'] ?? null;
            echo json_encode(getUsersByOrganization($organizationId, $userName));
            
        } elseif (preg_match('/\/api\/organizations\/(\d+)\/devices$/', $path, $matches)) {
            $organizationId = (int)$matches[1];
            echo json_encode(getDevicesByOrganization($organizationId));
            
        } elseif (preg_match('/\/api\/organizations\/(\d+)\/logs$/', $path, $matches)) {
            $organizationId = (int)$matches[1];
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo = $queryParams['date_to'] ?? null;
            $userName = $queryParams['user_name'] ?? null;
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 10;
            $page = isset($queryParams['page']) ? max(1, (int)$queryParams['page']) : 1;
            echo json_encode(getLogsByOrganization($organizationId, $dateFrom, $dateTo, $userName, $limit, $page));

        } elseif (preg_match('/\/api\/organizations\/(\d+)\/device-assignments$/', $path, $matches)) {
            $organizationId = (int)$matches[1];
            echo json_encode(getOrganizationDeviceAssignments($organizationId));


        } elseif (preg_match('/\/api\/users\/([^\/]+)$/', $path, $matches)) {
            $punchingCode = $matches[1];
            $organizationId = $queryParams['organization_id'] ?? null;
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo = $queryParams['date_to'] ?? null;
            echo getLogsByPunchingCode($punchingCode, $organizationId, $dateFrom, $dateTo);
            
       
        } elseif (preg_match('/\/api\/devices\/([^\/]+)$/', $path, $matches)) {
            $serialNumber = $matches[1];
            echo json_encode(getDeviceDetails($serialNumber));
            

        } elseif (preg_match('/\/api\/logs$/', $path)) {
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo = $queryParams['date_to'] ?? null;
            $userName = $queryParams['user_name'] ?? null;
            echo getAllLogs($dateFrom, $dateTo, $userName);
            
        } elseif (preg_match('/\/api\/logs\/export$/', $path)) {
             exportLogsToExcel();
            
        } elseif (preg_match('/\/api\/logs\/device\/([^\/]+)$/', $path, $matches)) {
            requireAuth();
            $deviceSerial = $matches[1];
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo = $queryParams['date_to'] ?? null;
            $userName = $queryParams['user_name'] ?? null;
            echo json_encode(getLogsByDevice($deviceSerial, $dateFrom, $dateTo, $userName));
            
        } elseif (preg_match('/\/api\/admins$/', $path)) {
            requireSuperAdmin();
            echo json_encode(getAllAdmins());

        } elseif (preg_match('/\/api\/users\/(\d+)\/devices$/', $path, $matches)) {
            requireAuth();
            $userId = (int)$matches[1];
            echo json_encode(getUserAssignedDevices($userId));

        } elseif (preg_match('/\/api\/devices\/(\d+)\/users$/', $path, $matches)) {
            requireAuth();
            $deviceId = (int)$matches[1];
            echo json_encode(getDeviceAssignedUsers($deviceId));

        } elseif (preg_match('/\/api\/users\/csv-template$/', $path)) {
            requireAuth();
            generateUserCSVTemplate();

        } elseif (preg_match('/\/api\/users$/', $path)) {
            requireAuth();
            $search = $queryParams['search'] ?? null;
            $organizationId = $queryParams['organization_id'] ?? null;
            $userType = $queryParams['user_type'] ?? null;
            $status = $queryParams['status'] ?? null;
            echo json_encode(searchUsers($search, $organizationId, $userType, $status));

        } elseif (preg_match('/\/api\/organizations\/(\d+)\/punch-settings$/', $path, $matches)) {
            requireAuth();
            $organizationId = (int)$matches[1];
            echo json_encode(getOrganizationPunchSettings($organizationId));

        } elseif (preg_match('/\/api\/organizations\/(\d+)\/attendance$/', $path, $matches)) {
            requireAuth();
            $organizationId = (int)$matches[1];
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo = $queryParams['date_to'] ?? null;
            echo json_encode(getOrganizationAttendance($organizationId, $dateFrom, $dateTo));

        } elseif (preg_match('/\/api\/organizations\/(\d+)\/attendance\/today$/', $path, $matches)) {
            requireAuth();
            $organizationId = (int)$matches[1];
            echo json_encode(getTodayAttendanceStatus($organizationId));

        } elseif (preg_match('/\/api\/organizations\/(\d+)\/absence-report$/', $path, $matches)) {
            requireAuth();
            $organizationId = (int)$matches[1];
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo = $queryParams['date_to'] ?? null;
            echo json_encode(getAbsenceReport($organizationId, $dateFrom, $dateTo));

        } elseif (preg_match('/\/api\/users\/(\d+)\/attendance-report$/', $path, $matches)) {
            requireAuth();
            $userId = (int)$matches[1];
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo = $queryParams['date_to'] ?? null;
            echo json_encode(getUserAttendanceReport($userId, $dateFrom, $dateTo));

        } elseif (preg_match('/\/api\/?$/', $path) || $path === '/ws.php') {
            echo json_encode([
                'message' => 'IoT Organization RESTful API',
                'version' => '1.0',
                'endpoints' => [
                    'organizations' => [
                        'GET /api/organizations' => 'List all organizations',
                        'GET /api/organizations/{id}' => 'Get organization details',
                        'POST /api/organizations' => 'Create organization',
                        'PUT /api/organizations/{id}' => 'Update organization',
                        'GET /api/organizations/{id}/users' => 'Get organization users',
                        'GET /api/organizations/{id}/devices' => 'Get organization devices',
                        'GET /api/organizations/{id}/logs' => 'Get organization logs',
                        'GET /api/organizations/{id}/punch-settings' => 'Get punch time settings',
                        'PUT /api/organizations/{id}/punch-settings' => 'Update punch time settings',
                        'GET /api/organizations/{id}/attendance' => 'Get attendance report',
                        'GET /api/organizations/{id}/attendance/today' => 'Get today\'s attendance status',
                        'GET /api/organizations/{id}/absence-report' => 'Get absence report',
                        'POST /api/organizations/{id}/generate-absence-records' => 'Generate absence records for date'
                    ],
                    'users' => [
                        'GET /api/users' => 'Search users by punching code or name (supports filters: search, organization_id, user_type, status)',
                        'POST /api/users' => 'Create user',
                        'POST /api/users/bulk' => 'Bulk create users',
                        'POST /api/users/upload-csv' => 'Upload users from CSV file',
                        'GET /api/users/csv-template' => 'Download CSV template for bulk upload',
                        'GET /api/users/{punching_code}' => 'Get user logs',
                        'GET /api/users/{user_id}/attendance-report' => 'Get user attendance report',
                        'PUT /api/users/{punching_code}/activate' => 'Activate user',
                        'PUT /api/users/{punching_code}/deactivate' => 'Deactivate user',
                        'DELETE /api/users/{punching_code}' => 'Delete user from organization (cascade deletes all related data)'
                    ],
                    'devices' => [
                        'POST /api/devices' => 'Register device',
                        'GET /api/devices/{serial_number}' => 'Get device details',
                        'PUT /api/devices/{serial_number}' => 'Update device',
                        'PUT /api/devices/{serial_number}/organization' => 'Assign device to organization'
                    ],
                    'logs' => [
                        'GET /api/logs' => 'Get all logs from all organizations',
                        'GET /api/logs/export' => 'Export logs to Excel',
                        'GET /api/logs/device/{device_serial}' => 'Get logs by device',
                        'GET /api/users/{punching_code}' => 'Get logs by user (punching code)',
                        'GET /api/organizations/{id}/logs' => 'Get logs by organization'
                    ],
                    'admins' => [
                        'GET /api/admins' => 'Get all administrators (Super Admin only)',
                        'POST /api/admins' => 'Create new administrator (Super Admin only)',
                        'POST /api/admins/{id}/impersonate' => 'Impersonate admin (Super Admin only)',
                        'POST /api/admins/exit-impersonation' => 'Exit admin impersonation session',
                        'PUT /api/admins/{id}/password' => 'Update admin password (Super Admin only)'
                    ],
                    'assignments' => [
                        'GET /api/users/{user_id}/devices' => 'Get devices assigned to user',
                        'GET /api/devices/{device_id}/users' => 'Get users assigned to device',
                        'GET /api/organizations/{organization_id}/device-assignments' => 'Get all device assignments for organization',
                        'POST /api/users/{user_id}/devices/{device_id}/assign' => 'Assign user to device',
                        'POST /api/devices/{device_id}/users/bulk-assign' => 'Bulk assign users to device',
                        'DELETE /api/users/{user_id}/devices/{device_id}/assign' => 'Remove user from device'
                    ]
                ]
            ]);
            
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
        }
        break;
        
    case 'POST':
        if (preg_match('/\/api\/auth\/login$/', $path)) {
            if (!$jsonInput || empty($jsonInput['email']) || empty($jsonInput['password'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Email and password are required']);
                break;
            }
            
            echo json_encode(loginAdmin($jsonInput['email'], $jsonInput['password']));
            
        } elseif (preg_match('/\/api\/auth\/logout$/', $path)) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Logged out successfully'
            ]);
            
        } elseif (preg_match('/\/api\/organizations$/', $path)) {
            if (!$jsonInput || empty($jsonInput['name']) || empty($jsonInput['contact_person']) || 
                empty($jsonInput['email']) || empty($jsonInput['phone'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields: name, contact_person, email, phone']);
                break;
            }
            
            echo json_encode(createOrganization(
                $jsonInput['name'],
                $jsonInput['address'] ?? null,
                $jsonInput['contact_person'],
                $jsonInput['email'],
                $jsonInput['phone'],
                $jsonInput['description'] ?? null
            ));
            
        } elseif (preg_match('/\/api\/users$/', $path)) {
            requireAuth();
            $user = requireAuth();

            if (!$jsonInput || empty($jsonInput['punching_code']) || empty($jsonInput['name']) ||
                empty($jsonInput['phone']) || empty($jsonInput['email'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields: punching_code, name, phone, email']);
                break;
            }

            echo json_encode(getOrCreateUser(
                $jsonInput['punching_code'],
                $jsonInput['name'],
                $jsonInput['phone'],
                $jsonInput['email'],
                $jsonInput['organization_id'] ?? null,
                $jsonInput['device_id'] ?? null,
                $user['admin_id'],
                $jsonInput['user_type'] ?? 'student'
            ));
            
        } elseif (preg_match('/\/api\/users\/bulk$/', $path)) {
            requireAuth();
            $user = requireAuth();

            if (!$jsonInput || !isset($jsonInput['users'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: users array']);
                break;
            }

            echo json_encode(bulkCreateUsers($jsonInput['users'], $jsonInput['organization_id'] ?? null, $user['admin_id']));
            
        } elseif (preg_match('/\/api\/devices$/', $path)) {
            requireAuth();
            if (!$jsonInput || empty($jsonInput['serial_number']) || empty($jsonInput['organization_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields: serial_number, organization_id']);
                break;
            }
            
            echo json_encode(registerDevice(
                $jsonInput['serial_number'],
                $jsonInput['organization_id'],
                $jsonInput['device_name'] ?? null,
                $jsonInput['device_model'] ?? null,
                $jsonInput['ip_address'] ?? null
            ));
            
        } elseif (preg_match('/\/api\/admins$/', $path)) {
            requireSuperAdmin();
            if (!$jsonInput || empty($jsonInput['username']) || empty($jsonInput['email']) || 
                empty($jsonInput['password']) || empty($jsonInput['role'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields: username, email, password, role']);
                break;
            }
            
            echo json_encode(createAdmin(
                $jsonInput['username'],
                $jsonInput['email'],
                $jsonInput['password'],
                $jsonInput['role'],
                $jsonInput['organization_id'] ?? null
            ));

        } elseif (preg_match('/\/api\/admins\/(\d+)\/impersonate$/', $path, $matches)) {
            requireSuperAdmin();
            $targetAdminId = (int)$matches[1];
            echo json_encode(impersonateAdmin($targetAdminId));

        } elseif (preg_match('/\/api\/admins\/exit-impersonation$/', $path)) {
            requireAuth(); // Any authenticated user can call this
            echo json_encode(exitImpersonation());

        } elseif (preg_match('/\/api\/users\/(\d+)\/devices\/(\d+)\/assign$/', $path, $matches)) {
            requireAuth();
            $user = requireAuth();
            $userId = (int)$matches[1];
            $deviceId = (int)$matches[2];
            echo json_encode(assignUserToDevice($userId, $deviceId, $user['admin_id']));

        } elseif (preg_match('/\/api\/devices\/(\d+)\/users\/bulk-assign$/', $path, $matches)) {
            requireAuth();
            $user = requireAuth();
            $deviceId = (int)$matches[1];

            if (!$jsonInput || !isset($jsonInput['user_ids']) || !is_array($jsonInput['user_ids'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: user_ids array']);
                break;
            }

            echo json_encode(bulkAssignUsersToDevice($deviceId, $jsonInput['user_ids'], $user['admin_id']));

        } elseif (preg_match('/\/api\/users\/upload-csv$/', $path)) {
            requireAuth();

            if (!isset($_FILES['csv_file'])) {
                http_response_code(400);
                echo json_encode(['error' => 'CSV file is required']);
                break;
            }

            $csvFile = $_FILES['csv_file'];

            // Validate file type
            $allowedTypes = ['text/csv', 'application/csv', 'text/plain'];
            if (!in_array($csvFile['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid file type. Only CSV files are allowed.']);
                break;
            }

            // Validate file size (10MB max)
            if ($csvFile['size'] > 10 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['error' => 'File too large. Maximum size is 10MB.']);
                break;
            }

            $defaultOrganizationId = $_POST['organization_id'] ?? null;
            echo json_encode(uploadUsersFromCSV($csvFile, $defaultOrganizationId));

        } elseif (preg_match('/\/api\/organizations\/(\d+)\/generate-absence-records$/', $path, $matches)) {
            requireAuth();
            $organizationId = (int)$matches[1];
            $date = $jsonInput['date'] ?? null;
            echo json_encode(generateAbsenceRecords($organizationId, $date));

        } elseif (preg_match('/\/api\/setup\/default-admin$/', $path)) {
            echo json_encode(createDefaultSuperAdmin());
            
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
        }
        break;
        
    case 'PUT':
        if (preg_match('/\/api\/organizations\/(\d+)$/', $path, $matches)) {
            $organizationId = (int)$matches[1];
            
            echo json_encode(updateOrganization(
                $organizationId,
                $jsonInput['name'] ?? null,
                $jsonInput['address'] ?? null,
                $jsonInput['contact_person'] ?? null,
                $jsonInput['email'] ?? null,
                $jsonInput['phone'] ?? null,
                $jsonInput['description'] ?? null,
                $jsonInput['status'] ?? null
            ));
            
        } elseif (preg_match('/\/api\/users\/([^\/]+)\/activate$/', $path, $matches)) {
            $user = requireAuth();
            $punchingCode = $matches[1];
            // Use organization_id from request body, or default to authenticated user's org
            $orgId = $jsonInput['organization_id'] ?? $user['organization_id'];
            echo json_encode(activateUser($punchingCode, $orgId));

        } elseif (preg_match('/\/api\/users\/([^\/]+)\/deactivate$/', $path, $matches)) {
            $user = requireAuth();
            $punchingCode = $matches[1];
            // Use organization_id from request body, or default to authenticated user's org
            $orgId = $jsonInput['organization_id'] ?? $user['organization_id'];
            echo json_encode(deactivateUser($punchingCode, $orgId));
            
        } elseif (preg_match('/\/api\/devices\/([^\/]+)$/', $path, $matches)) {
            $serialNumber = $matches[1];

            if (!$jsonInput || empty($jsonInput)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields provided to update']);
                break;
            }

            // Validate status if provided
            if (isset($jsonInput['status']) && !in_array($jsonInput['status'], ['active', 'inactive', 'maintenance'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status. Must be: active, inactive, or maintenance']);
                break;
            }

            echo json_encode(updateDeviceStatus($serialNumber, $jsonInput));
            
        } elseif (preg_match('/\/api\/devices\/([^\/]+)\/organization$/', $path, $matches)) {
            requireAuth();
            $serialNumber = $matches[1];
            
            if (!$jsonInput || empty($jsonInput['organization_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: organization_id']);
                break;
            }
            
            echo json_encode(assignDeviceToOrganization($serialNumber, $jsonInput['organization_id']));
            
        } elseif (preg_match('/\/api\/admins\/(\d+)\/password$/', $path, $matches)) {
            requireSuperAdmin();
            $adminId = (int)$matches[1];
            
            if (!$jsonInput || empty($jsonInput['password'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: password']);
                break;
            }
            
            echo json_encode(updateAdminPassword($adminId, $jsonInput['password']));

        } elseif (preg_match('/\/api\/organizations\/(\d+)\/punch-settings$/', $path, $matches)) {
            requireAuth();
            $user = requireAuth();
            $organizationId = (int)$matches[1];

            if (!$jsonInput) {
                http_response_code(400);
                echo json_encode(['error' => 'JSON body required']);
                break;
            }

            echo json_encode(updatePunchSettings($organizationId, $jsonInput, $user['admin_id']));

        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
        }
        break;

    case 'DELETE':
        if (preg_match('/\/api\/users\/(\d+)\/devices\/(\d+)\/assign$/', $path, $matches)) {
            requireAuth();
            $user = requireAuth();
            $userId = (int)$matches[1];
            $deviceId = (int)$matches[2];
            echo json_encode(removeUserFromDevice($userId, $deviceId, $user['admin_id']));

        } elseif (preg_match('/\/api\/users\/([^\/]+)$/', $path, $matches)) {
            $user = requireAuth();
            $punchingCode = $matches[1];
            // Use organization_id from request body, or default to authenticated user's org
            $orgId = $jsonInput['organization_id'] ?? $user['organization_id'];
            echo json_encode(deleteUser($punchingCode, $orgId));

        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode([
            'error' => 'Method not allowed',
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ]);
        break;
}



// Only start WebSocket server if not handling API requests
$isApiRequest = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false;

if (!$isApiRequest) {
    set_time_limit(0);
    ob_implicit_flush();

    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(socket_bind($socket, $SERVER_IP, $SERVER_PORT)==false)
{
	var_dump($SERVER_PORT);
	echo " bind Failed ".$SERVER_IP.":".$SERVER_PORT;
	exit;
}
socket_listen($socket, $MAX_THREADS);
//socket_set_nonblock($socket);

$machines = array($socket); //所有Socket列表
$unread = array(); //当前正在读取的Socket列表
$data = array(); //每个Socket未处理完的数据。
$machineInfo = array(); // id  对应的 IP端口
$id = array();  //IP端口对应的 ID
$constat = array(); //
$userData=array(); //用户列表

$startData = date('md His_');
$imgID=1;

error_log("WebSocket server started at $startData");
safe_output_flush();

$bConnect=false;
$startTime=microtime(true);
$getList=true;
$dataTime=microtime(true);;
do {
	$unread = $machines;
	if(socket_select($unread, $write, $except, 0,100)>0)
	{
		foreach ($unread as $mark => $ready) {
			if ($ready === $socket) {
				$accept = socket_accept($socket);
				socket_getpeername($accept, $address, $port);
				error_log("Client connected: $address:$port " . date('His'));
				safe_output_flush();
				
				$machines[] = $accept; //添加
				$data["id{$address}_{$port}"]=''; //清空数据
				$constat["id{$address}_{$port}"]=false;
				/*
				$headers = socket_read($accept, 4096, PHP_BINARY_READ);
				preg_match_all('/Sec-WebSocket-Key:\s*(.*?)\r\n/', $headers, $key);
				$key = base64_encode(SHA1($key[1][0].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
				//$buffer = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $key\r\n\r\n";
				$buffer = "HTTP/1.1
			 Upgrade: WebSocket
			 Connection: Upgrade";
				socket_write($accept, $buffer, strlen($buffer));
				socket_set_nonblock($accept);
				if($bConnect===false)
				{
					$bConnect=true;
				}
				*/
			} else {
				//$packet = @socket_read($ready, 4096, PHP_NORMAL_READ);
				socket_getpeername($ready, $address, $port);
				$frame = @socket_read($ready, 4096, PHP_BINARY_READ);
				if ($frame) {
					
					$dataTime=microtime(true);;
					if($constat["id{$address}_{$port}"]==false) //握手
					{
						if(woshou($ready,$frame))
						{
							$constat["id{$address}_{$port}"]=true;
							error_log("WebSocket handshake completed: $address:$port " . date('His'));
							
						}
						else 
							error_log("WebSocket handshake failed: " . $frame);
					safe_output_flush();
						continue;
					}
					
					$DataRec=''; //收到数据
					if(isset($data["id{$address}_{$port}"]))
						$DataRec=$data["id{$address}_{$port}"];
					$DataLen=strlen($DataRec); //数据长度
					
					$size = strlen($frame);
					$DataRec.=$frame;
					$DataLen+=$size;
					
					$NeedLen=2; //数据头
					while($DataLen>$NeedLen)
					{
						//取长度
						$optcode=ord($DataRec[0]) & 15;
						
						//echo "</br> onframe:".ord($DataRec[0]).ord($DataRec[1])." len:".$DataLen;
						
						$b2 = ord($DataRec[1]);
						$mask = ($b2 &128) != 0;
						$payloadlength = $b2&127;
						
						if($payloadlength === 126)
							$NeedLen+=2;
						else if($payloadlength>126)
							$NeedLen+=8;
						if($DataLen<$NeedLen)
							break;
							
						$nDataPos=2;
						$maxpacketsize=$DataLen;
						if (!($payloadlength >= 0 && $payloadlength <= 125))
						{
							if ($payloadlength === 126) // && (optcode != PING && optcode != PONG &&  optcode != CLOSING)
							{					
								$payloadlength=ord($DataRec[$nDataPos+1]);
								$payloadlength+=ord($DataRec[$nDataPos])<<8;
								$nDataPos+=2;

							} else {					
								$payloadlength=ord($DataRec[$nDataPos+7]);
								$payloadlength+=ord($DataRec[$nDataPos+6])<<8;
								$payloadlength+=ord($DataRec[$nDataPos+5])<<16;
								$payloadlength+=ord($DataRec[$nDataPos+4])<<24;
								//....
								$nDataPos+=8;
							}
						}
						if($payloadlength>=0 && $payloadlength<1024*1024*2)
						{
							$NeedLen += ($mask ? 4 : 0);
							$NeedLen += $payloadlength;
							
							if($maxpacketsize < $NeedLen)
							{
								//echo($maxpacketsize." < ".$NeedLen." | ");
								//ob_flush();
								break;
							}
							//echo "</br>";
							
							$packet = '';
							if ($mask) {
								$maskPos=$nDataPos;
								$nDataPos+=4;

							  for ($i = 0; $i < $payloadlength; $i++) {
								$packet .=($DataRec[$i+$nDataPos] ^ $DataRec[$maskPos+$i % 4]);
							  }
							  $nDataPos+=$payloadlength;
							} else {
								$packet.=substr($DataRec,$nDataPos,$maxpacketsize-$nDataPos);
								$nDataPos=$maxpacketsize;
							}
							
							if(onFrame($packet,$ready,$address,$port,$optcode)==true)
							{
								//$curTime=microtime(true);
							}
						
							if($nDataPos===$DataLen)
							{
								$DataRec='';
								$DataLen=0;
								//echo "</br>end:".$nDataPos;
							}
							else
							{
								$DataRec=substr($DataRec,$nDataPos,$DataLen-$nDataPos);
								$DataLen-=$nDataPos;
								error_log("Processing next data chunk: " . $DataLen);
							}
							
					safe_output_flush();
						}
						else //长度错误
						{
							error_log("Error - invalid payload length: " . $payloadlength);
							break;
						}
						$NeedLen=2;
					}
					$data["id{$address}_{$port}"]=$DataRec;
					
				}else{
					//if($frame===false)
					{
						error_log("Client disconnected: $address:$port " . date('His'));
						safe_output_flush();
						socket_close($ready);
						unset($id["id{$address}_{$port}"]);
						unset($data["id{$address}_{$port}"]);
						unset($machines[$mark]);
					}
				}
			}
		}
	}
	$curTime=microtime(true);
	$tihstiime=$curTime-$dataTime;
	if($tihstiime>15) //ping
	{
		sendPingToAll();
		$dataTime=$curTime;
	}
	$tihstiime=$curTime-$startTime;
	if($tihstiime>1) //1秒检查一次。
	{
		$startTime=$curTime;
		/****************Check  for new command to be send **************/	
		if(file_exists("./commands/cmd.txt")){
			$packetCommand = file_get_contents("./commands/cmd.txt");
			unlink("./commands/cmd.txt");
			error_log("Received command: " . $packetCommand);
			
			if($packetCommand==='exit')
			{
				break;
			}
			else if($packetCommand==='getList')
			{
				$retTxt='{"cmd":"getuserlist","stn":true}';
				sendCmdToAll($retTxt);
			}
			else if($packetCommand==='getInfo')
			{
				$uerIndex=0;
				$uerIndex=sendGetUserInfo($uerIndex);
			}
		}			
	}
	
} while (true);
socket_close($socket);
} // End of WebSocket server code

function woshou($socket,$buffer){
        //截取Sec-WebSocket-Key的值并加密，其中$key后面的一部分258EAFA5-E914-47DA-95CA-C5AB0DC85B11字符串应该是固定的
        $buf  = substr($buffer,strpos($buffer,'Sec-WebSocket-Key:')+18);
        $key  = trim(substr($buf,0,strpos($buf,"\r\n")));
        $new_key = base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
         
        //按照协议组合信息进行返回
        $new_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $new_message .= "Upgrade: websocket\r\n";
        $new_message .= "Sec-WebSocket-Version: 13\r\n";
        $new_message .= "Connection: Upgrade\r\n";
        $new_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
        socket_write($socket,$new_message,strlen($new_message));
        return true;
    }
	
function sendGetUserInfo($uerIndex)
{
	global $userData;
	if($uerIndex<0)
		return -1;
		
	$count=count($userData);
	while($uerIndex<$count)
	{
		$record=$userData[$uerIndex];
		if(isset($record['enrollid']) && isset($record['backupnum']))
		{
			$eid=$record['enrollid'];
			$bknum=$record['backupnum'];
			if($eid!=null && $bknum!=null)
			{
				//更新列表
				$retTxt='{"cmd":"getuserinfo","enrollid":'.$eid.',"backupnum":'.$bknum.'}';
				sendCmdToAll($retTxt);
				return $uerIndex+1;
			}
		}
		$uerIndex++;
	}
	return -1;
}
function sendCmdToAll($retTxt)
{
	global $socket;
	global $machines;
	global $id;
	$size = strlen($retTxt);
	if($size>0)
	{
		$code = 129;
		$bufferCommand = ($size<126?pack('CC', $code, $size):($size<65536?pack('CCn', $code, 126, $size):pack('CCNN', $code, 127,0, $size))).$retTxt;
		$sendCount=0;
		foreach ($machines as $ready) {
			if($ready!=$socket)
			{
				socket_getpeername($ready, $address, $port);
				if(isset($id["id{$address}_{$port}"])) //已经 收到 reg 的
				{
					socket_write($ready, $bufferCommand);
					$sendCount++;
				}
			}
		}
		error_log("Sent command: " . $bufferCommand . " count=" . $sendCount);
	safe_output_flush();
		return $sendCount;
	}
	return 0;
}
function sendPingToAll()
{
	global $socket;
	global $machines;
	global $id;
	
	$retTxt =chr(119).chr(0);
	$size =strlen($retTxt);
	$code = 129;
	$bufferCommand = ($size<126?pack('CC', $code, $size):($size<65536?pack('CCn', $code, 126, $size):pack('CCNN', $code, 127,0, $size))).$retTxt;

	$sendCount=0;
	foreach ($machines as $ready) {
		if($ready!=$socket)
		{
			socket_getpeername($ready, $address, $port);
			if(isset($id["id{$address}_{$port}"])) //已经 收到 reg 的
			{
				socket_write($ready, $bufferCommand);
				$sendCount++;
			}
		}
	}
	error_log("Sent ping: " . ord($bufferCommand[0]) . ord($bufferCommand[1]) . $bufferCommand . " count=" . $sendCount . "/" . date('His'));
	safe_output_flush();
	return $sendCount;

}
function onFrame($packet,$ready,$address, $port,$optcode)
{
	global $userData;
	global $getList;
	global $id;
	global $uerIndex;
	
	
	if($optcode==1 && $packet != '')
	{
		error_log("Processing packet - optcode: " . $optcode . ", length: " . strlen($packet));
		if(strlen($packet) <= 200) {
			error_log("Packet content: " . $packet);
		}
	}
	else
	{
		if($optcode==9) //ping 
		{
			$retTxt =chr(118).chr(0);
			$size =strlen($retTxt);
			$code = 129;
			$bufferCommand = ($size<126?pack('CC', $code, $size):($size<65536?pack('CCn', $code, 126, $size):pack('CCNN', $code, 127,0, $size))).$retTxt;
		
			socket_write($ready, $bufferCommand);
			//echo " ret ping ".date('His');
		}
		else
			error_log("Packet type: " . $optcode);
		return false;
	}
	$bReg=false;
	
	/* PROCESS */
		#$packet = substr(trim($frame),strpos($frame,"{")); // uncomment this line for windows
		$packet = json_decode($packet, true); /////Comment this for windows
		$retTxt='';
		if (isset($packet['ret'])) {
			
			switch ($packet['ret']) {
			
				case 'getalllog':
					$packet = str_replace('},]}','}]}',$packet); // extra
					$retLog = false;
					if((int)$packet['to'] >= (int)$packet['count']){
						$retLog = true;
					} 
					$retTxt = store($packet['record'], $id["id{$address}_{$port}"] ,1);
					if($retLog){
						$packet = '{"ret":"getalllog","stn":true}';
					}
					//echo $packet;
					break;
				case 'getuserlist':	//返回列表
					
					$records =$packet['record'];
					$userData=array_merge($userData,$records); //缓存用户列表。
					error_log("Processing records - existing: " . count($userData) . ", new: " . count($records));
					//next
					$count=$packet['count'];
					if($count>0) //继续
						$retTxt='{"cmd":"getuserlist","stn":false}';
					else
						return false;
					
					break;
				case 'getuserinfo':
					$packet = str_replace('},]}','}]}',$packet); // extra
					$bknum=$packet['backupnum'];
					if($bknum==50)
					{
						$img=$packet['record'];
						if($img!=null)
						{	
							$img=base64_decode($img,false);
							if($img!=null)
							{
								saveImg($img);
								$uerIndex=sendGetUserInfo($uerIndex);
								return true;
							}
						}
					}
					$uerIndex=sendGetUserInfo($uerIndex);
					return  false;
					default:
						return false;
				}

		}elseif (isset($packet['cmd'])) {
			
			switch ($packet['cmd']) {
				case 'reg':
					$id["id{$address}_{$port}"] = $packet['sn'];
					$machineInfo[$packet['sn']] = $address.":".$port;
					$retTxt = '{"ret":"reg", "result":true, "cloudtime":"'.date('Y-m-d H:i:s').'"}';
					
					$bReg=true;
					break;
				case 'sendlog':
					//$packet = str_replace('},]}','}]}',$packet); // extra
					$retTxt = store($packet['record'], $id["id{$address}_{$port}"]);
					$records =$packet['record'];
					foreach($records as $record){
						if(isset($record['image']))
						{
							$img=$record['image'];
							$img=base64_decode($img,false);
							if($img!=null)
							{
								saveImg($img);
								//return true;
							}
						}
					}
					break;
					
				case 'getalllog':
					$packet = str_replace('},]}','}]}',$packet); // extra
					$retTxt = store($packet['record'], $id["id{$address}_{$port}"]);
					break;
					
				case 'senduser':
					$img=$packet['record'];
					if($img!=null)
					{	
						$img=base64_decode($img,false);
						if($img!=null)
						{
							saveImg($img);
						}
					}
					$retTxt = '{"ret":"senduser", "result":true, "cloudtime":"'.date('Y-m-d H:i:s').'"}';
					break;
				default:
					$retTxt = '{"ret":"$packet["cmd"]", "result":true, "cloudtime":"'.date('Y-m-d H:i:s').'"}';
					break;
			}

		} else {
			var_dump("error", $packet);
		}

		/* PROCESS */
		$size = strlen($retTxt);
		if($size>0)
		{
			$code = 129;
			$buffer = ($size<126?pack('CC', $code, $size):($size<65536?pack('CCn', $code, 126, $size):pack('CCNN', $code, 127,0, $size))).$retTxt;
			socket_write($ready, $buffer);
			error_log("Sending response: " . ord($buffer[0]) . ord($buffer[1]) . ":" . substr($retTxt, 0, 100));
		}
		return false;
}
function saveImg($img)
{
	global $startData;
	global $imgID;
	$fileName="commands/".$startData.$imgID.".jpg";
	$imgID++;
	$file = file_put_contents($fileName,$img);
	error_log("Saved image file: " . $fileName);
}

// function store($records, $id, $sts = 0) {
//     global $pdoConn;

//     // Base SQL query
//     $sql = 'INSERT INTO tblt_timesheet (punchingcode, date, time, Tid) VALUES ';
//     $sqlArray = [];

//     foreach ($records as $record) {
//         // Validate time field
//         if (empty($record["time"]) || !strtotime($record["time"])) {
//             continue; // Skip invalid records
//         }

//         // Check for duplicates in the database
//         $stmt = $pdoConn->prepare(
//             "SELECT COUNT(*) FROM tblt_timesheet WHERE punchingcode = ? AND date = ? AND time = ?"
//         );
//         $stmt->execute([
//             $record["enrollid"],
//             date("Y-m-d", strtotime($record["time"])),
//             date("H:i:s", strtotime($record["time"]))
//         ]);

//         if ($stmt->fetchColumn() == 0) {
//             // Add to SQL Array if not duplicate
//             $sqlArray[] = '("' . $record["enrollid"] . '", "' . date("Y-m-d", strtotime($record["time"])) . '", "' . date("H:i:s", strtotime($record["time"])) . '", "' . $id . '")';

//             // Send email notification
//             $to = 'sobiechie16@gmail.com';
//             $subject = 'New record has been inserted';
//             $message = 'Details: Card Number: ' . $record["enrollid"] . ', Date: ' . date("Y-m-d", strtotime($record["time"])) . ', Time: ' . date("H:i:s", strtotime($record["time"]));
//             sendEmail($to,$message, $subject);

//             // Send SMS notification
//             $phoneNumber = '2349134327450';
//             $smsMessage = 'Card Number: ' . $record["enrollid"] . ', Date: ' . date("Y-m-d", strtotime($record["time"])) . ', Time: ' . date("H:i:s", strtotime($record["time"]));
//             sendSms($smsMessage, $phoneNumber);
//         }
//     }

//     if (!empty($sqlArray)) {
//         // Combine SQL statements and prepare query
//         $sql2 = $sql . implode(",", $sqlArray);

//         try {
//             $stmt = $pdoConn->prepare($sql2);
//             $exec = $stmt->execute();
//         } catch (PDOException $e) {
//             // Log SQL error
//             error_log("SQL Error: " . $e->getMessage());
//             return '{"ret":"sendlog","result":false,"reason":"SQL Error"}';
//         }

//         if ($exec) {
//             $result = $sts
//                 ? '{"cmd":"getalllog","stn":false,"cloudtime":"' . date('Y-m-d H:i:s') . '"}'
//                 : '{"ret":"sendlog","result":true,"cloudtime":"' . date('Y-m-d H:i:s') . '"}';
//             return $result;
//         } else {
//             return '{"ret":"sendlog","result":false,"reason":"Execution failed"}';
//         }
//     }

//     return '{"ret":"sendlog","result":false,"reason":"No records to insert"}';
// }

// function store($records, $id, $sts = 0) {
//     global $pdoConn;

//     $sql = 'INSERT INTO tblt_timesheet (punchingcode, date, time, Tid) VALUES ';
//     $sqlArray = [];

//     foreach ($records as $record) {
//         if (empty($record["time"]) || !strtotime($record["time"])) {
//             continue;
//         }

//         // Check for duplicates in the database
//         $stmt = $pdoConn->prepare(
//             "SELECT COUNT(*) FROM tblt_timesheet WHERE punchingcode = ? AND date = ? AND time = ?"
//         );
//         $stmt->execute([
//             $record["enrollid"],
//             date("Y-m-d", strtotime($record["time"])),
//             date("H:i:s", strtotime($record["time"]))
//         ]);

//         if ($stmt->fetchColumn() == 0) {
//             // Add to SQL Array if not duplicate
//             $sqlArray[] = '("' . $record["enrollid"] . '", "' . date("Y-m-d", strtotime($record["time"])) . '", "' . date("H:i:s", strtotime($record["time"])) . '", "' . $id . '")';

//             // Fetch the user's phone and email based on punchingcode
//             $stmt = $pdoConn->prepare("SELECT phone_number, email FROM users WHERE punching_code = ?");
//             $stmt->execute([$record["enrollid"]]);
//             $user = $stmt->fetch(PDO::FETCH_ASSOC);

//             if ($user) {
//                 $phoneNumber = $user['phone_number'];
//                 $email = $user['email'];

//                 // Send email notification
//                 $subject = 'New record has been inserted';
//                 $message = $message = 'Kindly note that the card bearer with Card Number: ' . $record["enrollid"] . ' just arrived at school';
//                 sendEmail($email, $message, $subject);

//                 // Send SMS notification
//                 $smsMessage = 'Card Number: ' . $record["enrollid"];
//                 sendSms($smsMessage, $phoneNumber);
//             } else {
//                 // Fallback in case no user is found (optional, depending on your need)
//                 error_log("No user found for punching code: " . $record["enrollid"]);
//             }
//         }
//     }

//     if (!empty($sqlArray)) {
//         $sql2 = $sql . implode(",", $sqlArray);

//         try {
//             $stmt = $pdoConn->prepare($sql2);
//             $exec = $stmt->execute();
//         } catch (PDOException $e) {
//             // Log SQL error
//             error_log("SQL Error: " . $e->getMessage());
//             return '{"ret":"sendlog","result":false,"reason":"SQL Error"}';
//         }

//         if ($exec) {
//             $result = $sts
//                 ? '{"cmd":"getalllog","stn":false,"cloudtime":"' . date('Y-m-d H:i:s') . '"}'
//                 : '{"ret":"sendlog","result":true,"cloudtime":"' . date('Y-m-d H:i:s') . '"}';
//             return $result;
//         } else {
//             return '{"ret":"sendlog","result":false,"reason":"Execution failed"}';
//         }
//     }

//     return '{"ret":"sendlog","result":false,"reason":"No records to insert"}';
// }

function store($records, $deviceSerial, $sts = 0) {
    $pdoConn = getValidConnection();

    // Input validation
    if (empty($records) || !is_array($records)) {
        error_log("Invalid records input: " . print_r($records, true));
        return '{"ret":"sendlog","result":false,"reason":"Invalid records data"}';
    }

    error_log("Processing batch with " . count($records) . " records for device serial: " . $deviceSerial);

    // Step 1: Get device information and organization
    try {
        $stmt = $pdoConn->prepare("SELECT id, organization_id, device_name FROM devices WHERE serial_number = ? AND status = 'active'");
        $stmt->execute([$deviceSerial]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            error_log("Device not found or inactive: " . $deviceSerial);
            // Auto-register device to default organization for backward compatibility
            $stmt = $pdoConn->prepare("INSERT INTO devices (serial_number, organization_id, device_name, status) VALUES (?, 1, ?, 'active')");
            $stmt->execute([$deviceSerial, 'Device-' . $deviceSerial]);
            $device = ['id' => $pdoConn->lastInsertId(), 'organization_id' => 1, 'device_name' => 'Device-' . $deviceSerial];
            error_log("Auto-registered device $deviceSerial to default organization");
        }
        
        $organizationId = $device['organization_id'];
        error_log("Device belongs to organization ID: $organizationId");
        
    } catch (PDOException $e) {
        error_log("Database error getting device info: " . $e->getMessage());
        return '{"ret":"sendlog","result":false,"reason":"Device lookup failed"}';
    }

    $sql = 'INSERT INTO tblt_timesheet (punchingcode, date, time, Tid, device_serial, organization_id) VALUES ';
    $sqlArray = [];
    $processedInBatch = [];
    $duplicatesInBatch = 0;
    $duplicatesInDb = 0;
    $invalidRecords = 0;
    $unauthorizedAccess = 0;
    $successfulNotifications = 0;

    foreach ($records as $index => $record) {
        // Validate record structure
        if (!isset($record["enrollid"]) || !isset($record["time"])) {
            error_log("Invalid record structure at index $index: " . print_r($record, true));
            $invalidRecords++;
            continue;
        }

        // Validate time format
        if (empty($record["time"]) || !strtotime($record["time"])) {
            error_log("Invalid time format at index $index: " . $record["time"]);
            $invalidRecords++;
            continue;
        }

        // Create unique key for this record
        $recordKey = $record["enrollid"] . "_" . $record["time"] . "_" . $deviceSerial;
        
        // Skip if already processed in this batch
        if (isset($processedInBatch[$recordKey])) {
            error_log("Duplicate found in batch: " . $recordKey);
            $duplicatesInBatch++;
            continue;
        }
        
        $processedInBatch[$recordKey] = true;

        // Step 2: Validate user belongs to same organization as device
        try {
            $stmt = $pdoConn->prepare("
                SELECT u.id, u.name, u.email, u.phone_number, u.organization_id, u.status, o.status as organization_status
                FROM users u
                LEFT JOIN organizations o ON u.organization_id = o.id
                WHERE u.punching_code = ?
            ");
            $stmt->execute([$record["enrollid"]]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                error_log("User not found: " . $record["enrollid"]);
                $invalidRecords++;
                continue;
            }
            
            if ($user['status'] !== 'active') {
                error_log("Inactive user attempted access: " . $record["enrollid"]);
                $unauthorizedAccess++;
                continue;
            }
            
            if ($user['organization_id'] != $organizationId) {
                error_log("Cross-organization access denied - User org: {$user['organization_id']}, Device org: $organizationId, User: {$record['enrollid']}, Device: $deviceSerial");
                $unauthorizedAccess++;
                continue;
            }

            // Check if organization is active for notifications
            if ($user['organization_status'] !== 'active') {
                error_log("Organization inactive, skipping notifications for org: {$user['organization_id']}, User: {$record['enrollid']}");
                // Continue processing the record but skip notifications
            }

        } catch (PDOException $e) {
            error_log("Database error during user validation: " . $e->getMessage());
            $invalidRecords++;
            continue;
        }

        // Step 2.5: Check if user is assigned to this device
        try {
            // Get device ID for assignment check
            $stmt = $pdoConn->prepare("SELECT id FROM devices WHERE serial_number = ?");
            $stmt->execute([$deviceSerial]);
            $deviceId = $stmt->fetchColumn();

            if (!$deviceId) {
                error_log("Device not found in database: " . $deviceSerial);
                $invalidRecords++;
                continue;
            }

            // Check if user is assigned to this device
            if (!isUserAssignedToDevice($user['id'], $deviceId)) {
                error_log("User not assigned to device - User: {$record['enrollid']} ({$user['name']}), Device: $deviceSerial");
                $unauthorizedAccess++;
                continue;
            }

        } catch (PDOException $e) {
            error_log("Database error during device assignment check: " . $e->getMessage());
            $invalidRecords++;
            continue;
        }

        // Step 3: Check for duplicates in database
        try {
            $stmt = $pdoConn->prepare(
                "SELECT COUNT(*) FROM tblt_timesheet WHERE punchingcode = ? AND date = ? AND time = ? AND device_serial = ?"
            );
            $stmt->execute([
                $record["enrollid"],
                date("Y-m-d", strtotime($record["time"])),
                date("H:i:s", strtotime($record["time"])),
                $deviceSerial
            ]);

            if ($stmt->fetchColumn() > 0) {
                error_log("Duplicate found in database: " . $recordKey);
                $duplicatesInDb++;
                continue;
            }
        } catch (PDOException $e) {
            error_log("Database error during duplicate check: " . $e->getMessage());
            continue;
        }

        // Step 4: Log enhanced attendance punch
        $punchResult = logAttendancePunch(
            $user['id'],
            $record["enrollid"],
            $organizationId,
            $deviceSerial,
            $record["time"]
        );

        if ($punchResult['status'] === 'success') {
            // Also add to legacy format for backward compatibility
            $sqlArray[] = sprintf(
                '(%s, %s, %s, %s, %s, %s)',
                $pdoConn->quote($record["enrollid"]),
                $pdoConn->quote(date("Y-m-d", strtotime($record["time"]))),
                $pdoConn->quote(date("H:i:s", strtotime($record["time"]))),
                $pdoConn->quote($deviceSerial),
                $pdoConn->quote($deviceSerial),
                $pdoConn->quote($organizationId)
            );

            // Log punch details
            error_log("Enhanced punch logged - User: {$record['enrollid']}, Type: {$punchResult['punch_type']}, Late: " . ($punchResult['is_late'] ? 'Yes' : 'No') . ", Early: " . ($punchResult['is_early'] ? 'Yes' : 'No'));
        } else {
            error_log("Failed to log enhanced attendance for user: {$record['enrollid']}");
        }

        // Step 5: Send notifications to organization users only (if organization is active)
        try {
            if ($user && !empty($user['email']) && $user['organization_status'] === 'active') {
                $userName = !empty($user['name']) ? $user['name'] : 'Unknown User';
                $punchTime = date("Y-m-d H:i:s", strtotime($record["time"]));

                // Determine punch type and create appropriate message
                $punchType = $punchResult['punch_type'] ?? 'unknown';
                $punchTypeLabel = '';
                $punchAction = '';

                if ($punchType === 'in') {
                    $punchTypeLabel = 'PUNCH IN';
                    $punchAction = 'arrived at';
                } elseif ($punchType === 'out') {
                    $punchTypeLabel = 'PUNCH OUT';
                    $punchAction = 'left';
                } else {
                    $punchTypeLabel = 'ATTENDANCE';
                    $punchAction = 'was recorded at';
                }

                // Send email notification
                $subject = sprintf('Attendance Alert - %s', $punchTypeLabel);
                $emailMessage = sprintf(
                    'Dear Parent/Guardian, This is to notify you that %s (Card Number: %s) has %s %s on %s.',
                    $userName,
                    $record["enrollid"],
                    $punchAction,
                    $device['device_name'] ?? 'Device ' . $deviceSerial,
                    $punchTime
                );

                if (sendEmail($user['email'], $emailMessage, $subject)) {
                    $successfulNotifications++;
                    error_log("Email sent successfully to: " . $user['email']);
                } else {
                    error_log("Failed to send email to: " . $user['email']);
                }

                // Send SMS notification
                if (!empty($user['phone_number'])) {
                    $smsMessage = sprintf(
                        '%s Alert: %s (Card: %s) %s at %s',
                        $punchTypeLabel,
                        $userName,
                        $record["enrollid"],
                        $punchAction,
                        date("H:i", strtotime($record["time"]))
                    );

                    if (sendSms($smsMessage, $user['phone_number'])) {
                        error_log("SMS sent successfully to: " . $user['phone_number']);
                    } else {
                        error_log("Failed to send SMS to: " . $user['phone_number']);
                    }
                }
            } elseif ($user && $user['organization_status'] !== 'active') {
                error_log("Skipping notifications for inactive organization: " . $user['organization_id'] . ", User: " . $record["enrollid"]);
            }
        } catch (Exception $e) {
            error_log("Error during notification process: " . $e->getMessage());
        }
    }

    // Log processing summary
    error_log(sprintf(
        "Organization-aware batch processing - Total: %d, Valid: %d, Batch duplicates: %d, DB duplicates: %d, Invalid: %d, Unauthorized: %d, Notifications: %d, Organization: %d",
        count($records),
        count($sqlArray),
        $duplicatesInBatch,
        $duplicatesInDb,
        $invalidRecords,
        $unauthorizedAccess,
        $successfulNotifications,
        $organizationId
    ));

    // Step 6: Insert valid records
    if (!empty($sqlArray)) {
        $sql2 = $sql . implode(",", $sqlArray);

        try {
            $pdoConn->beginTransaction();
            
            $stmt = $pdoConn->prepare($sql2);
            $exec = $stmt->execute();
            
            if ($exec) {
                $insertedRows = $stmt->rowCount();
                $pdoConn->commit();
                
                error_log("Successfully inserted $insertedRows organization-aware records");
                
                $result = $sts
                    ? '{"cmd":"getalllog","stn":false,"cloudtime":"' . date('Y-m-d H:i:s') . '","inserted":' . $insertedRows . '}'
                    : '{"ret":"sendlog","result":true,"cloudtime":"' . date('Y-m-d H:i:s') . '","inserted":' . $insertedRows . ',"notifications":' . $successfulNotifications . ',"organization_id":' . $organizationId . '}';
                
                return $result;
            } else {
                $pdoConn->rollback();
                error_log("Database execution failed for batch insert");
                return '{"ret":"sendlog","result":false,"reason":"Database execution failed"}';
            }
        } catch (PDOException $e) {
            $pdoConn->rollback();
            error_log("SQL Error during batch insert: " . $e->getMessage());
            return '{"ret":"sendlog","result":false,"reason":"SQL Error: ' . addslashes($e->getMessage()) . '"}';
        }
    }

    // Handle case where no records were inserted
    $reasons = [];
    if ($duplicatesInBatch > 0) $reasons[] = "$duplicatesInBatch batch duplicates";
    if ($duplicatesInDb > 0) $reasons[] = "$duplicatesInDb database duplicates";
    if ($invalidRecords > 0) $reasons[] = "$invalidRecords invalid records";
    if ($unauthorizedAccess > 0) $reasons[] = "$unauthorizedAccess unauthorized access attempts";
    
    $reason = "No new records inserted";
    if (!empty($reasons)) $reason .= " (" . implode(", ", $reasons) . ")";

    error_log("Organization-aware batch completed with no insertions: " . $reason);
    
    return '{"ret":"sendlog","result":true,"cloudtime":"' . date('Y-m-d H:i:s') . '","message":"' . $reason . '","notifications":' . $successfulNotifications . ',"organization_id":' . $organizationId . '}';
}

function getOrCreateUser($punching_code, $name, $phone, $email, $organization_id = null, $device_id = null, $assigned_by = null, $user_type = 'student') {
    $pdoConn = getValidConnection();

    try {
        // Input validation
        if (empty($punching_code) || empty($name)) {
            return [
                "status" => "error",
                "message" => "Punching code and name are required",
                "user_id" => null
            ];
        }

        // Validate user_type
        if (!in_array($user_type, ['staff', 'student'])) {
            $user_type = 'student'; // Default to student if invalid
        }
        
        // Set default organization if not provided
        if ($organization_id === null) {
            // Get default organization ID
            $stmt = $pdoConn->prepare("SELECT id FROM organizations WHERE name = 'Default Organization' LIMIT 1");
            $stmt->execute();
            $organization_id = $stmt->fetchColumn();
            
            if (!$organization_id) {
                // Create default organization if it doesn't exist
                $stmt = $pdoConn->prepare("INSERT INTO organizations (name, description) VALUES (?, ?)");
                $stmt->execute(['Default Organization', 'Default organization for users']);
                $organization_id = $pdoConn->lastInsertId();
            }
        }
        
        // Validate organization exists
        $stmt = $pdoConn->prepare("SELECT name FROM organizations WHERE id = ?");
        $stmt->execute([$organization_id]);
        $orgName = $stmt->fetchColumn();
        
        if (!$orgName) {
            return [
                "status" => "error", 
                "message" => "Organization ID $organization_id does not exist",
                "user_id" => null
            ];
        }
        
        // Check if user exists in this organization
        $stmt = $pdoConn->prepare("SELECT id, name, email, phone_number, user_type, organization_id, status FROM users WHERE punching_code = ? AND organization_id = ?");
        $stmt->execute([$punching_code, $organization_id]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            // User exists in this organization - update other details if needed
            $updates = [];
            $params = [];
            
            // Update other fields if they're different and not empty
            if (!empty($name) && $existingUser['name'] !== $name) {
                $updates[] = "name = ?";
                $params[] = $name;
            }

            if (!empty($email) && $existingUser['email'] !== $email) {
                $updates[] = "email = ?";
                $params[] = $email;
            }

            if (!empty($phone) && $existingUser['phone_number'] !== $phone) {
                $updates[] = "phone_number = ?";
                $params[] = $phone;
            }

            if (!empty($user_type) && $existingUser['user_type'] !== $user_type) {
                $updates[] = "user_type = ?";
                $params[] = $user_type;
            }

            // Ensure user is active
            if ($existingUser['status'] !== 'active') {
                $updates[] = "status = ?";
                $params[] = 'active';
            }
            
            $updates[] = "updated_at = NOW()";
            
            // Perform update if needed
            if (count($params) > 0) {
                $params[] = $punching_code; // For WHERE clause
                $params[] = $organization_id; // For WHERE clause
                $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE punching_code = ? AND organization_id = ?";
                $stmt = $pdoConn->prepare($sql);
                $stmt->execute($params);
                
                $result = [
                    "status" => "updated",
                    "message" => "User updated with new information",
                    "user_id" => $existingUser['id'],
                    "organization_id" => $organization_id,
                    "organization_name" => $orgName,
                    "changes_made" => count($params) - 1
                ];

                // Assign device if device_id and organization_id are provided
                if ($device_id !== null && $assigned_by !== null && $organization_id !== null) {
                    // Validate that device belongs to the same organization
                    $stmt = $pdoConn->prepare("SELECT organization_id FROM devices WHERE id = ?");
                    $stmt->execute([$device_id]);
                    $deviceOrgId = $stmt->fetchColumn();

                    if ($deviceOrgId && $deviceOrgId == $organization_id) {
                        $assignmentResult = assignUserToDevice($existingUser['id'], $device_id, $assigned_by);
                        $result['device_assignment'] = $assignmentResult;
                    } else {
                        $result['device_assignment'] = [
                            'status' => 'error',
                            'message' => 'Device does not belong to the user\'s organization'
                        ];
                    }
                }

                return $result;
            } else {
                $result = [
                    "status" => "exists",
                    "message" => "User already exists with current information",
                    "user_id" => $existingUser['id'],
                    "organization_id" => $existingUser['organization_id'],
                    "organization_name" => $orgName
                ];

                // Assign device if device_id and organization_id are provided
                if ($device_id !== null && $assigned_by !== null && $organization_id !== null) {
                    // Validate that device belongs to the same organization
                    $stmt = $pdoConn->prepare("SELECT organization_id FROM devices WHERE id = ?");
                    $stmt->execute([$device_id]);
                    $deviceOrgId = $stmt->fetchColumn();

                    if ($deviceOrgId && $deviceOrgId == $organization_id) {
                        $assignmentResult = assignUserToDevice($existingUser['id'], $device_id, $assigned_by);
                        $result['device_assignment'] = $assignmentResult;
                    } else {
                        $result['device_assignment'] = [
                            'status' => 'error',
                            'message' => 'Device does not belong to the user\'s organization'
                        ];
                    }
                }

                return $result;
            }
        }

        // User doesn't exist - create new user
        $stmt = $pdoConn->prepare(
            "INSERT INTO users (punching_code, name, email, phone_number, user_type, organization_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())"
        );

        $stmt->execute([$punching_code, $name, $email, $phone, $user_type, $organization_id]);
        $newUserId = $pdoConn->lastInsertId();
        
        error_log("Created new user: $punching_code in organization $organization_id ($orgName)");

        $result = [
            "status" => "created",
            "message" => "New user created successfully",
            "user_id" => $newUserId,
            "organization_id" => $organization_id,
            "organization_name" => $orgName
        ];

        // Assign device if device_id and organization_id are provided
        if ($device_id !== null && $assigned_by !== null && $organization_id !== null) {
            // Validate that device belongs to the same organization
            $stmt = $pdoConn->prepare("SELECT organization_id FROM devices WHERE id = ?");
            $stmt->execute([$device_id]);
            $deviceOrgId = $stmt->fetchColumn();

            if ($deviceOrgId && $deviceOrgId == $organization_id) {
                $assignmentResult = assignUserToDevice($newUserId, $device_id, $assigned_by);
                $result['device_assignment'] = $assignmentResult;
            } else {
                $result['device_assignment'] = [
                    'status' => 'error',
                    'message' => 'Device does not belong to the user\'s organization'
                ];
            }
        }

        return $result;
        
    } catch (PDOException $e) {
        error_log("Database error in getOrCreateUser: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error: " . $e->getMessage(),
            "user_id" => null
        ];
    } catch (Exception $e) {
        error_log("General error in getOrCreateUser: " . $e->getMessage());
        return [
            "status" => "error", 
            "message" => "Error: " . $e->getMessage(),
            "user_id" => null
        ];
    }
}

// Enhanced user management functions
function activateUser($punching_code, $organization_id) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE punching_code = ? AND organization_id = ?");
        $stmt->execute([$punching_code, $organization_id]);

        if ($stmt->rowCount() > 0) {
            error_log("Activated user: $punching_code in organization: $organization_id");
            return ["status" => "success", "message" => "User activated successfully"];
        } else {
            return ["status" => "error", "message" => "User not found in the specified organization"];
        }
    } catch (PDOException $e) {
        error_log("Error activating user: " . $e->getMessage());
        return ["status" => "error", "message" => "Database error"];
    }
}

function deactivateUser($punching_code, $organization_id) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE punching_code = ? AND organization_id = ?");
        $stmt->execute([$punching_code, $organization_id]);

        if ($stmt->rowCount() > 0) {
            error_log("Deactivated user: $punching_code in organization: $organization_id");
            return ["status" => "success", "message" => "User deactivated successfully"];
        } else {
            return ["status" => "error", "message" => "User not found in the specified organization"];
        }
    } catch (PDOException $e) {
        error_log("Error deactivating user: " . $e->getMessage());
        return ["status" => "error", "message" => "Database error"];
    }
}

function deleteUser($punching_code, $organization_id) {
    $pdoConn = getValidConnection();

    try {
        // Start transaction to ensure all deletions succeed or roll back
        $pdoConn->beginTransaction();

        // Get user ID first
        $stmt = $pdoConn->prepare("SELECT id, name FROM users WHERE punching_code = ? AND organization_id = ?");
        $stmt->execute([$punching_code, $organization_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ["status" => "error", "message" => "User not found in the specified organization"];
        }

        $userId = $user['id'];
        $userName = $user['name'];

        // Delete user-device assignments
        $stmt = $pdoConn->prepare("DELETE FROM user_device_assignments WHERE user_id = ?");
        $stmt->execute([$userId]);
        $deviceAssignmentsDeleted = $stmt->rowCount();

        // Delete attendance logs for this user
        $stmt = $pdoConn->prepare("DELETE FROM attendance_logs WHERE punching_code = ?");
        $stmt->execute([$punching_code]);
        $attendanceLogsDeleted = $stmt->rowCount();

        // Delete daily attendance records
        $stmt = $pdoConn->prepare("DELETE FROM daily_attendance WHERE user_id = ?");
        $stmt->execute([$userId]);
        $dailyAttendanceDeleted = $stmt->rowCount();

        // Delete absence records
        $stmt = $pdoConn->prepare("DELETE FROM absence_records WHERE user_id = ?");
        $stmt->execute([$userId]);
        $absenceRecordsDeleted = $stmt->rowCount();

        // Finally, delete the user
        $stmt = $pdoConn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);

        // Commit transaction
        $pdoConn->commit();

        error_log("Deleted user: $punching_code (ID: $userId, Name: $userName) from organization: $organization_id");
        error_log("Cascade deletions - Device assignments: $deviceAssignmentsDeleted, Attendance logs: $attendanceLogsDeleted, Daily attendance: $dailyAttendanceDeleted, Absence records: $absenceRecordsDeleted");

        return [
            "status" => "success",
            "message" => "User deleted successfully",
            "details" => [
                "user_id" => $userId,
                "punching_code" => $punching_code,
                "name" => $userName,
                "device_assignments_deleted" => $deviceAssignmentsDeleted,
                "attendance_logs_deleted" => $attendanceLogsDeleted,
                "daily_attendance_deleted" => $dailyAttendanceDeleted,
                "absence_records_deleted" => $absenceRecordsDeleted
            ]
        ];
    } catch (PDOException $e) {
        // Rollback on error
        if ($pdoConn->inTransaction()) {
            $pdoConn->rollBack();
        }
        error_log("Error deleting user: " . $e->getMessage());
        return ["status" => "error", "message" => "Database error: " . $e->getMessage()];
    }
}

function bulkCreateUsers($users_data, $organization_id = null, $assigned_by = null) {
    $pdoConn = getValidConnection();
    
    try {
        $results = [
            "created" => 0,
            "updated" => 0,
            "errors" => 0,
            "details" => []
        ];
        
        $pdoConn->beginTransaction();
        
        foreach ($users_data as $index => $userData) {
            // Use individual row organization_id if provided, otherwise use the default
            $userOrgId = $userData['organization_id'] ?? $organization_id;

            $result = getOrCreateUser(
                $userData['punching_code'] ?? null,
                $userData['name'] ?? null,
                $userData['phone'] ?? null,
                $userData['email'] ?? null,
                $userOrgId,
                $userData['device_id'] ?? null,  // Optional device assignment for bulk creation
                $assigned_by,
                $userData['user_type'] ?? 'student'  // Default to student if not provided
            );
            
            switch ($result['status']) {
                case 'created':
                    $results['created']++;
                    break;
                case 'updated':
                    $results['updated']++;
                    break;
                case 'exists':
                    // Count as updated for reporting
                    $results['updated']++;
                    break;
                case 'error':
                    $results['errors']++;
                    $results['details'][] = "Row $index: " . $result['message'];
                    break;
            }
        }
        
        $pdoConn->commit();
        
        error_log("Bulk user creation completed - Created: {$results['created']}, Updated: {$results['updated']}, Errors: {$results['errors']}");
        
        return [
            "status" => "completed",
            "message" => "Bulk operation completed",
            "results" => $results
        ];
        
    } catch (Exception $e) {
        $pdoConn->rollback();
        error_log("Bulk user creation failed: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Bulk operation failed: " . $e->getMessage()
        ];
    }
}

function registerDevice($serial_number, $organization_id, $device_name = null, $device_model = null, $ip_address = null) {
    $pdoConn = getValidConnection();
    
    try {
        // Validate organization exists
        $stmt = $pdoConn->prepare("SELECT id FROM organizations WHERE id = ?");
        $stmt->execute([$organization_id]);
        if (!$stmt->fetch()) {
            return [
                "status" => "error",
                "message" => "Organization not found"
            ];
        }
        
        // Set defaults if not provided
        $device_name = $device_name ?: 'Device-' . $serial_number;
        
        // Check if device already exists
        $stmt = $pdoConn->prepare("SELECT id, organization_id, status FROM devices WHERE serial_number = ?");
        $stmt->execute([$serial_number]);
        $existingDevice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingDevice) {
            // Update existing device
            $stmt = $pdoConn->prepare("
                UPDATE devices 
                SET organization_id = ?, device_name = ?, device_model = ?, 
                    ip_address = ?, status = 'active', updated_at = CURRENT_TIMESTAMP
                WHERE serial_number = ?
            ");
            $stmt->execute([$organization_id, $device_name, $device_model, $ip_address, $serial_number]);
            
            error_log("Updated device $serial_number assignment to organization $organization_id");
            
            return [
                "status" => "success",
                "message" => "Device updated successfully",
                "action" => "updated",
                "device_id" => $existingDevice['id'],
                "serial_number" => $serial_number,
                "organization_id" => $organization_id
            ];
        } else {
            // Create new device
            $stmt = $pdoConn->prepare("
                INSERT INTO devices (serial_number, organization_id, device_name, device_model, ip_address, status) 
                VALUES (?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$serial_number, $organization_id, $device_name, $device_model, $ip_address]);
            
            $device_id = $pdoConn->lastInsertId();
            
            error_log("Registered new device $serial_number to organization $organization_id");
            
            return [
                "status" => "success",
                "message" => "Device registered successfully",
                "action" => "created",
                "device_id" => $device_id,
                "serial_number" => $serial_number,
                "organization_id" => $organization_id
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Device registration error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error during device registration"
        ];
    }
}

function assignDeviceToOrganization($serial_number, $organization_id) {
    $pdoConn = getValidConnection();
    
    try {
        // Validate organization exists
        $stmt = $pdoConn->prepare("SELECT id, name FROM organizations WHERE id = ?");
        $stmt->execute([$organization_id]);
        $organization = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$organization) {
            return [
                "status" => "error",
                "message" => "Organization not found"
            ];
        }
        
        // Check if device exists
        $stmt = $pdoConn->prepare("SELECT id, organization_id FROM devices WHERE serial_number = ?");
        $stmt->execute([$serial_number]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            return [
                "status" => "error",
                "message" => "Device not found"
            ];
        }
        
        // Update device organization
        $stmt = $pdoConn->prepare("
            UPDATE devices 
            SET organization_id = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE serial_number = ?
        ");
        $stmt->execute([$organization_id, $serial_number]);
        
        error_log("Assigned device $serial_number to organization {$organization['name']} (ID: $organization_id)");
        
        return [
            "status" => "success",
            "message" => "Device assigned successfully",
            "device_id" => $device['id'],
            "serial_number" => $serial_number,
            "previous_organization_id" => $device['organization_id'],
            "new_organization_id" => $organization_id,
            "organization_name" => $organization['name']
        ];
        
    } catch (PDOException $e) {
        error_log("Device assignment error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error during device assignment"
        ];
    }
}

function getDevicesByOrganization($organization_id) {
    $pdoConn = getValidConnection();
    
    try {
        $stmt = $pdoConn->prepare("
            SELECT d.*, o.name as organization_name, o.status as organization_status
            FROM devices d
            LEFT JOIN organizations o ON d.organization_id = o.id
            WHERE d.organization_id = ?
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$organization_id]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            "status" => "success",
            "message" => "Devices retrieved successfully",
            "organization_id" => $organization_id,
            "device_count" => count($devices),
            "devices" => $devices
        ];
        
    } catch (PDOException $e) {
        error_log("Get devices error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error retrieving devices"
        ];
    }
}

function updateDeviceStatus($serial_number, $updateData) {
    $pdoConn = getValidConnection();

    try {
        // Check if device exists
        $stmt = $pdoConn->prepare("SELECT * FROM devices WHERE serial_number = ?");
        $stmt->execute([$serial_number]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$device) {
            return [
                "status" => "error",
                "message" => "Device not found"
            ];
        }

        // Define allowed fields for update
        $allowedFields = ['device_name', 'device_model', 'ip_address', 'status', 'organization_id'];
        $updateFields = [];
        $updateValues = [];
        $previousValues = [];

        // Build dynamic update query
        foreach ($updateData as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updateFields[] = "$field = ?";
                $updateValues[] = $value;
                $previousValues[$field] = $device[$field];
            }
        }

        if (empty($updateFields)) {
            return [
                "status" => "error",
                "message" => "No valid fields provided to update. Allowed fields: " . implode(', ', $allowedFields)
            ];
        }

        // Add updated_at timestamp
        $updateFields[] = "updated_at = CURRENT_TIMESTAMP";

        // Execute update
        $sql = "UPDATE devices SET " . implode(', ', $updateFields) . " WHERE serial_number = ?";
        $updateValues[] = $serial_number;

        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($updateValues);

        error_log("Updated device $serial_number fields: " . implode(', ', array_keys($previousValues)));

        return [
            "status" => "success",
            "message" => "Device updated successfully",
            "device_id" => $device['id'],
            "serial_number" => $serial_number,
            "updated_fields" => array_keys($previousValues),
            "previous_values" => $previousValues,
            "new_values" => array_intersect_key($updateData, array_flip($allowedFields))
        ];

    } catch (PDOException $e) {
        error_log("Device update error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error updating device"
        ];
    }
}

function getUsersByOrganization($organization_id, $user_name = null) {
    $pdoConn = getValidConnection();

    try {
        $sql = "
            SELECT u.*, o.name as organization_name, o.status as organization_status
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE u.organization_id = ?
        ";

        $params = [$organization_id];

        if ($user_name) {
            $sql .= " AND u.name LIKE ?";
            $params[] = '%' . $user_name . '%';
        }

        $sql .= " ORDER BY u.created_at DESC";

        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            "status" => "success",
            "message" => "Users retrieved successfully",
            "organization_id" => $organization_id,
            "filters" => [
                "user_name" => $user_name
            ],
            "user_count" => count($users),
            "users" => $users
        ];
        
    } catch (PDOException $e) {
        error_log("Get users by organization error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error retrieving users"
        ];
    }
}

function searchUsers($search = null, $organization_id = null, $user_type = null, $status = null) {
    $pdoConn = getValidConnection();

    try {
        $sql = "
            SELECT u.*, o.name as organization_name, o.status as organization_status
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE 1=1
        ";

        $params = [];

        // Search by punching code or name
        if ($search) {
            $sql .= " AND (u.punching_code LIKE ? OR u.name LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        if ($organization_id) {
            $sql .= " AND u.organization_id = ?";
            $params[] = $organization_id;
        }

        if ($user_type) {
            $sql .= " AND u.user_type = ?";
            $params[] = $user_type;
        }

        if ($status) {
            $sql .= " AND u.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY u.created_at DESC";

        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            "status" => "success",
            "message" => "Users retrieved successfully",
            "filters" => [
                "search" => $search,
                "organization_id" => $organization_id,
                "user_type" => $user_type,
                "status" => $status
            ],
            "user_count" => count($users),
            "users" => $users
        ];

    } catch (PDOException $e) {
        error_log("Search users error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error searching users"
        ];
    }
}


function getAllLogs($date_from = null, $date_to = null, $user_name = null) {
	$pdoConn = getValidConnection();

	try {
        $sql = 'SELECT t.timesheetid, t.punchingcode, t.date, t.time, t.Tid,
                       u.id as user_id, t.organization_id, u.name, u.user_type, o.name as organization_name, o.status as organization_status,
                       d.device_name, d.serial_number,
                       al.punch_type, al.is_late, al.is_early, al.is_auto_generated, al.notes,
                       da.punch_in_time, da.punch_out_time, da.total_hours, da.status as daily_status,
                       da.late_minutes, da.early_out_minutes, da.overtime_hours
                FROM tblt_timesheet t
                LEFT JOIN users u ON t.punchingcode = u.punching_code AND t.organization_id = u.organization_id
                LEFT JOIN organizations o ON t.organization_id = o.id
                LEFT JOIN devices d ON t.device_serial = d.serial_number
                LEFT JOIN attendance_logs al ON t.punchingcode = al.punching_code
                    AND t.date = al.punch_date AND t.time = al.punch_time
                LEFT JOIN daily_attendance da ON u.id = da.user_id AND t.date = da.attendance_date
                WHERE 1=1';

        $params = [];

        if ($date_from) {
            $sql .= ' AND t.date >= ?';
            $params[] = $date_from;
        }

        if ($date_to) {
            $sql .= ' AND t.date <= ?';
            $params[] = $date_to;
        }

        if ($user_name) {
            $sql .= ' AND u.name LIKE ?';
            $params[] = '%' . $user_name . '%';
        }

        $sql .= ' ORDER BY t.date DESC, t.time DESC';

        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($params);

        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Return the logs in JSON format
        return json_encode([
            'status' => 'success',
            'filters' => [
                'date_from' => $date_from,
                'date_to' => $date_to,
                'user_name' => $user_name
            ],
            'logs' => $logs,
            'total_count' => count($logs)
        ]);
    } catch (PDOException $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function getLogsByPunchingCode($punchingCode, $organization_id = null, $date_from = null, $date_to = null) {
    $pdoConn = getValidConnection();
    try {
        $sql = "
            SELECT t.*, u.name, o.name as organization_name, o.status as organization_status,
                   d.device_name, d.serial_number,
                   al.punch_type, al.is_late, al.is_early, al.is_auto_generated, al.notes,
                   da.punch_in_time, da.punch_out_time, da.total_hours, da.status as daily_status,
                   da.late_minutes, da.early_out_minutes, da.overtime_hours
            FROM tblt_timesheet t
            LEFT JOIN users u ON t.punchingcode = u.punching_code AND t.organization_id = u.organization_id
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN devices d ON t.device_serial = d.serial_number
            LEFT JOIN attendance_logs al ON t.punchingcode = al.punching_code
                AND t.date = al.punch_date AND t.time = al.punch_time
            LEFT JOIN daily_attendance da ON u.id = da.user_id AND t.date = da.attendance_date
            WHERE t.punchingcode = ?
        ";

        $params = [$punchingCode];

        if ($organization_id) {
            $sql .= " AND t.organization_id = ?";
            $params[] = $organization_id;
        }

        if ($date_from) {
            $sql .= " AND t.date >= ?";
            $params[] = $date_from;
        }

        if ($date_to) {
            $sql .= " AND t.date <= ?";
            $params[] = $date_to;
        }

        $sql .= " ORDER BY t.date DESC, t.time DESC";

        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return json_encode([
            'status' => 'success',
            'filters' => [
                'punching_code' => $punchingCode,
                'organization_id' => $organization_id,
                'date_from' => $date_from,
                'date_to' => $date_to
            ],
            'logs' => $logs,
            'total_count' => count($logs)
        ]);
    } catch (PDOException $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function getLogsByOrganization($organization_id, $date_from = null, $date_to = null, $user_name = null, $limit = 10, $page = 1) {
    $pdoConn = getValidConnection();

    try {
        // Calculate offset from page number
        $page = max(1, (int)$page); // Ensure page is at least 1
        $offset = ($page - 1) * $limit;

        // Build the WHERE clause for both count and data queries
        $whereClause = "WHERE t.organization_id = ?";
        $params = [$organization_id];
        $countParams = [$organization_id];

        if ($date_from) {
            $whereClause .= " AND t.date >= ?";
            $params[] = $date_from;
            $countParams[] = $date_from;
        }

        if ($date_to) {
            $whereClause .= " AND t.date <= ?";
            $params[] = $date_to;
            $countParams[] = $date_to;
        }

        if ($user_name) {
            $whereClause .= " AND u.name LIKE ?";
            $searchParam = '%' . $user_name . '%';
            $params[] = $searchParam;
            $countParams[] = $searchParam;
        }

        // Get total count for pagination
        $countSql = "
            SELECT COUNT(*) as total
            FROM tblt_timesheet t
            LEFT JOIN users u ON t.punchingcode = u.punching_code AND t.organization_id = u.organization_id
            $whereClause
        ";

        $countStmt = $pdoConn->prepare($countSql);
        $countStmt->execute($countParams);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Calculate total pages
        $totalPages = (int)ceil($totalCount / $limit);

        // Get paginated data
        $sql = "
            SELECT t.*, u.name, u.email, u.phone_number, u.user_type, o.name as organization_name, o.status as organization_status,
                   d.device_name, d.serial_number,
                   al.punch_type, al.is_late, al.is_early, al.is_auto_generated, al.notes,
                   da.punch_in_time, da.punch_out_time, da.total_hours, da.status as daily_status,
                   da.late_minutes, da.early_out_minutes, da.overtime_hours
            FROM tblt_timesheet t
            LEFT JOIN users u ON t.punchingcode = u.punching_code AND t.organization_id = u.organization_id
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN devices d ON t.device_serial = d.serial_number
            LEFT JOIN attendance_logs al ON t.punchingcode = al.punching_code
                AND t.date = al.punch_date AND t.time = al.punch_time
            LEFT JOIN daily_attendance da ON u.id = da.user_id AND t.date = da.attendance_date
            $whereClause
            ORDER BY t.date DESC, t.time DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ";

        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get organization details
        $orgStmt = $pdoConn->prepare("SELECT name FROM organizations WHERE id = ?");
        $orgStmt->execute([$organization_id]);
        $organization = $orgStmt->fetch(PDO::FETCH_ASSOC);

        return [
            "status" => "success",
            "message" => "Logs retrieved successfully",
            "organization_id" => $organization_id,
            "organization_name" => $organization['name'] ?? 'Unknown',
            "filters" => [
                "date_from" => $date_from,
                "date_to" => $date_to,
                "user_name" => $user_name
            ],
            "pagination" => [
                "page" => $page,
                "limit" => (int)$limit,
                "total_count" => (int)$totalCount,
                "total_pages" => $totalPages,
                "current_count" => count($logs),
                "has_next" => $page < $totalPages,
                "has_previous" => $page > 1
            ],
            "logs" => $logs
        ];

    } catch (PDOException $e) {
        error_log("Get logs by organization error: " . $e->getMessage());
        error_log("Organization ID: " . $organization_id);
        error_log("Filters: " . json_encode(['date_from' => $date_from, 'date_to' => $date_to, 'user_name' => $user_name, 'limit' => $limit, 'page' => $page]));
        return [
            "status" => "error",
            "message" => "Database error retrieving logs"
        ];
    }
}

function exportLogsToExcel() {
    $pdoConn = getValidConnection();

    // Step 1: Fetch data from the database with all fields (same as getAllLogs)
    $sql = 'SELECT t.timesheetid, t.punchingcode, t.date, t.time, t.Tid,
                   u.id as user_id, t.organization_id, u.name, u.user_type, o.name as organization_name, o.status as organization_status,
                   d.device_name, d.serial_number,
                   al.punch_type, al.is_late, al.is_early, al.is_auto_generated, al.notes,
                   da.punch_in_time, da.punch_out_time, da.total_hours, da.status as daily_status,
                   da.late_minutes, da.early_out_minutes, da.overtime_hours
            FROM tblt_timesheet t
            LEFT JOIN users u ON t.punchingcode = u.punching_code AND t.organization_id = u.organization_id
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN devices d ON t.device_serial = d.serial_number
            LEFT JOIN attendance_logs al ON t.punchingcode = al.punching_code
                AND t.date = al.punch_date AND t.time = al.punch_time
            LEFT JOIN daily_attendance da ON u.id = da.user_id AND t.date = da.attendance_date
            ORDER BY t.date DESC, t.time DESC';

    $stmt = $pdoConn->query($sql);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Step 2: Initialize Spreadsheet
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Step 3: Populate spreadsheet headers
    $sheet->setCellValue('A1', 'Punching Code');
    $sheet->setCellValue('B1', 'Name');
    $sheet->setCellValue('C1', 'Date');
    $sheet->setCellValue('D1', 'Punch In Time');
    $sheet->setCellValue('E1', 'Punch Out Time');
    $sheet->setCellValue('F1', 'Device Name');
    $sheet->setCellValue('G1', 'Status');

    // Step 4: Populate spreadsheet data
    $row = 2;
    foreach ($logs as $log) {
        $sheet->setCellValue('A' . $row, $log['punchingcode'] ?? '');
        $sheet->setCellValue('B' . $row, $log['name'] ?? '');
        $sheet->setCellValue('C' . $row, $log['date'] ?? '');
        $sheet->setCellValue('D' . $row, $log['punch_in_time'] ?? '');
        $sheet->setCellValue('E' . $row, $log['punch_out_time'] ?? '');
        $sheet->setCellValue('F' . $row, $log['device_name'] ?? '');
        $sheet->setCellValue('G' . $row, $log['daily_status'] ?? '');
        $row++;
    }

    // Step 5: Set headers for file download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Logs.xlsx"');
    header('Cache-Control: max-age=0');

    // Step 6: Write to output
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// Organization Management Functions
function createOrganization($name, $address, $contact_person, $email, $phone, $description = null) {
    $pdoConn = getValidConnection();
    
    try {
        // Input validation
        if (empty($name) || empty($contact_person) || empty($email) || empty($phone)) {
            return [
                "status" => "error",
                "message" => "Name, contact person, email, and phone are required"
            ];
        }
        
        // Check if organization with same name already exists
        $stmt = $pdoConn->prepare("SELECT id FROM organizations WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            return [
                "status" => "error",
                "message" => "Organization with this name already exists"
            ];
        }
        
        // Create new organization
        $stmt = $pdoConn->prepare("
            INSERT INTO organizations (name, description, address, contact_person, email, phone) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$name, $description, $address, $contact_person, $email, $phone]);
        $organization_id = $pdoConn->lastInsertId();
        
        error_log("Created new organization: $name (ID: $organization_id)");
        
        return [
            "status" => "success",
            "message" => "Organization created successfully",
            "organization_id" => $organization_id,
            "name" => $name
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in createOrganization: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error: " . $e->getMessage()
        ];
    }
}

function getOrganization($organization_id) {
    $pdoConn = getValidConnection();
    
    try {
        // Get organization details
        $stmt = $pdoConn->prepare("
            SELECT id, name, description, address, contact_person, email, phone,
                   status, created_at as dateJoined, updated_at
            FROM organizations
            WHERE id = ?
        ");
        $stmt->execute([$organization_id]);
        $organization = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$organization) {
            return [
                "status" => "error",
                "message" => "Organization not found"
            ];
        }
        
        // Get devices assigned to this organization
        $stmt = $pdoConn->prepare("
            SELECT id, serial_number, device_name, device_model, 
                   ip_address, status, created_at
            FROM devices 
            WHERE organization_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$organization_id]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get users under this organization
        $stmt = $pdoConn->prepare("
            SELECT id, punching_code, name, email, phone_number, 
                   status, created_at
            FROM users 
            WHERE organization_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$organization_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            "status" => "success",
            "message" => "Organization retrieved successfully",
            "organization" => [
                "id" => $organization['id'],
                "name" => $organization['name'],
                "description" => $organization['description'],
                "address" => $organization['address'],
                "contact_person" => $organization['contact_person'],
                "email" => $organization['email'],
                "phone" => $organization['phone'],
                "dateJoined" => $organization['dateJoined'],
                "updated_at" => $organization['updated_at'],
                "devices_count" => count($devices),
                "users_count" => count($users),
                "devices" => $devices,
                "users" => $users
            ]
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in getOrganization: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error retrieving organization"
        ];
    }
}

function getAllOrganizations() {
    $pdoConn = getValidConnection();
    
    try {
        // Get all organizations with summary data
        $stmt = $pdoConn->prepare("
            SELECT o.id, o.name, o.description, o.address, o.contact_person, o.email, o.phone,
                   o.status, o.created_at as dateJoined, o.updated_at,
                   COUNT(DISTINCT d.id) as device_count,
                   COUNT(DISTINCT u.id) as user_count
            FROM organizations o
            LEFT JOIN devices d ON o.id = d.organization_id
            LEFT JOIN users u ON o.id = u.organization_id
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ");
        $stmt->execute();
        $organizations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            "status" => "success",
            "message" => "Organizations retrieved successfully",
            "total_count" => count($organizations),
            "organizations" => $organizations
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in getAllOrganizations: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error retrieving organizations"
        ];
    }
}

function updateOrganization($organization_id, $name = null, $address = null, $contact_person = null, $email = null, $phone = null, $description = null, $status = null) {
    $pdoConn = getValidConnection();
    
    try {
        // Check if organization exists
        $stmt = $pdoConn->prepare("SELECT id, name FROM organizations WHERE id = ?");
        $stmt->execute([$organization_id]);
        $existingOrg = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingOrg) {
            return [
                "status" => "error",
                "message" => "Organization not found"
            ];
        }
        
        // Build update query dynamically based on provided parameters
        $updates = [];
        $params = [];
        
        if (!empty($name)) {
            // Check if another organization has this name
            $stmt = $pdoConn->prepare("SELECT id FROM organizations WHERE name = ? AND id != ?");
            $stmt->execute([$name, $organization_id]);
            if ($stmt->fetch()) {
                return [
                    "status" => "error",
                    "message" => "Another organization with this name already exists"
                ];
            }
            $updates[] = "name = ?";
            $params[] = $name;
        }
        
        if ($address !== null) {
            $updates[] = "address = ?";
            $params[] = $address;
        }
        
        if (!empty($contact_person)) {
            $updates[] = "contact_person = ?";
            $params[] = $contact_person;
        }
        
        if (!empty($email)) {
            $updates[] = "email = ?";
            $params[] = $email;
        }
        
        if (!empty($phone)) {
            $updates[] = "phone = ?";
            $params[] = $phone;
        }
        
        if ($description !== null) {
            $updates[] = "description = ?";
            $params[] = $description;
        }

        if ($status !== null) {
            // Validate status
            if (!in_array($status, ['active', 'inactive'])) {
                return [
                    "status" => "error",
                    "message" => "Invalid status. Must be: active or inactive"
                ];
            }
            $updates[] = "status = ?";
            $params[] = $status;
        }

        if (empty($updates)) {
            return [
                "status" => "success",
                "message" => "No changes provided"
            ];
        }
        
        // Add updated_at timestamp
        $updates[] = "updated_at = NOW()";
        $params[] = $organization_id; // For WHERE clause
        
        $sql = "UPDATE organizations SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($params);
        
        error_log("Updated organization ID $organization_id with " . count($updates) . " changes");
        
        return [
            "status" => "success",
            "message" => "Organization updated successfully",
            "organization_id" => $organization_id,
            "changes_made" => count($updates) - 1 // Exclude updated_at from count
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in updateOrganization: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error updating organization"
        ];
    }
}

function getDeviceDetails($serial_number) {
    $pdoConn = getValidConnection();
    
    try {
        // Get device details with organization info
        $stmt = $pdoConn->prepare("
            SELECT d.*, o.name as organization_name, o.status as organization_status
            FROM devices d
            LEFT JOIN organizations o ON d.organization_id = o.id
            WHERE d.serial_number = ?
        ");
        $stmt->execute([$serial_number]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            return [
                "status" => "error",
                "message" => "Device not found"
            ];
        }
        
        return [
            "status" => "success",
            "message" => "Device retrieved successfully",
            "device" => $device
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in getDeviceDetails: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error retrieving device details"
        ];
    }
}

function getLogsByDevice($device_serial, $date_from = null, $date_to = null, $user_name = null) {
    $pdoConn = getValidConnection();

    try {
        $sql = 'SELECT t.timesheetid, t.punchingcode, t.date, t.time, t.Tid,
                       u.id as user_id, t.organization_id, u.name, o.name as organization_name, o.status as organization_status,
                       d.device_name, d.serial_number,
                       al.punch_type, al.is_late, al.is_early, al.is_auto_generated, al.notes,
                       da.punch_in_time, da.punch_out_time, da.total_hours, da.status as daily_status,
                       da.late_minutes, da.early_out_minutes, da.overtime_hours
                FROM tblt_timesheet t
                LEFT JOIN users u ON t.punchingcode = u.punching_code AND t.organization_id = u.organization_id
                LEFT JOIN organizations o ON t.organization_id = o.id
                LEFT JOIN devices d ON t.device_serial = d.serial_number
                LEFT JOIN attendance_logs al ON t.punchingcode = al.punching_code
                    AND t.date = al.punch_date AND t.time = al.punch_time
                LEFT JOIN daily_attendance da ON u.id = da.user_id AND t.date = da.attendance_date
                WHERE t.Tid = ?';

        $params = [$device_serial];

        if ($date_from) {
            $sql .= ' AND t.date >= ?';
            $params[] = $date_from;
        }

        if ($date_to) {
            $sql .= ' AND t.date <= ?';
            $params[] = $date_to;
        }

        if ($user_name) {
            $sql .= ' AND u.name LIKE ?';
            $params[] = '%' . $user_name . '%';
        }

        $sql .= ' ORDER BY t.date DESC, t.time DESC';

        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            "status" => "success",
            "message" => "Logs retrieved successfully",
            "device_serial" => $device_serial,
            "filters" => [
                "date_from" => $date_from,
                "date_to" => $date_to,
                "user_name" => $user_name
            ],
            "total_count" => count($logs),
            "logs" => $logs
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in getLogsByDevice: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error retrieving logs"
        ];
    }
}

// ===============================
// AUTHENTICATION FUNCTIONS
// ===============================

function loginAdmin($email, $password) {
    $pdoConn = getValidConnection();

    try {
        // Get admin by email and check organization status
        $stmt = $pdoConn->prepare("
            SELECT a.*, o.name as organization_name, o.status as organization_status
            FROM admins a
            LEFT JOIN organizations o ON a.organization_id = o.id
            WHERE a.email = ? AND a.status = 'active'
        ");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return [
                'status' => 'error',
                'message' => 'Invalid email or password'
            ];
        }

        // Check if organization is active (for non-super-admin users)
        if ($admin['role'] !== 'super-admin' && $admin['organization_status'] !== 'active') {
            return [
                'status' => 'error',
                'message' => 'Organization access has been deactivated. Please contact support.'
            ];
        }
        
        // Generate JWT token
        $token = generateJWT($admin);
        
        error_log("Admin login successful: " . $admin['email']);
        
        return [
            'status' => 'success',
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $admin['id'],
                'username' => $admin['username'],
                'email' => $admin['email'],
                'role' => $admin['role'],
                'organization_id' => $admin['organization_id'],
                'organization_name' => $admin['organization_name'] ?? null
            ]
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in loginAdmin: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Login failed due to server error'
        ];
    }
}

function generateAdminWelcomeEmail($username, $email, $password, $role, $organizationName = null) {
    $systemName = "Haymini IoT Management System";
    $loginUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
                "://{$_SERVER['HTTP_HOST']}" . dirname($_SERVER['REQUEST_URI']) . "/";

    $organizationInfo = $organizationName ? "<tr><td style='padding: 8px 0; color: #333; font-weight: bold;'>Organization:</td><td style='padding: 8px 0; color: #666;'>{$organizationName}</td></tr>" : "";
    $roleDisplay = $role === 'super-admin' ? 'Super Administrator' : 'Administrator';

    $emailTemplate = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Welcome to {$systemName}</title>
</head>
<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background-color: #f4f4f4;'>
    <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); overflow: hidden;'>

        <!-- Header -->
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center;'>
            <h1 style='margin: 0; font-size: 28px; font-weight: 300;'>Welcome to {$systemName}</h1>
            <p style='margin: 10px 0 0; opacity: 0.9; font-size: 16px;'>Your Administrator Account is Ready</p>
        </div>

        <!-- Content -->
        <div style='padding: 30px 20px;'>
            <h2 style='color: #667eea; margin-bottom: 20px; font-size: 22px;'>Dear {$username},</h2>

            <p style='font-size: 16px; margin-bottom: 25px; color: #555;'>
                Your administrator account has been successfully created by a Super Administrator.
                Welcome to the team!
            </p>

            <!-- Account Details Box -->
            <div style='background-color: #f8f9ff; border-left: 4px solid #667eea; padding: 20px; margin: 25px 0; border-radius: 0 5px 5px 0;'>
                <h3 style='margin: 0 0 15px; color: #667eea; font-size: 18px;'>Account Details</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; color: #333; font-weight: bold;'>Username:</td>
                        <td style='padding: 8px 0; color: #666;'>{$username}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #333; font-weight: bold;'>Email:</td>
                        <td style='padding: 8px 0; color: #666;'>{$email}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #333; font-weight: bold;'>Role:</td>
                        <td style='padding: 8px 0; color: #666;'>{$roleDisplay}</td>
                    </tr>
                    {$organizationInfo}
                </table>
            </div>

            <!-- Login Credentials Box -->
            <div style='background-color: #fff5f5; border-left: 4px solid #e53e3e; padding: 20px; margin: 25px 0; border-radius: 0 5px 5px 0;'>
                <h3 style='margin: 0 0 15px; color: #e53e3e; font-size: 18px;'>🔐 Login Credentials</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; color: #333; font-weight: bold;'>Email:</td>
                        <td style='padding: 8px 0; color: #666; font-family: monospace; background: #f0f0f0; padding: 5px 8px; border-radius: 3px;'>{$email}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; color: #333; font-weight: bold;'>Password:</td>
                        <td style='padding: 8px 0; color: #666; font-family: monospace; background: #f0f0f0; padding: 5px 8px; border-radius: 3px;'>{$password}</td>
                    </tr>
                </table>
            </div>

            <!-- Login Button -->
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$loginUrl}' style='display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; font-size: 16px; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);'>
                    Access Your Dashboard
                </a>
            </div>

            <!-- Security Notes -->
            <div style='background-color: #fffbf0; border-left: 4px solid #f6ad55; padding: 20px; margin: 25px 0; border-radius: 0 5px 5px 0;'>
                <h3 style='margin: 0 0 15px; color: #c05621; font-size: 18px;'>🛡️ Important Security Notes</h3>
                <ul style='margin: 0; padding-left: 20px; color: #744210;'>
                    <li style='margin-bottom: 8px;'>Please change your password immediately after your first login</li>
                    <li style='margin-bottom: 8px;'>Keep your login credentials secure and do not share them</li>
                    <li style='margin-bottom: 8px;'>Contact your system administrator if you have any questions</li>
                </ul>
            </div>

            <p style='font-size: 16px; margin-top: 25px; color: #555;'>
                Thank you for joining our team! We're excited to have you aboard.
            </p>

            <p style='margin-top: 30px; color: #667eea; font-weight: bold;'>
                Best regards,<br>
                {$systemName} Team
            </p>
        </div>

        <!-- Footer -->
        <div style='background-color: #f8f9ff; padding: 20px; text-align: center; border-top: 1px solid #e2e8f0;'>
            <p style='margin: 0; color: #a0aec0; font-size: 14px;'>
                This is an automated message. Please do not reply to this email.
            </p>
        </div>
    </div>
</body>
</html>
    ";

    return trim($emailTemplate);
}

function createAdmin($username, $email, $password, $role, $organizationId = null) {
    $pdoConn = getValidConnection();
    
    try {
        // Validate password
        $passwordValidation = validatePassword($password);
        if ($passwordValidation !== true) {
            return [
                'status' => 'error',
                'message' => $passwordValidation
            ];
        }
        
        // Validate role and organization assignment
        if ($role === 'super_admin' && $organizationId !== null) {
            return [
                'status' => 'error',
                'message' => 'Super admin cannot be assigned to an organization'
            ];
        }
        
        if ($role === 'admin' && $organizationId === null) {
            return [
                'status' => 'error',
                'message' => 'Admin must be assigned to an organization'
            ];
        }
        
        // Check if email or username already exists
        $stmt = $pdoConn->prepare("SELECT id FROM admins WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            return [
                'status' => 'error',
                'message' => 'Email or username already exists'
            ];
        }
        
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        // Create admin
        $stmt = $pdoConn->prepare("
            INSERT INTO admins (username, email, password_hash, role, organization_id, status) 
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        
        $stmt->execute([$username, $email, $passwordHash, $role, $organizationId]);
        $adminId = $pdoConn->lastInsertId();

        error_log("Created new admin: $email (Role: $role, ID: $adminId)");

        // Get organization name if organization_id is provided
        $organizationName = null;
        if ($organizationId) {
            $orgStmt = $pdoConn->prepare("SELECT name FROM organizations WHERE id = ?");
            $orgStmt->execute([$organizationId]);
            $organizationName = $orgStmt->fetchColumn();
        }

        // Send welcome email with login credentials
        try {
            $emailSubject = "Welcome to Haymini IoT - Your Admin Account Created";
            $emailMessage = generateAdminWelcomeEmail($username, $email, $password, $role, $organizationName);

            if (sendEmail($email, $emailMessage, $emailSubject)) {
                error_log("Welcome email sent successfully to: $email");
                $emailStatus = "Welcome email sent successfully";
            } else {
                error_log("Failed to send welcome email to: $email");
                $emailStatus = "Admin created but email notification failed";
            }
        } catch (Exception $e) {
            error_log("Error sending welcome email to $email: " . $e->getMessage());
            $emailStatus = "Admin created but email notification failed";
        }

        return [
            'status' => 'success',
            'message' => 'Admin created successfully',
            'admin_id' => $adminId,
            'email_status' => $emailStatus
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in createAdmin: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to create admin due to server error'
        ];
    }
}

function getAllAdmins() {
    $pdoConn = getValidConnection();
    
    try {
        $stmt = $pdoConn->prepare("
            SELECT a.id, a.username, a.email, a.role, a.organization_id, a.status, 
                   a.created_at, a.updated_at, o.name as organization_name
            FROM admins a
            LEFT JOIN organizations o ON a.organization_id = o.id
            ORDER BY a.created_at DESC
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'status' => 'success',
            'message' => 'Admins retrieved successfully',
            'total_count' => count($admins),
            'admins' => $admins
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in getAllAdmins: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve admins'
        ];
    }
}

function impersonateAdmin($targetAdminId) {
    $pdoConn = getValidConnection();

    try {
        // Verify the target admin exists and is active
        $stmt = $pdoConn->prepare("
            SELECT a.id, a.username, a.email, a.role, a.organization_id, a.status,
                   o.name as organization_name, o.status as organization_status
            FROM admins a
            LEFT JOIN organizations o ON a.organization_id = o.id
            WHERE a.id = ? AND a.status = 'active'
        ");
        $stmt->execute([$targetAdminId]);
        $targetAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetAdmin) {
            return [
                'status' => 'error',
                'message' => 'Target admin not found or inactive'
            ];
        }

        // Prevent impersonating another super admin
        if ($targetAdmin['role'] === 'super_admin') {
            return [
                'status' => 'error',
                'message' => 'Cannot impersonate another super admin'
            ];
        }

        // Check if target admin's organization is active (if applicable)
        if ($targetAdmin['organization_id'] && $targetAdmin['organization_status'] !== 'active') {
            return [
                'status' => 'error',
                'message' => 'Cannot impersonate admin from inactive organization'
            ];
        }

        // Generate JWT token for impersonation
        $impersonationToken = generateJWT($targetAdmin);

        error_log("Super admin impersonation: Target admin {$targetAdmin['email']} (ID: {$targetAdminId})");

        return [
            'status' => 'success',
            'message' => 'Admin impersonation successful',
            'token' => $impersonationToken,
            'admin' => [
                'id' => $targetAdmin['id'],
                'username' => $targetAdmin['username'],
                'email' => $targetAdmin['email'],
                'role' => $targetAdmin['role'],
                'organization_id' => $targetAdmin['organization_id'],
                'organization_name' => $targetAdmin['organization_name']
            ]
        ];

    } catch (PDOException $e) {
        error_log("Database error in impersonateAdmin: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to impersonate admin due to server error'
        ];
    }
}

function exitImpersonation() {
    // This endpoint allows returning to super admin session
    // The frontend should store the original super admin token before impersonation
    return [
        'status' => 'success',
        'message' => 'Impersonation session ended. Please use your original super admin token.'
    ];
}

function updateAdminPassword($adminId, $newPassword) {
    $pdoConn = getValidConnection();
    
    try {
        // Validate password
        $passwordValidation = validatePassword($newPassword);
        if ($passwordValidation !== true) {
            return [
                'status' => 'error',
                'message' => $passwordValidation
            ];
        }
        
        // Hash new password
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password
        $stmt = $pdoConn->prepare("
            UPDATE admins 
            SET password_hash = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        
        $stmt->execute([$passwordHash, $adminId]);
        
        if ($stmt->rowCount() > 0) {
            error_log("Updated password for admin ID: $adminId");
            return [
                'status' => 'success',
                'message' => 'Password updated successfully'
            ];
        } else {
            return [
                'status' => 'error',
                'message' => 'Admin not found'
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Database error in updateAdminPassword: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to update password'
        ];
    }
}

function createDefaultSuperAdmin() {
    $pdoConn = getValidConnection();
    
    try {
        // Check if super admin already exists
        $stmt = $pdoConn->prepare("SELECT id FROM admins WHERE email = ?");
        $stmt->execute(['super.admin@haymini.net']);
        if ($stmt->fetch()) {
            return [
                'status' => 'exists',
                'message' => 'Default super admin already exists'
            ];
        }
        
        // Create default super admin with password 'Admin@2024'
        $password = 'Admin@2024';
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdoConn->prepare("
            INSERT INTO admins (username, email, password_hash, role, status) 
            VALUES (?, ?, ?, 'super_admin', 'active')
        ");
        
        $stmt->execute(['super_admin', 'super.admin@haymini.net', $passwordHash]);
        
        error_log("Created default super admin: super.admin@haymini.net");
        
        return [
            'status' => 'success',
            'message' => 'Default super admin created successfully',
            'email' => 'super.admin@haymini.net',
            'password' => $password
        ];
        
    } catch (PDOException $e) {
        error_log("Database error in createDefaultSuperAdmin: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to create default super admin'
        ];
    }
}

// ===============================
// USER-DEVICE ASSIGNMENT FUNCTIONS
// ===============================

function assignUserToDevice($userId, $deviceId, $assignedBy) {
    $pdoConn = getValidConnection();

    try {
        // Validate user exists and get organization
        $stmt = $pdoConn->prepare("SELECT id, organization_id, punching_code, name FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'User not found or inactive'
            ];
        }

        // Validate device exists and get organization
        $stmt = $pdoConn->prepare("SELECT id, serial_number, device_name, organization_id FROM devices WHERE id = ? AND status = 'active'");
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$device) {
            return [
                'status' => 'error',
                'message' => 'Device not found or inactive'
            ];
        }

        // Ensure user and device belong to same organization
        if ($user['organization_id'] != $device['organization_id']) {
            return [
                'status' => 'error',
                'message' => 'User and device must belong to the same organization'
            ];
        }

        // Check if assignment already exists
        $stmt = $pdoConn->prepare("SELECT id, status FROM user_device_assignments WHERE user_id = ? AND device_id = ?");
        $stmt->execute([$userId, $deviceId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['status'] === 'active') {
                return [
                    'status' => 'exists',
                    'message' => 'User is already assigned to this device'
                ];
            } else {
                // Reactivate existing assignment
                $stmt = $pdoConn->prepare("UPDATE user_device_assignments SET status = 'active', assigned_by = ?, assigned_at = NOW() WHERE id = ?");
                $stmt->execute([$assignedBy, $existing['id']]);

                error_log("Reactivated user-device assignment: User {$user['punching_code']} to Device {$device['serial_number']}");

                return [
                    'status' => 'success',
                    'message' => 'User assignment reactivated successfully'
                ];
            }
        }

        // Create new assignment
        $stmt = $pdoConn->prepare("
            INSERT INTO user_device_assignments (user_id, device_id, assigned_by, status)
            VALUES (?, ?, ?, 'active')
        ");
        $stmt->execute([$userId, $deviceId, $assignedBy]);

        error_log("Created user-device assignment: User {$user['punching_code']} ({$user['name']}) to Device {$device['serial_number']} ({$device['device_name']})");

        return [
            'status' => 'success',
            'message' => 'User assigned to device successfully',
            'assignment_id' => $pdoConn->lastInsertId()
        ];

    } catch (PDOException $e) {
        error_log("Database error in assignUserToDevice: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to assign user to device due to server error'
        ];
    }
}

function removeUserFromDevice($userId, $deviceId, $removedBy) {
    $pdoConn = getValidConnection();

    try {
        // Check if assignment exists and is active
        $stmt = $pdoConn->prepare("SELECT id FROM user_device_assignments WHERE user_id = ? AND device_id = ? AND status = 'active'");
        $stmt->execute([$userId, $deviceId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$assignment) {
            return [
                'status' => 'error',
                'message' => 'Active assignment not found'
            ];
        }

        // Deactivate assignment
        $stmt = $pdoConn->prepare("UPDATE user_device_assignments SET status = 'inactive', assigned_by = ?, assigned_at = NOW() WHERE id = ?");
        $stmt->execute([$removedBy, $assignment['id']]);

        error_log("Removed user-device assignment: Assignment ID {$assignment['id']}");

        return [
            'status' => 'success',
            'message' => 'User removed from device successfully'
        ];

    } catch (PDOException $e) {
        error_log("Database error in removeUserFromDevice: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to remove user from device due to server error'
        ];
    }
}

function getUserAssignedDevices($userId) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("
            SELECT d.id, d.serial_number, d.device_name, d.device_model, d.ip_address, d.status,
                   uda.assigned_at, a.username as assigned_by_username
            FROM user_device_assignments uda
            INNER JOIN devices d ON uda.device_id = d.id
            LEFT JOIN admins a ON uda.assigned_by = a.id
            WHERE uda.user_id = ? AND uda.status = 'active'
            ORDER BY uda.assigned_at DESC
        ");
        $stmt->execute([$userId]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'status' => 'success',
            'message' => 'User assigned devices retrieved successfully',
            'total_count' => count($devices),
            'devices' => $devices
        ];

    } catch (PDOException $e) {
        error_log("Database error in getUserAssignedDevices: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve user assigned devices'
        ];
    }
}

function getDeviceAssignedUsers($deviceId) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("
            SELECT u.id, u.punching_code, u.name, u.email, u.phone_number, u.user_type, u.status,
                   uda.assigned_at, a.username as assigned_by_username
            FROM user_device_assignments uda
            INNER JOIN users u ON uda.user_id = u.id
            LEFT JOIN admins a ON uda.assigned_by = a.id
            WHERE uda.device_id = ? AND uda.status = 'active'
            ORDER BY uda.assigned_at DESC
        ");
        $stmt->execute([$deviceId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'status' => 'success',
            'message' => 'Device assigned users retrieved successfully',
            'total_count' => count($users),
            'users' => $users
        ];

    } catch (PDOException $e) {
        error_log("Database error in getDeviceAssignedUsers: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve device assigned users'
        ];
    }
}

function isUserAssignedToDevice($userId, $deviceId) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("
            SELECT COUNT(*) FROM user_device_assignments
            WHERE user_id = ? AND device_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId, $deviceId]);
        return $stmt->fetchColumn() > 0;

    } catch (PDOException $e) {
        error_log("Database error in isUserAssignedToDevice: " . $e->getMessage());
        return false;
    }
}

function bulkAssignUsersToDevice($deviceId, $userIds, $assignedBy) {
    $pdoConn = getValidConnection();

    try {
        $results = [
            'assigned' => 0,
            'reactivated' => 0,
            'already_assigned' => 0,
            'errors' => 0,
            'details' => []
        ];

        foreach ($userIds as $userId) {
            $result = assignUserToDevice($userId, $deviceId, $assignedBy);

            if ($result['status'] === 'success') {
                $results['assigned']++;
            } elseif ($result['status'] === 'exists') {
                $results['already_assigned']++;
            } else {
                $results['errors']++;
            }

            $results['details'][] = [
                'user_id' => $userId,
                'status' => $result['status'],
                'message' => $result['message']
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Bulk assignment completed',
            'results' => $results
        ];

    } catch (Exception $e) {
        error_log("Error in bulkAssignUsersToDevice: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Bulk assignment failed: ' . $e->getMessage()
        ];
    }
}

function getOrganizationDeviceAssignments($organizationId) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("
            SELECT
                uda.id,
                uda.user_id,
                uda.device_id,
                uda.assigned_at,
                uda.assigned_by,
                u.punching_code,
                u.name as user_name,
                u.email as user_email,
                u.user_type,
                d.serial_number as device_serial,
                d.device_name,
                d.device_model,
                d.organization_id,
                o.name as organization_name,
                a.username as assigned_by_username
            FROM user_device_assignments uda
            INNER JOIN users u ON uda.user_id = u.id
            INNER JOIN devices d ON uda.device_id = d.id
            INNER JOIN organizations o ON d.organization_id = o.id
            LEFT JOIN admins a ON uda.assigned_by = a.id
            WHERE d.organization_id = ? AND uda.status = 'active'
            ORDER BY uda.assigned_at DESC
        ");
        $stmt->execute([$organizationId]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'status' => 'success',
            'message' => 'Organization device assignments retrieved successfully',
            'total_count' => count($assignments),
            'assignments' => $assignments
        ];

    } catch (PDOException $e) {
        error_log("Database error in getOrganizationDeviceAssignments: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve organization device assignments'
        ];
    }
}

// ===============================
// CSV UPLOAD FUNCTIONS
// ===============================

function generateUserCSVTemplate() {
    $headers = [
        'punching_code',
        'name',
        'email',
        'phone',
        'organization_id',
        'user_type'
    ];

    $sampleData = [
        ['U001', 'John Doe', 'john.doe@example.com', '1234567890', '1', 'student'],
        ['U002', 'Jane Smith', 'jane.smith@example.com', '0987654321', '1', 'staff'],
        ['U003', 'Bob Johnson', 'bob.johnson@example.com', '5555551234', '2', 'student']
    ];

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_template.csv"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

    $output = fopen('php://output', 'w');

    // Write CSV headers
    fputcsv($output, $headers);

    // Write sample data
    foreach ($sampleData as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();
}

function parseCSVFile($filePath) {
    $results = [
        'valid_rows' => [],
        'invalid_rows' => [],
        'errors' => []
    ];

    if (!file_exists($filePath)) {
        $results['errors'][] = 'CSV file not found';
        return $results;
    }

    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        $results['errors'][] = 'Unable to read CSV file';
        return $results;
    }

    $headers = fgetcsv($handle);
    if ($headers === false) {
        $results['errors'][] = 'CSV file is empty or invalid';
        fclose($handle);
        return $results;
    }

    // Expected headers (order doesn't matter)
    $expectedHeaders = ['punching_code', 'name', 'email', 'phone', 'organization_id', 'user_type'];
    $headerMap = [];

    // Map headers to their positions
    foreach ($expectedHeaders as $expectedHeader) {
        $position = array_search($expectedHeader, $headers);
        if ($position !== false) {
            $headerMap[$expectedHeader] = $position;
        }
    }

    // Check for required headers
    $requiredHeaders = ['punching_code', 'name', 'email', 'phone'];
    $missingHeaders = [];
    foreach ($requiredHeaders as $required) {
        if (!isset($headerMap[$required])) {
            $missingHeaders[] = $required;
        }
    }

    if (!empty($missingHeaders)) {
        $results['errors'][] = 'Missing required headers: ' . implode(', ', $missingHeaders);
        fclose($handle);
        return $results;
    }

    $rowNumber = 1; // Start from 1 (after headers)
    while (($data = fgetcsv($handle)) !== false) {
        $rowNumber++;

        // Skip empty rows
        if (empty(array_filter($data))) {
            continue;
        }

        $rowData = [
            'punching_code' => isset($headerMap['punching_code']) ? trim($data[$headerMap['punching_code']] ?? '') : '',
            'name' => isset($headerMap['name']) ? trim($data[$headerMap['name']] ?? '') : '',
            'email' => isset($headerMap['email']) ? trim($data[$headerMap['email']] ?? '') : '',
            'phone' => isset($headerMap['phone']) ? trim($data[$headerMap['phone']] ?? '') : '',
            'organization_id' => isset($headerMap['organization_id']) ? trim($data[$headerMap['organization_id']] ?? '') : null,
            'user_type' => isset($headerMap['user_type']) ? trim($data[$headerMap['user_type']] ?? '') : 'student'
        ];

        // Validate row data
        $errors = [];

        if (empty($rowData['punching_code'])) {
            $errors[] = 'Punching code is required';
        }

        if (empty($rowData['name'])) {
            $errors[] = 'Name is required';
        }

        if (empty($rowData['email']) || !filter_var($rowData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required';
        }

        if (empty($rowData['phone'])) {
            $errors[] = 'Phone number is required';
        }

        // Validate organization_id if provided
        if (!empty($rowData['organization_id']) && !is_numeric($rowData['organization_id'])) {
            $errors[] = 'Organization ID must be numeric';
        }

        // Validate user_type if provided
        if (!empty($rowData['user_type']) && !in_array($rowData['user_type'], ['staff', 'student'])) {
            $errors[] = 'User type must be either "staff" or "student"';
        }

        if (!empty($errors)) {
            $results['invalid_rows'][] = [
                'row_number' => $rowNumber,
                'data' => $rowData,
                'errors' => $errors
            ];
        } else {
            $results['valid_rows'][] = $rowData;
        }
    }

    fclose($handle);
    return $results;
}

function uploadUsersFromCSV($csvFile, $defaultOrganizationId = null) {
    try {
        // Parse CSV file
        $parseResults = parseCSVFile($csvFile['tmp_name']);

        if (!empty($parseResults['errors'])) {
            return [
                'status' => 'error',
                'message' => 'CSV parsing failed',
                'errors' => $parseResults['errors']
            ];
        }

        if (empty($parseResults['valid_rows'])) {
            return [
                'status' => 'error',
                'message' => 'No valid user data found in CSV',
                'invalid_rows' => $parseResults['invalid_rows']
            ];
        }

        // Set organization_id for rows that don't have one
        foreach ($parseResults['valid_rows'] as &$row) {
            if (empty($row['organization_id'])) {
                $row['organization_id'] = $defaultOrganizationId;
            }
        }

        // Use existing bulk creation function (don't pass defaultOrganizationId as it's already set in rows)
        $bulkResults = bulkCreateUsers($parseResults['valid_rows'], null);

        // Combine results
        $response = [
            'status' => 'success',
            'message' => 'CSV upload completed',
            'csv_parsing' => [
                'total_rows_processed' => count($parseResults['valid_rows']) + count($parseResults['invalid_rows']),
                'valid_rows' => count($parseResults['valid_rows']),
                'invalid_rows' => count($parseResults['invalid_rows']),
                'parsing_errors' => $parseResults['errors']
            ],
            'user_creation' => $bulkResults['results'],
            'invalid_rows_details' => $parseResults['invalid_rows']
        ];

        // Clean up uploaded file
        if (file_exists($csvFile['tmp_name'])) {
            unlink($csvFile['tmp_name']);
        }

        return $response;

    } catch (Exception $e) {
        error_log("Error in uploadUsersFromCSV: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'CSV upload failed: ' . $e->getMessage()
        ];
    }
}

// ===============================
// PUNCH TIME SETTINGS FUNCTIONS
// ===============================

function getOrganizationPunchSettings($organizationId) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("
            SELECT * FROM organization_punch_settings
            WHERE organization_id = ?
        ");
        $stmt->execute([$organizationId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            // Create default settings if none exist
            $defaultSettings = createDefaultPunchSettings($organizationId);
            return $defaultSettings;
        }

        return [
            'status' => 'success',
            'settings' => $settings
        ];

    } catch (PDOException $e) {
        error_log("Database error in getOrganizationPunchSettings: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve punch settings'
        ];
    }
}

function createDefaultPunchSettings($organizationId) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("
            INSERT INTO organization_punch_settings
            (organization_id, punch_in_start_time, punch_in_end_time, punch_out_start_time, punch_out_end_time)
            VALUES (?, '07:00:00', '11:59:59', '12:00:00', '18:00:00')
        ");
        $stmt->execute([$organizationId]);

        return [
            'status' => 'success',
            'message' => 'Default punch settings created',
            'settings' => [
                'id' => $pdoConn->lastInsertId(),
                'organization_id' => $organizationId,
                'punch_in_start_time' => '07:00:00',
                'punch_in_end_time' => '11:59:59',
                'punch_out_start_time' => '12:00:00',
                'punch_out_end_time' => '18:00:00',
                'grace_period_minutes' => 15,
                'require_both_punches' => true
            ]
        ];

    } catch (PDOException $e) {
        error_log("Database error in createDefaultPunchSettings: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to create default punch settings'
        ];
    }
}

function updatePunchSettings($organizationId, $settings, $updatedBy) {
    $pdoConn = getValidConnection();

    try {
        // Validate time formats
        $timeFields = ['punch_in_start_time', 'punch_in_end_time', 'punch_out_start_time', 'punch_out_end_time', 'auto_punch_out_time'];
        foreach ($timeFields as $field) {
            if (isset($settings[$field]) && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $settings[$field])) {
                return [
                    'status' => 'error',
                    'message' => "Invalid time format for {$field}. Use HH:MM:SS format."
                ];
            }
        }

        // Validate logical time ranges
        if (isset($settings['punch_in_start_time']) && isset($settings['punch_in_end_time'])) {
            if ($settings['punch_in_start_time'] >= $settings['punch_in_end_time']) {
                return [
                    'status' => 'error',
                    'message' => 'Punch in start time must be before punch in end time'
                ];
            }
        }

        if (isset($settings['punch_out_start_time']) && isset($settings['punch_out_end_time'])) {
            if ($settings['punch_out_start_time'] >= $settings['punch_out_end_time']) {
                return [
                    'status' => 'error',
                    'message' => 'Punch out start time must be before punch out end time'
                ];
            }
        }

        // Build update query dynamically
        $updates = [];
        $params = [];

        $allowedFields = [
            'punch_in_start_time', 'punch_in_end_time', 'punch_out_start_time', 'punch_out_end_time',
            'timezone', 'grace_period_minutes', 'require_both_punches', 'auto_punch_out_time'
        ];

        foreach ($allowedFields as $field) {
            if (isset($settings[$field])) {
                $updates[] = "{$field} = ?";
                $params[] = $settings[$field];
            }
        }

        if (empty($updates)) {
            return [
                'status' => 'error',
                'message' => 'No valid fields provided for update'
            ];
        }

        $updates[] = "updated_at = NOW()";
        $updates[] = "created_by = ?";
        $params[] = $updatedBy;
        $params[] = $organizationId;

        $sql = "UPDATE organization_punch_settings SET " . implode(", ", $updates) . " WHERE organization_id = ?";
        $stmt = $pdoConn->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return [
                'status' => 'error',
                'message' => 'No settings found to update or no changes made'
            ];
        }

        error_log("Updated punch settings for organization: $organizationId");

        return [
            'status' => 'success',
            'message' => 'Punch settings updated successfully'
        ];

    } catch (PDOException $e) {
        error_log("Database error in updatePunchSettings: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to update punch settings'
        ];
    }
}

function determinePunchType($organizationId, $currentTime) {
    $settings = getOrganizationPunchSettings($organizationId);

    if ($settings['status'] !== 'success') {
        return 'in'; // Default fallback
    }

    $punchSettings = $settings['settings'];
    $time = date('H:i:s', strtotime($currentTime));

    // Check if time falls within punch in period
    if ($time >= $punchSettings['punch_in_start_time'] && $time <= $punchSettings['punch_in_end_time']) {
        return 'in';
    }

    // Check if time falls within punch out period
    if ($time >= $punchSettings['punch_out_start_time'] && $time <= $punchSettings['punch_out_end_time']) {
        return 'out';
    }

    // If outside both periods, determine based on proximity
    $punchInStart = strtotime($punchSettings['punch_in_start_time']);
    $punchOutEnd = strtotime($punchSettings['punch_out_end_time']);
    $currentTimeStamp = strtotime($time);

    // If before punch in period, treat as early punch in
    if ($currentTimeStamp < $punchInStart) {
        return 'in';
    }

    // If after punch out period, treat as late punch out
    if ($currentTimeStamp > $punchOutEnd) {
        return 'out';
    }

    // Default to 'in' for edge cases
    return 'in';
}

function isLatePunch($organizationId, $punchType, $punchTime) {
    $settings = getOrganizationPunchSettings($organizationId);

    if ($settings['status'] !== 'success') {
        return false;
    }

    $punchSettings = $settings['settings'];
    $time = date('H:i:s', strtotime($punchTime));
    $gracePeriod = $punchSettings['grace_period_minutes'] ?? 15;

    if ($punchType === 'in') {
        $deadline = date('H:i:s', strtotime($punchSettings['punch_in_end_time'] . " + {$gracePeriod} minutes"));
        return $time > $deadline;
    }

    return false; // We don't typically consider punch out as late
}

function isEarlyPunch($organizationId, $punchType, $punchTime) {
    $settings = getOrganizationPunchSettings($organizationId);

    if ($settings['status'] !== 'success') {
        return false;
    }

    $punchSettings = $settings['settings'];
    $time = date('H:i:s', strtotime($punchTime));

    if ($punchType === 'out') {
        return $time < $punchSettings['punch_out_start_time'];
    }

    return false;
}

// ===============================
// ENHANCED ATTENDANCE FUNCTIONS
// ===============================

function logAttendancePunch($userId, $punchingCode, $organizationId, $deviceSerial, $punchDateTime, $ipAddress = null) {
    $pdoConn = getValidConnection();

    try {
        $punchDate = date('Y-m-d', strtotime($punchDateTime));
        $punchTime = date('H:i:s', strtotime($punchDateTime));
        $punchType = determinePunchType($organizationId, $punchDateTime);
        $isLate = isLatePunch($organizationId, $punchType, $punchDateTime);
        $isEarly = isEarlyPunch($organizationId, $punchType, $punchDateTime);

        // Insert into attendance_logs
        $stmt = $pdoConn->prepare("
            INSERT INTO attendance_logs
            (punching_code, user_id, organization_id, device_serial, punch_date, punch_time, punch_datetime,
             punch_type, is_late, is_early, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $punchingCode, $userId, $organizationId, $deviceSerial, $punchDate, $punchTime,
            $punchDateTime, $punchType, $isLate, $isEarly, $ipAddress
        ]);

        // Update or create daily attendance record
        updateDailyAttendance($userId, $punchingCode, $organizationId, $punchDate);

        return [
            'status' => 'success',
            'punch_type' => $punchType,
            'is_late' => $isLate,
            'is_early' => $isEarly
        ];

    } catch (PDOException $e) {
        error_log("Database error in logAttendancePunch: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to log attendance punch'
        ];
    }
}

function updateDailyAttendance($userId, $punchingCode, $organizationId, $date) {
    $pdoConn = getValidConnection();

    try {
        // Get all punches for this user on this date
        $stmt = $pdoConn->prepare("
            SELECT punch_type, punch_time, is_late, is_early
            FROM attendance_logs
            WHERE user_id = ? AND punch_date = ?
            ORDER BY punch_time ASC
        ");
        $stmt->execute([$userId, $date]);
        $punches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $punchInTime = null;
        $punchOutTime = null;
        $isLate = false;
        $isEarlyOut = false;
        $lateMinutes = 0;
        $earlyOutMinutes = 0;

        foreach ($punches as $punch) {
            if ($punch['punch_type'] === 'in' && !$punchInTime) {
                $punchInTime = $punch['punch_time'];
                $isLate = $punch['is_late'];

                if ($isLate) {
                    $settings = getOrganizationPunchSettings($organizationId);
                    if ($settings['status'] === 'success') {
                        $expectedTime = $settings['settings']['punch_in_end_time'];
                        $lateMinutes = max(0, (strtotime($punchInTime) - strtotime($expectedTime)) / 60);
                    }
                }
            }

            if ($punch['punch_type'] === 'out') {
                $punchOutTime = $punch['punch_time'];
                $isEarlyOut = $punch['is_early'];

                if ($isEarlyOut) {
                    $settings = getOrganizationPunchSettings($organizationId);
                    if ($settings['status'] === 'success') {
                        $expectedTime = $settings['settings']['punch_out_start_time'];
                        $earlyOutMinutes = max(0, (strtotime($expectedTime) - strtotime($punchOutTime)) / 60);
                    }
                }
            }
        }

        // Calculate total hours and status
        $totalHours = 0;
        $status = 'absent';

        if ($punchInTime && $punchOutTime) {
            $totalHours = (strtotime($punchOutTime) - strtotime($punchInTime)) / 3600;
            $status = $isLate ? 'late' : ($isEarlyOut ? 'early_out' : 'present');
        } elseif ($punchInTime) {
            $status = 'partial';
        }

        // Insert or update daily attendance
        $stmt = $pdoConn->prepare("
            INSERT INTO daily_attendance
            (user_id, punching_code, organization_id, attendance_date, punch_in_time, punch_out_time,
             total_hours, status, is_late, is_early_out, late_minutes, early_out_minutes, auto_generated)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)
            ON DUPLICATE KEY UPDATE
            punch_in_time = VALUES(punch_in_time),
            punch_out_time = VALUES(punch_out_time),
            total_hours = VALUES(total_hours),
            status = VALUES(status),
            is_late = VALUES(is_late),
            is_early_out = VALUES(is_early_out),
            late_minutes = VALUES(late_minutes),
            early_out_minutes = VALUES(early_out_minutes),
            updated_at = NOW()
        ");

        $stmt->execute([
            $userId, $punchingCode, $organizationId, $date, $punchInTime, $punchOutTime,
            $totalHours, $status, $isLate, $isEarlyOut, $lateMinutes, $earlyOutMinutes
        ]);

        return true;

    } catch (PDOException $e) {
        error_log("Database error in updateDailyAttendance: " . $e->getMessage());
        return false;
    }
}

function generateAbsenceRecords($organizationId, $date = null) {
    $pdoConn = getValidConnection();

    if (!$date) {
        $date = date('Y-m-d');
    }

    try {
        // Get all active users in the organization
        $stmt = $pdoConn->prepare("
            SELECT id, punching_code FROM users
            WHERE organization_id = ? AND status = 'active'
        ");
        $stmt->execute([$organizationId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $absenceCount = 0;

        foreach ($users as $user) {
            // Check if user has any attendance record for this date
            $stmt = $pdoConn->prepare("
                SELECT id, status FROM daily_attendance
                WHERE user_id = ? AND attendance_date = ?
            ");
            $stmt->execute([$user['id'], $date]);
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$attendance) {
                // Create absence record
                $stmt = $pdoConn->prepare("
                    INSERT INTO daily_attendance
                    (user_id, punching_code, organization_id, attendance_date, status, auto_generated)
                    VALUES (?, ?, ?, ?, 'absent', TRUE)
                ");
                $stmt->execute([$user['id'], $user['punching_code'], $organizationId, $date]);

                // Create absence tracking record
                $stmt = $pdoConn->prepare("
                    INSERT INTO absence_records
                    (user_id, organization_id, absence_date, absence_type)
                    VALUES (?, ?, ?, 'absent')
                ");
                $stmt->execute([$user['id'], $organizationId, $date]);

                $absenceCount++;
            } elseif ($attendance['status'] === 'partial') {
                // Check what type of partial attendance
                $stmt = $pdoConn->prepare("
                    SELECT punch_in_time, punch_out_time FROM daily_attendance
                    WHERE user_id = ? AND attendance_date = ?
                ");
                $stmt->execute([$user['id'], $date]);
                $punchData = $stmt->fetch(PDO::FETCH_ASSOC);

                $absenceType = null;
                if (!$punchData['punch_in_time']) {
                    $absenceType = 'no_punch_in';
                } elseif (!$punchData['punch_out_time']) {
                    $absenceType = 'no_punch_out';
                }

                if ($absenceType) {
                    $stmt = $pdoConn->prepare("
                        INSERT IGNORE INTO absence_records
                        (user_id, organization_id, absence_date, absence_type)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$user['id'], $organizationId, $date, $absenceType]);
                }
            }
        }

        return [
            'status' => 'success',
            'absence_records_created' => $absenceCount
        ];

    } catch (PDOException $e) {
        error_log("Database error in generateAbsenceRecords: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to generate absence records'
        ];
    }
}

// ===============================
// ATTENDANCE REPORTING FUNCTIONS
// ===============================

function getOrganizationAttendance($organizationId, $dateFrom = null, $dateTo = null) {
    $pdoConn = getValidConnection();

    if (!$dateFrom) $dateFrom = date('Y-m-d');
    if (!$dateTo) $dateTo = $dateFrom;

    try {
        $stmt = $pdoConn->prepare("
            SELECT da.*, u.name as user_name, u.email, u.punching_code, u.user_type
            FROM daily_attendance da
            INNER JOIN users u ON da.user_id = u.id
            WHERE da.organization_id = ? AND da.attendance_date BETWEEN ? AND ?
            ORDER BY da.attendance_date DESC, u.name ASC
        ");
        $stmt->execute([$organizationId, $dateFrom, $dateTo]);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get summary statistics
        $stats = [
            'total_records' => count($attendance),
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'partial' => 0,
            'early_out' => 0
        ];

        foreach ($attendance as $record) {
            $stats[$record['status']]++;
        }

        return [
            'status' => 'success',
            'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
            'attendance_records' => $attendance,
            'summary' => $stats
        ];

    } catch (PDOException $e) {
        error_log("Database error in getOrganizationAttendance: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve attendance data'
        ];
    }
}

function getUserAttendanceReport($userId, $dateFrom = null, $dateTo = null) {
    $pdoConn = getValidConnection();

    if (!$dateFrom) $dateFrom = date('Y-m-d', strtotime('-30 days'));
    if (!$dateTo) $dateTo = date('Y-m-d');

    try {
        $stmt = $pdoConn->prepare("
            SELECT da.*, u.name as user_name, u.email, u.punching_code, u.user_type
            FROM daily_attendance da
            INNER JOIN users u ON da.user_id = u.id
            WHERE da.user_id = ? AND da.attendance_date BETWEEN ? AND ?
            ORDER BY da.attendance_date DESC
        ");
        $stmt->execute([$userId, $dateFrom, $dateTo]);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate statistics
        $totalDays = count($attendance);
        $presentDays = 0;
        $lateDays = 0;
        $totalHours = 0;

        foreach ($attendance as $record) {
            if (in_array($record['status'], ['present', 'late', 'early_out'])) {
                $presentDays++;
                $totalHours += $record['total_hours'];
            }
            if ($record['is_late']) {
                $lateDays++;
            }
        }

        $attendanceRate = $totalDays > 0 ? ($presentDays / $totalDays) * 100 : 0;
        $averageHours = $presentDays > 0 ? $totalHours / $presentDays : 0;

        return [
            'status' => 'success',
            'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
            'attendance_records' => $attendance,
            'statistics' => [
                'total_days' => $totalDays,
                'present_days' => $presentDays,
                'absent_days' => $totalDays - $presentDays,
                'late_days' => $lateDays,
                'attendance_rate' => round($attendanceRate, 2),
                'total_hours_worked' => round($totalHours, 2),
                'average_hours_per_day' => round($averageHours, 2)
            ]
        ];

    } catch (PDOException $e) {
        error_log("Database error in getUserAttendanceReport: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve user attendance report'
        ];
    }
}

function getTodayAttendanceStatus($organizationId) {
    $pdoConn = getValidConnection();

    try {
        $stmt = $pdoConn->prepare("
            SELECT
                u.id as user_id,
                u.punching_code,
                u.name as user_name,
                u.user_type,
                da.punch_in_time,
                da.punch_out_time,
                da.status,
                da.is_late,
                da.late_minutes,
                CASE
                    WHEN da.punch_in_time IS NOT NULL AND da.punch_out_time IS NOT NULL THEN 'completed'
                    WHEN da.punch_in_time IS NOT NULL AND da.punch_out_time IS NULL THEN 'in_progress'
                    WHEN da.punch_in_time IS NULL THEN 'not_started'
                END as current_status
            FROM users u
            LEFT JOIN daily_attendance da ON u.id = da.user_id AND da.attendance_date = CURDATE()
            WHERE u.organization_id = ? AND u.status = 'active'
            ORDER BY u.name ASC
        ");
        $stmt->execute([$organizationId]);
        $todayAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count status
        $statusCounts = [
            'completed' => 0,
            'in_progress' => 0,
            'not_started' => 0,
            'late' => 0
        ];

        foreach ($todayAttendance as $record) {
            $statusCounts[$record['current_status']]++;
            if ($record['is_late']) {
                $statusCounts['late']++;
            }
        }

        return [
            'status' => 'success',
            'date' => date('Y-m-d'),
            'attendance_status' => $todayAttendance,
            'summary' => $statusCounts
        ];

    } catch (PDOException $e) {
        error_log("Database error in getTodayAttendanceStatus: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve today\'s attendance status'
        ];
    }
}

function getAbsenceReport($organizationId, $dateFrom = null, $dateTo = null) {
    $pdoConn = getValidConnection();

    if (!$dateFrom) $dateFrom = date('Y-m-d', strtotime('-7 days'));
    if (!$dateTo) $dateTo = date('Y-m-d');

    try {
        $stmt = $pdoConn->prepare("
            SELECT
                ar.*,
                u.name as user_name,
                u.email,
                u.punching_code,
                u.user_type,
                a.username as excused_by_name
            FROM absence_records ar
            INNER JOIN users u ON ar.user_id = u.id
            LEFT JOIN admins a ON ar.excused_by = a.id
            WHERE ar.organization_id = ? AND ar.absence_date BETWEEN ? AND ?
            ORDER BY ar.absence_date DESC, u.name ASC
        ");
        $stmt->execute([$organizationId, $dateFrom, $dateTo]);
        $absences = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by absence type
        $typeGroups = [
            'absent' => [],
            'no_punch_in' => [],
            'no_punch_out' => [],
            'both_missing' => []
        ];

        foreach ($absences as $absence) {
            $typeGroups[$absence['absence_type']][] = $absence;
        }

        return [
            'status' => 'success',
            'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
            'absence_records' => $absences,
            'grouped_by_type' => $typeGroups,
            'total_absences' => count($absences)
        ];

    } catch (PDOException $e) {
        error_log("Database error in getAbsenceReport: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve absence report'
        ];
    }
}

?>