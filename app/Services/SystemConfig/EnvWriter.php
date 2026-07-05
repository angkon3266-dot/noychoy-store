<?php

namespace App\Services\SystemConfig;

/**
 * Minimal, careful reader/writer for the .env file. Used ONLY for bootstrap
 * settings that cannot live in the DB (the database connection). Preserves
 * comments, ordering and unrelated keys; quotes values that need it.
 */
class EnvWriter
{
    private function path(): string
    {
        return app()->environmentFilePath();
    }

    public function exists(): bool
    {
        return is_file($this->path()) && is_writable($this->path());
    }

    public function get(string $key): ?string
    {
        if (! is_file($this->path())) {
            return null;
        }

        foreach (file($this->path(), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $m)) {
                return $this->unquote(trim($m[1]));
            }
        }

        return null;
    }

    /**
     * Set multiple keys atomically-ish (single rewrite). Returns false on any
     * I/O failure so callers can roll back.
     *
     * @param  array<string,?string>  $pairs
     */
    public function setMany(array $pairs): bool
    {
        $path = $this->path();
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }

        foreach ($pairs as $key => $value) {
            $line = $key.'='.$this->quote((string) $value);
            $pattern = '/^\s*'.preg_quote($key, '/').'\s*=.*$/m';

            $content = preg_match($pattern, $content)
                ? preg_replace($pattern, $line, $content)
                : rtrim($content, "\n")."\n".$line."\n";
        }

        // Write to a temp file then rename for a safer swap.
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }

        return rename($tmp, $path);
    }

    private function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Quote when the value contains spaces, #, quotes or leading/trailing space.
        if (preg_match('/\s|#|"|\'|=/', $value)) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }

    private function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        return str_replace('\"', '"', $value);
    }
}
