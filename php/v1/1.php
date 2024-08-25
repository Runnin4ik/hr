<?php

namespace Manager;

class User
{
    /**
     * Возвращает пользователей старше заданного возраста.
     * @param int $age
     * @return array
     */
    public static function getUsersAboveAge(int $age): array
    {
        $userInstance = \Gateway\User::getInstance();
        $userInstance->setLimit(10);
        return $userInstance->getUsersAboveAge($age);
    }

    /**
     * Возвращает пользователей по списку имен.
     * @return array
     * @throws \InvalidArgumentException
     */
    public static function getUsersByNames(): array
    {
        if (!isset($_GET['names']) || !is_array($_GET['names'])) {
            throw new \InvalidArgumentException('Invalid or missing "names" parameter.');
        }
        $users = [];
        foreach ($_GET['names'] as $name) {
            $users[] = \Gateway\User::getInstance()->getUserByName($name);
        }

        return $users;
    }

    /**
     * Добавляет пользователей в базу данных.
     * @param $users
     * @return array
     * @throws \InvalidArgumentException|\Exception
     */
    public static function addUsers($users): array
    {
        $userIds = [];
        $userInstance = \Gateway\User::getInstance();
        $pdo = $userInstance->getConnection();
        $pdo->beginTransaction();
        try {
            foreach ($users as $user) {
                if (!isset($user['name'], $user['lastName'], $user['age'])) {
                    throw new \InvalidArgumentException('User data is incomplete.');
                }
                $userIds[] = $userInstance->addUser($user['name'], $user['lastName'], $user['age']);
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $userIds;
    }
}