<?php
declare(strict_types=1);

namespace LearningCloud;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Store
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function connect(bool $publicVerification = false): self
    {
        $dsn = getenv('LEARNING_DSN');
        $user = getenv('LEARNING_DB_USER');
        $password = getenv('LEARNING_DB_PASSWORD');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') || $user === false || $password === false) {
            throw new RuntimeException('Set LEARNING_DSN, LEARNING_DB_USER and LEARNING_DB_PASSWORD.');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        // Each PHP worker may retain one connection reserved for public SELECTs.
        // Initialize the session on connection creation, including after a reconnect.
        $initOption = defined('Pdo\\Mysql::ATTR_INIT_COMMAND') ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND') : PDO::MYSQL_ATTR_INIT_COMMAND;
        $options[$initOption] = "SET SESSION time_zone = '+00:00', SESSION transaction_isolation = 'READ-COMMITTED'";
        if ($publicVerification && getenv('LEARNING_VERIFY_PERSISTENT') !== '0') {
            $options[PDO::ATTR_PERSISTENT] = 'learning-public-verification';
        }
        $pdo = new PDO($dsn, $user, $password, $options);
        return new self($pdo);
    }

    public function one(string $sql, array $parameters = []): ?array
    {
        $row = $this->statement($sql, $parameters)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $parameters = []): array
    {
        return $this->statement($sql, $parameters)->fetchAll();
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return $this->statement($sql, $parameters)->rowCount();
    }

    public function insert(string $sql, array $parameters): int
    {
        $this->statement($sql, $parameters);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public static function duplicate(PDOException $error): bool
    {
        return ($error->errorInfo[1] ?? null) === 1062;
    }

    private function statement(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($parameters) as $index => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }
}
