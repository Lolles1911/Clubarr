<?php

namespace App\MediaServers\Drivers;

use App\MediaServers\MediaServerException;

class JellyfinDriver extends MediaBrowserDriver
{
    protected function folderPolicyId(array $folder): string
    {
        return (string) ($folder['Id'] ?? '');
    }

    protected function newUser(string $username, string $password): string
    {
        try {
            $user = $this->http->json('POST', '/Users/New', json: ['Name' => $username, 'Password' => $password]);
        } catch (MediaServerException $e) {
            throw $this->createFailed($e, $username);
        }
        if (empty($user['Id'])) {
            throw new MediaServerException(__(':name did not return the new user.', ['name' => $this->server->name]));
        }

        return (string) $user['Id'];
    }
}
