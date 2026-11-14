<?php

declare(strict_types=1);

namespace Charm\Zstd\Tests;

/** The reference implementation: the `zstd` command-line tool, when it is on PATH. */
final class Cli
{
    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available === null) {
            $out = [];
            $code = 1;
            @exec('zstd --version 2>/dev/null', $out, $code);
            self::$available = $code === 0 && stripos(implode("\n", $out), 'zstandard') !== false;
        }
        return self::$available;
    }

    public static function version(): string
    {
        return trim((string) shell_exec('zstd --version 2>/dev/null'));
    }

    /** Compresses from a file, so that (like `zstd file`) the frame records the content size. $args are shell-ready options. */
    public static function compress(string $data, string $args = ''): string
    {
        $f = self::tmp();
        file_put_contents($f, $data);
        try {
            return self::run("zstd -q $args -c " . escapeshellarg($f));
        } finally {
            @unlink($f);
        }
    }

    /** Compresses from a pipe: the frame has no content size. */
    public static function compressPiped(string $data, string $args = ''): string
    {
        return self::run("zstd -q $args -c", $data);
    }

    /** `zstd -d`: throws if the tool rejects the data. */
    public static function decompress(string $zst): string
    {
        return self::run('zstd -q -d -c', $zst);
    }

    /** Runs a shell command; its stdin (if any) comes from a temp file, so nothing can deadlock on a full pipe. */
    private static function run(string $cmd, string $stdin = ''): string
    {
        $f = null;
        if ($stdin !== '') {
            $f = self::tmp();
            file_put_contents($f, $stdin);
            $cmd .= ' < ' . escapeshellarg($f);
        } else {
            $cmd .= ' < /dev/null';
        }
        try {
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) {
                throw new \RuntimeException("cannot start: $cmd");
            }
            // stderr is only read after stdout: zstd -q writes (almost) nothing there
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($p);
        } finally {
            if ($f !== null) {
                @unlink($f);
            }
        }
        if ($code !== 0) {
            throw new \RuntimeException("`$cmd` exited with $code: " . trim($err));
        }
        return $out;
    }

    private static function tmp(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'charm-zstd-');
        if ($f === false) {
            throw new \RuntimeException('cannot create a temp file');
        }
        return $f;
    }
}
