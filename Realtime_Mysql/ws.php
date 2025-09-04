<?php
require 'mailer.php';
require "termii.php";
include_once 'config.php';
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();
error_reporting(E_ALL);

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

if ($_SERVER['REQUEST_METHOD'] === 'GET' || php_sapi_name() === "cli") {
    $action = $_GET['action'] ?? null;

    if ($action) {
        switch ($action) {
            case 'getLogs':
                $organization_id = filter_input(INPUT_GET, 'organization_id', FILTER_VALIDATE_INT);
                echo getAllLogs($organization_id);
				exit;
                break;

            case 'getLogsByPunchingCode':
                $punchingcode = filter_input(INPUT_GET, 'punchingcode', FILTER_SANITIZE_STRING);
                $organization_id = filter_input(INPUT_GET, 'organization_id', FILTER_VALIDATE_INT);
                if ($punchingcode) {
                   
                    echo getLogsByPunchingCode($punchingcode, $organization_id);
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing or invalid punchingcode parameter']);
                }
                break;

            case 'getLogsByOrganization':
                $organization_id = filter_input(INPUT_GET, 'organization_id', FILTER_VALIDATE_INT);
                $date_from = filter_input(INPUT_GET, 'date_from');
                $date_to = filter_input(INPUT_GET, 'date_to');
                
                if ($organization_id) {
                    echo json_encode(getLogsByOrganization($organization_id, $date_from, $date_to));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required parameter (organization_id)']);
                }
                break;

            case 'exportLogs':
                exportLogsToExcel();
                break;

            case 'getOrCreateUser':
                $punching_code = filter_input(INPUT_GET, 'punching_code');
                $name = filter_input(INPUT_GET, 'name');
                $phone = filter_input(INPUT_GET, 'phone');
                $email = filter_input(INPUT_GET, 'email');
                $organization_id = filter_input(INPUT_GET, 'organization_id', FILTER_VALIDATE_INT);

                if ($punching_code && $name && $phone && $email) {
                    echo json_encode(getOrCreateUser($punching_code, $name, $phone, $email, $organization_id));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required parameters (punching_code, name, phone, email)']);
                }
                break;

            case 'registerDevice':
                $serial_number = filter_input(INPUT_GET, 'serial_number');
                $organization_id = filter_input(INPUT_GET, 'organization_id', FILTER_VALIDATE_INT);
                $device_name = filter_input(INPUT_GET, 'device_name');
                $device_model = filter_input(INPUT_GET, 'device_model');
                $ip_address = filter_input(INPUT_GET, 'ip_address');

                if ($serial_number && $organization_id) {
                    echo json_encode(registerDevice($serial_number, $organization_id, $device_name, $device_model, $ip_address));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required parameters (serial_number, organization_id)']);
                }
                break;

            case 'assignDeviceToOrganization':
                $serial_number = filter_input(INPUT_GET, 'serial_number');
                $organization_id = filter_input(INPUT_GET, 'organization_id', FILTER_VALIDATE_INT);

                if ($serial_number && $organization_id) {
                    echo json_encode(assignDeviceToOrganization($serial_number, $organization_id));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required parameters (serial_number, organization_id)']);
                }
                break;

            case 'getDevicesByOrganization':
                $organization_id = filter_input(INPUT_GET, 'organization_id', FILTER_VALIDATE_INT);

                if ($organization_id) {
                    echo json_encode(getDevicesByOrganization($organization_id));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required parameter (organization_id)']);
                }
                break;

            case 'updateDeviceStatus':
                $serial_number = filter_input(INPUT_GET, 'serial_number');
                $status = filter_input(INPUT_GET, 'status');

                if ($serial_number && $status && in_array($status, ['active', 'inactive', 'maintenance'])) {
                    echo json_encode(updateDeviceStatus($serial_number, $status));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing or invalid parameters (serial_number, status: active/inactive/maintenance)']);
                }
                break;

            case 'activateUser':
                $punching_code = filter_input(INPUT_GET, 'punching_code');

                if ($punching_code) {
                    echo json_encode(activateUser($punching_code));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required parameter (punching_code)']);
                }
                break;

            case 'deactivateUser':
                $punching_code = filter_input(INPUT_GET, 'punching_code');

                if ($punching_code) {
                    echo json_encode(deactivateUser($punching_code));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required parameter (punching_code)']);
                }
                break;

            case 'bulkCreateUsers':
                // Get JSON data from request body
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if ($data && isset($data['users'])) {
                    $users_data = $data['users'];
                    $organization_id = isset($data['organization_id']) ? intval($data['organization_id']) : null;
                    echo json_encode(bulkCreateUsers($users_data, $organization_id));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing or invalid JSON data. Expected format: {"users": [...], "organization_id": 1}']);
                }
                break;

            case 'getUsersByOrganization':
                $organization_id = filter_input(INPUT_GET, 'organization_id', FILTER_VALIDATE_INT);

                if ($organization_id) {
                    echo json_encode(getUsersByOrganization($organization_id));
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required parameter (organization_id)']);
                }
                break;

            default:
                // Invalid action provided
                http_response_code(400);
                echo json_encode([
                    'error' => 'Invalid action',
                    'available_actions' => [
                        'getLogs',
                        'getLogsByPunchingCode',
                        'getLogsByOrganization',
                        'exportLogs',
                        'getOrCreateUser',
                        'activateUser',
                        'deactivateUser', 
                        'bulkCreateUsers',
                        'getUsersByOrganization',
                        'registerDevice',
                        'assignDeviceToOrganization',
                        'getDevicesByOrganization',
                        'updateDeviceStatus'
                    ]
                ]);
                break;
        }
    } else {
        http_response_code(200);
        echo json_encode([
            'message' => 'Welcome to the API',
            'instructions' => [
                'getLogs' => '/ws.php?action=getLogs',
                'getLogsByPunchingCode' => '/ws.php?action=getLogsByPunchingCode&punchingcode={value}',
                'exportLogs' => '/ws.php?action=exportLogs',
						'getOrCreateUser' => '/ws.php?action=getOrCreateUser&punching_code={value}&name={value}&phone={value}&email={value}'
            ]
        ]);
    }
} else {
    // Unsupported request method
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
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

echo " start...$startData </br>";
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
				echo "</br>accept : $address:$port ".date('His');
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
							echo "</br>connected : $address:$port ".date('His');
							
						}
						else 
							echo "</br>shakehand failed ".$frame;
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
								echo "</br>next:".$DataLen;
							}
							
					safe_output_flush();
						}
						else //长度错误
						{
							echo "</br>err len:".$payloadlength;
							break;
						}
						$NeedLen=2;
					}
					$data["id{$address}_{$port}"]=$DataRec;
					
				}else{
					//if($frame===false)
					{
						echo "</br>disconnected : $address:$port ".date('His')."</br>";
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
			echo "</br></br>cmd:".$packetCommand;
			
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
		echo "</br>【sendcmd】".$bufferCommand." cont=".$sendCount."</br>";
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
	echo "</br>【sendping】".ord($bufferCommand[0]).ord($bufferCommand[1]).$bufferCommand." cont=".$sendCount."/".date('His');;
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
		echo "</br>".$optcode.":";
		echo strlen($packet);
		if(strlen($packet)>200)
			echo substr($packet,0,200);
		else
			echo $packet;
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
			echo("</br>type:".$optcode);
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
					echo "<br>count:".count($userData)." new:".count($records);
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
			echo "</br>write:".ord($buffer[0]).ord($buffer[1]).":".$retTxt;
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
	echo "</br>save file ".$fileName;
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
    global $pdoConn;

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
    global $pdoConn;
    
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
    global $pdoConn;
    
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
    global $pdoConn;
    
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
    global $pdoConn;
    
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
    global $pdoConn;
    
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
    global $pdoConn;
    
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
    global $pdoConn;
    
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
    global $pdoConn;
    
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
    global $pdoConn;
    
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


function getAllLogs($organization_id = null) {
	global $pdoConn;

	try {
        if ($organization_id) {
            $sql = 'SELECT t.*, u.name, o.name as organization_name 
                    FROM tblt_timesheet t
                    LEFT JOIN users u ON t.punchingcode = u.punching_code
                    LEFT JOIN organizations o ON t.organization_id = o.id
                    WHERE t.organization_id = ?
                    ORDER BY t.date DESC, t.time DESC';
            $stmt = $pdoConn->prepare($sql);
            $stmt->execute([$organization_id]);
        } else {
            $sql = 'SELECT t.*, u.name, o.name as organization_name 
                    FROM tblt_timesheet t
                    LEFT JOIN users u ON t.punchingcode = u.punching_code
                    LEFT JOIN organizations o ON t.organization_id = o.id
                    ORDER BY t.date DESC, t.time DESC';
            $stmt = $pdoConn->prepare($sql);
            $stmt->execute();
        }
        
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Return the logs in JSON format
        return json_encode([
            'logs' => $logs,
            'organization_id' => $organization_id,
            'total_count' => count($logs)
        ]);
    } catch (PDOException $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function getLogsByPunchingCode($punchingCode, $organization_id = null) {
    global $pdoConn;
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
    global $pdoConn;
    
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
    global $pdoConn;

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

?>