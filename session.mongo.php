<?php

/**
 * session_mongo
 * php sessions stored in mongo
 */
class session_mongo {
	// collection php_session; for reference
	static $collection = array(
		'_id'       => 'MongoId',
		'data' 		=> 'string',	// serialized session data
		'expires'   => 'int32',		// unixts when session expires ie no longer valid and eligible for gc
		'sessionid' => 'string', 	// unique index
		'updated'   => 'int32', 	// unixts when last updated
		'userid'    => 'MongoId'	// user id, custom field
	);

	static private $col = null;					// collection reference
	static private $started = false; 			// already started?
	static private $opts = array();				// options, see start()


	// php session handler
	// references:
	// https://github.com/richsage/Symfony2-MongoDB-session-storage/blob/master/MongoDBSessionStorage.php
	// https://github.com/sourcemap/Codeigniter-Sessions-with-MongoDB-PHP-Driver/blob/master/application/libraries/MY_Session.php
	// https://github.com/gargoyle/MongoSession/blob/master/MongoSession.php
	//

	/**
	 * start a session
	 * connect to database, register session handlers, start session
	 *
	 * @param array $opts	see $defaults
	 *
	 * @return bool
	 */
	public static function start($opts=array()) {

		if (self::$started) {
			return true;
		}

		$m_time_start = microtime(true);

		// see php.ini for other sessions defaults; session.*

		$defaults = array(
			'collection' 	 => 'php_session',	// collection name
			'database'		 => 'test_database',// db name
			'gc_maxlifetime' => 1440, 			// ini_get('session.gc_maxlifetime'), // default 1440
			'name'			 => 'sid',			// session (cookie) name
			'timeout'		 => 1000, 			// ms
			'uri'			 => 'mongodb://127.0.0.1:27017'	// host:port
		);
		self::$opts = array_merge($defaults, $opts);

		// very basic mongo connection, you will probably use your own db handler (or pass it in)

		try {
			$mongo = new Mongo(self::$opts['uri']);
		} catch(Exception $e) {
			error_log('No database connection; Unable to initialize sessions; '.$e->getMessage());
			return false;
		}
		try {
			$db = $mongo->selectDB(self::$opts['database']);
		} catch(Exception $e) {
			error_log('Cannot select database; Unable to initialize sessions; '.$e->getMessage());
			return false;
		}
		if ($db === false) {
			error_log('Cannot select database; Unable to initialize sessions');
			return false;
		}
		try {
			self::$col = $db->selectCollection(self::$opts['collection']);
		} catch(Exception $e) {
			error_log('Cannot select collection; Unable to initialize sessions; '.$e->getMessage());
			return false;
		}
		if (self::$col === false) {
			error_log('Cannot select collection; Unable to initialize sessions');
			return false;
		}

		// use this object as the session handler
		session_set_save_handler(
			array('session_mongo', '_open'),
			array('session_mongo', '_close'),
			array('session_mongo', '_read'),
			array('session_mongo', '_write'),
			array('session_mongo', '_destroy'),
			array('session_mongo', '_gc')
		);

		// at end of script, write session data and end session
		register_shutdown_function('session_write_close');

		// start session
		session_name(self::$opts['name']);
		session_start();

		self::$started = true;

		error_log('session connect time: '.sprintf('%.4f', microtime(true) - $m_time_start).'s');

		return true;
	}

	/**
	 * opens a session
	 *
	 * @param string $path
	 * @param string $name
	 *
	 * @return bool
	 */
	public static function _open($path=null, $name=null) {
		return true;
	}

	/**
	 * closes a session
	 *
	 * @return bool
	 */
	public static function _close() {
		return true;
	}

	/**
	 * reads a session
	 *
	 * @param string $id 	session id
	 *
	 * @return string session data
	 */
	public static function _read($id) {
		$now = time();

		$doc = self::$col->findOne(
			array(
				'sessionid' => $id		// where
			),
			array(						// fields
				'data',
				'expires'
			)
		);
		if ($doc && isset($doc['data'])) {
			// have data
			if ($doc['expires'] < $now) {
				// but expired
				$ret =  '';
			} else {
				// data good, return
				$ret = $doc['data'];
			}
		} else {
			// could not find session, so create a new one
			// creating (once) on read allows userid (and other custom fields) to be initialized
			$ret = '';
			self::$col->insert(
				array(
					'data' 		=> '',
					'expires' 	=> $now + self::$opts['gc_maxlifetime'],
					'sessionid' => $id,
					'updated' 	=> $now,
					'userid' 	=> null
				),
				array(
					'fsync'	 	=> true,		// write to disk
					'safe'	 	=> true,		// block
					'timeout'	=> self::$opts['timeout']
				)
			);
		}
		return $ret;
	}

	/**
	 * writes session data
	 *
	 * @param string $id 	session id
	 * @param string $data 	serialized set of session data
	 *
	 * @return bool
	 */
	public static function _write($id, $data) {
		$now = time();

		$ret = self::$col->update(
			array(
				'sessionid' => $id			// where
			),
			array('$set' => array(			// set
				'data' 		=> $data,
				'expires' 	=> $now + self::$opts['gc_maxlifetime'],
				'updated' 	=> $now
			)),
			array(
				'fsync'	 	=> true,		// write to disk
				'safe'	 	=> true,		// block
				'multiple' 	=> false,		// only update one doc
				'timeout'	=> self::$opts['timeout'],
				'upsert' 	=> false		// do not create if does not exist
			)
		);
		return $ret && $ret['ok'] == 1; // updated or not
	}

	/**
	 * updateUserId
	 * update userid in session; so sessions can be deleted by userid (kick a user)
	 *
	 * @param string $userid 	user id
	 *
	 * @return bool
	 */
	public static function updateUserId($userid) {
		$now = time();

		$ret = self::$col->update(
			array(
				'sessionid' => session_id()			// where
			),
			array('$set' 	=> array(				// set
				'userid' 	=> $userid ? new MongoId($userid) : null,
				'expires' 	=> $now + self::$opts['gc_maxlifetime'],
				'updated' 	=> $now
			)),
			array(
				'fsync'	 	=> true,				// write to disk
				'multiple' 	=> false,				// only update one doc
				'safe'	 	=> true,				// block
				'timeout'	=> self::$opts['timeout'],
				'upsert' 	=> false				// do not create if does not exist
			)
		);
		return $ret && $ret['ok'] == 1;
	}

	/**
	 * destroys a session
	 * executed when a session is destroyed with session_destroy() or with session_regenerate_id()
	 *
	 * @param string $id 	session id to destroy
	 *
	 * @return bool
	 */
	public static function _destroy($id) {
		$ret = self::$col->remove(
			array(
				'sessionid' => $id			// where
			),
			array(
				'fsync'	 	=> true,		// write to disk
				'justOne' 	=> false,		// remove multiple, if any; should only be one, but eh
				'safe'	 	=> true,		// block
				'timeout'	=> self::$opts['timeout']
			)
		);
		return $ret;
	}

	/**
	 * cleans up old sessions
	 * called by php based on session.gc_probability and session.gc_divisor
	 *
	 * @param int $lifetime 	lifetime of a session (s)
	 *
	 * @return bool
	 */
	public static function _gc($lifetime) {
		$now = time();

		self::$col->remove(
			array(
				'expires' => array('$lt' => $now - $lifetime)	// where expired
			),
			array(
				'fsync'	 	=> false,		// don't force write to disk
				'justOne' 	=> false,		// remove multiple, if any
				'safe'	 	=> false,		// don't block
				'timeout'	=> self::$opts['timeout']
			)
		);
		return true;
	}



	/**
	 * regenerates the session id
	 *
	 * @param bool $destroy 	true - start new session w/o data from last session; caller should prob header/redirect
	 *
	 * @return bool
	 */
	public static function regenerate($destroy=true)	{
		$ret = session_regenerate_id($destroy);
		return $ret;
	}
}
