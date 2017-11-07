<?php

namespace Javer\SphinxBundle\Loader;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOSqlite\Driver as SqliteDriver;
use Doctrine\DBAL\Driver\Statement;
use Javer\SphinxBundle\Sphinx\Manager;
use Javer\SphinxBundle\Sphinx\Query;

/**
 * Class DoctrineDataLoader
 *
 * @package Javer\SphinxBundle\Loader
 */
class DoctrineDataLoader
{
    /**
     * @var Manager
     */
    protected $sphinx;

    /**
     * @var Connection
     */
    protected $database;

    /**
     * DoctrineDataLoader constructor.
     *
     * @param Manager    $sphinx
     * @param Connection $database
     */
    public function __construct(Manager $sphinx, Connection $database = null)
    {
        $this->sphinx = $sphinx;
        $this->database = $database;
    }

    /**
     * Load data from database to indexes.
     *
     * @param array $indexes
     */
    public function loadDataIntoIndexes($indexes)
    {
        foreach ($indexes as $indexName => $indexData) {
            $this->clearIndex($indexName);

            $joinedData = $this->loadDataForJoinedFields($indexName, $indexData['joinedFields']);

            $this->loadDataForSqlQuery($indexName, $indexData['schema'], $indexData['query'], $joinedData);

            $this->loadDataForAttrMulti($indexName, $indexData['attrMulti']);
        }
    }

    /**
     * Clear index.
     *
     * @param string $indexName
     */
    protected function clearIndex(string $indexName)
    {
        $this->createSphinxQuery('DELETE FROM ' . $indexName . ' WHERE id >= 1')
            ->execute();
    }

    /**
     * Load data for sql_query.
     *
     * @param string $indexName
     * @param array  $schema
     * @param string $sqlQuery
     * @param array  $joinedData
     *
     * @return integer
     */
    protected function loadDataForSqlQuery(string $indexName, array $schema, string $sqlQuery, array $joinedData = [])
    {
        $stmt = $this->executeDatabaseQuery($sqlQuery);

        $sphinxQuery = $this->createSphinxQuery();
        $batch = '';
        $columns = [];

        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (empty($columns)) {
                $columns = array_merge(array_keys($row), array_keys($joinedData));
            }

            $values = [];
            foreach ($row as $columnName => $columnValue) {
                $columnType = $columnName == 'id' ? 'uint' : ($schema[$columnName] ?? 'string');

                $values[] = $sphinxQuery->quoteValue($this->castValue($columnValue, $columnType));
            }

            if (isset($row['id'])) {
                $docId = (int) $row['id'];

                foreach ($joinedData as $joinedColumnName => $joinedColumnValues) {
                    $values[] = $sphinxQuery->quoteValue(implode(' ', $joinedColumnValues[$docId] ?? []));
                }
            }

            $batch .= (strlen($batch) > 0 ? ', ' : '') . '(' . implode(', ', $values) . ')';
        }

        if (count($columns) == 0 || strlen($batch) == 0) {
            return 0;
        }

        $sql = 'REPLACE INTO ' . $indexName . ' (' . implode(', ', $columns) . ') VALUES ' . $batch;

        return $sphinxQuery->setQuery($sql)->execute();
    }

    /**
     * Load data for sql_attr_multi.
     *
     * @param string $indexName
     * @param array  $attrMulti
     */
    protected function loadDataForAttrMulti(string $indexName, array $attrMulti)
    {
        foreach ($attrMulti as list($attrType, $attrName, $attrQuery)) {
            $stmt = $this->executeDatabaseQuery($attrQuery);

            $multiValues = [];
            $sphinxQuery = $this->createSphinxQuery();

            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                $docId = (int) $row[0];
                $value = $row[1];

                if (!isset($multiValues[$docId])) {
                    $multiValues[$docId] = [];
                }

                $multiValues[$docId][] = $sphinxQuery->quoteValue($this->castValue($value, $attrType));
            }

            foreach ($multiValues as $docId => $values) {
                $sql = sprintf(
                    'UPDATE %s SET %s = (%s) WHERE id = %d',
                    $indexName,
                    $attrName,
                    implode(', ', array_unique($values)),
                    $docId
                );

                $this->createSphinxQuery($sql)->execute();
            }
        }
    }

    /**
     * Load data for sql_joined_field.
     *
     * @param string $indexName
     * @param array  $joinedFields
     *
     * @return array
     */
    protected function loadDataForJoinedFields(string $indexName, array $joinedFields)
    {
        $data = [];

        foreach ($joinedFields as list($queryType, $fieldName, $joinedQuery)) {
            $stmt = $this->executeDatabaseQuery($joinedQuery);
            $data[$fieldName] = [];

            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                $docId = (int) $row[0];
                $value = (string) $row[1];

                if (!isset($data[$fieldName][$docId])) {
                    $data[$fieldName][$docId] = [];
                }

                $data[$fieldName][$docId][] = $value;
            }
        }

        return $data;
    }

    /**
     * Execute query on the current database.
     *
     * @param string $query
     *
     * @return Statement
     */
    protected function executeDatabaseQuery(string $query)
    {
        return $this->database->executeQuery($this->adaptQuery($query));
    }

    /**
     * Adapt query to the current database driver.
     *
     * @param string $query
     *
     * @return string
     */
    protected function adaptQuery(string $query)
    {
        if ($this->database->getDriver() instanceof SqliteDriver) {
            $query = preg_replace("/UNIX_TIMESTAMP\(/i", "strftime('%s', ", $query);
        }

        return $query;
    }

    /**
     * Create a new Sphinx Query.
     *
     * @param string $query
     *
     * @return Query
     */
    protected function createSphinxQuery(string $query = null)
    {
        $sphinxQuery = $this->sphinx->createQuery();

        if (!is_null($query)) {
            $sphinxQuery->setQuery($query);
        }

        return $sphinxQuery;
    }

    /**
     * Cast value to the given type.
     *
     * @param mixed  $value
     * @param string $type
     *
     * @return string|integer
     */
    protected function castValue($value, string $type)
    {
        if (in_array($type, ['uint', 'bool', 'bigint', 'timestamp'])) {
            return (int) $value;
        }

        return (string) $value;
    }
}
