<?php

namespace App\MediaServers\Data;

/** Something new on a server: a film, or a show with a new episode. $ref finds its poster. */
final readonly class RecentItem
{
    public function __construct(public string $ref, public string $title, public ?int $year, public int $addedAt) {}

    /** @return array{ref: string, title: string, year: int|null, added: int} for the cache */
    public function toArray(): array
    {
        return ['ref' => $this->ref, 'title' => $this->title, 'year' => $this->year, 'added' => $this->addedAt];
    }
}
