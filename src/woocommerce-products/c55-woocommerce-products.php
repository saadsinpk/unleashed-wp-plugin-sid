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
            if ($attr['Name'] === 'Name') {
                $parentVariation = strtolower($attr['Value']);
            } else {
                $attributes[strtolower($attr['Name'])] = [$attr['Value']];
            }
        }
    }
    $product = wc_get_product($productId);

    $post_data = array(
        'ID'         => $productId,
        'post_title'    => $parentVariation
        // 'post_content'  => $model['ProductDescription'],
        // 'post_excerpt'  => $model['ProductDescription']
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
    if (!empty($model['ImageUrl'])) {
        $image_meta_url = '_knawatfibu_url';
        update_post_meta($productId, $image_meta_url, $model['ImageUrl']);
    } else {
        $attachId = 'http://go.dev55.com.au/wp-content/uploads/2022/08/placeholder-gourmet-organics-herbs-spices-foods.jpg';
        $image_meta_url = '_knawatfibu_url';
        update_post_meta($productId, $image_meta_url, $attachId);
    }
    $product->save();
    if (isset($model['Weight'])) {
        if (isset($model['Weight'])) {
            $product->set_weight($model['Weight']); // weight (reseting)
            // echo "<br>";
            // echo $model['Weight'];
            // echo "<br>";
        }
    }


    // SET update data
    // $mappedUpdateModel = [
    //     'author'        => '', // optional
    //     'title'         => $parentVariation,
    //     'content'       => $item['ProductDescription'],
    //     'excerpt'       => $item['ProductDescription'],
    //     'regular_price' => '', // product regular price
    //     'sale_price'    => '', // product sale price (optional)
    //     'stock'         => '', // Set a minimal stock quantity
    //     'set_manage_stock' => false,
    //     'image_id'      => '', // optional
    //     'gallery_ids'   => array(), // optional
    //     'sku'           => $sku, // optional
    //     'tax_class'     => '', // optional
    //     'weight'        => '', // optional
    //     // For NEW attributes/values use NAMES (not slugs)
    //     'attributes'    => $attributes
    // ];

    // dd($product);
}
