<?php echo '<p>Hello World</p>'; ?>
<?php
ob_start();
phpinfo();
$info = ob_get_clean();
echo $info;