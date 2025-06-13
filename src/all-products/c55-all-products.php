<?php
if (!function_exists('c55_syncAllProducts')) {
    function c55_syncAllProducts($group, $next_page = 1) {
        $endPoint = 'Products';
        $filterParams = [
            'ProductGroup' => $group,
            'includeAttributes' => 'true'
        ];

        echo '<p> Fetching all Products for ...</p>';
		echo '<p>Group Name...'.$group.'</p>';
        $response = get_remote_unleashed_url($endPoint, $filterParams, $next_page);
         if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model;
        }
        c55_plugin_log($response);
    }
}


if (!function_exists('c55_updateProductByGuid')) {
    function c55_updateProductByGuid($guid, $data) {
        $endPoint = 'Products'. $guid;
        // $filterParams = [
        //     'ProductCode' => $productCode,
        //     'includeAttributes' => 'true'
        // ];

        $response = post_remote_unleashed_url($endPoint, $data);
        // dd($response);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model;
        }
        c55_plugin_log($response);
    }
}

