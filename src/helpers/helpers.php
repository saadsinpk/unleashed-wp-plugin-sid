<?php

if (!function_exists('c55_plugin_log')) {
    function c55_plugin_log($entry, $mode = 'a', $file = 'plugin') 
    {
        // Get WordPress uploads directory.
        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'];

        // If the entry is array, json_encode.
        if (is_array($entry)) {
            $entry = json_encode($entry);
        }

        // Write the log file.
        $file  = $upload_dir . '/' . $file . '.log';
        $file  = fopen($file, $mode);
        $bytes = fwrite($file, current_time('mysql') . "::" . $entry . "\n");
        fclose($file);

        return $bytes;
    }
}

function dd($var)
{
    echo '<pre>' . var_export($var, true) . '</pre>';
    die();
}

