<?php
/* QCubed Development Framework for PHP
 * http://www.qcu.be/
 *
 * Copyright (C) 2006
 * Justin Sinclair - The Sinclair Group, LLC - http://www.sinclairgroup.net/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

namespace QCubed\Database\PostgreSql;

use QCubed\Database\DatabaseBase;
use QCubed\Database\ForeignKey;
use QCubed\Database\Index;
use QCubed\Exception\Caller;
use QCubed\QDateTime;

/**
 * PostgreSQL database adapter.
 *
 * To allow QCubed to determine the identity column in a PostgreSQL table (and because a
 * table may have more than one column generated by the SERIAL "data type"), this adapter
 * assumes that if the first column (ordinal position 1) was created as SERIAL, it is the
 * identity column.  Otherwise, no identity column will be set for that table.
 *
 * @was QPostgreSqlDatabase
 */
class Database extends DatabaseBase
{
    /** Adapter name */
    const ADAPTER = 'PostgreSQL Database Adapter';

    protected $objPgSql;
    protected $objMostRecentResult;
    protected $blnOnlyFullGroupBy = true;

    public function sqlVariable($mixData, $blnIncludeEquality = false, $blnReverseEquality = false)
    {
        // Are we SqlVariabling a BOOLEAN value?
        if (is_bool($mixData)) {
            // Yes
            if ($blnIncludeEquality) {
                // We must include the inequality

                if ($blnReverseEquality) {
                    // Do a "Reverse Equality"

                    // Check against NULL, True then False
                    if (is_null($mixData)) {
                        return 'IS NOT NULL';
                    } else {
                        if ($mixData) {
                            return "= '0'";
                        } else {
                            return "!= '0'";
                        }
                    }
                } else {
                    // Check against NULL, True then False
                    if (is_null($mixData)) {
                        return 'IS NULL';
                    } else {
                        if ($mixData) {
                            return "!= '0'";
                        } else {
                            return "= '0'";
                        }
                    }
                }
            } else {
                // Check against NULL, True then False
                if (is_null($mixData)) {
                    return 'NULL';
                } else {
                    if ($mixData) {
                        return "'1'";
                    } else {
                        return "'0'";
                    }
                }
            }
        }

        // Check for Equality Inclusion
        if ($blnIncludeEquality) {
            if ($blnReverseEquality) {
                if (is_null($mixData)) {
                    $strToReturn = 'IS NOT ';
                } else {
                    $strToReturn = '!= ';
                }
            } else {
                if (is_null($mixData)) {
                    $strToReturn = 'IS ';
                } else {
                    $strToReturn = '= ';
                }
            }
        } else {
            $strToReturn = '';
        }

        // Check for NULL Value
        if (is_null($mixData)) {
            return $strToReturn . 'NULL';
        }

        // Check for NUMERIC Value
        if (is_integer($mixData) || is_float($mixData)) {
            return $strToReturn . sprintf('%s', $mixData);
        }

        // Check for DATE Value
        if ($mixData instanceof QDateTime) {
            if ($mixData->isTimeNull()) {
                if ($mixData->isDateNull()) {
                    return $strToReturn . 'NULL'; // null date and time is a null value
                }
                return $strToReturn . sprintf("'%s'", $mixData->qFormat('YYYY-MM-DD'));
            } elseif ($mixData->isDateNull()) {
                return $strToReturn . sprintf("'%s'", $mixData->qFormat('hhhh:mm:ss'));
            } else {
                return $strToReturn . sprintf("'%s'", $mixData->qFormat(QDateTime::FORMAT_ISO));
            }
        }

        // Assume it's some kind of string value
        return $strToReturn . sprintf("'%s'", pg_escape_string($mixData));
    }

    public function sqlLimitVariablePrefix($strLimitInfo)
    {
        // PostgreSQL uses Limit by Suffixes (via a LIMIT clause)
        // Prefix is not used, therefore, return null
        return null;
    }

    public function sqlLimitVariableSuffix($strLimitInfo)
    {
        // Setup limit suffix (if applicable) via a LIMIT clause
        if (strlen($strLimitInfo)) {
            if (strpos($strLimitInfo, ';') !== false) {
                throw new \Exception('Invalid Semicolon in LIMIT Info');
            }
            if (strpos($strLimitInfo, '`') !== false) {
                throw new \Exception('Invalid Backtick in LIMIT Info');
            }

            // First figure out if we HAVE an offset
            $strArray = explode(',', $strLimitInfo);

            if (count($strArray) == 2) {
                // Yep -- there's an offset
                return sprintf('LIMIT %s OFFSET %s', $strArray[1], $strArray[0]);
            } else {
                if (count($strArray) == 1) {
                    return sprintf('LIMIT %s', $strArray[0]);
                } else {
                    throw new \Exception('Invalid Limit Info: ' . $strLimitInfo, 0, null);
                }
            }
        }

        return null;
    }

    public function sqlSortByVariable($strSortByInfo)
    {
        // Setup sorting information (if applicable) via a ORDER BY clause
        if (strlen($strSortByInfo)) {
            if (strpos($strSortByInfo, ';') !== false) {
                throw new \Exception('Invalid Semicolon in ORDER BY Info');
            }
            if (strpos($strSortByInfo, '`') !== false) {
                throw new \Exception('Invalid Backtick in ORDER BY Info');
            }

            return "ORDER BY $strSortByInfo";
        }

        return null;
    }

    public function insertOrUpdate($strTable, $mixColumnsAndValuesArray, $strPKNames = null)
    {
        $strEscapedArray = $this->escapeIdentifiersAndValues($mixColumnsAndValuesArray);
        $strColumns = array_keys($strEscapedArray);
        $strUpdateStatement = '';
        foreach ($strEscapedArray as $strColumn => $strValue) {
            if ($strUpdateStatement) {
                $strUpdateStatement .= ', ';
            }
            $strUpdateStatement .= $strColumn . ' = ' . $strValue;
        }
        if (is_null($strPKNames)) {
            $strPKNames = array($strColumns[0]);
        } else {
            if (is_array($strPKNames)) {
                $strPKNames = $this->escapeIdentifiers($strPKNames);
            } else {
                $strPKNames = array($this->escapeIdentifier($strPKNames));
            }
        }
        $strMatchCondition = '';
        foreach ($strPKNames as $strPKName) {
            if ($strMatchCondition) {
                $strMatchCondition .= ' AND ';
            }
            $strMatchCondition .= $strPKName . ' = ' . $strEscapedArray[$strPKName];
        }
        $strTable = $this->EscapeIdentifierBegin . $strTable . $this->EscapeIdentifierEnd;
        $strUpdateSql = sprintf('UPDATE %s SET %s WHERE %s',
            $strTable, $strUpdateStatement, $strMatchCondition);
        $strInsertSql = sprintf('INSERT INTO %s (%s) SELECT %s WHERE NOT EXISTS (SELECT 1 FROM %s WHERE %s)',
            $strTable,
            implode(', ', $strColumns),
            implode(', ', array_values($strEscapedArray)),
            $strTable, $strMatchCondition);
        $this->transactionBegin();
        try {
            $this->executeNonQuery($strUpdateSql);
            $this->executeNonQuery($strInsertSql);
            $this->transactionCommit();
        } catch (\Exception $ex) {
            $this->transactionRollback();
            throw $ex;
        }
    }

    /**
     * Connects to the database
     *
     * @throws Exception
     */
    public function connect()
    {
        // Lookup Adapter-Specific Connection Properties
        $strServer = $this->Server;
        $strName = $this->Database;
        $strUsername = $this->Username;
        $strPassword = $this->Password;
        $strPort = $this->Port;

        // Connect to the Database Server
        $this->objPgSql = pg_connect(sprintf('host=%s dbname=%s user=%s password=%s port=%s', $strServer, $strName,
            $strUsername, $strPassword, $strPort));

        if (!$this->objPgSql) {
            throw new \Exception("Unable to connect to Database", -1, null);
        }

        // Update Connected Flag
        $this->blnConnectedFlag = true;
    }

    public function __get($strName)
    {
        switch ($strName) {
            case 'AffectedRows':
                return pg_affected_rows($this->objMostRecentResult);
            default:
                try {
                    return parent::__get($strName);
                } catch (Caller $objExc) {
                    $objExc->incrementOffset();
                    throw $objExc;
                }
        }
    }

    /**
     * @param string $strQuery
     * @return Result
     * @throws \Exception
     */
    protected function executeQuery($strQuery)
    {
        // Perform the Query
        $objResult = pg_query($this->objPgSql, $strQuery);
        if (!$objResult) {
            throw new \Exception(pg_last_error(), 0, $strQuery);
        }

        // Return the Result
        $this->objMostRecentResult = $objResult;
        $objPgSqlDatabaseResult = new Result($objResult, $this);
        return $objPgSqlDatabaseResult;
    }

    /**
     * @param string $strNonQuery
     * @throws \Exception
     * @return void
     */
    protected function executeNonQuery($strNonQuery)
    {
        // Perform the Query
        $objResult = pg_query($this->objPgSql, $strNonQuery);
        if (!$objResult) {
            throw new \Exception(pg_last_error(), 0, $strNonQuery);
        }
        $this->objMostRecentResult = $objResult;
    }

    /**
     * Returns the list of tables in the database as string
     *
     * @return array List of tables in the database as string
     */
    public function getTables()
    {
        $objResult = $this->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = current_schema() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME ASC");
        $strToReturn = array();
        while ($strRowArray = $objResult->fetchRow()) {
            array_push($strToReturn, $strRowArray[0]);
        }
        return $strToReturn;
    }

    /**
     * @param string $strTableName
     * @return array
     */
    public function getFieldsForTable($strTableName)
    {
        $strTableName = $this->sqlVariable($strTableName);
        $strQuery = sprintf('
			SELECT 
				columns.table_name,
				columns.column_name,
				columns.ordinal_position,
				columns.column_default,
				columns.is_nullable,
				columns.data_type,
				columns.character_maximum_length,
				descr.description AS comment,
				(pg_get_serial_sequence(columns.table_name,columns.column_name) IS NOT NULL) AS is_serial
			FROM 
				INFORMATION_SCHEMA.COLUMNS columns
				JOIN pg_catalog.pg_class klass ON (columns.table_name = klass.relname AND klass.relkind = \'r\')
				LEFT JOIN pg_catalog.pg_description descr ON (descr.objoid = klass.oid AND descr.objsubid = columns.ordinal_position)
			WHERE 
				columns.table_schema = current_schema()
			AND
				columns.table_name = %s
			ORDER BY
				ordinal_position
		', $strTableName);

        $objResult = $this->query($strQuery);

        $objFields = array();

        while ($objRow = $objResult->getNextRow()) {
            array_push($objFields, new Field($objRow, $this));
        }

        return $objFields;
    }

    /**
     * @param null $strTableName
     * @param null $strColumnName
     * @return mixed
     */
    public function insertId($strTableName = null, $strColumnName = null)
    {
        $strQuery = sprintf('
			SELECT currval(pg_get_serial_sequence(%s, %s))
		', $this->sqlVariable($strTableName), $this->sqlVariable($strColumnName));

        $objResult = $this->query($strQuery);
        $objRow = $objResult->fetchRow();
        return $objRow[0];
    }

    public function close()
    {
        pg_close($this->objPgSql);

        // Update Connected Flag
        $this->blnConnectedFlag = false;
    }

    /**
     * Sends the 'BEGIN' command to the PostgreSQL server to start a transaction
     */
    protected function executeTransactionBegin()
    {
        $this->nonQuery('BEGIN;');
    }

    /**
     * Sends the 'COMMIT' command to the PostgreSQL server to commit/end a transaction
     */
    protected function executeTransactionCommit()
    {
        $this->nonQuery('COMMIT;');
    }

    /**
     * Sends the 'ROLLBACK' command to the PostgreSQL server to revert a transaction
     */
    protected function executeTransactionRollBack()
    {
        $this->nonQuery('ROLLBACK;');
    }

    /**
     * @param $strKeyDefinition
     * @return array
     * @throws \Exception
     */
    private function parseColumnNameArrayFromKeyDefinition($strKeyDefinition)
    {
        $strKeyDefinition = trim($strKeyDefinition);

        // Get rid of the opening "(" and the closing ")"
        $intPosition = strpos($strKeyDefinition, '(');
        if ($intPosition === false) {
            throw new \Exception("Invalid Key Definition: $strKeyDefinition");
        }
        $strKeyDefinition = trim(substr($strKeyDefinition, $intPosition + 1));

        $intPosition = strpos($strKeyDefinition, ')');
        if ($intPosition === false) {
            throw new \Exception("Invalid Key Definition: $strKeyDefinition");
        }
        $strKeyDefinition = trim(substr($strKeyDefinition, 0, $intPosition));
        $strKeyDefinition = str_replace(" ", "", $strKeyDefinition);

        // Create the Array
        // TODO: Current method doesn't support key names with commas or parenthesis in them!
        $strToReturn = explode(',', $strKeyDefinition);

        // Take out trailing and leading '"' character in each name (if applicable)
        for ($intIndex = 0; $intIndex < count($strToReturn); $intIndex++) {
            $strColumn = $strToReturn[$intIndex];

            if (substr($strColumn, 0, 1) == '"') {
                $strColumn = substr($strColumn, 1, strpos($strColumn, '"', 1) - 1);
            }

            $strToReturn[$intIndex] = $strColumn;
        }

        return $strToReturn;
    }

    public function getIndexesForTable($strTableName)
    {
        $objIndexArray = array();

        $objResult = $this->query(sprintf('
			SELECT 
				c2.relname AS indname, 
				i.indisprimary, 
				i.indisunique, 
				pg_catalog.pg_get_indexdef(i.indexrelid) AS inddef 
			FROM 
				pg_catalog.pg_class c, 
				pg_catalog.pg_class c2, 
				pg_catalog.pg_index i
			WHERE 
				c.relname = %s 
			AND 
				pg_catalog.pg_table_is_visible(c.oid)
			AND 
				c.oid = i.indrelid 
			AND 
				i.indexrelid = c2.oid
			ORDER BY 
				c2.relname
		', $this->sqlVariable($strTableName)));

        while ($objRow = $objResult->getNextRow()) {
            $strIndexDefinition = $objRow->getColumn('inddef');
            $strKeyName = $objRow->getColumn('indname');
            $blnPrimaryKey = ($objRow->getColumn('indisprimary') === "t");
            $blnUnique = ($objRow->getColumn('indisunique') === "t");
            $strColumnNameArray = $this->parseColumnNameArrayFromKeyDefinition($strIndexDefinition);

            $objIndex = new Index($strKeyName, $blnPrimaryKey, $blnUnique, $strColumnNameArray);
            array_push($objIndexArray, $objIndex);
        }

        return $objIndexArray;
    }

    /**
     * @param string $strTableName
     * @return ForeignKey[]
     */
    public function getForeignKeysForTable($strTableName)
    {
        $objForeignKeyArray = array();

        // Use Query to pull the FKs
        $strQuery = sprintf('
			SELECT
				pc.conname,
				pg_catalog.pg_get_constraintdef(pc.oid, true) AS consrc
			FROM
				pg_catalog.pg_constraint pc
			WHERE
				pc.conrelid = 
				(
					SELECT
						oid 
					FROM 
						pg_catalog.pg_class 
					WHERE
						relname=%s
					AND 
						relnamespace = 
						(
							SELECT 
								oid 
							FROM 
								pg_catalog.pg_namespace
							WHERE 
								nspname=current_schema()
						)
				)
			AND 
				pc.contype = \'f\'
		', $this->sqlVariable($strTableName));

        $objResult = $this->query($strQuery);

        while ($objRow = $objResult->getNextRow()) {
            $strKeyName = $objRow->getColumn('conname');

            // Remove leading and trailing '"' characters (if applicable)
            if (substr($strKeyName, 0, 1) == '"') {
                $strKeyName = substr($strKeyName, 1, strlen($strKeyName) - 2);
            }

            // By the end of the following lines, we will end up with a strTokenArray
            // Index 1: the list of columns that are the foreign key
            // Index 2: the table which this FK references
            // Index 3: the list of columns which this FK references
            $strTokenArray = explode('FOREIGN KEY ', $objRow->getColumn('consrc'));
            $strTokenArray[1] = explode(' REFERENCES ', $strTokenArray[1]);
            $strTokenArray[2] = $strTokenArray[1][1];
            $strTokenArray[1] = $strTokenArray[1][0];
            $strTokenArray[2] = explode("(", $strTokenArray[2]);
            $strTokenArray[3] = "(" . $strTokenArray[2][1];
            $strTokenArray[2] = $strTokenArray[2][0];

            // Remove leading and trailing '"' characters (if applicable)
            if (substr($strTokenArray[2], 0, 1) == '"') {
                $strTokenArray[2] = substr($strTokenArray[2], 1, strlen($strTokenArray[2]) - 2);
            }

            $strColumnNameArray = $this->parseColumnNameArrayFromKeyDefinition($strTokenArray[1]);
            $strReferenceTableName = $strTokenArray[2];
            $strReferenceColumnNameArray = $this->parseColumnNameArrayFromKeyDefinition($strTokenArray[3]);

            $objForeignKey = new ForeignKey(
                $strKeyName,
                $strColumnNameArray,
                $strReferenceTableName,
                $strReferenceColumnNameArray);
            array_push($objForeignKeyArray, $objForeignKey);
        }

        // Return the Array of Foreign Keys
        return $objForeignKeyArray;
    }

    /**
     * @param $sql
     * @return mixed
     */
    public function explainStatement($sql)
    {
        return $this->query("EXPLAIN " . $sql);
    }
}

