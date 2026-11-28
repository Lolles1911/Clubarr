<?php

namespace App\MediaServers\Data;

/** A user on a media server. Server administrators are marked, and Clubarr never changes them. */
final readonly class RemoteUser
{
    public function __construct(
        public string $id,
        public string $username,
        public ?string $email = null,
        public bool $isAdmin = false,
        public bool $isDisabled = false,
        /** When they last watched or signed in (Unix time), if the server tells. */
        public ?int $lastSeen = null,
    ) {}

    /**
     * Plain values, for the cache: Laravel's cache does not bring objects
     * back (cache.serializable_classes = false, against object injection).
     *
     * @return array{id: string, username: string, email: ?string, isAdmin: bool, isDisabled: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'username' => $this->username, 'email' => $this->email, 'isAdmin' => $this->isAdmin, 'isDisabled' => $this->isDisabled];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self((string) ($row['id'] ?? ''), (string) ($row['username'] ?? ''), isset($row['email']) ? (string) $row['email'] : null,
            (bool) ($row['isAdmin'] ?? false), (bool) ($row['isDisabled'] ?? false));
    }
}
