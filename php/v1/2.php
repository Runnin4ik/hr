<?php

namespace Gateway;

use PDO;
use PDOException;

class User
{
    /**
     * @var User|null Экземпляр класса User
     */
    private static ?User $instance = null;

    /**
     * @var PDO|null PDO-соединение
     */
    private ?PDO $pdo = null;

    /**
     * @var int Лимит получения пользователей
     */
    private int $getUsersLimit = 10;

    private function __construct() {}
    private function __clone() {}
    private function __wakeup() {}

    /**
     * Получает лимит получения пользователей.
     * @return int
     */
    public function getLimit(): int
    {
        return $this->getUsersLimit;
    }

    /**
     * Устанавливает лимит получения пользователей.
     * @param int $limit
     * @throws \InvalidArgumentException
     */
    public function setLimit(int $limit): void
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Limit must be a positive integer');
        }
        $this->getUsersLimit = $limit;
    }

    /**
     * Возвращает единственный экземпляр класса User.
     * @return User
     */
    public static function getInstance(): User
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Возвращает PDO-соединение.
     * @return PDO
     */
    public function getConnection(): PDO
    {
        if (is_null($this->pdo)) {
            $dsn = 'mysql:dbname=db;host=127.0.0.1';
            $user = 'dbuser';
            $password = 'dbpass';

            try {
                $this->pdo = new PDO($dsn, $user, $password, [
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            } catch (PDOException $e) {
                throw new \RuntimeException("Database connection error: " . $e->getMessage(), 500);
            }
        }

        return $this->pdo;
    }

    /**
     * Возвращает список пользователей старше заданного возраста.
     * @param int $age
     * @return array
     */
    public function getUsersAboveAge(int $age): array
    {
        //Предположил, что setting имеет тип JSON (и, следовательно, он валидный) и не нужно проверять наличие key
        //Иначе нужно делать реализацию с проверкой наличия key и валидности json
        $stmt = $this->getConnection()->prepare(
            "SELECT id, name, lastName, `from`, age, setting->>'$.key' AS `key`
                   FROM Users 
                   WHERE age > :age 
                   LIMIT :limit"
        );
        $stmt->bindValue(':age', $age);
        $stmt->bindValue(':limit', $this->getUsersLimit);
        return $stmt->fetchAll();
    }

    /**
     * Возвращает пользователя по имени.
     * @param string $name
     * @return array
     */
    public function getUserByName(string $name): array
    {
        $stmt = $this->getConnection()->prepare("SELECT id, name, lastName, `from`, age FROM Users WHERE name = :name");
        $stmt->execute([':name' => $name]);

        return $stmt->fetch() ?: [];
    }

    /**
     * Добавляет пользователя в базу данных.
     * @param string $name
     * @param string $lastName
     * @param int $age
     * @return string|false
     */
    public function addUser(string $name, string $lastName, int $age): string|false
    {
        $pdo = $this->getConnection();
        //нет параметра `from`, потому что у него есть значение по умолчанию?
        $stmt = $pdo->prepare("INSERT INTO Users (name, lastName, age) VALUES (:name, :lastName, :age)");
        $stmt->execute([':name' => $name, ':lastName' => $lastName, ':age' => $age]);

        return $pdo->lastInsertId();
    }
}