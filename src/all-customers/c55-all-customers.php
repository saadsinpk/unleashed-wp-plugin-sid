<?php
if (!function_exists('c55_syncAllCustomers')) {
    function c55_syncAllCustomers($page = 1) {
        $endPoint = 'Customers/'.$page;
        $filterParams = ["pageSize"=>200];

        echo '<p> Fetching all Customers for ...</p>';
        $response = get_remote_unleashed_url($endPoint, $filterParams);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model;
        }
        c55_plugin_log($response);
    }
}

if (!function_exists('c55_getCustomerByProductCode')) {
    function c55_getCustomerByProductCode($productCode) {
        $endPoint = 'Customers';
        $filterParams = [
            'ProductCode' => $productCode,
            'includeAttributes' => 'true'
        ];

        $response = get_remote_unleashed_url($endPoint, $filterParams);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model;
        }
        c55_plugin_log($response);
    }
}

if (!function_exists('c55_updateCustomerByGuid')) {
    function c55_updateCustomerByGuid($guid, $data) {
        $endPoint = 'Products'. $guid;

        $response = post_remote_unleashed_url($endPoint, $data);
        dd($response);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model;
        }
        c55_plugin_log($response);
    }
}
