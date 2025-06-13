<?php
if (!function_exists('c55_updateProduct')) {
    function c55_updateProduct($model, $GUID)
    {
        $endPoint = 'Products' . $GUID;

        $response = get_remote_unleashed_url($endPoint);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model;
        }
        c55_plugin_log($response);
    }
}
