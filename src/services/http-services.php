<?php

/**
 * Outbound GET calls to UNleashed API
 */


defined('ABSPATH') || exit;



function get_remote_unleashed_url($endpoint, $params = null)
{

    $baseUrl = 'https://api.unleashedsoftware.com/';
    $applicationID = get_option('unleashed_api_id');
    $applicationKey = get_option('unleashed_api_key');
    $defaultTimeOutSecs = 30;
    $queryParams = $params ? http_build_query($params) : "";
    $fullUrl = $baseUrl . $endpoint . '?' . $queryParams;

    $response = wp_remote_get(
        $fullUrl,
        array(
            'timeout' => $defaultTimeOutSecs,
            'headers' => array(
                'api-auth-id' => $applicationID,
                'api-auth-signature' => getSignature($queryParams, $applicationKey),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            )
        )
    );
    return $response;
}
function post_remote_unleashed_url($endpoint, $data = null, $params = null)
{

    $baseUrl = 'https://api.unleashedsoftware.com/';
    $applicationID = get_option('unleashed_api_id');
    $applicationKey = get_option('unleashed_api_key');
    $defaultTimeOutSecs = 30;
    $queryParams = $params ? http_build_query($params) : "";
    $fullUrl = $baseUrl . $endpoint . '?' . $queryParams;

    $response = wp_remote_post(
        $fullUrl,
        array(
            'timeout' => $defaultTimeOutSecs,
            'headers' => array(
                'api-auth-id' => $applicationID,
                'api-auth-signature' => getSignature($queryParams, $applicationKey),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => $data
        ),
    );
    return $response;
}
function getSignature($request, $key)
{
    return base64_encode(hash_hmac('sha256', $request, $key, true));
}
