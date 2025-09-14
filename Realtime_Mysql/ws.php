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
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    
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

// Handle GET requests or CLI calls
// if ($_SERVER['REQUEST_METHOD'] === 'GET' || php_sapi_name() === "cli") {
//     $action = $_GET['action'] ?? null;

//     if ($action) {
//         switch ($action) {
//             case 'getLogs':
//                 header('Content-Type: application/json');
//                 echo getAllLogs();
//                 break;

//             case 'getLogsByPunchingCode':
//                 $punchingcode = filter_input(INPUT_GET, 'punchingcode', FILTER_SANITIZE_STRING);
//                 if ($punchingcode) {
//                     header('Content-Type: application/json');
//                     echo getLogsByPunchingCode($punchingcode);
//                 } else {
//                     http_response_code(400);
//                     echo json_encode(['error' => 'Missing or invalid punchingcode parameter']);
//                 }
//                 break;

//             case 'exportLogs':
//                 exportLogsToExcel();
//                 break;

//             default:
//                 // Invalid action provided
//                 http_response_code(400);
//                 echo json_encode([
//                     'error' => 'Invalid action',
//                     'available_actions' => [
//                         'getLogs',
//                         'getLogsByPunchingCode',
//                         'exportLogs'
//                     ]
//                 ]);
//                 break;
//         }
//     } else {
//         // No action provided, show default response
//         http_response_code(200);
//         echo json_encode([
//             'message' => 'Welcome to the API',
//             'instructions' => [
//                 'getLogs' => '/ws.php?action=getLogs',
//                 'getLogsByPunchingCode' => '/ws.php?action=getLogsByPunchingCode&punchingcode={value}',
//                 'exportLogs' => '/ws.php?action=exportLogs'
//             ]
//         ]);
//     }
// } else {
//     // Unsupported request method
//     http_response_code(405);
//     echo json_encode(['error' => 'Method not allowed']);
// }

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
    
    // === AUTHENTICATION & RESOURCES ===
    case 'GET':
        if (preg_match('/\/api\/auth\/me$/', $path)) {
            // GET /api/auth/me - Get current user info
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
            
    // === ORGANIZATIONS RESOURCE ===
        } elseif (preg_match('/\/api\/organizations\/(\d+)$/', $path, $matches)) {
            // GET /api/organizations/{id} - Get specific organization
            $organizationId = (int)$matches[1];
            echo json_encode(getOrganization($organizationId));
            
        } elseif (preg_match('/\/api\/organizations$/', $path)) {
            // GET /api/organizations - Get all organizations
            echo json_encode(getAllOrganizations());
            
        } elseif (preg_match('/\/api\/organizations\/(\d+)\/users$/', $path, $matches)) {
            // GET /api/organizations/{id}/users - Get users by organization
            $organizationId = (int)$matches[1];
            echo json_encode(getUsersByOrganization($organizationId));
            
        } elseif (preg_match('/\/api\/organizations\/(\d+)\/devices$/', $path, $matches)) {
            // GET /api/organizations/{id}/devices - Get devices by organization
            $organizationId = (int)$matches[1];
            echo json_encode(getDevicesByOrganization($organizationId));
            
        } elseif (preg_match('/\/api\/organizations\/(\d+)\/logs$/', $path, $matches)) {
            // GET /api/organizations/{id}/logs - Get logs by organization
            $organizationId = (int)$matches[1];
            $dateFrom = $queryParams['date_from'] ?? null;
            $dateTo = $queryParams['date_to'] ?? null;
            echo json_encode(getLogsByOrganization($organizationId, $dateFrom, $dateTo));
            
        // === USERS RESOURCE ===
        } elseif (preg_match('/\/api\/users\/([^\/]+)$/', $path, $matches)) {
            // GET /api/users/{punching_code} - Get user by punching code
            $punchingCode = $matches[1];
            $organizationId = $queryParams['organization_id'] ?? null;
            echo getLogsByPunchingCode($punchingCode, $organizationId);
            
        // === DEVICES RESOURCE ===
        } elseif (preg_match('/\/api\/devices\/([^\/]+)$/', $path, $matches)) {
            // GET /api/devices/{serial_number} - Get device details
            $serialNumber = $matches[1];
            echo json_encode(getDeviceDetails($serialNumber));
            
        // === LOGS RESOURCE ===
        } elseif (preg_match('/\/api\/logs$/', $path)) {
            // GET /api/logs - Get all logs from all organizations
            echo getAllLogs();
            
        } elseif (preg_match('/\/api\/logs\/export$/', $path)) {
            // GET /api/logs/export - Export logs to Excel
            exportLogsToExcel();
            
        } elseif (preg_match('/\/api\/logs\/device\/([^\/]+)$/', $path, $matches)) {
            // GET /api/logs/device/{device_serial} - Get logs by device
            requireAuth();
            $deviceSerial = $matches[1];
            echo json_encode(getLogsByDevice($deviceSerial));
            
        } elseif (preg_match('/\/api\/admins$/', $path)) {
            // GET /api/admins - List all admins (super admin only)
            requireSuperAdmin();
            echo json_encode(getAllAdmins());
            
        // === API ROOT ===
        } elseif (preg_match('/\/api\/?$/', $path) || $path === '/ws.php') {
            // GET /api - API documentation
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
                        'GET /api/organizations/{id}/logs' => 'Get organization logs'
                    ],
                    'users' => [
                        'POST /api/users' => 'Create user',
                        'POST /api/users/bulk' => 'Bulk create users',
                        'GET /api/users/{punching_code}' => 'Get user logs',
                        'PUT /api/users/{punching_code}/activate' => 'Activate user',
                        'PUT /api/users/{punching_code}/deactivate' => 'Deactivate user'
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
            // POST /api/auth/login - Login
            if (!$jsonInput || empty($jsonInput['email']) || empty($jsonInput['password'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Email and password are required']);
                break;
            }
            
            echo json_encode(loginAdmin($jsonInput['email'], $jsonInput['password']));
            
        } elseif (preg_match('/\/api\/auth\/logout$/', $path)) {
            // POST /api/auth/logout - Logout (token-based, so just return success)
            echo json_encode([
                'status' => 'success',
                'message' => 'Logged out successfully'
            ]);
            
        } elseif (preg_match('/\/api\/organizations$/', $path)) {
            // POST /api/organizations - Create organization
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
            // POST /api/users - Create user
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
                $jsonInput['organization_id'] ?? null
            ));
            
        } elseif (preg_match('/\/api\/users\/bulk$/', $path)) {
            // POST /api/users/bulk - Bulk create users
            if (!$jsonInput || !isset($jsonInput['users'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: users array']);
                break;
            }
            
            echo json_encode(bulkCreateUsers($jsonInput['users'], $jsonInput['organization_id'] ?? null));
            
        } elseif (preg_match('/\/api\/devices$/', $path)) {
            // POST /api/devices - Register device
            requireAuth(); // Require authentication
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
            // POST /api/admins - Create admin (super admin only)
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
            
        } elseif (preg_match('/\/api\/setup\/default-admin$/', $path)) {
            // POST /api/setup/default-admin - Create default super admin (no auth required)
            echo json_encode(createDefaultSuperAdmin());
            
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
        }
        break;
        
    case 'PUT':
        if (preg_match('/\/api\/organizations\/(\d+)$/', $path, $matches)) {
            // PUT /api/organizations/{id} - Update organization
            $organizationId = (int)$matches[1];
            
            echo json_encode(updateOrganization(
                $organizationId,
                $jsonInput['name'] ?? null,
                $jsonInput['address'] ?? null,
                $jsonInput['contact_person'] ?? null,
                $jsonInput['email'] ?? null,
                $jsonInput['phone'] ?? null,
                $jsonInput['description'] ?? null
            ));
            
        } elseif (preg_match('/\/api\/users\/([^\/]+)\/activate$/', $path, $matches)) {
            // PUT /api/users/{punching_code}/activate - Activate user
            $punchingCode = $matches[1];
            echo json_encode(activateUser($punchingCode));
            
        } elseif (preg_match('/\/api\/users\/([^\/]+)\/deactivate$/', $path, $matches)) {
            // PUT /api/users/{punching_code}/deactivate - Deactivate user
            $punchingCode = $matches[1];
            echo json_encode(deactivateUser($punchingCode));
            
        } elseif (preg_match('/\/api\/devices\/([^\/]+)$/', $path, $matches)) {
            // PUT /api/devices/{serial_number} - Update device status
            $serialNumber = $matches[1];
            
            if (!$jsonInput || empty($jsonInput['status'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: status']);
                break;
            }
            
            if (!in_array($jsonInput['status'], ['active', 'inactive', 'maintenance'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status. Must be: active, inactive, or maintenance']);
                break;
            }
            
            echo json_encode(updateDeviceStatus($serialNumber, $jsonInput['status']));
            
        } elseif (preg_match('/\/api\/devices\/([^\/]+)\/organization$/', $path, $matches)) {
            // PUT /api/devices/{serial_number}/organization - Assign device to organization
            requireAuth();
            $serialNumber = $matches[1];
            
            if (!$jsonInput || empty($jsonInput['organization_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: organization_id']);
                break;
            }
            
            echo json_encode(assignDeviceToOrganization($serialNumber, $jsonInput['organization_id']));
            
        } elseif (preg_match('/\/api\/admins\/(\d+)\/password$/', $path, $matches)) {
            // PUT /api/admins/{id}/password - Update admin password (super admin only)
            requireSuperAdmin();
            $adminId = (int)$matches[1];
            
            if (!$jsonInput || empty($jsonInput['password'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: password']);
                break;
            }
            
            echo json_encode(updateAdminPassword($adminId, $jsonInput['password']));
            
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'error' => 'Method not allowed',
            'allowed_methods' => ['GET', 'POST', 'PUT']
        ]);
        break;
}



set_time_limit(0);
ob_implicit_flush();

//date_default_timezone_set('Asia/Calcutta');
//date_default_timezone_set('PRC');

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
            $stmt = $pdoConn->prepare("SELECT id, name, email, phone_number, organization_id, status FROM users WHERE punching_code = ?");
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
            
        } catch (PDOException $e) {
            error_log("Database error during user validation: " . $e->getMessage());
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

        // Step 4: Add to insertion array with organization context
        $sqlArray[] = sprintf(
            '(%s, %s, %s, %s, %s, %s)',
            $pdoConn->quote($record["enrollid"]),
            $pdoConn->quote(date("Y-m-d", strtotime($record["time"]))),
            $pdoConn->quote(date("H:i:s", strtotime($record["time"]))),
            $pdoConn->quote($deviceSerial),
            $pdoConn->quote($deviceSerial),
            $pdoConn->quote($organizationId)
        );

        // Step 5: Send notifications to organization users only
        try {
            if ($user && !empty($user['email'])) {
                $userName = !empty($user['name']) ? $user['name'] : 'Unknown User';
                $punchTime = date("Y-m-d H:i:s", strtotime($record["time"]));
                
                // Send email notification
                $subject = 'Attendance Alert - New Record';
                $emailMessage = sprintf(
                    'Dear Parent/Guardian, This is to notify you that %s (Card Number: %s) has been recorded at %s on %s.',
                    $userName,
                    $record["enrollid"],
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
                        'Attendance Alert: %s (Card: %s) at %s',
                        $userName,
                        $record["enrollid"],
                        date("H:i", strtotime($record["time"]))
                    );
                    
                    if (sendSms($smsMessage, $user['phone_number'])) {
                        error_log("SMS sent successfully to: " . $user['phone_number']);
                    } else {
                        error_log("Failed to send SMS to: " . $user['phone_number']);
                    }
                }
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

function getOrCreateUser($punching_code, $name, $phone, $email, $organization_id = null) {
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
        
        // Check if user exists
        $stmt = $pdoConn->prepare("SELECT id, name, email, phone_number, organization_id, status FROM users WHERE punching_code = ?");
        $stmt->execute([$punching_code]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            // User exists - check if we need to update organization or other details
            $updates = [];
            $params = [];
            
            if ($existingUser['organization_id'] != $organization_id) {
                $updates[] = "organization_id = ?";
                $params[] = $organization_id;
                error_log("Updating user {$punching_code} organization from {$existingUser['organization_id']} to {$organization_id}");
            }
            
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
            
            // Ensure user is active
            if ($existingUser['status'] !== 'active') {
                $updates[] = "status = ?";
                $params[] = 'active';
            }
            
            $updates[] = "updated_at = NOW()";
            
            // Perform update if needed
            if (count($params) > 0) {
                $params[] = $punching_code; // For WHERE clause
                $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE punching_code = ?";
                $stmt = $pdoConn->prepare($sql);
                $stmt->execute($params);
                
                return [
                    "status" => "updated",
                    "message" => "User updated with new information",
                    "user_id" => $existingUser['id'],
                    "organization_id" => $organization_id,
                    "organization_name" => $orgName,
                    "changes_made" => count($params) - 1
                ];
            } else {
                return [
                    "status" => "exists",
                    "message" => "User already exists with current information",
                    "user_id" => $existingUser['id'],
                    "organization_id" => $existingUser['organization_id'],
                    "organization_name" => $orgName
                ];
            }
        }

        // User doesn't exist - create new user
        $stmt = $pdoConn->prepare(
            "INSERT INTO users (punching_code, name, email, phone_number, organization_id, status, created_at) 
             VALUES (?, ?, ?, ?, ?, 'active', NOW())"
        );
        
        $stmt->execute([$punching_code, $name, $email, $phone, $organization_id]);
        $newUserId = $pdoConn->lastInsertId();
        
        error_log("Created new user: $punching_code in organization $organization_id ($orgName)");
        
        return [
            "status" => "created",
            "message" => "New user created successfully",
            "user_id" => $newUserId,
            "organization_id" => $organization_id,
            "organization_name" => $orgName
        ];
        
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
function activateUser($punching_code) {
    $pdoConn = getValidConnection();
    
    try {
        $stmt = $pdoConn->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE punching_code = ?");
        $stmt->execute([$punching_code]);
        
        if ($stmt->rowCount() > 0) {
            error_log("Activated user: $punching_code");
            return ["status" => "success", "message" => "User activated successfully"];
        } else {
            return ["status" => "error", "message" => "User not found"];
        }
    } catch (PDOException $e) {
        error_log("Error activating user: " . $e->getMessage());
        return ["status" => "error", "message" => "Database error"];
    }
}

function deactivateUser($punching_code) {
    $pdoConn = getValidConnection();
    
    try {
        $stmt = $pdoConn->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE punching_code = ?");
        $stmt->execute([$punching_code]);
        
        if ($stmt->rowCount() > 0) {
            error_log("Deactivated user: $punching_code");
            return ["status" => "success", "message" => "User deactivated successfully"];
        } else {
            return ["status" => "error", "message" => "User not found"];
        }
    } catch (PDOException $e) {
        error_log("Error deactivating user: " . $e->getMessage());
        return ["status" => "error", "message" => "Database error"];
    }
}

function bulkCreateUsers($users_data, $organization_id = null) {
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
            $result = getOrCreateUser(
                $userData['punching_code'] ?? null,
                $userData['name'] ?? null,
                $userData['phone'] ?? null,
                $userData['email'] ?? null,
                $organization_id
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
            SELECT d.*, o.name as organization_name
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

function updateDeviceStatus($serial_number, $status) {
    $pdoConn = getValidConnection();
    
    try {
        // Check if device exists
        $stmt = $pdoConn->prepare("SELECT id, status FROM devices WHERE serial_number = ?");
        $stmt->execute([$serial_number]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            return [
                "status" => "error",
                "message" => "Device not found"
            ];
        }
        
        // Update device status
        $stmt = $pdoConn->prepare("
            UPDATE devices 
            SET status = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE serial_number = ?
        ");
        $stmt->execute([$status, $serial_number]);
        
        error_log("Updated device $serial_number status from {$device['status']} to $status");
        
        return [
            "status" => "success",
            "message" => "Device status updated successfully",
            "device_id" => $device['id'],
            "serial_number" => $serial_number,
            "previous_status" => $device['status'],
            "new_status" => $status
        ];
        
    } catch (PDOException $e) {
        error_log("Device status update error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error updating device status"
        ];
    }
}

function getUsersByOrganization($organization_id) {
    $pdoConn = getValidConnection();
    
    try {
        $stmt = $pdoConn->prepare("
            SELECT u.*, o.name as organization_name
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE u.organization_id = ?
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$organization_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            "status" => "success",
            "message" => "Users retrieved successfully",
            "organization_id" => $organization_id,
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


function getAllLogs() {
	$pdoConn = getValidConnection();

	try {
        $sql = 'SELECT t.timesheetid, t.punchingcode, t.date, t.time, t.Tid, 
                       u.id as user_id, t.organization_id, u.name, o.name as organization_name 
                FROM tblt_timesheet t
                LEFT JOIN users u ON t.punchingcode = u.punching_code
                LEFT JOIN organizations o ON t.organization_id = o.id
                ORDER BY t.date DESC, t.time DESC';
        $stmt = $pdoConn->prepare($sql);
        $stmt->execute();
        
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Return the logs in JSON format
        return json_encode([
            'logs' => $logs,
            'total_count' => count($logs)
        ]);
    } catch (PDOException $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function getLogsByPunchingCode($punchingCode, $organization_id = null) {
    $pdoConn = getValidConnection();
    try {
        if ($organization_id) {
            $stmt = $pdoConn->prepare("
                SELECT t.*, u.name, o.name as organization_name 
                FROM tblt_timesheet t
                LEFT JOIN users u ON t.punchingcode = u.punching_code
                LEFT JOIN organizations o ON t.organization_id = o.id
                WHERE t.punchingcode = :punchingCode AND t.organization_id = :organization_id
                ORDER BY t.date DESC, t.time DESC
            ");
            $stmt->bindParam(':punchingCode', $punchingCode, PDO::PARAM_STR);
            $stmt->bindParam(':organization_id', $organization_id, PDO::PARAM_INT);
        } else {
            $stmt = $pdoConn->prepare("
                SELECT t.*, u.name, o.name as organization_name 
                FROM tblt_timesheet t
                LEFT JOIN users u ON t.punchingcode = u.punching_code
                LEFT JOIN organizations o ON t.organization_id = o.id
                WHERE t.punchingcode = :punchingCode
                ORDER BY t.date DESC, t.time DESC
            ");
            $stmt->bindParam(':punchingCode', $punchingCode, PDO::PARAM_STR);
        }
        
        // Execute the query
        $stmt->execute();
        // Fetch all matching records
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Return results as JSON
        return json_encode([
            'logs' => $logs,
            'punching_code' => $punchingCode,
            'organization_id' => $organization_id,
            'total_count' => count($logs)
        ]);
    } catch (PDOException $e) {
        // Handle any errors, return as JSON error message
        return json_encode(['error' => $e->getMessage()]);
    }
}

function getLogsByOrganization($organization_id, $date_from = null, $date_to = null) {
    $pdoConn = getValidConnection();
    
    try {
        $sql = "
            SELECT t.*, u.name, u.email, u.phone_number, o.name as organization_name,
                   d.device_name, d.serial_number
            FROM tblt_timesheet t
            LEFT JOIN users u ON t.punchingcode = u.punching_code
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN devices d ON t.device_serial = d.serial_number
            WHERE t.organization_id = ?
        ";
        
        $params = [$organization_id];
        
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
        
        // Get organization details
        $orgStmt = $pdoConn->prepare("SELECT name FROM organizations WHERE id = ?");
        $orgStmt->execute([$organization_id]);
        $organization = $orgStmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            "status" => "success",
            "message" => "Logs retrieved successfully",
            "organization_id" => $organization_id,
            "organization_name" => $organization['name'] ?? 'Unknown',
            "date_range" => [
                "from" => $date_from,
                "to" => $date_to
            ],
            "total_count" => count($logs),
            "logs" => $logs
        ];
        
    } catch (PDOException $e) {
        error_log("Get logs by organization error: " . $e->getMessage());
        return [
            "status" => "error",
            "message" => "Database error retrieving logs"
        ];
    }
}

function exportLogsToExcel() {
    $pdoConn = getValidConnection();

    // Step 1: Fetch data from the database
    $stmt = $pdoConn->query("SELECT * FROM tblt_timesheet");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Step 2: Initialize Spreadsheet
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Step 3: Populate spreadsheet headers
    $sheet->setCellValue('A1', 'Punching Code');
    $sheet->setCellValue('B1', 'Date');
    $sheet->setCellValue('C1', 'Time');
    $sheet->setCellValue('D1', 'Tid');

    // Step 4: Populate spreadsheet data
    $row = 2;
    foreach ($logs as $log) {
        $sheet->setCellValue('A' . $row, $log['punchingcode']);
        $sheet->setCellValue('B' . $row, $log['date']);
        $sheet->setCellValue('C' . $row, $log['time']);
        $sheet->setCellValue('D' . $row, $log['Tid']);
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
                   created_at as dateJoined, updated_at
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
                   o.created_at as dateJoined, o.updated_at,
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

function updateOrganization($organization_id, $name = null, $address = null, $contact_person = null, $email = null, $phone = null, $description = null) {
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
            SELECT d.*, o.name as organization_name
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

function getLogsByDevice($device_serial) {
    $pdoConn = getValidConnection();
    
    try {
        $sql = 'SELECT t.timesheetid, t.punchingcode, t.date, t.time, t.Tid, 
                       u.id as user_id, t.organization_id, u.name, o.name as organization_name 
                FROM tblt_timesheet t
                LEFT JOIN users u ON t.punchingcode = u.punching_code
                LEFT JOIN organizations o ON t.organization_id = o.id
                WHERE t.Tid = ?
                ORDER BY t.date DESC, t.time DESC';
        
        $stmt = $pdoConn->prepare($sql);
        $stmt->execute([$device_serial]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            "status" => "success",
            "message" => "Logs retrieved successfully",
            "device_serial" => $device_serial,
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
        // Get admin by email
        $stmt = $pdoConn->prepare("
            SELECT a.*, o.name as organization_name 
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
        
        return [
            'status' => 'success',
            'message' => 'Admin created successfully',
            'admin_id' => $adminId
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

?>