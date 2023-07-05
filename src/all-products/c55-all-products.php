<?php
if (!function_exists('c55_syncAllProducts')) {
    function c55_syncAllProducts($group) {
        $endPoint = 'Products';
        $filterParams = [
            'ProductGroup' => $group,
            'includeAttributes' => 'true'
        ];

        echo '<p> Fetching all Products for ...</p>';
		echo '<p>Group Name...'.$group.'</p>';
        $response = get_remote_unleashed_url($endPoint, $filterParams);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model;
        }
        c55_plugin_log($response);
    }
}

if (!function_exists('c55_getProductByProductCode')) {
    function c55_getProductByProductCode($productCode) {
        $endPoint = 'Products';
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

if (!function_exists('c55_updateProductByGuid')) {
    function c55_updateProductByGuid($guid, $data) {
        $endPoint = 'Products'. $guid;
        // $filterParams = [
        //     'ProductCode' => $productCode,
        //     'includeAttributes' => 'true'
        // ];

        $response = post_remote_unleashed_url($endPoint, $data);
        dd($response);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            return $model;
        }
        c55_plugin_log($response);
    }
}

// Post a stock adjustement request
// {
// 	"Warehouse": {
// 		"Guid": "ffa99030-326c-4607-8a16-796b599d6e30",
// 		"WarehouseCode": "GOHerbs",
// 		"WarehouseName": "GOHerbs"
// 	},
// 	"AdjustmentDate": "2021-11-30",
// 	"AdjustmentReason": "Adjustment",
// 	"Guid": "14955e0d-35be-4eea-a167-bfd416536ec3",
// 	"StockAdjustmentLines": [{
// 		"LineNumber": 1,
// 		"Product": {
// 			"ProductCode": "ZAA30G"
// 		},
// 		"NewQuantity": 1093,
//                  "NewActualValue":1093,
// 		"Guid": "14955e0d-35be-4eea-a167-bfd416536ec3"
// 	}]
// }
