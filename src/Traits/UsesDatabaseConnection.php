<?php

namespace App\Traits;

/**
 * UsesDatabaseConnection Trait
 * Provides database connection management for services
 * Supports both legacy pg_query connections and future Eloquent integration
 */
trait UsesDatabaseConnection
{
    protected $connection;

    /**
     * Set database connection
     * 
     * @param resource|null $connection PostgreSQL connection resource
     */
    public function setConnection($connection = null)
    {
        if ($connection === null) {
            global $connection;
        }
        
        $this->connection = $connection;
        return $this;
    }

    /**
     * Get database connection
     * 
     * @return resource PostgreSQL connection resource
     */
    public function getConnection()
    {
        if ($this->connection === null) {
            global $connection;
            $this->connection = $connection;
        }
        
        return $this->connection;
    }

    /**
     * Check if connection is available
     * 
     * @return bool
     */
    protected function hasConnection()
    {
        return $this->getConnection() !== null;
    }

    /**
     * Execute parameterized query safely
     * 
     * @param string $query SQL query with $1, $2 placeholders
     * @param array $params Query parameters
     * @return resource Query result
     * @throws \Exception
     */
    protected function executeQuery($query, $params = [])
    {
        $conn = $this->getConnection();
        
        if (!$conn) {
            throw new \Exception('Database connection not available');
        }

        if (empty($params)) {
            $result = pg_query($conn, $query);
        } else {
            $result = pg_query_params($conn, $query, $params);
        }

        if ($result === false) {
            error_log("Database query error: " . pg_last_error($conn));
            throw new \Exception('Database query failed: ' . pg_last_error($conn));
        }

        return $result;
    }

    /**
     * Fetch all results from query
     * 
     * @param resource $result Query result
     * @return array
     */
    protected function fetchAll($result)
    {
        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Fetch single row from query
     * 
     * @param resource $result Query result
     * @return array|null
     */
    protected function fetchOne($result)
    {
        return pg_fetch_assoc($result);
    }

    /**
     * Get number of rows affected
     * 
     * @param resource $result Query result
     * @return int
     */
    protected function getRowCount($result)
    {
        return pg_num_rows($result);
    }
}
