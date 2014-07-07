<?php
/**
 * @author Rémy M. Böhler <code@rrelmy.ch>
 */

namespace Rorm;

use stdClass;
use Iterator;
use JsonSerializable;
use PDO;

/**
 * Class Model
 */
abstract class Model implements Iterator, JsonSerializable
{
    /** @var string */
    public static $_table;

    /** @var string|array */
    public static $_idColumn = 'id';

    /** @var bool */
    public static $_autoId = true;

    /** @var array */
    public static $_ignoreColumns = array();

    /** @var string */
    public static $_connection = Rorm::CONNECTION_DEFAULT;

    /** @var array */
    public $_data = array();

    /**
     * @return string
     */
    public static function getTable()
    {
        if (isset(static::$_table)) {
            return static::$_table;
        }

        return strtolower(str_replace('\\', '_', get_called_class()));
    }

    /**
     * @return \PDO|null
     */
    public static function getDatabase()
    {
        return Rorm::getDatabase(static::$_connection);
    }

    /**
     * @return static
     */
    public static function create()
    {
        return new static();
    }

    /**
     * @param mixed $id , ...
     * @return static
     */
    public static function find($id)
    {
        $query = static::query();
        call_user_func_array(array($query, 'whereId'), func_get_args());
        return $query->findOne();
    }

    /**
     * @return QueryBuilder
     */
    public static function query()
    {
        return new QueryBuilder(static::getTable(), static::$_idColumn, get_called_class(), static::getDatabase());
    }

    /**
     * @param string $query
     * @param array $params
     * @return Query
     */
    public static function customQuery($query, array $params = array())
    {
        $ormQuery = new Query(get_called_class(), static::getDatabase());
        $ormQuery->setQuery($query);
        if ($params) {
            $ormQuery->setParams($params);
        }
        return $ormQuery;
    }

    /**
     * @return array|mixed
     */
    public function getId()
    {
        if (is_array(static::$_idColumn)) {
            $result = array();
            foreach (static::$_idColumn as $key) {
                $result[$key] = $this->get($key);
            }
            return $result;
        } else {
            return $this->get(static::$_idColumn);
        }
    }

    /**
     * @return bool
     */
    public function hasId()
    {
        if (is_array(static::$_idColumn)) {
            foreach (static::$_idColumn as $key) {
                $value = $this->get($key);
                if (empty($value)) {
                    return false;
                }
            }
            return true;
        } else {
            $value = $this->get(static::$_idColumn);
            return !empty($value);
        }
    }

    /**
     * @return bool
     * @throws QueryException
     * @throws \PDOException
     */
    public function save()
    {
        if (empty($this->_data)) {
            throw new QueryException('can not save empty data!');
        }

        $dbh = static::getDatabase();
        $quoteIdentifier = Rorm::getIdentifierQuoter($dbh);
        $quotedTable = $quoteIdentifier(static::getTable());

        $idColumns = static::$_idColumn;
        if (!is_array($idColumns)) {
            $idColumns = array($idColumns);
        }

        // ignore fields
        $notSetFields = static::$_ignoreColumns;

        /**
         * Different queries are built for each driver
         *
         * IDEA: probably split into methods (saveMySQL, savePostgreSQL, saveSQLite)
         */
        if (Rorm::isPostreSQL($dbh)) {
            /**
             * PostgreSQL
             *
             * For a merge use the sample syntax from the wiki for a merge
             * @see http://www.postgresql.org/docs/current/static/plpgsql-control-structures.html#PLPGSQL-UPSERT-EXAMPLE
             */

            $doMerge = $this->hasId();

            if ($doMerge) {
                $sqlColumnsSet = array();
                $sqlWhere = array();
            }

            $quotedData = array();

            foreach ($this->_data as $column => $value) {
                if (in_array($column, $notSetFields)) {
                    continue;
                }

                $quotedColumn = $quoteIdentifier($column);
                $value = Rorm::quote($dbh, $value);

                $quotedData[$quotedColumn] = $value;

                if ($doMerge) {
                    if (in_array($column, $idColumns)) {
                        $sqlWhere[] = $quotedColumn . ' = ' . $value;
                    } else {
                        $sqlColumnsSet[] = $quotedColumn . ' = ' . $value;
                    }
                }
            }
            unset($column, $value, $quotedColumn);

            $sqlColumnsValues =
                '(' . implode(', ', array_keys($quotedData)) . ')' .
                ' VALUES ' .
                '(' . implode(', ', $quotedData) . ')';

            if ($doMerge) {
                // merge
                $sqlColumnsSet = implode(', ', $sqlColumnsSet);
                $sqlWhere = implode(' AND ', $sqlWhere);

                $sql =
                    'CREATE OR REPLACE FUNCTION rorm_merge()  RETURNS VOID AS
                    $$
                    BEGIN
                        LOOP
                            -- first try to update the key
                            UPDATE ' . $quotedTable . ' SET ' . $sqlColumnsSet . ' WHERE ' . $sqlWhere . ';
                        IF found THEN
                            RETURN;
                        END IF;
                        -- not there, so try to insert the key
                        -- if someone else inserts the same key concurrently,
                        -- we could get a unique-key failure
                        BEGIN
                            INSERT INTO ' . $quotedTable . ' ' . $sqlColumnsValues . ';
                            RETURN;
                        EXCEPTION WHEN unique_violation THEN
                            -- Do nothing, and loop to try the UPDATE again.
                        END;
                    END LOOP;
                END;
                $$
                LANGUAGE plpgsql;

                SELECT rorm_merge();';

                // execute (most likely throws PDOException if there is an error)
                if ($dbh->exec($sql) === false) {
                    return false;
                }

                return true;
            } else {
                // basic insert
                $sql =
                    'INSERT INTO ' . $quotedTable . ' ' .
                    $sqlColumnsValues .
                    ' RETURNING ' . $quoteIdentifier(static::$_idColumn);

                // execute (most likely throws PDOException if there is an error)
                $stmt = $dbh->query($sql);
                if (!$stmt) {
                    return false;
                }

                // update generated id
                if (static::$_autoId && !$this->hasId()) {
                    // last insert id
                    $this->set(static::$_idColumn, $stmt->fetchColumn());
                }

                return true;
            }
        } elseif (Rorm::isMySQL($dbh)) {
            /**
             * MySQL
             * Instead of REPLACE INTO we use INSERT INTO ON DUPLICATE KEY UPDATE.
             * Because REPLACE INTO does DELETE and INSERT,
             * which does not play nice with TRIGGERs and FOREIGN KEY CONSTRAINTS
             */
            $sql = 'INSERT INTO ' . $quotedTable . ' ';

            $doMerge = $this->hasId();

            $insertData = array();
            if ($doMerge) {
                $updateData = array();
            }

            foreach ($this->_data as $column => $value) {
                if (in_array($column, $notSetFields)) {
                    continue;
                }

                $quotedColumn = $quoteIdentifier($column);
                $insertData[$quotedColumn] = Rorm::quote($dbh, $value);

                if ($doMerge && !in_array($column, $idColumns)) {
                    $updateData[] = $quotedColumn . ' = VALUES(' . $quotedColumn . ')';
                }
            }
            unset($column, $value, $quotedColumn);

            // insert
            $sql .=
                '(' . implode(', ', array_keys($insertData)) . ')' .
                ' VALUES ' .
                '(' . implode(', ', $insertData) . ')';

            if ($doMerge) {
                // update
                $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateData);
            }

            // execute (most likely throws PDOException if there is an error)
            if ($dbh->exec($sql) === false) {
                return false;
            }

            // update generated id
            if (static::$_autoId && !$doMerge) {
                // last insert id
                $this->set(static::$_idColumn, $dbh->lastInsertId());
            }

            return true;
        } else {
            /**
             * SQLite
             */
            $sql = 'INSERT OR REPLACE INTO ' . $quotedTable . ' ';

            // build (column) VALUES (values)
            $quotedData = array();
            foreach ($this->_data as $column => $value) {
                if (in_array($column, $notSetFields)) {
                    continue;
                }

                $quotedData[$quoteIdentifier($column)] = Rorm::quote($dbh, $value);
            }
            unset($column, $value);

            $sql .= '(' . implode(', ', array_keys($quotedData)) . ') VALUES (' . implode(', ', $quotedData) . ')';

            // execute (most likely throws PDOException if there is an error)
            if ($dbh->exec($sql) === false) {
                return false;
            }

            // update generated id
            if (static::$_autoId && !$this->hasId()) {
                // last insert id
                $this->set(static::$_idColumn, $dbh->lastInsertId());
            }

            return true;
        }
    }

    /**
     * @return bool
     */
    public function delete()
    {
        $dbh = static::getDatabase();
        $quoteIdentifier = Rorm::getIdentifierQuoter($dbh);

        $idColumns = static::$_idColumn;
        if (!is_array($idColumns)) {
            $idColumns = array($idColumns);
        }

        $where = array();
        foreach ($idColumns as $columnName) {
            $where[] = $quoteIdentifier($columnName) . ' = ' . Rorm::quote($dbh, $this->$columnName);
        }

        $sql = 'DELETE FROM ' . $quoteIdentifier(static::getTable()) . ' WHERE ' . implode(' AND ', $where);

        return $dbh->exec($sql) > 0;
    }

    // data access
    /**
     * @return array
     */
    public function getData()
    {
        return $this->_data;
    }

    /**
     * @param array $data
     */
    public function setData(array $data)
    {
        $this->_data = $data;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function get($name)
    {
        if (array_key_exists($name, $this->_data)) {
            return $this->_data[$name];
        }
        return null;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return $this
     */
    public function set($name, $value)
    {
        $this->_data[$name] = $value;
        return $this;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->_data[$name]);
    }

    /**
     * Remove data from the model
     *
     * ATTENTION:
     * with the goal to only set the data we have this method can lead to unexpected behaviour
     *
     * @param string $name
     */
    public function remove($name)
    {
        unset($this->_data[$name]);
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    /**
     * @param string $name
     * @return bool
     */
    public function __isset($name)
    {
        return $this->has($name);
    }

    /**
     * @param string $name
     */
    public function __unset($name)
    {
        $this->remove($name);
    }

    /**
     * @param stdClass|array|Iterator $object
     * @param array $except
     */
    public function copyDataFrom($object, array $except = array())
    {
        foreach ($object as $key => $value) {
            if (!in_array($key, $except)) {
                $this->set($key, $value);
            }
        }
    }

    // Iterator
    public function rewind()
    {
        reset($this->_data);
    }

    /**
     * @return mixed
     */
    public function current()
    {
        return current($this->_data);
    }

    /**
     * @return mixed
     */
    public function key()
    {
        return key($this->_data);
    }

    public function next()
    {
        next($this->_data);
    }

    /**
     * @return bool
     */
    public function valid()
    {
        return key($this->_data) !== null;
    }

    // JsonSerializable
    /**
     * @return mixed
     */
    public function jsonSerialize()
    {
        return $this->_data;
    }
}
