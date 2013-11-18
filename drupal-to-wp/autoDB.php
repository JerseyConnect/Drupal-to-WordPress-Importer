<?php

/**
 * Automatic database skeleton code -- with stub logging for Drupal converter
 */

#require_once 'logging.php';
define( 'DEBUG_OFF', 0 );

/**
 * Class for accessing a column in a table
 */
class Column {
	
	var $columnName = NULL;
	var $columnType = NULL;
	var $TableLink = NULL;

	function __construct( $columnName, $dataType, &$TableLink ) {
		
		$this->TableLink  = &$TableLink;
		$this->columnName = $columnName;
		$this->columnType = $dataType;
		
	}
	
	/**
	 * Prepare a column's value for insert/update or select use
	 * @param mixed Value the value to be used
	 * @return mixed the prepared value
	 */
	function prepareValue( $value ) {
		
		if( $this->TableLink->DBLink->statement )
			return $value;
		
		if( is_string( $value ) && strlen( $value ) > 0 && $value{0} == '&' ) {
			$value = substr( $value,1 );
			return $value;
		} else if( stristr( $this->columnType, 'int' ) ) {
			return (int)$value;
		} else {
			return '\'' . $this->TableLink->escape( $value ) . '\'';
		}
		
	}
	
	/**
	 * Get all the values of a certain column only
	 * @param array Criteria desired filters
	 * @return array|FALSE array of results or FALSE if none are found
	 */
	function getValues( $criteria = NULL ) {

		$results = $this->TableLink->DBLink->getRecords(
			$this->TableLink->DBLink->query(
				'SELECT ' . $this->TableLink->escape($this->columnName) . ' FROM ' . $this->TableLink->escape($this->TableLink->DBLink->dbName) . '.' . $this->TableLink->escape($this->TableLink->tableName) . (($criteria != NULL) ? $this->TableLink->DBLink->conditionBuilder($criteria)  : '' )
			)
		);
		
		$result = array();
		foreach( $results as $value ) {
			$result[] = $value[$this->columnName];
		}
		
		return $result;
		
	}
	
	/**
	 * Get all values for a particular column from the table
	 * @return array All values from the selected column 
	 */
	function getAllValues() {		return $this->getValues();	}

	/**
	 * Get a list of unique values for a column from the table
	 * @param $criteria Array of criteria for limiting query
	 * @return array List of unique values from the column
	 */
	function getUniqueValues( $criteria = NULL ) {
		
		$results = $this->TableLink->DBLink->getRecords(
			$this->TableLink->DBLink->query(
				'SELECT DISTINCT ' . $this->TableLink->escape($this->columnName) . ' FROM ' . $this->TableLink->escape($this->TableLink->DBLink->dbName) . '.' . $this->TableLink->escape($this->TableLink->tableName) . (($criteria != NULL) ? $this->TableLink->DBLink->conditionBuilder($criteria)  : '' )
			)
		);
		
		$result = array();
		foreach( $results as $value ) {
			$result[] = $value[$this->columnName];
		}
		
		return $result;
		
	}

	/**
	 * Get a single value from a column by criteria
	 * @param $criteria Array of criteria for selecting the row
	 * @return mixed value of the column chosen
	 */
	function getValue( $criteria = NULL ) {

		$results = $this->getValues( $criteria );
		return $results ? $results[0] : FALSE;

	}

	/**
	 * Register a foreign key constraint
	 * NOTE: This has been disabled in AutoDB::buildRelationships(), below, to avoid instantiating every table and column on each page load.
	 * @param string $tableName name of referring table
	 * @param string $columnName name of referring column
	 */
	function addDependent( $tableName, $columnName ) {

		if( array_key_exists( ($this->TableLink->tableName . '.' . $this->columnName), $this->TableLink->DBLink->relationships ) ) {
			$this->TableLink->DBLink->relationships[$this->TableLink->tableName . '.' . $this->columnName][] = $tableName . '.' . $columnName;
		} else {
			$this->TableLink->DBLink->relationships[$this->TableLink->tableName . '.' . $this->columnName] = array($tableName . '.' . $columnName);
		}

	}

}

class Table {
	
	var $tableName = NULL;
	var $columns = NULL;
	var $columnTypes = NULL;
	var $DBLink = NULL;
	var $primaryKey = NULL;
	var $debug = NULL;
	
	function __construct( $tableName, &$DBLink, &$debug = DEBUG_OFF ) {

		$this->tableName = $tableName;
		$this->DBLink    = &$DBLink;
		$this->debug     = &$debug;
		
		$this->buildColumns();
	}
	

	/**
	 * Get records from a database table
	 * @param $criteria Array of criteria for limiting the scope of results
	 * @param $mode Flag to perform a recursive query of tables with foreign keys in the present table
	 * @param $originator Name of table originating the query
	 * @param $trail Array of table names involved in the recursive query - to prevent loops
	 * @return array Array of the results of the query
	 */
	function getRecords( $criteria = NULL, $mode = 'brief',$originator = NULL, $trail = NULL ) {

		if( is_int( $criteria ) )	$criteria = array( $this->primaryKey => $criteria );

		$results = $this->DBLink->getRecords(
			$this->DBLink->query(
				'SELECT * FROM ' . $this->escape( $this->DBLink->dbName ) . '.' . $this->escape( $this->tableName ) . $this->DBLink->conditionBuilder( $criteria )
			)
		);
		
		if( ! $results || $mode != 'FULL' )
			return $results;

		LogMessage::debug( 'Originator of this dependency check chain is: ' . $originator, $this->debug );

		if( $originator == NULL ) $originator = $this->tableName;
		if( $trail == NULL ) $trail = array();

		/** add the name of the current table to the trail regardless */
		$trail[] = $this->tableName;

		/** Check for tables dependent on each column in this table, and grab any associated results */
		
		foreach( $this->columns as $column => $type ) {
			/** if there are any columns that DEPEND ON this one */
			LogMessage::debug( 'Checking for dependents on: ' . $this->tableName . '.' . $column, $this->debug );

			if( array_key_exists( ($this->tableName . '.' . $column), $this->DBLink->relationships ) ) {

				foreach( $this->DBLink->relationships[$this->tableName . '.' . $column] as $dependent ) {

					if( $originator == substr( $dependent, 0, strpos( $dependent, '.' ) ) ) continue;
					if( in_array( substr( $dependent, 0, strpos( $dependent, '.' ) ), $trail ) ) continue;

					LogMessage::debug( 'Adding dependent ' . $dependent . ' on column: ' . $this->tableName . '.' . $column, $this->debug );

					$dependentTable  = substr( $dependent,0 , strpos( $dependent, '.' ) );
					$dependentColumn = substr( $dependent, strpos( $dependent, '.' ) + 1 );

					$trail[] = $dependentTable;

					foreach($results as $key => $result) {
						$results[$key][$dependentTable] = $this->DBLink->{$dependentTable}->getRecords(
							array(
								$dependentColumn => $result[$column]
								),
								'FULL',
								$originator,
								$trail
							);
					}
				}
			}
			
			/** check for columns that this column DEPENDS ON */
			LogMessage::debug('Checking for dependecies from: ' . $this->tableName . '.' . $column,$this->debug);

			foreach($this->DBLink->relationships as $providerName => $provider) {

				if($originator == substr($providerName,0,strpos($providerName,'.'))) continue;
				if(in_array(substr($providerName,0,strpos($providerName,'.')),$trail)) continue;

				if(in_array(($this->tableName . '.' . $column),$provider)) {
					LogMessage::debug('Adding dependent ' . $column . ' from column: ' . $providerName,$this->debug);
					$providerTable = substr($providerName,0,strpos($providerName,'.'));
					$providerColumn = substr($providerName,strpos($providerName,'.')+1);

					$trail[] = $providerTable;

					foreach($results as $key => $result) {
						$results[$key][$providerTable] = $this->DBLink->{$providerTable}->getRecords(
							array(
								$providerColumn => $result[$column]
								),
								'FULL',
								$originator,
								$trail
							);
					}
				}
			}
			
		}
		
		return $results;
	}

	/**
	 * Retrieve an unfiltered set of records from the database table
	 * @param $mode Flag to control recursion in the query
	 * @return array Array of results
	 */
	function getAllRecords($mode = 'brief') {		return $this->getRecords(NULL,$mode);	}

	/**
	 * Retrieve a single row from the selected database table (no need to specify a LIMIT)
	 * @param $criteria Array of query criteria
	 * @param $mode Flag to control recursion in the query
	 * @return array A single record from the database
	 */
	function getRecord($criteria = NULL,$mode = 'brief') {
		if(is_null($criteria))	$criteria = array();
		if(is_int($criteria))	$criteria = array($this->primaryKey => $criteria);
		$criteria['LIMIT'] = 1;
		$result = $this->getRecords($criteria,$mode);
		if($result) $result = $result[0];
		return $result;
	}
	
	/**
	 * Add a new record to the database table
	 * @param $recordData Array of fields comprising the new record to be added
	 * @return mixed Primary key (if auto-incrementing, 0 otherwise) of new record or FALSE upon failure
	 */
	function add($recordData) {

		/** Prepare each value for insertion / updating in DB */
		$recordData = $this->prepareValues( $recordData );

		$result = $this->DBLink->query('INSERT INTO ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . ' (' . implode(', ',array_keys($recordData)) . ') VALUES (' . implode(', ',$recordData) . ')');

		if( $result && ! is_a( $result, 'Error' ) ) {
			$id = $this->DBLink->getLastInsertId();
			new LogMessage('Added row ' . $id . ' to table ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . '.');
			return $id;
		} else {
			new Error('Failed to insert row into table ' . $this->escape($this->tableName) . ': ' . $this->DBLink->getLastError());
			return FALSE;
		}

	}
	
	/**
	 * Update a record or records in the table
	 * @param $selector Array of criteria for specifying the records to be updated
	 * @param $recordData Array of values to be updated in the respective records
	 * @return mixed|FALSE result of the query
	 */
	function update($selector,$recordData) {

		/** Prepare each value for insertion / updating in DB */
		$recordData = $this->prepareValues( $recordData );

		$values = array();
		foreach($recordData as $key => $value) {
			$values[] = $key . '=' . $value;
		}

		if(is_int($selector)) {

			$selector = array($this->primaryKey => $selector);

		} else if(!is_array($selector)) {
			new Error('A selector must be specified for updates to ' . $this->escape($this->tableName) . '.');
			return FALSE;
		}

		$result = $this->DBLink->query('UPDATE ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . ' SET ' . implode(', ',$values) . $this->DBLink->conditionBuilder($selector));

		if( $result && ! is_a( $result, 'Error' ) && $this->DBLink->getAffectedRows() > 0 ) {
			new LogMessage('Updated row(s) in table ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . ' by selector: ' . $this->DBLink->conditionBuilder($selector));
			return $result;
		} else {
			new Error('Failed to update row(s) in table ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . ': ' . $this->DBLink->getLastError());
			return FALSE;
		}
	}

	/**
	 * Adds record if it is new, or updates if it exists
	 * @param $recordData Array of values to be updated in the respective records
	 * @return mixed|FALSE result of the query
	 */
	function addOrUpdate( $recordData ) {

		/** Prepare each value for insertion / updating in DB */
		$recordData = $this->prepareValues( $recordData );

		$updateValues = array();
		foreach( $recordData as $key => $value ) {
			if( $key == $this->primaryKey ) continue;
			$updateValues[] = $key . '=' . $value;
		}
		

		$result = $this->DBLink->query('INSERT INTO ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . ' (' . implode(', ',array_keys($recordData)) . ') VALUES (' . implode(', ',$recordData) . ')' . ' ON DUPLICATE KEY UPDATE ' . implode(', ',$updateValues) );
		if($result) {
//			if($this->DBLink->getLastInsertId())
//				new LogMessage('Added row(s) in table ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName));
//			else
//				new LogMessage('Updated row(s) in table ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName));
			return $result;
		} else {
			new Error('Failed to insert or update row(s) in table ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . ': ' . $this->DBLink->getLastError());
			return FALSE;
		}

	}

	/**
	 * Remove records from the database
	 * @param $selector int|array criteria for records to be deleted
	 * @return mixed|FALSE result of the query or FALSE if failed
	 */
	function delete( $selector = null ) {

		if( is_int( $selector ) )
			$selector = array( $this->primaryKey => $selector );

		if( ! empty( $selector) && ! is_array( $selector ) ) {
			new Error( 'A selector must be specified for deletions from ' . $this->escape($this->tableName) . '.' );
			return FALSE;
		}

		$result = $this->DBLink->query('DELETE FROM ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . $this->DBLink->conditionBuilder($selector));
		if( $result && ! is_a( $result, 'Error' ) && $this->DBLink->getAffectedRows() > 0 ) {
			new LogMessage('Deleted row(s) from table ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . ' by selector: ' . $this->DBLink->conditionBuilder($selector));
			return $result;
		} else {
			new Error('Failed to delete row(s) from table ' . $this->escape($this->DBLink->dbName) . '.' . $this->escape($this->tableName) . ': ' . $this->DBLink->getLastError());
			return FALSE;
		}

	}
	
	/**
	 * Build a list of columns and column types for other class functions
	 * @return VOID
	 */
	function buildColumns() {
		$results = $this->DBLink->getRecords(
			$this->DBLink->query(
				'DESCRIBE `' . $this->escape($this->DBLink->dbName) . '`.`' . $this->escape($this->tableName) . '`'
			)
		);
		
		foreach( $results as $result ) {

//			echo 'Instantiating a new column object for: ' . $result['Field'] . '<br />' . "\n";
			$customColumn = $result['Field'] . 'Column';
			if( class_exists( $customColumn ) ) {
				$this->{$result['Field']} = new $customColumn($result['Field'],$result['Type'],$this);
			} else {
				$this->{$result['Field']} = new Column($result['Field'],$result['Type'],$this);
			}
			$this->columns[$result['Field']] = $result['Type'];

			if( is_null( $this->primaryKey ) && $result['Key'] == 'PRI' )
				$this->primaryKey = $result['Field'];
		}
	}
	
	/**
	 * Retrieve a stored list of columns in this table
	 * @param $mode Flag to control whether a full or limited list will be returned
	 * @return array Array of columns with info or just type
	 */
	function getColumnList( $mode = 'simple' ) {
		if( $mode != 'simple' )
			return $this->columns;
		
		return array_keys( $this->columns );
	}

	/**
	 * Retrieve a list of tables that have a specified column as a foreign key (or all tables with any column in the current table if omitted)
	 * @param $columnName string Optional name of the column to search for relationships
	 * @return array List of tables that have foreign keys on the selected column (or any column in the current table)
	 */
	function getRelationships( $columnName = NULL ) {

		if( ! is_null( $columnName ) ) {
			return $this->DBLink->relationships[$this->tableName . '.' . $columnName];
		}

		$results = array();
		foreach( $this->columns as $column => $type ) {
			$results[$column] = $this->DBLink->relationships[$this->tableName . '.' . $column];
		}

		return $results;

	}

	/** Wrapper for individual column prepareValue functions for bulk use
	 * 
	 * 
	 */
	function prepareValues( $recordData ) {
		foreach( $recordData as $field => $value ) {
			if( isset( $this->{$field} ) ) {
				$recordData[$field] = $this->{$field}->prepareValue($value);
			} else {
				unset($recordData[$field]);
			}
		}
		return $recordData;
	}

	/**
	 * Escape a value for use in a SQL query
	 * @param $string string to be escaped
	 * @return string Escaped string
	 */
	function escape( $string ) {
		if( is_array( $string ) ) return new Error( 'Received an array: ' . print_r( $string, TRUE ) );
		
		return mysqli_real_escape_string( $this->DBLink->link, $string );
	}
}

class AutoDB {

	var $dbName = NULL;
	var $link = NULL;
	var $tables = NULL;
	var $query = NULL;
	var $debug = DEBUG_OFF;
	var $statement = FALSE;
	var $tableFilter = NULL;
	
	/** this is a hack to provide a global relationship structure */
	var $relationships = NULL;

	/** this regex matches key characters - see AutoDB::buildConditions for details */
	var $regex = '/([<>!](=)?)?([&\*\%])?([^%\*]+)([\%\*])?$/';

	/** this regex matches foreign keys in the SHOW TABLE STATUS 'Comment' column - see DB::buildRelationships */
	var $fkeyRegex = '/ \(`(.*?)`\) REFER `(.*?)\/(.*?)`\(`(.*?)`\)/';

	/** these words will be escaped with backticks '`' when used as column names */
	var $reservedWords = array(
			'type',
			'asc',
			'desc',
			'value',
			'left',
			'join',
			'inner',
			'outer',
			'user'
		);

	/**
	 * Create an instance of the DB class, and start the process of scanning the DB
	 * @param string Host address of the database host
	 * @param string DB name of the database
	 * @param string user user name with which to access the database
	 * @param string pass password for the user accessing the database
	 * @param enum Debug toggle debug output and specify a method
	 * @param string|array TableFilter filter tables searched - string for wildcard matches or array for a known list
	 */
	function __construct( $host, $db, $user, $pass, $debug = DEBUG_OFF, $tableFilter = NULL ) {

		if( $debug ) $this->debug = $debug;
		if( $tableFilter != NULL ) $this->tableFilter = $tableFilter;

		LogMessage::debug( 'Connecting to ' . $host . ' with user ' . $user, $this->debug );
		$conn = mysqli_connect( $host, $user, $pass );
		if($conn) {
			LogMessage::debug( 'Selecting database: ' . $db, $this->debug );
			$link = mysqli_select_db( $conn, $db );
			if(!$link) {
				return new Error('Could not access requested database.');
			}
		} else {
			return new Error('Could not connect to requested host.');
		}
		
		$this->dbName = $db;
		$this->link   = $conn;
		
		$this->buildTables();
		$this->buildRelationships();
	}
	
	function __destruct() {
		if( $this->statement ) {
			$this->statement->close();
		}
		mysqli_close( $this->link );
	}

	/**
	 * Use magic method getter to dynamically create table objects
	 */
	function __get( $tableName ) {
		
//		if( isset( $this->$tableName ) ) {
//			return new Error('An instantiated table was called with the magic getter.');
//		}
		if( in_array( $tableName, $this->tables ) ) {
			LogMessage::debug( 'Instantiating a new table object for: ' . $tableName, $this->debug );
			$customTable = $tableName . 'Table';
			if( class_exists( $customTable ) ) {
				$this->{$tableName} = new $customTable($tableName,$this,$this->debug);
			} else {
				$this->{$tableName} = new Table($tableName,$this,$this->debug);
			}
			return $this->{$tableName};
		} else {
			return new Error('The table you specified - `' . $tableName . '` - does not exist.');
		}
		
	}

	function __isset( $tableName ) {
//		echo 'Checking for: ' . $tableName;
		return in_array( $tableName, $this->tables );
	}

	/**
	 * Build a list of tables in the selected database, optionally filtered
	 */
	function buildTables() {

		LogMessage::debug( 'Building list of tables from DB', $this->debug );
		$query = 'SHOW TABLES FROM ' . $this->escape($this->dbName,$this->link) . $this->getFilterString('buildTables');
		$result = $this->query( $query );
		if( $result ) {
			$results = $this->getRecords( $result );
			LogMessage::debug(array('Action: retrieved tables from DB',$results),$this->debug);

			if( count( $results ) > 0 ) {
				foreach( $results as $table ) {

					/* Don't instantiate Table objects yet */ /*
					$customTable = current($table) . 'Table';
					if(class_exists($customTable)) {
						$this->{current($table)} = new $customTable(current($table),$this,$this->debug);
					} else {
						$this->{current($table)} = new Table(current($table),$this,$this->debug);
					}
					*/
					
					$this->tables[] = current($table);
				}
			}
		}
	}

	/**
	 * Build foreign key relationships among tables in the schema
	 */
	function buildRelationships() {

		$this->relationships = array();

		LogMessage::debug('Building a set of relationships from DB',$this->debug);
		
		$method = 'information_schema';
		
		if( $method == 'show_status' ) {
		
			/**
			 * Deduce foreign key constraints from the 'Comment' field in the table status
			 * (this only works for the first FK in each table, but is fast)
			 */
			$query = 'SHOW TABLE STATUS FROM ' . $this->escape($this->dbName,$this->link) . $this->getFilterString('buildRelationships');
			$result = $this->query($query);
			if( $result ) {
				$tables = $this->getRecords( $result );
	
				if( count( $tables ) > 0 ) {
					foreach( $tables as $table ) {
						if( $table['Comment'] != '' ) {
							$refer = preg_match_all( $this->fkeyRegex, $table['Comment'], $fkeys, PREG_SET_ORDER );
	
							/**
							 * 0 - whole REFER statement
							 * 1 - referring column
							 * 2 - referred schema
							 * 3 - referred table
							 * 4 - referred column
							 */
	
							if( $refer ) {
								foreach( $fkeys as $fkey ) {
									if( $fkey[2] == $this->dbName ) {
										LogMessage::debug('Found a dependency of ' . $table['Name'] . ':' . $fkey[1] . ' on ' . $fkey[3] . ':' . $fkey[4],$this->debug);
										$this->{$fkey[3]}->{$fkey[4]}->addDependent( $table['Name'], $fkey[1] );
									}
								}
							}
						}
					}
				}
			}
			
		} else {
			
			$query = 'SELECT * FROM information_schema.KEY_COLUMN_USAGE K WHERE TABLE_SCHEMA = \'' . $this->escape($this->dbName,$this->link) . '\' AND REFERENCED_TABLE_SCHEMA IS NOT NULL;';
			$result = $this->query( $query );
			if( $result ) {
				$constraints = $this->getRecords( $result );
				LogMessage::debug(array('Action: retrieved constraints from DB',$constraints),$this->debug);
				if( count( $constraints ) > 0 ) {
					foreach( $constraints as $constraint ) {
						LogMessage::debug('Found a dependency of ' . $constraint['TABLE_NAME'] . ':' . $constraint['COLUMN_NAME'] . ' on ' . $constraint['REFERENCED_TABLE_NAME'] . ':' . $constraint['REFERENCED_COLUMN_NAME'],$this->debug);
						
						if( array_key_exists( ($constraint['REFERENCED_TABLE_NAME'] . '.' . $constraint['REFERENCED_COLUMN_NAME']), $this->relationships ) ) {
							$this->relationships[$constraint['REFERENCED_TABLE_NAME'] . '.' . $constraint['REFERENCED_COLUMN_NAME']][] = $constraint['TABLE_NAME'] . '.' . $constraint['COLUMN_NAME'];
						} else {
							$this->relationships[$constraint['REFERENCED_TABLE_NAME'] . '.' . $constraint['REFERENCED_COLUMN_NAME']] = array($constraint['TABLE_NAME'] . '.' . $constraint['COLUMN_NAME']);
						}

						/** pass the relationship to the column itself for handling - this causes the table and columns to be instatiated and has been sacrificed for speed */
//						$this->{$constraint['REFERENCED_TABLE_NAME']}->{$constraint['REFERENCED_COLUMN_NAME']}->addDependent($constraint['TABLE_NAME'],$constraint['COLUMN_NAME']);
					}
				}
			}
			
		}

	}

	/**
	 * Perform a SQL query
	 * @param $queryString SQL query string to be executed
	 * @return mixed Result of the query (FALSE on failure, resource on select, TRUE on update)
	 */
	function query( $queryString ) {
		
		$this->query = $queryString;

		LogMessage::debug('Running query: ' . $this->query,$this->debug);
		$result = mysqli_query( $this->link, $queryString );
		if( ! $result ) {
			return new Error('Query produced an invalid result - ' . $this->getLastError() . "\nQuery was: " . $queryString);
		} else {
			LogMessage::debug('Query returned a valid result',$this->debug);
		}
		return $result;
		
	}
	
	/**
	 * Get the records contained in a successful SQL query
	 * @param $queryResult Resource from a successful SQL SELECT, DESCRIBE, EXPLAIN, or SHOW statement
	 * @return array Associative array of results
	 */
	function getRecords( $queryResult = null ) {

		// Suppress warning in editor -- needed?
		$row = array();

		if( $this->statement && $queryResult == null ) {
			$this->statement->store_result();
		    $meta = $this->statement->result_metadata();
		    while ( $field = $meta->fetch_field() )
		    {
		        $params[] = &$row[$field->name];
		    }
		
		    call_user_func_array(array($this->statement, 'bind_result'), $params);
		
		    while ( $this->statement->fetch() ) {
		        foreach( $row as $key => $val )
		        {
		            $c[$key] = $val;
		        }
		        $result[] = $c;
		    }
		   
		    $stmt->close(); 
		}
		if( is_a( $queryResult, 'mysqli_result' ) || is_resource( $queryResult ) ) {
			$results = array();
			while( $result = mysqli_fetch_assoc( $queryResult ) ) {
				$results[] = $result;
			}
			return $results;
		}
		
		return new Error('Cannot retrieve records from invalid result set.');
		
	}
	
	/**
	 * Retrieve a list of tables being managed by this instance
	 * @return array List of tables
	 */
	function getTableList() {
		return $this->tables;
	}
	
	/**
	 * build a SQL clause out of an array
	 * @param array $cArr condition array - see below for syntax
	 * @return string SQL clause
	 * based on:
	 *	an array of column name => value
	 * 	an array of column name => array (value OR value OR value)
	 * 	entries are mutual - AND
	 *  special options:
	 * 		value = &value - used as a reference, will not enclose in quotes
	 * 		value = [*|%]value[*|%] - designates a LIKE wildcard search
	 * 		value = ![*|%]value[*|%]- designates a NOT LIKE wildcard search
	 * 		value = [>|<|!](=)value - designates a NOT, LT or GT search
	 * 		value = [asc|desc] - designates an ORDER BY clause
	 * 		key   = [asc|desc] - designates an ORBER BY clause
	 * 		key	  = LIMIT	   - designates a LIMIT clause
	 */
	function conditionBuilder( $cArr ) {

		if( ! is_array( $cArr ) ) { return $cArr; }
		
		$this->limits = '';
		$this->orders = '';
		$conditions   = $this->buildConditions( $cArr );
		$result       = '';
		
		if( count( $conditions ) > 0 ) {
			$result =  " WHERE " . implode( " AND ", $conditions );
		}
		$result .= $this->orders . $this->limits;
		return $result;
	}
	
	/**
	 * private function  - recursively build conditions
	 * @param array $cArr array containing conditions - may be at a sub-level
	 * @param string $userKey a key from a higher level to be applied to all entries on the sub-level
	 * @return array 1-Dimensional array of conditions
	 */ 
	function buildConditions( $cArr, $userKey = NULL ) {
		
		$conditions = array();
		if( ! is_array( $cArr ) )
			return $cArr;
		
		$specialWords = array(
			'asc',
			'desc',
			'ASC',
			'DESC',
			'LIMIT'
		);
		
		foreach( $cArr as $key => $arrVal ) {
			
			if( ! is_null( $userKey ) && $userKey != 'AND' )
				$key = $userKey;
			
			if( is_array( $arrVal ) ) {
				
				if( $userKey == 'AND' ) {
					$conditions[] = '(' . implode(' AND ',$this->buildConditions($arrVal,$key)) . ')';
				} else {
				
					switch( $key ) {
						case 'AND':
							$conditions[] = '(' . implode(' AND ',$this->buildConditions($arrVal,$key)) . ')';
							break;
						case 'OR':
							$conditions[] = '(' . implode(' OR ',$this->buildConditions($arrVal)) . ')';
							break;
						default:
							if( is_numeric( $key ) ) {
								$conditions[] = '(' . implode(' OR ',$this->buildConditions($arrVal)) . ')';
							} else {
								$conditions[] = '(' . implode(' OR ',$this->buildConditions($arrVal,$key)) . ')';
							}
							break;
					}
				}
				
			} else {

				/* split the value into pieces */
				preg_match( $this->regex, $arrVal, $matches );
				
				/* handle ORDER BY and LIMIT statements */
				if( in_array( $key, $specialWords ) || ( isset( $matches[0] ) && in_array( $matches[0], $specialWords ) ) ) {

					// TODO: replace with this->escape or mysqli_real_escape_string
					$operator	= $this->escape(in_array($key,$specialWords) ? $key : $matches[0]);
					$operand	= $this->escape(in_array($key,$specialWords) ? $matches[0] : $key);

					if( in_array( $operand, $this->reservedWords ) || ( strpos( $operand,' ' ) !== FALSE ) ) {
						$operand = '`' . $operand . '`';
					}
					
					switch( $operator ) {
						case 'asc':
						case 'desc':
						case 'ASC':
						case 'DESC':
							if( $this->orders == '' ) {
								$this->orders = ' ORDER BY ' . $operand . ' ' . $operator;
							} else {
								$this->orders .= ', ' . $operand . ' ' . $operator;
							}
							break;
						case 'LIMIT':
							$this->limits = ' LIMIT ' . $this->escape($operand);
							break;
					}

				} else {

					/* escape key with backticks if needed */
					if( ( in_array( $key, $this->reservedWords ) && ! in_array( $arrVal, $this->reservedWords ) ) || ( strpos( $key, ' ' ) !== FALSE ) ) {
						$key = '`' . $key . '`';
					}

					$arrVal = $matches[4];

					/* set the operator for this statement */
					$operator = '=';
					if( isset( $matches[1] ) && $matches[1] != NULL ) {
						if( $matches[1] == '!' )
							$matches[1] .= '=';
						
						$operator = ' ' . $matches[1] . ' ';
					}
			
					/* check for fuzziness */
					if( in_array( $matches[3], array( '%', '*' ) ) || ( isset( $matches[5] ) && in_array( $matches[5], array( '%', '*' ) ) ) ) {
						if( $matches[3] == '*' )
							$matches[3] = '%';
						
						if( isset( $matches[5] ) && $matches[5] == '*' )
							$matches[5] = '%';
						
						$operator = ( $matches[1] == '!=' ) ? ' NOT LIKE ' : ' LIKE ' ;
						$arrVal = $matches[3] . $arrVal . ( isset( $matches[5] ) ? $matches[5] : '' );
					}
					
					/* escape field name and value for SQL safety */
					$key	= $this->escape($key);
					$arrVal = ( is_numeric( $arrVal ) || $matches[3] == '&' ) ? $arrVal : '\'' . $this->escape($arrVal) . '\'';

					$conditions[] = $key . $operator . $arrVal;

				}
			}
		}
		return $conditions;
	}

	/**
	 * Get the ID generated for an AUTO INCREMENT column created by the last query
	 * @return integer ID of last column
	 */
	function getLastInsertId() {
		return mysqli_insert_id( $this->link );
	}

	/**
	 * Get the number of rows affected by an INSERT, UPDATE, DELETE, or SELECT statement
	 * @return integer|string # of rows altered or retrieved
	 */
	function getAffectedRows() {
		return mysqli_affected_rows( $this->link );
	}

	/**
	 * Get the last error generated by a SQL statement
	 * @return string Last SQL error
	 */
	function getLastError() {
		return mysqli_error( $this->link );
	}

	function escape( $string ) {
		return mysqli_real_escape_string( $this->link, $string );
	}

	/**
	 * Start a transaction on supported database types
	 */
	function startTransaction() {
		return $this->query('BEGIN');
	}

	/**
	 * Commits an in-progress database transaction
	 */
	function commit() {
		return $this->query('COMMIT');
	}

	/**
	 * Rolls back an in-progress database transaction
	 */
	function rollback() {
		$query = $this->query;
		$this->query('ROLLBACK');
		return new Error('Transaction canceled. A query may have failed; see below: ' . $query . ' failed: ' . $this->getLastError());
	}

	function prepare( $statement ) {
		if( $this->statement )
			$this->statement->close();
		
		$this->statement = mysqli_prepare( $this->link, $statement );
		if( ! $this->statement ) {
			return new Error('Could not create prepared statement.  Error is: ' . $this->getLastError());
		}
		return ( $this->statement !== false );
	}

	function bind( $params ) {
		if( ! $this->statement ) {
			return new Error('No prepared statement exists.');
		}
		$params = array_values( $params );
		/** build list of column types */
		$typeString = '';
		foreach( $params as $param ) {
			if( is_int( $param ) )	$typeString .= 'i';
			else if( is_float( $param ) || is_double( $param ) )	$typeString .= 'd';
			else $typeString .= 's';
		}
		
		$bind_params = array( $typeString );
		
		 for ( $i=0; $i < count($params); $i++ ) {
            $bind_name = 'bind' . $i;
            $$bind_name = $params[$i];
            $bind_params[] = &$$bind_name;
        }
        		
		if( ! call_user_func_array( array( &$this->statement, 'bind_param' ), $bind_params ) ) {
			return new Error('Could not bind parameters.  Error is: ' . $this->getLastError());
		}
		return true;
	}

	function execute() {
		if( ! $this->statement ) {
			return new Error('No valid prepared statement exists.');
		}
		if( ! $this->statement->execute() ) {
			return new Error('Statement execution failed with error: ' . $this->getLastError());
		}
		return true;
	}

	function closeStatement() {
		if( $this->statement )
			$this->statement->close();
		return true;
	}

	/**
	 * Build a WHERE clause to use in selecting tables from the DB, incorporating any desired table name filters
	 * @param $caller string Name of function that called this one - optional
	 * @return string WHERE clause limiting the selection of tables
	 */
	function getFilterString ($caller = 'buildTables' ) {
		// We can use debug_backtrace for this but it is slooow (?)
		$log = debug_backtrace();
		$caller = $log[1]['function'];

		$columnName = ( strcasecmp( $caller,'buildTables' ) == 0 ) ? 'Tables_in_' . $this->escape($this->dbName,$this->link) : 'Name';
		if( $this->tableFilter ) {
			if( is_array( $this->tableFilter )  ) {
				// TODO: replace with mysqli_real_escape_string or this->escape
				return ' WHERE ' . $columnName . ' IN (\'' . implode('\',\'',array_map('mysql_real_escape_string',$this->tableFilter)) . '\')';
			} else {
				return ' WHERE ' . $columnName . ' LIKE \'' . $this->escape( $this->tableFilter, $this->link ) . '\'';
			}
		} else {
			return '';
		}
	}

}


if( ! function_exists( 'makeSearchableBy' ) ) {
	/**
	 * Return an associative array or arrays, indexed by a sub-key in the original array - optional callback support
	 * @param array $srcArray assoc. array to be indexed
	 * @param string $key array key to use as index
	 * @param string $callback optional callback function to be performed on index key
	 * @return array re-indexed array
	 */
	function makeSearchableBy( $srcArray, $key, $callback = NULL ) {
		$results = array();
		foreach( $srcArray as $record ) {
			if( ! is_null( $callback ) && function_exists( $callback ) ) {
				$results[$callback($record[$key])][] = $record;
			} else {
				$results[$record[$key]][] = $record;
			}
		}
		return $results;
	}
}

/*
// Usage examples
$hub = new AutoDB('localhost','hub','root','password');

print_r($hub->library->lata->getUniqueValues());
print_r($hub->domain->getRecord(array('domain'=>'example.com')));
*/


/**
 * Stub logging code
 */
class LogMessage {

	function __construct($msgString) {

		return true;
	}

	static function debug($message, $handler) {
		// Don't do anything
	}
	
}

class Error {

	function __construct($errorString) {
		echo "\n" . $errorString . "<br>\n";
	}

}


?>