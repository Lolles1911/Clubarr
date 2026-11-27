<?php

namespace App\MediaServers\Drivers;

use App\MediaServers\MediaServerException;
use Throwable;

class EmbyDriver extends MediaBrowserDriver
{
    /** Emby wants a library's Guid in EnabledFolders where it has one. */
    protected function folderPolicyId(array $folder): string
    {
        return (string) ($folder['Guid'] ?? $folder['Id'] ?? '');
    }

    /** Emby makes the user first and sets the password after. */
    protected function newUser(string $username, string $password): string
    {
        try {
            $user = $this->http->json('POST', '/Users/New', json: ['Name' => $username]);
        } catch (MediaServerException $e) {
            throw $this->createFailed($e, $username);
        }
        $id = (string) ($user['Id'] ?? '');
        if ($id === '') {
            throw new MediaServerException(__(':name did not return the new user.', ['name' => $this->server->name]));
        }
        try {
            $this->http->send('POST', '/Users/'.rawurlencode($id).'/Password', json: ['CurrentPw' => '', 'NewPw' => $password, 'ResetPassword' => false]);
        } catch (Throwable $e) {
            // Never leave a user without a password.
            try {
                $this->http->send('DELETE', '/Users/'.rawurlencode($id));
            } catch (Throwable) {
            }

            throw $e instanceof MediaServerException ? $e : new MediaServerException($e->getMessage());
        }

        return $id;
    }
}
