<?php

/**
 * Represents and handles all schema management for a database
 *
 * @package framework
 * @subpackage model
 */
abstract class DBSchemaManager {

	/**
	 *
	 * @config
	 * Check tables when running /dev/build, and repair them if necessary.
	 * In case of large databases or more fine-grained control on how to handle
	 * data corruption in tables, you can disable this behaviour and handle it
	 * outside of this class, e.g. through a nightly system task with extended logging capabilities.
	 *
	 * @var boolean
	 */
	private static $check_and_repair_on_build = true;

	/**
	 * Instance of the database controller this schema belongs to
	 *
	 * @var SS_Database
	 */
	protected $database = null;

	/**
	 * If this is false, then information about database operations
	 * will be displayed, eg creation of tables.
	 *
	 * @var boolean
	 */
	protected $supressOutput = false;

	/**
	 * Injector injection point for database controller
	 *
	 * @param SS_Database $connector
	 */
	public function setDatabase(SS_Database $database) {
		$this->database = $database;
	}

	/**
	 * The table list, generated by the tableList() function.
	 * Used by the requireTable() function.
	 *
	 * @var array
	 */
	protected $tableList;

	/**
	 * Keeps track whether we are currently updating the schema.
	 *
	 * @var boolean
	 */
	protected $schemaIsUpdating = false;

	/**
	 * Large array structure that represents a schema update transaction
	 *
	 * @var array
	 */
	protected $schemaUpdateTransaction;

	/**
	 * Enable supression of database messages.
	 */
	public function quiet() {
		$this->supressOutput = true;
	}

	/**
	 * Execute the given SQL query.
	 * This abstract function must be defined by subclasses as part of the actual implementation.
	 * It should return a subclass of SS_Query as the result.
	 *
	 * @param string $sql The SQL query to execute
	 * @param int $errorLevel The level of error reporting to enable for the query
	 * @return SS_Query
	 */
	public function query($sql, $errorLevel = E_USER_ERROR) {
		return $this->database->query($sql, $errorLevel);
	}


	/**
	 * Execute the given SQL parameterised query with the specified arguments
	 *
	 * @param string $sql The SQL query to execute. The ? character will denote parameters.
	 * @param array $parameters An ordered list of arguments.
	 * @param int $errorLevel The level of error reporting to enable for the query
	 * @return SS_Query
	 */
	public function preparedQuery($sql, $parameters, $errorLevel = E_USER_ERROR) {
		return $this->database->preparedQuery($sql, $parameters, $errorLevel);
	}

	/**
	 * Initiates a schema update within a single callback
	 *
	 * @var callable $callback
	 * @throws Exception
	 */
	public function schemaUpdate($callback) {
		// Begin schema update
		$this->schemaIsUpdating = true;

		// Update table list
		$this->tableList = array();
		$tables = $this->tableList();
		foreach ($tables as $table) {
			$this->tableList[strtolower($table)] = $table;
		}

		// Clear update list for client code to mess around with
		$this->schemaUpdateTransaction = array();

		$error = null;
		try {

			// Yield control to client code
			$callback();

			// If the client code has cancelled the update then abort
			if(!$this->isSchemaUpdating()) return;

			// End schema update
			foreach ($this->schemaUpdateTransaction as $tableName => $changes) {
				$advancedOptions = isset($changes['advancedOptions']) ? $changes['advancedOptions'] : null;
				switch ($changes['command']) {
					case 'create':
						$this->createTable($tableName, $changes['newFields'], $changes['newIndexes'],
										$changes['options'], $advancedOptions);
						break;

					case 'alter':
						$this->alterTable($tableName, $changes['newFields'], $changes['newIndexes'],
										$changes['alteredFields'], $changes['alteredIndexes'],
										$changes['alteredOptions'], $advancedOptions);
						break;
				}
			}
		} catch(Exception $ex) {
			$error = $ex;
		}
		// finally {
		$this->schemaUpdateTransaction = null;
		$this->schemaIsUpdating = false;
		// }

		if($error) throw $error;
	}

	/**
	 * Cancels the schema updates requested during (but not after) schemaUpdate() call.
	 */
	public function cancelSchemaUpdate() {
		$this->schemaUpdateTransaction = null;
		$this->schemaIsUpdating = false;
	}

	/**
	 * Returns true if we are during a schema update.
	 *
	 * @return boolean
	 */
	function isSchemaUpdating() {
		return $this->schemaIsUpdating;
	}

	/**
	 * Returns true if schema modifications were requested during (but not after) schemaUpdate() call.
	 *
	 * @return boolean
	 */
	public function doesSchemaNeedUpdating() {
		return (bool) $this->schemaUpdateTransaction;
	}

	// Transactional schema altering functions - they don't do anything except for update schemaUpdateTransaction

	/**
	 * Instruct the schema manager to record a table creation to later execute
	 *
	 * @param string $table Name of the table
	 * @param array $options Create table options (ENGINE, etc.)
	 * @param array $advanced_options Advanced table creation options
	 */
	public function transCreateTable($table, $options = null, $advanced_options = null) {
		$this->schemaUpdateTransaction[$table] = array(
			'command' => 'create',
			'newFields' => array(),
			'newIndexes' => array(),
			'options' => $options,
			'advancedOptions' => $advanced_options
		);
	}

	/**
	 * Instruct the schema manager to record a table alteration to later execute
	 *
	 * @param string $table Name of the table
	 * @param array $options Create table options (ENGINE, etc.)
	 * @param array $advanced_options Advanced table creation options
	 */
	public function transAlterTable($table, $options, $advanced_options) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['alteredOptions'] = $options;
		$this->schemaUpdateTransaction[$table]['advancedOptions'] = $advanced_options;
	}

	/**
	 * Instruct the schema manager to record a field to be later created
	 *
	 * @param string $table Name of the table to hold this field
	 * @param string $field Name of the field to create
	 * @param string $schema Field specification as a string
	 */
	public function transCreateField($table, $field, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['newFields'][$field] = $schema;
	}

	/**
	 * Instruct the schema manager to record an index to be later created
	 *
	 * @param string $table Name of the table to hold this index
	 * @param string $index Name of the index to create
	 * @param array $schema Already parsed index specification
	 */
	public function transCreateIndex($table, $index, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['newIndexes'][$index] = $schema;
	}

	/**
	 * Instruct the schema manager to record a field to be later updated
	 *
	 * @param string $table Name of the table to hold this field
	 * @param string $field Name of the field to update
	 * @param string $schema Field specification as a string
	 */
	public function transAlterField($table, $field, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['alteredFields'][$field] = $schema;
	}

	/**
	 * Instruct the schema manager to record an index to be later updated
	 *
	 * @param string $table Name of the table to hold this index
	 * @param string $index Name of the index to update
	 * @param array $schema Already parsed index specification
	 */
	public function transAlterIndex($table, $index, $schema) {
		$this->transInitTable($table);
		$this->schemaUpdateTransaction[$table]['alteredIndexes'][$index] = $schema;
	}

	/**
	 * Handler for the other transXXX methods - mark the given table as being altered
	 * if it doesn't already exist
	 *
	 * @param string $table Name of the table to initialise
	 */
	protected function transInitTable($table) {
		if (!isset($this->schemaUpdateTransaction[$table])) {
			$this->schemaUpdateTransaction[$table] = array(
				'command' => 'alter',
				'newFields' => array(),
				'newIndexes' => array(),
				'alteredFields' => array(),
				'alteredIndexes' => array(),
				'alteredOptions' => ''
			);
		}
	}

	/**
	 * Generate the following table in the database, modifying whatever already exists
	 * as necessary.
	 *
	 * @todo Change detection for CREATE TABLE $options other than "Engine"
	 *
	 * @param string $table The name of the table
	 * @param array $fieldSchema A list of the fields to create, in the same form as DataObject::$db
	 * @param array $indexSchema A list of indexes to create. See {@link requireIndex()}
	 * The values of the array can be one of:
	 *   - true: Create a single column index on the field named the same as the index.
	 *   - array('fields' => array('A','B','C'), 'type' => 'index/unique/fulltext'): This gives you full
	 *     control over the index.
	 * @param boolean $hasAutoIncPK A flag indicating that the primary key on this table is an autoincrement type
	 * @param string $options SQL statement to append to the CREATE TABLE call.
	 * @param array $extensions List of extensions
	 */
	public function requireTable($table, $fieldSchema = null, $indexSchema = null, $hasAutoIncPK = true,
		$options = array(), $extensions = false
	) {
		if (!isset($this->tableList[strtolower($table)])) {
			$this->transCreateTable($table, $options, $extensions);
			$this->alterationMessage("Table $table: created", "created");
		} else {
			if (Config::inst()->get('DBSchemaManager', 'check_and_repair_on_build')) {
				$this->checkAndRepairTable($table, $options);
			}

			// Check if options changed
			$tableOptionsChanged = false;
			if (isset($options[get_class($this)]) || true) {
				if (isset($options[get_class($this)])) {
					if (preg_match('/ENGINE=([^\s]*)/', $options[get_class($this)], $alteredEngineMatches)) {
						$alteredEngine = $alteredEngineMatches[1];
						$tableStatus = $this->query(sprintf(
												'SHOW TABLE STATUS LIKE \'%s\'', $table
										))->first();
						$tableOptionsChanged = ($tableStatus['Engine'] != $alteredEngine);
					}
				}
			}

			if ($tableOptionsChanged || ($extensions && $this->database->supportsExtensions($extensions))) {
				$this->transAlterTable($table, $options, $extensions);
			}
		}

		//DB ABSTRACTION: we need to convert this to a db-specific version:
		if(!isset($fieldSchema['ID'])) {
			$this->requireField($table, 'ID', $this->IdColumn(false, $hasAutoIncPK));
		}

		// Create custom fields
		if ($fieldSchema) {
			foreach ($fieldSchema as $fieldName => $fieldSpec) {

				//Is this an array field?
				$arrayValue = '';
				if (strpos($fieldSpec, '[') !== false) {
					//If so, remove it and store that info separately
					$pos = strpos($fieldSpec, '[');
					$arrayValue = substr($fieldSpec, $pos);
					$fieldSpec = substr($fieldSpec, 0, $pos);
				}

				$fieldObj = Object::create_from_string($fieldSpec, $fieldName);
				$fieldObj->arrayValue = $arrayValue;

				$fieldObj->setTable($table);

				if($fieldObj instanceof PrimaryKey) {
					$fieldObj->setAutoIncrement($hasAutoIncPK);
				}

				$fieldObj->requireField();
			}
		}

		// Create custom indexes
		if ($indexSchema) {
			foreach ($indexSchema as $indexName => $indexDetails) {
				$this->requireIndex($table, $indexName, $indexDetails);
			}
		}
	}

	/**
	 * If the given table exists, move it out of the way by renaming it to _obsolete_(tablename).
	 * @param string $table The table name.
	 */
	public function dontRequireTable($table) {
		if (isset($this->tableList[strtolower($table)])) {
			$suffix = '';
			while (isset($this->tableList[strtolower("_obsolete_{$table}$suffix")])) {
				$suffix = $suffix
						? ($suffix + 1)
						: 2;
			}
			$this->renameTable($table, "_obsolete_{$table}$suffix");
			$this->alterationMessage("Table $table: renamed to _obsolete_{$table}$suffix", "obsolete");
		}
	}

	/**
	 * Generate the given index in the database, modifying whatever already exists as necessary.
	 *
	 * The keys of the array are the names of the index.
	 * The values of the array can be one of:
	 *  - true: Create a single column index on the field named the same as the index.
	 *  - array('type' => 'index|unique|fulltext', 'value' => 'FieldA, FieldB'): This gives you full
	 *    control over the index.
	 *
	 * @param string $table The table name.
	 * @param string $index The index name.
	 * @param string|array|boolean $spec The specification of the index in any
	 * loose format. See requireTable() for more information.
	 */
	public function requireIndex($table, $index, $spec) {
		// Detect if adding to a new table
		$newTable = !isset($this->tableList[strtolower($table)]);

		// Force spec into standard array format
		$spec = $this->parseIndexSpec($index, $spec);
		$specString = $this->convertIndexSpec($spec);

		// Check existing index
		if (!$newTable) {
			$indexKey = $this->indexKey($table, $index, $spec);
			$indexList = $this->indexList($table);
			if (isset($indexList[$indexKey])) {

				// $oldSpec should be in standard array format
				$oldSpec = $indexList[$indexKey];
				$oldSpecString = $this->convertIndexSpec($oldSpec);
			}
		}

		// Initiate either generation or modification of index
		if ($newTable || !isset($indexList[$indexKey])) {
			// New index
			$this->transCreateIndex($table, $index, $spec);
			$this->alterationMessage("Index $table.$index: created as $specString", "created");
		} else if ($oldSpecString != $specString) {
			// Updated index
			$this->transAlterIndex($table, $index, $spec);
			$this->alterationMessage(
				"Index $table.$index: changed to $specString <i style=\"color: #AAA\">(from $oldSpecString)</i>",
				"changed"
			);
		}
	}

	/**
	 * Splits a spec string safely, considering quoted columns, whitespace,
	 * and cleaning brackets
	 *
	 * @param string $spec The input index specification string
	 * @return array List of columns in the spec
	 */
	protected function explodeColumnString($spec) {
		// Remove any leading/trailing brackets and outlying modifiers
		// E.g. 'unique (Title, "QuotedColumn");' => 'Title, "QuotedColumn"'
		$containedSpec = preg_replace('/(.*\(\s*)|(\s*\).*)/', '', $spec);

		// Split potentially quoted modifiers
		// E.g. 'Title, "QuotedColumn"' => array('Title', 'QuotedColumn')
		return preg_split('/"?\s*,\s*"?/', trim($containedSpec, '(") '));
	}

	/**
	 * Builds a properly quoted column list from an array
	 *
	 * @param array $columns List of columns to implode
	 * @return string A properly quoted list of column names
	 */
	protected function implodeColumnList($columns) {
		if(empty($columns)) return '';
		return '"' . implode('","', $columns) . '"';
	}

	/**
	 * Given an index specification in the form of a string ensure that each
	 * column name is property quoted, stripping brackets and modifiers.
	 * This index may also be in the form of a "CREATE INDEX..." sql fragment
	 *
	 * @param string $spec The input specification or query. E.g. 'unique (Column1, Column2)'
	 * @return string The properly quoted column list. E.g. '"Column1", "Column2"'
	 */
	protected function quoteColumnSpecString($spec) {
		$bits = $this->explodeColumnString($spec);
		return $this->implodeColumnList($bits);
	}

	/**
	 * Given an index spec determines the index type
	 *
	 * @param array|string $spec
	 * @return string
	 */
	protected function determineIndexType($spec) {
		// check array spec
		if(is_array($spec) && isset($spec['type'])) {
			return $spec['type'];
		} elseif (!is_array($spec) && preg_match('/(?<type>\w+)\s*\(/', $spec, $matchType)) {
			return strtolower($matchType['type']);
		} else {
			return 'index';
		}
	}

	/**
	 * Converts an array or string index spec into a universally useful array
	 *
	 * @see convertIndexSpec() for approximate inverse
	 * @param string|array $spec
	 * @return array The resulting spec array with the required fields name, type, and value
	 */
	protected function parseIndexSpec($name, $spec) {
		// Support $indexes = array('ColumnName' => true) for quick indexes
		if ($spec === true) {
			return array(
				'name' => $name,
				'value' => $this->quoteColumnSpecString($name),
				'type' => 'index'
			);
		}

		// Do minimal cleanup on any already parsed spec
		if(is_array($spec)) {
			$spec['value'] = $this->quoteColumnSpecString($spec['value']);
			$spec['type'] = empty($spec['type']) ? 'index' : trim($spec['type']);
			return $spec;
		}

		// Nicely formatted spec!
		return array(
			'name' => $name,
			'value' => $this->quoteColumnSpecString($spec),
			'type' => $this->determineIndexType($spec)
		);
	}

	/**
	 * This takes the index spec which has been provided by a class (ie static $indexes = blah blah)
	 * and turns it into a proper string.
	 * Some indexes may be arrays, such as fulltext and unique indexes, and this allows database-specific
	 * arrays to be created. See {@link requireTable()} for details on the index format.
	 *
	 * @see http://dev.mysql.com/doc/refman/5.0/en/create-index.html
	 * @see parseIndexSpec() for approximate inverse
	 *
	 * @param string|array $indexSpec
	 */
	protected function convertIndexSpec($indexSpec) {
		// Return already converted spec
		if (!is_array($indexSpec)) return $indexSpec;

		// Combine elements into standard string format
		return "{$indexSpec['type']} ({$indexSpec['value']})";
	}

	/**
	 * Returns true if the given table is exists in the current database
	 *
	 * @param string $table Name of table to check
	 * @return boolean Flag indicating existence of table
	 */
	abstract public function hasTable($tableName);

	/**
	 * Return true if the table exists and already has a the field specified
	 *
	 * @param string $tableName - The table to check
	 * @param string $fieldName - The field to check
	 * @return bool - True if the table exists and the field exists on the table
	 */
	public function hasField($tableName, $fieldName) {
		if (!$this->hasTable($tableName)) return false;
		$fields = $this->fieldList($tableName);
		return array_key_exists($fieldName, $fields);
	}

	/**
	 * Generate the given field on the table, modifying whatever already exists as necessary.
	 *
	 * @param string $table The table name.
	 * @param string $field The field name.
	 * @param array|string $spec The field specification. If passed in array syntax, the specific database
	 * 	driver takes care of the ALTER TABLE syntax. If passed as a string, its assumed to
	 * 	be prepared as a direct SQL framgment ready for insertion into ALTER TABLE. In this case you'll
	 * 	need to take care of database abstraction in your DBField subclass.
	 */
	public function requireField($table, $field, $spec) {
		//TODO: this is starting to get extremely fragmented.
		//There are two different versions of $spec floating around, and their content changes depending
		//on how they are structured.  This needs to be tidied up.
		$fieldValue = null;
		$newTable = false;

		// backwards compatibility patch for pre 2.4 requireField() calls
		$spec_orig = $spec;

		if (!is_string($spec)) {
			$spec['parts']['name'] = $field;
			$spec_orig['parts']['name'] = $field;
			//Convert the $spec array into a database-specific string
			$spec = $this->$spec['type']($spec['parts'], true);
		}

		// Collations didn't come in until MySQL 4.1.  Anything earlier will throw a syntax error if you try and use
		// collations.
		// TODO: move this to the MySQLDatabase file, or drop it altogether?
		if (!$this->database->supportsCollations()) {
			$spec = preg_replace('/ *character set [^ ]+( collate [^ ]+)?( |$)/', '\\2', $spec);
		}

		if (!isset($this->tableList[strtolower($table)])) $newTable = true;

		if (is_array($spec)) {
			$specValue = $this->$spec_orig['type']($spec_orig['parts']);
		} else {
			$specValue = $spec;
		}

		// We need to get db-specific versions of the ID column:
		if ($spec_orig == $this->IdColumn() || $spec_orig == $this->IdColumn(true)) {
			$specValue = $this->IdColumn(true);
		}

		if (!$newTable) {
			$fieldList = $this->fieldList($table);
			if (isset($fieldList[$field])) {
				if (is_array($fieldList[$field])) {
					$fieldValue = $fieldList[$field]['data_type'];
				} else {
					$fieldValue = $fieldList[$field];
				}
			}
		}

		// Get the version of the field as we would create it. This is used for comparison purposes to see if the
		// existing field is different to what we now want
		if (is_array($spec_orig)) {
			$spec_orig = $this->$spec_orig['type']($spec_orig['parts']);
		}

		if ($newTable || $fieldValue == '') {
			$this->transCreateField($table, $field, $spec_orig);
			$this->alterationMessage("Field $table.$field: created as $spec_orig", "created");
		} else if ($fieldValue != $specValue) {
			// If enums/sets are being modified, then we need to fix existing data in the table.
			// Update any records where the enum is set to a legacy value to be set to the default.
			foreach (array('enum', 'set') as $enumtype) {
				if (preg_match("/^$enumtype/i", $specValue)) {
					$newStr = preg_replace("/(^$enumtype\s*\(')|('$\).*)/i", "", $spec_orig);
					$new = preg_split("/'\s*,\s*'/", $newStr);

					$oldStr = preg_replace("/(^$enumtype\s*\(')|('$\).*)/i", "", $fieldValue);
					$old = preg_split("/'\s*,\s*'/", $newStr);

					$holder = array();
					foreach ($old as $check) {
						if (!in_array($check, $new)) {
							$holder[] = $check;
						}
					}
					if (count($holder)) {
						$default = explode('default ', $spec_orig);
						$default = $default[1];
						$query = "UPDATE \"$table\" SET $field=$default WHERE $field IN (";
						for ($i = 0; $i + 1 < count($holder); $i++) {
							$query .= "'{$holder[$i]}', ";
						}
						$query .= "'{$holder[$i]}')";
						$this->query($query);
						$amount = $this->database->affectedRows();
						$this->alterationMessage("Changed $amount rows to default value of field $field"
								. " (Value: $default)");
					}
				}
			}
			$this->transAlterField($table, $field, $spec_orig);
			$this->alterationMessage(
				"Field $table.$field: changed to $specValue <i style=\"color: #AAA\">(from {$fieldValue})</i>",
				"changed"
			);
		}
	}

	/**
	 * If the given field exists, move it out of the way by renaming it to _obsolete_(fieldname).
	 *
	 * @param string $table
	 * @param string $fieldName
	 */
	public function dontRequireField($table, $fieldName) {
		$fieldList = $this->fieldList($table);
		if (array_key_exists($fieldName, $fieldList)) {
			$suffix = '';
			while (isset($fieldList[strtolower("_obsolete_{$fieldName}$suffix")])) {
				$suffix = $suffix
						? ($suffix + 1)
						: 2;
			}
			$this->renameField($table, $fieldName, "_obsolete_{$fieldName}$suffix");
			$this->alterationMessage(
				"Field $table.$fieldName: renamed to $table._obsolete_{$fieldName}$suffix",
				"obsolete"
			);
		}
	}

	/**
	 * Show a message about database alteration
	 *
	 * @param string $message to display
	 * @param string $type one of [created|changed|repaired|obsolete|deleted|error]
	 */
	public function alterationMessage($message, $type = "") {
		if (!$this->supressOutput) {
			if (Director::is_cli()) {
				switch ($type) {
					case "created":
					case "changed":
					case "repaired":
						$sign = "+";
						break;
					case "obsolete":
					case "deleted":
						$sign = '-';
						break;
					case "notice":
						$sign = '*';
						break;
					case "error":
						$sign = "!";
						break;
					default:
						$sign = " ";
				}
				$message = strip_tags($message);
				echo "  $sign $message\n";
			} else {
				switch ($type) {
					case "created":
						$color = "green";
						break;
					case "obsolete":
						$color = "red";
						break;
					case "notice":
						$color = "orange";
						break;
					case "error":
						$color = "red";
						break;
					case "deleted":
						$color = "red";
						break;
					case "changed":
						$color = "blue";
						break;
					case "repaired":
						$color = "blue";
						break;
					default:
						$color = "";
				}
				echo "<li style=\"color: $color\">$message</li>";
			}
		}
	}

	/**
	 * This returns the data type for the id column which is the primary key for each table
	 *
	 * @param boolean $asDbValue
	 * @param boolean $hasAutoIncPK
	 * @return string
	 */
	abstract public function IdColumn($asDbValue = false, $hasAutoIncPK = true);

	/**
	 * Checks a table's integrity and repairs it if necessary.
	 *
	 * @param string $tableName The name of the table.
	 * @return boolean Return true if the table has integrity after the method is complete.
	 */
	abstract public function checkAndRepairTable($tableName);

	/**
	 * Returns the values of the given enum field
	 *
	 * @param string $tableName Name of table to check
	 * @param string $fieldName name of enum field to check
	 * @return array List of enum values
	 */
	abstract public function enumValuesForField($tableName, $fieldName);


	/*
	 * This is a lookup table for data types.
	 * For instance, Postgres uses 'INT', while MySQL uses 'UNSIGNED'
	 * So this is a DB-specific list of equivilents.
	 *
	 * @param string $type
	 * @return string
	 */
	abstract public function dbDataType($type);

	/**
	 * Retrieves the list of all databases the user has access to
	 *
	 * @return array List of database names
	 */
	abstract public function databaseList();

	/**
	 * Determine if the database with the specified name exists
	 *
	 * @param string $name Name of the database to check for
	 * @return boolean Flag indicating whether this database exists
	 */
	abstract public function databaseExists($name);

	/**
	 * Create a database with the specified name
	 *
	 * @param string $name Name of the database to create
	 * @return boolean True if successful
	 */
	abstract public function createDatabase($name);

	/**
	 * Drops a database with the specified name
	 *
	 * @param string $name Name of the database to drop
	 */
	abstract public function dropDatabase($name);

	/**
	 * Alter an index on a table.
	 *
	 * @param string $tableName The name of the table.
	 * @param string $indexName The name of the index.
	 * @param string $indexSpec The specification of the index, see {@link SS_Database::requireIndex()}
	 *                          for more details.
	 * @todo Find out where this is called from - Is it even used? Aren't indexes always dropped and re-added?
	 */
	abstract public function alterIndex($tableName, $indexName, $indexSpec);

	/**
	 * Determines the key that should be used to identify this index
	 * when retrieved from DBSchemaManager->indexList.
	 * In some connectors this is the database-visible name, in others the
	 * usercode-visible name.
	 *
	 * @param string $table
	 * @param string $index
	 * @param array $spec
	 * @return string Key for this index
	 */
	abstract protected function indexKey($table, $index, $spec);

	/**
	 * Return the list of indexes in a table.
	 *
	 * @param string $table The table name.
	 * @return array[array] List of current indexes in the table, each in standard
	 * array form. The key for this array should be predictable using the indexKey
	 * method
	 */
	abstract public function indexList($table);

	/**
	 * Returns a list of all tables in the database.
	 * Keys are table names in lower case, values are table names in case that
	 * database expects.
	 *
	 * @return array
	 */
	abstract public function tableList();

	/**
	 * Create a new table.
	 *
	 * @param string $table The name of the table
	 * @param array $fields A map of field names to field types
	 * @param array $indexes A map of indexes
	 * @param array $options An map of additional options.  The available keys are as follows:
	 *   - 'MSSQLDatabase'/'MySQLDatabase'/'PostgreSQLDatabase' - database-specific options such as "engine" for MySQL.
	 *   - 'temporary' - If true, then a temporary table will be created
	 * @param $advancedOptions Advanced creation options
	 * @return string The table name generated.  This may be different from the table name, for example with temporary
	 * tables.
	 */
	abstract public function createTable($table, $fields = null, $indexes = null, $options = null,
										$advancedOptions = null);

	/**
	 * Alter a table's schema.
	 *
	 * @param string $table The name of the table to alter
	 * @param array $newFields New fields, a map of field name => field schema
	 * @param array $newIndexes New indexes, a map of index name => index type
	 * @param array $alteredFields Updated fields, a map of field name => field schema
	 * @param array $alteredIndexes Updated indexes, a map of index name => index type
	 * @param array $alteredOptions
	 * @param array $advancedOptions
	 */
	abstract public function alterTable($table, $newFields = null, $newIndexes = null, $alteredFields = null,
										$alteredIndexes = null, $alteredOptions = null, $advancedOptions = null);

	/**
	 * Rename a table.
	 *
	 * @param string $oldTableName The old table name.
	 * @param string $newTableName The new table name.
	 */
	abstract public function renameTable($oldTableName, $newTableName);

	/**
	 * Create a new field on a table.
	 *
	 * @param string $table Name of the table.
	 * @param string $field Name of the field to add.
	 * @param string $spec The field specification, eg 'INTEGER NOT NULL'
	 */
	abstract public function createField($table, $field, $spec);

	/**
	 * Change the database column name of the given field.
	 *
	 * @param string $tableName The name of the tbale the field is in.
	 * @param string $oldName The name of the field to change.
	 * @param string $newName The new name of the field
	 */
	abstract public function renameField($tableName, $oldName, $newName);

	/**
	 * Get a list of all the fields for the given table.
	 * Returns a map of field name => field spec.
	 *
	 * @param string $table The table name.
	 * @return array
	 */
	abstract public function fieldList($table);

	/**
	 *
	 * This allows the cached values for a table's field list to be erased.
	 * If $tablename is empty, then the whole cache is erased.
	 *
	 * @param string $tableName
	 *
	 * @return boolean
	 */
	public function clearCachedFieldlist($tableName = false) {
		return true;
	}

}
