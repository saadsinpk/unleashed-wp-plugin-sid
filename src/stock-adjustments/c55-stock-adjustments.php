<?php
// Deperected not used
if (!function_exists('c55_getStockAdjustMents')) {
    function c55_getStockAdjustMents($date = null)
    {
        $date  = $date ? $date : gmdate("Y-m-d");
        $endPoint = 'StockAdjustments';
        $filterParams = [
            'adjustmentDate' => $date
        ];

        echo '<p> Fetching all Stock Adjusted Products: ' . $date . '</p>';
        $response = get_remote_unleashed_url($endPoint, $filterParams);
        if ($response && array_key_exists('body', $response)) {
            $model = json_decode($response['body'], true);
            c55_updateAllProducts($model);
        }
        c55_plugin_log($response);
    }
}

if (!function_exists('c55_updateAllProducts')) {
    function c55_updateAllProducts($model)
    {
        $woocommerceModel = c55_getAllProducts();
        $keys = array_map("c55_returnIdsSKUOfProducts", $woocommerceModel);
        // dd($model);
        // dd($woocommerceModel[0]->get_id());
        $productsToUpdate = [];
        if ($model && $model['Items'] && count($model['Items']) > 0) {
            foreach ($model['Items'] as $index => $item) {
                if ($item && $item['StockAdjustmentLines']) {
                    foreach ($item['StockAdjustmentLines'] as $i => $stock) {
                        // dd($stock);
                        $productsToUpdate[$index]['sku'] = $stock['Product']['ProductCode'];
                        $productsToUpdate[$index]['manage_stock'] = true;
                        $productsToUpdate[$index]['stock_quantity'] = $stock['NewQuantity'];
                        $productsToUpdate[$index]['stock_status'] = $stock['NewQuantity'] > 0
                            ? 'in_stock'
                            : 'out_stock';
                        $matches = array_search($productsToUpdate[$index]['sku'], array_column($keys, 'sku'));
                        $id = $keys[$matches]['id'];
                        $productsToUpdate[$index]['id'] = $id;
                    }
                }
            }
        }
        // $keys = array_map("c55_returnIdsSKUOfProducts", $woocommerceModel);
        // dd($productsToUpdate);
        // $key = array_search('PAPSWE1KG', array_column($keys, 'sku'));
        // dd($keys[$key]);
        // $result = array_merge($productsToUpdate, $keys);
        dd($productsToUpdate);
        // dd($productsToUpdate);
        // c55_plugin_log($response);
        updateWoocommerceStockLevels($productsToUpdate);
    }
}
function c55_returnIdsSKUOfProducts($product) {
    if ($product) {
        $a = ['sku' => $product->get_sku(), 'id' => $product->get_id() ];
        return $a;
    }

}
function updateWoocommerceStockLevels($productsToUpdate) {
    global $woocommerce;

    $data = [
        'update' => $productsToUpdate
    ];
    dd($woocommerce->post('products/batch', $data));
}
