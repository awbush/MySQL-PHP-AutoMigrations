<?php
/**
* This file houses the MpmMigrationHelper class.
*
* @package    mysql_php_migrations
* @subpackage Controllers
* @license    http://www.opensource.org/licenses/bsd-license.php  The New BSD License
* @link       http://code.google.com/p/mysql-php-migrations/
*/

/**
* The MpmMigrationHelper contains a number of static functions which are used during the migration process.
*
* @package    mysql_php_migrations
* @subpackage Controllers
*/
class MpmMigrationHelper
{

	/**
	* Sets the current active migration.
	*
	* @uses MpmDbHelper::getDbObj()
	*
	* @param int $id the ID of the migration to set as the current one
	*
	* @return void
	*/
	static public function setCurrentMigration($id)
	{
		$sql1 = "UPDATE `mpm_migrations` SET `is_current` = '0'";
		$sql2 = "UPDATE `mpm_migrations` SET `is_current` = '1' WHERE `id` = {$id}";
		$obj = MpmDbHelper::getDbObj();
		$obj->beginTransaction();
		try
		{
			$obj->exec($sql1);
			$obj->exec($sql2);
		}
		catch (Exception $e)
		{
			$obj->rollback();
			echo "\n\tQuery failed!";
			echo "\n\t--- " . $e->getMessage();
			exit;
		}
		$obj->commit();
	}


	/**
	* Performs a single migration.
	*
	* @uses MpmStringHelper::getFilenameFromTimestamp()
	* @uses MpmDbHelper::getPdoObj()
	* @uses MpmDbHelper::getMysqliObj()
	* @uses MpmCommandLineWriter::getInstance()
	* @uses MpmCommandLineWriter::writeLine()
	* @uses MPM_DB_PATH
	*
	* @param object  $obj        		    a simple object with migration information (from a migration list)
	* @param int    &$total_migrations_run a running total of migrations run
	* @param bool    $forced               if true, exceptions will not cause the script to exit
	*
	* @return void
	*/
	static public function runMigration(&$obj, $method = 'up', $forced = false)
	{
		$file_timestamp = MpmStringHelper::getFilenameFromTimestamp($obj->timestamp);
		if($method == 'up') {
			$classname = 'Migration_' . $file_timestamp;
			
			// make sure the file exists; if it doesn't, skip it but display a message
			$files = glob(MPM_DB_PATH.$file_timestamp.'*.php');
			if(empty($files)) {
				echo "\n\tMigration " . $obj->timestamp . ' (ID '.$obj->id.') skipped - file missing.';
				return;
			}

			if(count($files)>1) {
				echo "\n\tError: Duplicate migration timestamp found! " . $obj->timestamp . ' (ID '.$obj->id.')' ;
				exit;
			}
			$filename = $files[0];

			// file exists -- run the migration
			echo "\n\tPerforming " . strtoupper($method) . " migration " . $obj->timestamp . ' (ID '.$obj->id.')... ';
			require_once($filename);
			$migration = new $classname();

		} else {
			//migrate down via stored database objects
			//fetch object from database
			//unserialize
			eval('?>'.$obj->objectstore.'<?');
			$down_migration = 'Migration_objectstore_'.$file_timestamp;

			$migration = new $down_migration();
			if(!$migration) {
				echo "failed!";

				$clw = MpmCommandLineWriter::getInstance();
				$clw->writeLine("Migration " . $obj->timestamp . ' (ID '.$obj->id.') - Object missing or broken', 12);
				if (!$forced)
				{
					echo "\n\n";
					exit;
				}
				else
				{
					return;
				}
			}
			echo "\n\tPerforming " . strtoupper($method) . " migration " . $obj->timestamp . ' (ID '.$obj->id.')... ';
		}

		
		if ($migration instanceof MpmMigration) // need PDO object
		{
			$dbObj = MpmDbHelper::getPdoObj();
		}
		else
		{
			$dbObj = MpmDbHelper::getMysqliObj();
		}
		$dbObj->beginTransaction();
		if ($method == 'down')
		{
			$active = 0;
		}
		else
		{
			$active = 1;
		}

		try
		{
			$migration->$method($dbObj);
			if ($method == 'up') {
				//fetch object, store in database
				$string = file_get_contents($filename);
				$string = preg_replace('/(class Migration_)(\d{4}(?:_\d{2}){5})/','$1objectstore_$2',$string,1);
				$query_serial = sprintf(', objectstore="%s" ',$dbObj->real_escape_string($string));
				$sql = "UPDATE `mpm_migrations` SET `active` = '$active' $query_serial WHERE `id` = {$obj->id}";
			} else {
				//delete from database. 
				//Old and new items may need to take same ID space in DB
				$sql = "DELETE FROM `mpm_migrations` WHERE `id` = {$obj->id}";
			}
			$dbObj->exec($sql);

		}
		catch (Exception $e)
		{
			$dbObj->rollback();
			echo "failed!";
			echo "\n";
			$clw = MpmCommandLineWriter::getInstance();
			$clw->writeLine($e->getMessage(), 12);
			if (!$forced)
			{
				echo "\n\n";
				exit;
			}
			else
			{
				return;
			}
		}
		$dbObj->commit();    
		echo "done.";
	}

	/**
	* Returns the timestamp of the migration currently rolled to.
	*
	* @uses MpmDbHelper::getDbObj()
	* @uses MpmDbHelper::getMethod()
	* @uses MPM_METHOD_PDO
	* @uses MPM_METHOD_MYSQLI
	*
	* @return string
	*/
	static public function getCurrentMigrationTimestamp()
	{
		// Resolution to Issue #1 - PDO::rowCount is not reliable
		$sql1 = "SELECT COUNT(*) as total FROM `mpm_migrations` WHERE `is_current` = 1";
		$sql2 = "SELECT `timestamp` FROM `mpm_migrations` WHERE `is_current` = 1";
		$dbObj = MpmDbHelper::getDbObj();
		switch (MpmDbHelper::getMethod())
		{
			case MPM_METHOD_PDO:
				$stmt = $dbObj->query($sql1);
				if ($stmt->fetchColumn() == 0)
				{
					return false;
				}
				unset($stmt);
				$stmt = $dbObj->query($sql2);
				$row = $stmt->fetch(PDO::FETCH_ASSOC);
				$latest = $row['timestamp'];
				break;
			case MPM_METHOD_MYSQLI:
				$result = $dbObj->query($sql1);
				$row = $result->fetch_object();
				if ($row->total == 0)
				{
					return false;
				}
				$result->close();
				unset($result);
				$result = $dbObj->query($sql2);
				$row = $result->fetch_object();
				$latest = $row->timestamp;
				break;
		}
		return $latest;
	}

	/**
	* Returns the next migration in a direction given a timestamp
	*
	* @uses MpmMigrationHelper::getTimestampFromId()
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getPdoObj()
	* @uses MpmDbHelper::getMysqliObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @param string $timestamp the timestamp of the migration to compare against
	* @param string $direction the direction of the migration; should be 'up' or 'down'
	*
	* @return array
	*/
	static public function getNextTimestamp($timestamp, $direction = 'up')
	{
		$result = false;
		if ($direction == 'up')
		{
			$sql = "SELECT `id`, `timestamp` FROM `mpm_migrations` WHERE `active` = 0 AND `timestamp` > '$timestamp' ORDER BY `timestamp` ASC LIMIT 1";
		}
		else
		{
			$sql = "SELECT `id`, `timestamp`, `objectstore` FROM `mpm_migrations` WHERE `active` = 1 AND `timestamp` < '$timestamp' ORDER BY `timestamp` DESC LIMIT 1";
		}
		switch(MpmDbHelper::getMethod())
		{
			case MPM_METHOD_PDO:
			try
			{
				$pdo = MpmDbHelper::getPdoObj();
				$result = $pdo->query($sql)->fetch(PDO::FETCH_OBJ);
			}
			catch (Exception $e)
			{
				echo "\n\nError: " . $e->getMessage() . "\n\n";
				exit;
			}
			break;
			case MPM_METHOD_MYSQLI:
			try
			{
				$mysqli = MpmDbHelper::getMysqliObj();
				$result = $mysqli->query($sql)->fetch_object();
			}
			catch (Exception $e)
			{
				echo "\n\nError: " . $e->getMessage() . "\n\n";
				exit;
			}
			break;

		}
		return $result;
	}
	/**
	* Returns an array of migrations which need to be run (in order).
	*
	* @uses MpmMigrationHelper::getTimestampFromId()
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getPdoObj()
	* @uses MpmDbHelper::getMysqliObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @param int    $toId      the ID of the migration to stop on
	* @param string $direction the direction of the migration; should be 'up' or 'down'
	*
	* @return array
	*/
	static public function getListOfMigrations($toId, $direction = 'up')
	{
		$list = array();
		$timestamp = MpmMigrationHelper::getTimestampFromId($toId);
		if ($direction == 'up')
		{
			$sql = "SELECT `id`, `timestamp` FROM `mpm_migrations` WHERE `active` = 0 AND `timestamp` <= '$timestamp' ORDER BY `timestamp`";
		}
		else
		{
			$sql = "SELECT `id`, `timestamp`, `objectstore` FROM `mpm_migrations` WHERE `active` = 1 AND `timestamp` > '$timestamp' ORDER BY `timestamp` DESC";
		}
		switch(MpmDbHelper::getMethod())
		{
			case MPM_METHOD_PDO:
			try
			{
				$pdo = MpmDbHelper::getPdoObj();
				$stmt = $pdo->query($sql);
				while ($obj = $stmt->fetch(PDO::FETCH_OBJ))
				{
					$list[$obj->id] = $obj;
				}
			}
			catch (Exception $e)
			{
				echo "\n\nError: " . $e->getMessage() . "\n\n";
				exit;
			}
			break;
			case MPM_METHOD_MYSQLI:
			try
			{
				$mysqli = MpmDbHelper::getMysqliObj();
				$results = $mysqli->query($sql);
				while ($row = $results->fetch_object())
				{
					$list[$row->id] = $row;
				}
			}
			catch (Exception $e)
			{
				echo "\n\nError: " . $e->getMessage() . "\n\n";
				exit;
			}
			break;

		}
		return $list;
	}

	/**
	* Returns a timestamp when given a migration ID number.
	*
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getPdoObj()
	* @uses MpmDbHelper::getMysqliObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @param int $id the ID number of the migration
	*
	* @return string
	*/
	static public function getTimestampFromId($id)
	{
		try
		{
			switch (MpmDbHelper::getMethod())
			{
				case MPM_METHOD_PDO:
					// Resolution to Issue #1 - PDO::rowCount is not reliable
					$pdo = MpmDbHelper::getPdoObj();
					$sql = "SELECT COUNT(*) FROM `mpm_migrations` WHERE `id` = '$id'";
					$stmt = $pdo->query($sql);
					if ($stmt->fetchColumn() == 1)
					{
						unset($stmt);
						$sql = "SELECT `timestamp` FROM `mpm_migrations` WHERE `id` = '$id'";
						$stmt = $pdo->query($sql);
						$result = $stmt->fetch(PDO::FETCH_OBJ);
						$timestamp = $result->timestamp;
					}
					else
					{
						$timestamp = false;
					}
					break;
				case MPM_METHOD_MYSQLI:
					$mysqli = MpmDbHelper::getMysqliObj();
					$sql = "SELECT COUNT(*) as total FROM `mpm_migrations` WHERE `id` = '$id'";
					$stmt = $mysqli->query($sql);
					$row = $stmt->fetch_object();
					if ($row->total == 1)
					{
						$stmt->close();
						unset($stmt);
						$sql = "SELECT `timestamp` FROM `mpm_migrations` WHERE `id` = '$id'";
						$stmt = $mysqli->query($sql);
						$result = $stmt->fetch_object();
						$timestamp = $result->timestamp;
						$stmt->close();
						$mysqli->close();
					}
					else
					{
						$timestamp = false;
					}
					break;
			}
		}
		catch (Exception $e)
		{
			echo "\n\nERROR: " . $e->getMessage() . "\n\n";
			exit;
		}
		return $timestamp;
	}

	/**
	* Returns the number of the migration currently rolled to.
	*
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getDbObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @return string
	*/
	static public function getCurrentMigrationNumber()
	{
		try
		{
			switch (MpmDbHelper::getMethod())
			{
				case MPM_METHOD_PDO:
					$pdo = MpmDbHelper::getDbObj();
					// Resolution to Issue #1 - PDO::rowCount is not reliable
					$sql = "SELECT COUNT(*) FROM `mpm_migrations` WHERE `is_current` = 1";
					$stmt = $pdo->query($sql);
					if ($stmt->fetchColumn() == 0)
					{
						return false;
					}
					$sql = "SELECT `id` FROM `mpm_migrations` WHERE `is_current` = 1";
					unset($stmt);
					$stmt = $pdo->query($sql);
					$row = $stmt->fetch(PDO::FETCH_ASSOC);
					$latest = $row['id'];
					break;
				case MPM_METHOD_MYSQLI:
					$mysqli = MpmDbHelper::getDbObj();
					$sql = "SELECT COUNT(*) as total FROM `mpm_migrations` WHERE `is_current` = 1";
					$stmt = $mysqli->query($sql);
					$row = $stmt->fetch_object();
					if ($row->total == 0)
					{
						return false;
					}
					$stmt->close();
					unset($stmt);
					$sql = "SELECT `id` FROM `mpm_migrations` WHERE `is_current` = 1";
					$stmt = $mysqli->query($sql);
					$row = $stmt->fetch_object();
					$latest = $row->id;
					$stmt->close();
					$mysqli->close();
					break;
			}	    
		}
		catch (Exception $e)
		{
			echo "\n\nERROR: " . $e->getMessage() . "\n\n";
			exit;
		}
		return $latest;
	}

	/**
	* Returns the total number of migrations.
	*
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getDbObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @return int
	*/
	static public function getMigrationCount()
	{
		try
		{
			switch (MpmDbHelper::getMethod())
			{
				case MPM_METHOD_PDO:
					$pdo = MpmDbHelper::getDbObj();
					// Resolution to Issue #1 - PDO::rowCount is not reliable
					$sql = "SELECT COUNT(id) FROM `mpm_migrations`";
					$stmt = $pdo->query($sql);
					$count = $stmt->fetchColumn();
					break;
				case MPM_METHOD_MYSQLI:
					$mysqli = MpmDbHelper::getDbObj();
					$sql = "SELECT COUNT(id) AS total FROM `mpm_migrations`";
					$stmt = $mysqli->query($sql);
					$row = $stmt->fetch_object();
					$count = $row->total;
					break;
			}
		}
		catch (Exception $e)
		{
			echo "\n\nERROR: " . $e->getMessage() . "\n\n";
			exit;
		}
		return $count;
	}

	/**
	* Returns the ID of the latest migration.
	*
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getDbObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @return int
	*/
	static public function getLatestMigration()
	{
		$sql = "SELECT `id` FROM `mpm_migrations` ORDER BY `timestamp` DESC LIMIT 0,1";
		try
		{
			switch(MpmDbHelper::getMethod())
			{
				case MPM_METHOD_PDO:
					$pdo = MpmDbHelper::getDbObj();
					$stmt = $pdo->query($sql);
					$result = $stmt->fetch(PDO::FETCH_OBJ);
					if(!$result)return false;
					$to_id = $result->id;
					break;
				case MPM_METHOD_MYSQLI:
					$mysqli = MpmDbHelper::getDbObj();
					$stmt = $mysqli->query($sql);
					$result = $stmt->fetch_object();
					if(!$result)return false;
					$to_id = $result->id;
					break;
			}
		}
		catch (Exception $e)
		{
			echo "\n\nERROR: " . $e->getMessage() . "\n\n";
			exit;
		}
		return $to_id;
	}
	/**
	* Returns the ID of the oldest migration.
	*
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getDbObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @return int
	*/
	static public function getOldestMigration()
	{
		$sql = "SELECT `id` FROM `mpm_migrations` ORDER BY `timestamp` ASC LIMIT 0,1";
		try
		{
			switch(MpmDbHelper::getMethod())
			{
				case MPM_METHOD_PDO:
					$pdo = MpmDbHelper::getDbObj();
					$stmt = $pdo->query($sql);
					$result = $stmt->fetch(PDO::FETCH_OBJ);
					if(!$result)return false;
					$to_id = $result->id;
					break;
				case MPM_METHOD_MYSQLI:
					$mysqli = MpmDbHelper::getDbObj();
					$stmt = $mysqli->query($sql);
					$result = $stmt->fetch_object();
					if(!$result)return false;
					$to_id = $result->id;
					break;
			}
		}
		catch (Exception $e)
		{
			echo "\n\nERROR: " . $e->getMessage() . "\n\n";
			exit;
		}
		return $to_id;
	}
	/**
	* Checks to see if a migration with the given ID actually exists.
	*
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getDbObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @param int $id the ID of the migration
	*
	* @return int
	*/
	static public function doesMigrationExist($id)
	{
		$sql = "SELECT COUNT(*) as total FROM `mpm_migrations` WHERE `id` = '$id'";
		$return = false;
		try
		{
			switch(MpmDbHelper::getMethod())
			{
				case MPM_METHOD_PDO:
					$pdo = MpmDbHelper::getDbObj();
					$stmt = $pdo->query($sql);
					$result = $stmt->fetch(PDO::FETCH_OBJ);
					if ($result->total > 0)
					{
						$return = true;
					}
					break;
				case MPM_METHOD_MYSQLI:
					$mysqli = MpmDbHelper::getDbObj();
					$stmt = $mysqli->query($sql);
					$result = $stmt->fetch_object();
					if ($result->total > 0)
					{
						$return = true;
					}
					break;
			}
		}
		catch (Exception $e)
		{
			echo "\n\nERROR: " . $e->getMessage() . "\n\n";
			exit;
		}
		return $return;
	}

	/**
	* Returns a migration object; this object contains all data stored in the DB for the particular migration ID.
	*
	* @uses MpmDbHelper::getMethod()
	* @uses MpmDbHelper::getDbObj()
	* @uses MPM_METHOD_MYSQLI
	* @uses MPM_METHOD_PDO
	*
	* @param int $id the ID of the migration
	*
	* @return object
	*/
	static public function getMigrationObject($id)
	{
		$sql = "SELECT * FROM `mpm_migrations` WHERE `id` = '$id'";
		$obj = null;
		try
		{
			switch(MpmDbHelper::getMethod())
			{
				case MPM_METHOD_PDO:
					$pdo = MpmDbHelper::getDbObj();
					$stmt = $pdo->query($sql);
					$obj = $stmt->fetch(PDO::FETCH_OBJ);
					break;
				case MPM_METHOD_MYSQLI:
					$mysqli = MpmDbHelper::getDbObj();
					$stmt = $mysqli->query($sql);
					$obj = $stmt->fetch_object();
					break;
			}
		}
		catch (Exception $e)
		{
			echo "\n\nERROR: " . $e->getMessage() . "\n\n";
			exit;
		}
		return $obj;
	}
}

?>
