<?php

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class DatabaseUserProvider implements UserProviderInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, roles, password FROM public."user" WHERE LOWER(email) = LOWER(:email)',
            ['email' => $identifier],
        );

        if (false === $row) {
            $exception = new UserNotFoundException('User not found.');
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        $roles = is_array($row['roles']) ? $row['roles'] : json_decode((string) $row['roles'], true);

        return new DatabaseUser(
            (string) $row['id'],
            (string) $row['email'],
            is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [],
            (string) $row['password'],
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof DatabaseUser) {
            throw new \InvalidArgumentException(sprintf('Unsupported user class "%s".', get_debug_type($user)));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return DatabaseUser::class === $class || is_subclass_of($class, DatabaseUser::class);
    }
}