<?php
/**
 * Minimal PDO adapter for the original phpBB database call shape.
 *
 * Value-bearing queries use native prepared statements. Direct execution is
 * retained only for constant SQL and strictly validated identifiers.
 */

$db_default_connection = null;
$db_last_error = '';
$db_last_errno = 0;

function db_connect($host, $username, $password)
{
    global $db_default_connection, $db_last_error, $db_last_errno;

    try
    {
        $connection = new PDO(
            "mysql:host=$host;charset=utf8mb4",
            $username,
            $password,
            array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH,
                PDO::ATTR_STRINGIFY_FETCHES => true,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            )
        );
    }
    catch (PDOException $exception)
    {
        $db_last_error = 'Database operation failed.';
        $db_last_errno = (int) $exception->getCode();
        db_log_error('connect', $db_last_errno, (string) $exception->getCode());
        return false;
    }

    $db_default_connection = $connection;
    $db_last_error = '';
    $db_last_errno = 0;
    return $connection;
}

function db_select_db($database, $connection = null)
{
    global $db_default_connection, $db_last_error, $db_last_errno;

    $connection = $connection ?: $db_default_connection;
    if (!$connection || !db_valid_identifier($database))
    {
        $db_last_error = 'Invalid database connection or database name.';
        $db_last_errno = 0;
        return false;
    }

    $result = $connection->query("USE `$database`");
    if ($result === false)
    {
        db_capture_error($connection);
        return false;
    }

    $connection->exec('SET NAMES utf8mb4');
    return true;
}

function db_valid_identifier($identifier)
{
    return is_string($identifier) && preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
}

function db_query($sql, $connection = null)
{
    global $db_default_connection, $db_last_error, $db_last_errno;

    $connection = $connection ?: $db_default_connection;
    if (!$connection)
    {
        $db_last_error = 'No database connection is available.';
        $db_last_errno = 0;
        return false;
    }

    $result = $connection->query($sql);
    if ($result === false)
    {
        db_capture_error($connection);
        return false;
    }

    $db_last_error = '';
    $db_last_errno = 0;
    return $result;
}

function db_query_params($sql, $parameters = array(), $connection = null)
{
    global $db_default_connection, $db_last_error, $db_last_errno;

    $connection = $connection ?: $db_default_connection;
    if (!$connection)
    {
        $db_last_error = 'No database connection is available.';
        $db_last_errno = 0;
        return false;
    }

    $statement = $connection->prepare($sql);
    if ($statement === false)
    {
        db_capture_error($connection);
        return false;
    }

    foreach ($parameters as $name => $value)
    {
        $placeholder = is_int($name) ? $name + 1 : ':' . ltrim($name, ':');
        $type = PDO::PARAM_STR;
        if (is_int($value) || is_bool($value))
        {
            $type = PDO::PARAM_INT;
            $value = (int) $value;
        }
        else if ($value === null)
        {
            $type = PDO::PARAM_NULL;
        }
        $statement->bindValue($placeholder, $value, $type);
    }

    if (!$statement->execute())
    {
        db_capture_error($statement);
        return false;
    }

    $db_last_error = '';
    $db_last_errno = 0;
    return $statement;
}

function db_fetch_array($result)
{
    return $result ? $result->fetch(PDO::FETCH_BOTH) : false;
}

function db_fetch_row($result)
{
    return $result ? $result->fetch(PDO::FETCH_NUM) : false;
}

function db_num_rows($result)
{
    return $result ? $result->rowCount() : 0;
}

function db_affected_rows($result)
{
    return $result ? $result->rowCount() : 0;
}

function db_insert_id($connection = null)
{
    global $db_default_connection;
    $connection = $connection ?: $db_default_connection;
    return $connection ? (int) $connection->lastInsertId() : 0;
}

function db_error($connection = null)
{
    global $db_last_error;
    return $db_last_error ?: 'Database operation failed.';
}

function db_errno($connection = null)
{
    global $db_last_errno;
    return $db_last_errno;
}

function db_capture_error($connection)
{
    global $db_last_error, $db_last_errno;
    $details = $connection->errorInfo();
    $db_last_errno = isset($details[1]) ? (int) $details[1] : 0;
    $db_last_error = 'Database operation failed.';
    db_log_error('query', $db_last_errno, isset($details[0]) ? (string) $details[0] : '');
}

function db_log_error($stage, $driver_code, $sql_state)
{
    error_log(json_encode(array(
        'event' => 'database_error',
        'stage' => (string) $stage,
        'driver_code' => (int) $driver_code,
        'sql_state' => preg_replace('/[^A-Za-z0-9]/', '', (string) $sql_state),
    ), JSON_UNESCAPED_SLASHES));
}
