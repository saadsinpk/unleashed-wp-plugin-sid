<?php
// include_once('./all-products/c55-all-products.php');

// add_action('before_delete_post', 'c55_product_updated');
// add_action('save_post', 'c55_product_updated');

function c55_product_updated($post)
{
    $product = wc_get_product($post);
    if ($product) {
        $status = $product->get_status();
        $name = $product->get_name();
        $description = $product->get_description();
        $price = $product->get_price();
        $type = $product->get_type();
        // Other type is variable
        if ($status === 'publish' && $type === 'variation') {
            $model = c55_getProductByProductCode($product->get_sku());
            if ($model) {
                $guid = $model['Items'][0]['Guid'];
                $model = [];
                $model['ProductDescription'] = $description;
                $model['SellPriceTier1'] = $price;
                c55_updateProductByGuid($guid, $model);
            }
        }
    }
}

function c55_product_update_unleased($name, $description, $price, $GUID)
{
    $model = [];
    $model['ProductDescription'] = $description;
    $model['SellPriceTier1'] = $price;
    c55_updateProduct($model, $GUID);
}
