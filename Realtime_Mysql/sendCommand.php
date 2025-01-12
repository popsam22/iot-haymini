<?php
	//$cmd = '{"cmd":"settime","cloudtime":"2018-09-01 12:10:40"}';
	//$cmd = '{"cmd":"getalllog","stn":true,"from":"2018-01-01","to":"2019-10-10"}';
	//$cmd='{"cmd":"getuserlist","stn":true}';
	//$cmd='{"cmd":"getuserinfo","enrollid":147258,"backupnum":50}';
	
	if(isset($_POST['cmd']))
	{
		$cmd=$_POST['cmd'];
		echo sendCommandToMachine("cmd",$cmd);
		echo '<script>alert("已发送'.$cmd.'");</script>';
	}
	function sendCommandToMachine($sn , $cmd){
		$file = file_put_contents("./commands/".$sn.".txt",$cmd);
		return "ok $cmd";
	}
	
#Set time : {"cmd":"settime","cloudtime":"2018-10-10 11:37:40"}
#
?>
<form action="" method="post">
    <p><input type="text" name="cmd" value=getList hidden></p>
    
    <p>
        <input type="submit" value='getuserlist'>
    </p>
</form>

<form action="" method="post">
    <p><input type="text" name="cmd" value=getInfo hidden></p>
    
    <p>
        <input type="submit" value='getuserinfo'>
    </p>
</form>

<form action="" method="post">
    <p><input type="text" name="cmd" value=exit hidden></p>
    
    <p>
        <input type="submit" value='exit'>
    </p>
</form>
