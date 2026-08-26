<?php
namespace App\Libraries;
use Exception;
class Shopifyapi {
    public $shop_domain;
    public $token;
    public $api_key;
    public $secret;
    private $last_response_headers = null;

    public function __construct($config = array()) {
        if (isset($config['shop_domain'])) {
            $this->name = "Shopifyapi";
            $this->shop_domain = $config['shop_domain'];
            $this->token = $config['token'];
            $this->api_key = $config['api_key'];
            $this->secret = $config['secret'];
        }
    }

    // Get the URL required to request authorization
    public function getAuthorizeUrl($scope, $redirect_url = '') {
        $url = "https://{$this->shop_domain}/admin/oauth/authorize?client_id={$this->api_key}&scope=" . urlencode($scope);
        if ($redirect_url != '') {
            $url .= "&redirect_uri=" . urlencode($redirect_url);
        }
        return $url;
    }

    // Once the User has authorized the app, call this with the code to get the access token
    public function getAccessToken($code) {
        // POST to  POST https://SHOP_NAME.myshopify.com/admin/oauth/access_token
        $url = "https://{$this->shop_domain}/admin/oauth/access_token";
        $payload = "client_id={$this->api_key}&client_secret={$this->secret}&code=$code&expiring=1";
        $response = $this->curlHttpApiRequest('POST', $url, '', $payload, array());
        $response = json_decode($response, true);
        return $response;
    }

    public function callsMade() {
        return $this->shopApiCallLimitParam(0);
    }

    public function callLimit() {
        return $this->shopApiCallLimitParam(1);
    }

    public function callsLeft() {
        return $this->callLimit() - $this->callsMade();
    }

    public function call($method, $path, $params = array(), $token = '') {
        $baseurl = "https://{$this->shop_domain}/";
        $url = $baseurl . ltrim($path, '/');
        $query = in_array($method, array('GET', 'DELETE')) ? $params : array();
        $payload = in_array($method, array('POST', 'PUT')) ? stripslashes(json_encode($params)) : array();

        $request_headers = in_array($method, array('POST', 'PUT')) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();
        // add auth headers
        if ( $token != '') {
            $this->token = $token;
        }
        $request_headers[] = 'X-Shopify-Access-Token: ' . $this->token;
        if ($method == "POST") {
            //print_r($payload);
            //print_r($request_headers);exit;
        }

        $response = $this->curlHttpApiRequest($method, $url, $query, $payload, $request_headers);
        $response = json_decode($response, true);

//        print_r($response['errors']);exit;
//        if (isset($response['errors']) or ( $this->last_response_headers['http_status_code'] >= 400)) {
        if (isset($response['errors']) or ( $this->last_response_headers['http_status_message'] >= 400)) {
//            echo "<pre>";print_r($response);echo "</pre>";exit;
            throw new ShopifyApiException($method, $path, $params, $this->last_response_headers, $response);
        }
        return (is_array($response) and ( count($response) > 0)) ? array_shift($response) : $response;
    }

    private function curlHttpApiRequest($method, $url, $query = '', $payload = '', $request_headers = array()) {
        $url = $this->curlAppendQuery($url, $query);
        $ch = curl_init($url);
        $this->curlSetopts($ch, $method, $payload, $request_headers);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($errno){
            throw new ShopifyCurlException($error, $errno);
        }



        list($message_headers, $message_body) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);


        $message_body = json_decode($message_body, true);


        $page_link = $this->get_headers_response($response);
        if ( isset($page_link['Link']) && $page_link['Link'] != '' ) {
            $message_body['link'] = $page_link['Link'];
        }


        $this->last_response_headers = $this->curlParseHeaders($message_headers);

        return json_encode( $message_body );
    }

    private function curlHttpApiRequestForDiscountCode($method, $url, $query = '', $payload = '', $request_headers = array()) {
        $url = $this->curlAppendQuery($url, $query);
        $ch = curl_init($url);

        $this->curlSetopts($ch, $method, $payload, $request_headers);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        if ($errno){
            throw new ShopifyCurlException($error, $errno);
        }
        if ( $info['http_code'] != 200 ) {
            return;
        }


        list($message_headers, $message_body,$message_body2) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 3);

        $message_body = json_decode($message_body, true);

        if ( $message_body == '' && $message_body2 != '' ) {
            $message_body = json_decode($message_body2, true);
        }

        $page_link = $this->get_headers_response($response);
        if ( isset($page_link['Link']) && $page_link['Link'] != '' ) {
            $message_body['link'] = $page_link['Link'];
        }

        $this->last_response_headers = $this->curlParseHeaders($message_headers);

        return json_encode( $message_body );
    }

    function get_headers_response($response) {

        $headers = array();

        $header_text = substr($response, 0, strpos($response, "\r\n\r\n"));

        foreach (explode("\r\n", $header_text) as $i => $line)
            if ($i === 0)
                $headers['http_code'] = $line;
            else {
                list ($key, $value) = explode(': ', $line);

                $headers[$key] = $value;
            }
        return $headers;
    }
    private function curlAppendQuery($url, $query) {
        if (empty($query))
            return $url;
        if (is_array($query))
            return "$url?" . http_build_query($query);
        else
            return "$url?$query";
    }

    private function curlSetopts($ch, $method, $payload, $request_headers) {

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'HAC');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 500);
        curl_setopt($ch, CURLOPT_TIMEOUT, 500);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);


        if (!empty($request_headers))
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        if ($method != 'GET' && !empty($payload)) {
            if (is_array($payload))
                $payload = http_build_query($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

    }

    private function curlParseHeaders($message_headers) {
        $header_lines = preg_split("/\r\n|\n|\r/", $message_headers);
        $array = explode(' ', trim(array_shift($header_lines)), 3);
        $headers = array();
        list($headers['http_status_code'], $headers['http_status_message']) = $array;
        foreach ($header_lines as $header_line) {
            list($name, $value) = explode(':', $header_line, 2);
            $name = strtolower($name);
            $headers[$name] = trim($value);
        }

        return $headers;
    }

    private function shopApiCallLimitParam($index) {
        if ($this->last_response_headers == null) {
            throw new Exception('Cannot be called before an API call.');
        }
        $params = explode('/', $this->last_response_headers['http_x_shopify_shop_api_call_limit']);
        return (int) $params[$index];
    }

    public function directRequest($shop, $token, $method, $path, $params = '', $payload = '', $request_headers = array()) {
        ini_set('display_errors', 1);
        $baseurl = "https://$shop/";

        $url = $baseurl . ltrim($path, '/');

        $url = (!empty($params['next_link'])) ? $path : $baseurl . ltrim($path, '/');

        if (!empty($params['next_link']))
            unset($params['next_link']);


        $query = in_array($method, array('GET', 'DELETE')) ? $params : array();
        $payload = in_array($method, array('POST', 'PUT')) ? stripslashes(json_encode($params)) : array();


        $request_headers = in_array($method, array('POST', 'PUT')) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();

        // add auth headers
        $request_headers[] = 'X-Shopify-Access-Token: ' . $token;



        $response = $this->curlHttpApiRequest($method, $url, $query, $payload, $request_headers);
        $response = json_decode($response, true);
        return $response;
    }

    public function directDiscountRequest($shop, $token, $method, $path, $params = '', $payload = '', $request_headers = array()) {
        ini_set('display_errors', 1);
        $baseurl = "https://$shop/";

        $url = $baseurl . ltrim($path, '/');

        $url = (!empty($params['next_link'])) ? $path : $baseurl . ltrim($path, '/');

        if (!empty($params['next_link']))
            unset($params['next_link']);


        $query = in_array($method, array('GET', 'DELETE')) ? $params : array();
        $payload = in_array($method, array('POST', 'PUT')) ? stripslashes(json_encode($params)) : array();


        $request_headers = in_array($method, array('POST', 'PUT')) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();

        // add auth headers
        $request_headers[] = 'X-Shopify-Access-Token: ' . $token;

        $response = $this->curlHttpApiRequestForDiscountCode($method, $url, $query, $payload, $request_headers);
        $response = json_decode($response, true);
        return $response;
    }

    public function call_new_version($method, $path, $params = array()){

        $baseurl = "https://{$this->shop_domain}/";

        $url     = !empty($params['next_link']) ? $path : $baseurl . ltrim($path, '/');

        if(!empty($params['next_link'])) unset($params['next_link']);

        $query   = in_array($method, array('GET', 'DELETE')) ? $params : array();
        $payload = in_array($method, array('POST', 'PUT')) ?  stripslashes(json_encode($params, JSON_UNESCAPED_UNICODE)) : array();

        $request_headers = in_array( $method, array('POST', 'PUT') ) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();

        // add auth headers
        $request_headers[] = 'X-Shopify-Access-Token: ' . $this->token;

        $response = $this->curlHttpApiRequestNewVersion($method, $url, $query, $payload, $request_headers);
        $response['data'] = json_decode($response['data'], true);
        $response['data']['link'] = htmlentities($response['link'], ENT_QUOTES);



//        if (isset($response['errors']) or ( $this->last_response_headers['http_status_code'] >= 400)) {
        if (isset($response['errors']) or ( $this->last_response_headers['http_status_message'] >= 400)) {
            throw new ShopifyApiException($method, $path, $params, $this->last_response_headers, $response);
        }

        return (is_array($response) and ( count($response) > 0)) ? array_shift($response) : $response;
    }
}

class ShopifyCurlException extends Exception {

}

class ShopifyApiException extends Exception {

    protected $method;
    protected $path;
    protected $params;
    protected $response_headers;
    protected $response;

    function __construct($method, $path, $params, $response_headers, $response) {
        $this->method = $method;
        $this->path = $path;
        $this->params = $params;
        $this->response_headers = $response_headers;
        $this->response = $response;
        parent::__construct($response_headers['http_status_message'], $response_headers['http_status_message']);

    }

    function getMethod() {
        return $this->method;
    }

    function getPath() {
        return $this->path;
    }

    function getParams() {
        return $this->params;
    }

    function getResponseHeaders() {
        return $this->response_headers;
    }

    function getResponse() {
        return $this->response;
    }

}