<?php

namespace App\Services;

class DatabaseImportLock
{
    /** @return array<int, resource>|null */
    public function acquire(bool $exclusive): ?array
    {
        $paths = $exclusive
            ? ['luxquote-database-import.lock', 'luxquote-database-requests.lock']
            : ['luxquote-database-requests.lock'];
        $locks = [];
        foreach ($paths as $path) {
            $path = storage_path('framework/'.$path);
            $exists = is_file($path);
            $file = @fopen($path, $exists ? 'r' : 'c');
            if (! $exists && is_resource($file)) {
                @chmod($path, 0644);
            }
            if ($file === false || ! flock($file, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                if (is_resource($file)) {
                    fclose($file);
                }
                $this->release($locks);

                return null;
            }
            $locks[] = $file;
        }

        return $locks;
    }

    /** @param array<int, resource> $locks */
    public function release(array $locks): void
    {
        foreach ($locks as $lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
