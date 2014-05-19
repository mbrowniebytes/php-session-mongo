<?php


include 'session.mongo.php';

session_mongo::start(array(
	'collection' 	 => 'php_session',	// collection name
	'database'		 => 'test_database',// db name
	'gc_maxlifetime' => 1440, 			// ini_get('session.gc_maxlifetime'), // default 1440
	'name'			 => 'sid',			// session (cookie) name
	'timeout'		 => 1000, 			// ms
	'uri'			 => 'mongodb://127.0.0.1:27017'	// host:port
));


if (isset($_GET['set'])) {
	$_SESSION['test'] = 'Test session value set at: '.date('Y-m-d H:i:s');
} else if (isset($_GET['regenerate'])) {
	session_mongo::regenerate();
	header('Location: '.$_SERVER['PHP_SELF']);
	exit();
}

echo '<h3>Test php-session-mongo</h3><p>
<form action="example.php" method="GET">
<input name="reload" type="submit" value="Reload">
<input name="set" type="submit" value="Set Session">
<input name="regenerate" type="submit" value="New Session">
</form>
';
echo '<p>Session at '.date('Y-m-d H:i:s').': <pre>';
print_r($_SESSION);
echo '</pre><p>';


