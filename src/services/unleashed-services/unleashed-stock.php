<?php

if (!function_exists('c55_getStockOnHand')) {
    function c55_getStockOnHand($guid)
    {
        $endPoint = 'StockOnHand/' . $guid;

        $response = get_remote_unleashed_url($endPoint);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model['QtyOnHand'];
        }
        c55_plugin_log($response);
    }
}