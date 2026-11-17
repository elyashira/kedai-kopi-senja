<?php
if (!function_exists('env')) {
    function env($key, $default = null)
    {
        static $config = null;

        if ($config === null) {
            $config = [];

            $file = dirname(__DIR__) . '/.env';

            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    $separator = strpos($line, '=');

                    if ($separator === false) {
                        continue;
                    }

                    $name = trim(substr($line, 0, $separator));
                    $value = trim(substr($line, $separator + 1));

                    $value = trim($value, "\"'");

                    if ($name !== '') {
                        $config[$name] = $value;
                    }
                }
            }
        }
        return $config[$key] ?? $default;
    }
}