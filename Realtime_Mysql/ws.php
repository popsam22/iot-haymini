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
    // Simulate $_GET for CLI
    $options = getopt("", ["action:", "punchingcode:"]);
    $_GET = $options ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' || php_sapi_name() === "cli") {
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'getLogs':
                header('Content-Type: application/json');
                echo getAllLogs();
                exit();

            case 'getLogsByPunchingCode':
                $punchingcode = filter_input(INPUT_GET, 'punchingcode', FILTER_SANITIZE_STRING);
                if ($punchingcode) {
                    header('Content-Type: application/json');
                    echo getLogsByPunchingCode($punchingcode);
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing or invalid punchingcode parameter']);
                }
                exit();

            case 'exportLogs':
                exportLogsToExcel();
                exit();

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
                exit();
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Action parameter is required']);
        exit();
    }
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
ob_flush();

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
				ob_flush();
				
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
						ob_flush();
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
							
							ob_flush();
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
						ob_flush();
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
		$bufferCommand = ($size<126?pack('CC', $code, $size):($size<65536?pack('CCn', $code, 126, $size):pack('CCNN', $code, 127,0, $size))).$retTxt;'{"ret":"sendlog", "result":true, "cloudtime":"'.date('Y-m-d H:i:s').'"}';
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
		ob_flush();
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
	ob_flush();
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

// function store($records, $id , $sts = 0){
// 		global $pdoConn;
// 		$sql = 'Insert INTO tblt_timesheet(punchingcode,date,time,Tid) values';
// 		$sqlArray = array();
// 		foreach($records as $record){
// 				$sqlArray[] = '("'.$record["enrollid"].'","'.date("Y-m-d",strtotime($record["time"])).'","'.date("H:i:s",strtotime($record["time"])).'","'.$id.'")';

// 				//email sending
// 				// $to =  'balogunayobamme@gmail.com';
// 				$to =  'sobiechie16@gmail.com';
// 				$message = 'New Entry For you Habib';
// 				$subject = 'New record has been inserted with details: Card Number: '.$record["enrollid"].', Date: '.date("Y-m-d", strtotime($record["time"])).', Time: '.date("H:i:s", strtotime($record["time"]));

// 				sendEmail($to, $subject, $message);

// 				// $phoneNumber = '2347036248186'; 
// 				$phoneNumber = '2349134327450'; 
//         $smsMessage = 'Card Number: ' . $record["enrollid"] . ', Date: ' . date("Y-m-d", strtotime($record["time"])) . ', Time: ' . date("H:i:s", strtotime($record["time"]));
//         sendSms($smsMessage, $phoneNumber);
// 		}
// 		if(!empty($sqlArray)){
// 			$sql2=$sql.implode(",",$sqlArray);
// 			echo "</br>sql".$sql2;
// 			$stmt = $pdoConn->prepare($sql2);
// 			try {
// 			   $exec = $stmt->execute();
// 			} catch (PDOException $e){
// 			   echo $e->getMessage();
// 			}		
// 			if($exec){
// 				if($sts){
// 					$result = '{"cmd":"getalllog","stn":false,"cloudtime":"'.date('Y-m-d H:i:s').'"}';
// 				}else{
// 					$result = '{"ret":"sendlog","result":true,"cloudtime":"'.date('Y-m-d H:i:s').'"}';				
// 				}
// 				return $result;
// 			}else{
// 				$result = '{"ret":"sendlog","result":false,"reason":1}';			
// 			}
// 		}else{
// 				$result = '{"ret":"sendlog","result":false,"reason":1}';			
// 		}
// 		//return $result;
// 	return '{"ret":"sendlog", "result":true, "cloudtime":"'.date('Y-m-d H:i:s').'"}';;

// }

function store($records, $id, $sts = 0) {
    global $pdoConn;

    // Base SQL query
    $sql = 'INSERT INTO tblt_timesheet (punchingcode, date, time, Tid) VALUES ';
    $sqlArray = [];

    foreach ($records as $record) {
        // Validate time field
        if (empty($record["time"]) || !strtotime($record["time"])) {
            continue; // Skip invalid records
        }

        // Check for duplicates in the database
        $stmt = $pdoConn->prepare(
            "SELECT COUNT(*) FROM tblt_timesheet WHERE punchingcode = ? AND date = ? AND time = ?"
        );
        $stmt->execute([
            $record["enrollid"],
            date("Y-m-d", strtotime($record["time"])),
            date("H:i:s", strtotime($record["time"]))
        ]);

        if ($stmt->fetchColumn() == 0) {
            // Add to SQL Array if not duplicate
            $sqlArray[] = '("' . $record["enrollid"] . '", "' . date("Y-m-d", strtotime($record["time"])) . '", "' . date("H:i:s", strtotime($record["time"])) . '", "' . $id . '")';

            // Send email notification
            $to = 'sobiechie16@gmail.com';
            $subject = 'New record has been inserted';
            $message = 'Details: Card Number: ' . $record["enrollid"] . ', Date: ' . date("Y-m-d", strtotime($record["time"])) . ', Time: ' . date("H:i:s", strtotime($record["time"]));
            sendEmail($to,$message, $subject);

            // Send SMS notification
            $phoneNumber = '2349134327450';
            $smsMessage = 'Card Number: ' . $record["enrollid"] . ', Date: ' . date("Y-m-d", strtotime($record["time"])) . ', Time: ' . date("H:i:s", strtotime($record["time"]));
            sendSms($smsMessage, $phoneNumber);
        }
    }

    if (!empty($sqlArray)) {
        // Combine SQL statements and prepare query
        $sql2 = $sql . implode(",", $sqlArray);

        try {
            $stmt = $pdoConn->prepare($sql2);
            $exec = $stmt->execute();
        } catch (PDOException $e) {
            // Log SQL error
            error_log("SQL Error: " . $e->getMessage());
            return '{"ret":"sendlog","result":false,"reason":"SQL Error"}';
        }

        if ($exec) {
            $result = $sts
                ? '{"cmd":"getalllog","stn":false,"cloudtime":"' . date('Y-m-d H:i:s') . '"}'
                : '{"ret":"sendlog","result":true,"cloudtime":"' . date('Y-m-d H:i:s') . '"}';
            return $result;
        } else {
            return '{"ret":"sendlog","result":false,"reason":"Execution failed"}';
        }
    }

    return '{"ret":"sendlog","result":false,"reason":"No records to insert"}';
}


function getAllLogs() {
	global $pdoConn;
	try {
        $sql = 'SELECT punchingcode, date, time, Tid FROM tblt_timesheet ORDER BY date DESC, time DESC';
        $stmt = $pdoConn->prepare($sql);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Return the logs in JSON format
        return json_encode(['logs' => $logs]);
    } catch (PDOException $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function getLogsByPunchingCode($punchingCode) {
    global $pdoConn;
    try {
        $stmt = $pdoConn->prepare("SELECT * FROM tblt_timesheet WHERE punchingcode = :punchingCode");
        $stmt->bindParam(':punchingCode', $punchingCode, PDO::PARAM_STR);
        // Execute the query
        $stmt->execute();
        // Fetch all matching records
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Return results as JSON
        return json_encode($logs);
    } catch (PDOException $e) {
        // Handle any errors, return as JSON error message
        return json_encode(['error' => $e->getMessage()]);
    }
}

// function getUserList() {
//     global $socket; // Assuming you have a socket connection setup
//     $userList = [];
//     $from = 0;
//     $to = 39;
//     $stn = true;

//     do {
//         // Step 1: Send request to device
//         $request = [
//             "cmd" => "getuserlist",
//             "stn" => $stn
//         ];
//         socket_write($socket, json_encode($request));

//         // Step 2: Read response from the device
//         $response = socket_read($socket, 2048);
//         $data = json_decode($response, true);

//         if ($data['result'] === true && $data['count'] > 0) {
//             // Step 3: Append retrieved users to the user list
//             $userList = array_merge($userList, $data['record']);
//             $from += 40; // Increment to request the next batch
//             $to += 40;
//             $stn = false; // For subsequent requests
//         } else {
//             break; // Exit loop if no more records
//         }
//     } while ($data['count'] == 40); // Continue if we received a full package

//     return json_encode(["status" => "success", "users" => $userList]);
// }

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