<?php

if (!function_exists('c55_getAllProducts')) {
    function c55_getAllProducts()
    {
        $args = array(
            'orderby'  => 'sku',
            'limit'  => -1,
            'status' => 'publish'
        );
        $result = wp_cache_get('all_products');
        if (false === $result) {
            $products = wc_get_products($args);
            wp_cache_set('all_products', $products);
        }
        return $products;
    }
}

function c55_updateProductVariable($productId, $model)
{

    if (!isset($productId)) {
        throw new Exception('Missing product ID..');
    }
    // dd($model);
    $attributes = [];
    $parentVariation = '';
    if ($model) {
        foreach ($model['AttributeSet']['Attributes'] as $key => $attr) {
            if($attr['Value'] == '25g') {
                $attr['Value'] = '25g - Sachet';
            }

            if ($attr['Name'] === 'Name') {
                $parentVariation = strtolower($attr['Value']);
            } else {
                $attributes[strtolower($attr['Name'])] = [$attr['Value']];
            }
        }
    }
    unset($attributes['type']);

    $product = wc_get_product($productId);

    $post_data = array(
        'ID'         => $productId,
        'post_title'    => $parentVariation
    );

    wp_update_post($post_data);
    $product_attributes = array();
    foreach ($attributes as $key => $terms) {
        $taxonomy = wc_attribute_taxonomy_name($key); // The taxonomy slug
        $attr_label = ucfirst($key); // attribute label name
        $attr_name = (wc_sanitize_taxonomy_name($key)); // attribute slug

        // NEW Attributes: Register and save them
        if (!taxonomy_exists($taxonomy))
            save_product_attribute_from_name($attr_name, $attr_label);
        if (isset($terms[0]) && $terms[0] !== null) {
            $product_attributes[$taxonomy] = array(
                'name'         => $taxonomy,
                'value'        => '',
                'position'     => '',
                'is_visible'   => 0,
                'is_variation' => 1,
                'is_taxonomy'  => 1
            );
        }

        foreach ($terms as $value) {
            if (isset($value) && $value !== null) {
                $term_name = ucfirst($value);
                $term_slug = sanitize_title($value);

                // Check if the Term name exist and if not we create it.
                if (!term_exists($value, $taxonomy))
                    wp_insert_term($term_name, $taxonomy, array('slug' => $term_slug)); // Create the term

                // Set attribute values
                wp_set_post_terms($productId, $term_name, $taxonomy, true);
            }
        }
    }
    update_post_meta($productId, '_product_attributes', $product_attributes);
    $pro_img_time = get_post_meta($productId, "img_upload", true);
    $pro_cron_time = get_option("update_product_cron_time");

    if($pro_img_time != $pro_cron_time) {
        if (!empty($model['ImageUrl'])) {
            $image_meta_url = '_knawatfibu_url';
            update_post_meta($productId, $image_meta_url, $model['ImageUrl']);
        } else {
            $attachId = 'http://go.dev55.com.au/wp-content/uploads/2022/08/placeholder-gourmet-organics-herbs-spices-foods.jpg';
            $image_meta_url = '_knawatfibu_url';
            update_post_meta($productId, $image_meta_url, $attachId);
        }
    }
    $product->set_default_attributes(array());
    $product->save();
    if (isset($model['Weight'])) {
        if (isset($model['Weight'])) {
            $product->set_weight($model['Weight']); // weight (reseting)
        }
    }

}
