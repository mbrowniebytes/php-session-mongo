php-session-mongo
========
PHP session handler using mongo

usage
========
````
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
````

mongo collection
========
````
	static $collection = array(
		'_id'       => 'MongoId',
		'data' 		=> 'string',	// serialized session data
		'expires'   => 'int32',		// unixts when session expires ie no longer valid and eligible for gc
		'sessionid' => 'string', 	// unique index
		'updated'   => 'int32', 	// unixts when last updated
		'userid'    => 'MongoId'	// user id, custom field
	);
````
license
========
http://opensource.org/licenses/MIT


