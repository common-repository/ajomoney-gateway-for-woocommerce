<?php
/**
 * Plugin Name: AjoMoney Gateway for WooCommerce
 * Plugin URI: https://ajo.money/business
 * Description: WooCommerce buy now pay later and checkout gateway for AjoMoney
 * Version: 1.0.0
 * Author: AjoPay Financial Technology Limited
 * Author URI: https://ajo.money
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: ajomoney-woocommerce
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if(!in_array('woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option('active_plugins') ))) return;

add_action('plugins_loaded', 'ajomoney_payment_init', 11);


function  ajomoney_payment_init() {
    if( class_exists('WC_Payment_Gateway')) {
        class WC_Ajomoney_Pay_Gateway extends WC_Payment_Gateway {
            public function  __construct() {
                $this->id = 'ajomoney_payment';
                $this->icon = apply_filters( 'woocommerce_ajomoney_icon', plugins_url('/assets/icon.png', __FILE__) );
                $this->has_fields = false;
                $this->method_title = __( 'AjoMoney Payment', 'ajomoney-woocommerce' );
                $this->method_description = __( 'Buy now pay later and full payment checkout gateway for AjoMoney', 'ajomoney-woocommerce' );

                $this->title = $this->get_option('title');
                $this->enabled = $this->get_option('enabled');
                $this->description = $this->get_option('description');
                $this->instruction = $this->get_option('instruction');
                $this->apiKey = $this->get_option('apiKey');
                $this->testMode = $this->get_option('testMode');

                $this->init_form_fields();
                $this->init_settings();

                add_action( 'woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options') );

                add_action( 'woocommerce_thank_you_'.$this->id, array( $this, 'thank_you_page') );

                add_action( 'woocommerce_api_'.$this->id, array($this, 'ajm_chk_call'));

                add_action( 'admin_notices', array( $this, 'admin_notices' ) );

                // Check if the gateway can be used.
                if ( !$this->is_valid_for_use() ) {
                    $this->enabled = false;
                }
            }

            /**
             * Check if this gateway is enabled and available in the user's country.
             */
            public function is_valid_for_use() {

                if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_'.$this->id.'_supported_currencies', array( 'NGN') ) ) ) {

                    $this->msg = sprintf( __( 'AjoMoney does not support your store currency. Kindly set it to either NGN (&#8358) <a href="%s">here</a>', 'ajomoney-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=general' ) );

                    return false;

                }

                if (($this->testMode == 'no') && !$this->apiKey) {
                    # code...
                    $this->msg = __( 'A secret api key is required.', 'ajomoney-woocommerce' );

                    return false;
                }

                return true;

            }

            public function init_form_fields() {
                $this->form_fields = apply_filters( 'woo_ajomoney_pay_fields', array(
                    'enabled' => array(
                        'title' => __( 'Enabled/Disabled', 'ajomoney-woocommerce' ),
                        'type' => 'checkbox',
                        'label' => __( 'Enable or Disable AjoMoney Payment', 'ajomoney-woocommerce' ),
                        'default' => 'no'
                    ),

                    'testMode' => array(
                        'title' => __( 'Test Mode', 'ajomoney-woocommerce' ),
                        'type' => 'checkbox',
                        'label' => __( 'Enable API Test Mode', 'ajomoney-woocommerce' ),
                        'default' => 'yes',
                        'desc_tip' => true,
                        'description' => __( 'In test mode, checkout gateway will be a test environment. Uncheck this when you want to go live.', 'ajomoney-woocommerce' ),
                    ),
                    'title' => array(
                        'title' => __( 'Title', 'ajomoney-woocommerce' ),
                        'type' => 'text',
                        'label' => __( 'Add a new title for the AjoMoney Payment Gateway.', 'ajomoney-woocommerce' ),
                        'default' => __( 'Pay later or full - AjoMoney', 'ajomoney-woocommerce' ),
                        'desc_tip' => true,
                        'description' => __( 'AjoMoney Payments Gateway', 'ajomoney-woocommerce' ),
                    ),

                    'description' => array(
                        'title' => __( 'Description', 'ajomoney-woocommerce' ),
                        'type' => 'textarea',
                        'label' => __( 'Add a new description for the AjoMoney Payment Gateway.', 'ajomoney-woocommerce' ),
                        'default' => __( 'Buy now pay later or pay in full with AjoMoney. With buy now pay later, you pay 40% now and spread 60% over 6 weeks. Pay in full to increase pay later qualification next time.', 'ajomoney-woocommerce' ),
                        'desc_tip' => true,
                        'description' => __( 'AjoMoney Payments Gateway', 'ajomoney-woocommerce' ),
                    ),
                    'instruction' => array(
                        'title' => __( 'Instruction', 'ajomoney-woocommerce' ),
                        'type' => 'textarea',
                        'default' => __( '', 'ajomoney-woocommerce' ),
                        'desc_tip' => true,
                        'description' => __( 'Instruction is here', 'ajomoney-woocommerce' ),
                    ),
                    'apiKey' => array(
                        'title' => __( 'API Secret Key', 'ajomoney-woocommerce' ),
                        'type' => 'password',
                        'label' => __( 'Provide your business API secret key.', 'ajomoney-woocommerce' ),
                        'desc_tip' => true,
                        'description' => __( 'Login to your AjoMoney business account, go to API tab under settings page to get  your secret key.', 'ajomoney-woocommerce' ),
                    ),
                ) );
            }

            /**
             * Check if Paystack merchant details is filled.
             */
            public function admin_notices() {

                if ( 'no' == $this->enabled ) {
                    return;
                }

                // Check required fields.
                if ( !$this->testMode && !$this->apiKey ) {
                    /* translators: %s: admin url */
                    echo '<div class="error"><p>' . esc_html(sprintf( __( 'Please provide your AjoMoney secret key as you are no longer in test mode. Click <a href="%s">here</a> to be able to use the AjoMoney WooCommerce plugin.', 'ajomoney-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ajomoney-payment' ) )) . '</p></div>';
                    return;
                }

            }

            public function process_payment($order_id) {

                global $woocommerce;

                /**
                 * Get the order
                 */
                $order = new WC_Order($order_id);

                /**
                 * Get order data
                 */
                $orderData = wc_get_order($order_id);

                /**
                 * Get order items
                 */

                $items =$orderData->get_items();

                /**
                 * Initiate product data
                 */
                $products = [];
  
                foreach ( $items as $item_id => $item ) {
                
                    $product = $item->get_product();

                    $pr = [
                        "product_id" => $product->id,
                        "price" => (float) $product->price,
                        "quantity" => $item['qty'],
                        "title" => $product->name,
                        "image" => wp_get_attachment_url( $product->image_id )
                    ];

                    array_push($products, $pr);
                
                }

                $order->update_status('on-hold', __('Awaiting AjoMoney payment confirmation.', 'ajomoney-woocommerce' ));
                $theOrderData = $order->get_data();
                $data = array(
                    "product_details" => $products,
                    "subtotal" => $order->total,
                    "reference" => 'AJWPCHK' . $order_id . '_' . time(),
                    "redirect_url" => $this->url().'/wc-api/ajomoney_payment',
                    "currency" => get_woocommerce_currency(),
                    "metadata" => [
                        'order_id' => $order_id,
                        'shipping_fee' => $order->shipping_total,
                        "shipping" => $theOrderData['shipping'],
                        "billing" => $theOrderData['billing'],
                        "customer_user_agent" => $order->customer_user_agent
                    ]
                );
                
                $baseUrl = $this->testMode ==  'yes' ? 'https://ajomoney-merchant-api.herokuapp.com/api/v1/' : 'https://business-api.myajopay.com/api/v1/';

                $endpoint = $baseUrl.'store/generate/payment-link';

                $apiKey = $this->testMode == 'yes' ? 'eyJpdiI6IlBDMGpIQmhMUHB5ZkVRdE5QbElqOFE9PSIsInZhbHVlIjoiMXlWWERmK1BNRWRiRmdYcGt6VXVSdEZad1F2T1FHRDJSTkJXOENvNVRtRWNPWWVvMU9RcTdCdGh1S2VLZjd3bCtPZHRQcG1ZS1ZaVG8rWWV5eWEyYVVjUTVZMk8xbUFLRzNUSFZjeUVBVjZ4Ym1xYWpzRExtY0JyN3JzNUlFSlciLCJtYWMiOiI5YzE0OGFiZWM5ZjMwYTA0ZTMxODhjOGQwY2I2MGMzNTAxYmYzYmRkYWJlMWU3NzNmY2E1Y2FkZDViZTlhMDBmIiwidGFnIjoiIn0=' : $this->apiKey;
                
                $body = wp_json_encode( $data );
                
                $options = [
                    'body' => $body,
                    'method' => 'POST',
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer '.$apiKey
                    ],
                    'timeout'     => 60,
                    'redirection' => 5,
                    'blocking'    => true,
                    'httpversion' => '1.0',
                    'sslverify'   => false,
                    'data_format' => 'body',
                ];
                
                $response = wp_remote_post( $endpoint, $options );

                if ( is_wp_error( $response ) ) {
                    // $error_message = $response->get_error_message();
                    $order->update_status('cancelled', __('Unable to complete payment', 'ajomoney-woocommerce' ));
                    return  array(
                        'result' => 'error',
                        'redirect' => $this->get_return_url($order)
                    );
                } else {

                    $responseBody  = json_decode( wp_remote_retrieve_body( $response ) );
                    // wc_add_notice( 'This is a Success notice <pre>'.$this->testMode.json_encode($responseBody->data->url).'</pre>', 'success' );

                    try {
                        
                        if ($responseBody->status == 'success') {
                            # code...
                            
                            $redirectUrl = $responseBody->data->url;
                            // wc_add_notice( 'This is a Success notice <pre>'.$redirectUrl.'</pre>', 'success' );
                            return  array(
                                'result' => 'success',
                                'redirect' => $redirectUrl
                            );
                            // wp_redirect( $redirectUrl );
                            // exit;
                        }
                    } catch (\Throwable $th) {
                        //throw $th;
                    }
                }
            }

            public  function thank_you_page() {
                if($this->instructions) {
                    echo esc_html(wpautop( $this->instructions ));
                }
            }

            function ajm_chk_call() {
                // @ob_clean();
                if ( isset( $_REQUEST['ref'] ) ) {
                    $baseUrl = $this->testMode ==  'yes' ? 'https://ajomoney-merchant-api.herokuapp.com/api/v1/' : 'https://business-api.myajopay.com/api/v1/';

                    $endpoint = $baseUrl.'store/order/'.sanitize_text_field($_REQUEST['id']);

                    $apiKey = $this->testMode == 'yes' ? 'eyJpdiI6IlBDMGpIQmhMUHB5ZkVRdE5QbElqOFE9PSIsInZhbHVlIjoiMXlWWERmK1BNRWRiRmdYcGt6VXVSdEZad1F2T1FHRDJSTkJXOENvNVRtRWNPWWVvMU9RcTdCdGh1S2VLZjd3bCtPZHRQcG1ZS1ZaVG8rWWV5eWEyYVVjUTVZMk8xbUFLRzNUSFZjeUVBVjZ4Ym1xYWpzRExtY0JyN3JzNUlFSlciLCJtYWMiOiI5YzE0OGFiZWM5ZjMwYTA0ZTMxODhjOGQwY2I2MGMzNTAxYmYzYmRkYWJlMWU3NzNmY2E1Y2FkZDViZTlhMDBmIiwidGFnIjoiIn0=' : $this->apiKey;
                    
                    $options = [
                        'method' => 'GET',
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Bearer '.$apiKey
                        ],
                        'timeout'     => 60,
                        'redirection' => 5,
                        'blocking'    => true,
                        'httpversion' => '1.0',
                        'sslverify'   => false,
                    ];
                    
                    $response = wp_remote_post( $endpoint, $options );

                    if ( is_wp_error( $response ) ) {

                        $order->update_status('cancelled', __('Unable to validate payment on AjoMoney', 'ajomoney-woocommerce' ));
                        return  array(
                            'result' => 'error',
                            'redirect' => $this->get_return_url($order)
                        );
                    } else {

                        $responseBody  = json_decode( wp_remote_retrieve_body( $response ) );

                        try {
                            
                            if (($responseBody->status == 'success') && ($responseBody->data->status == 'pending')  ) {
                                # code...
                                
                                $order_details = explode( '_', sanitize_text_field($_REQUEST['ref']) );

                                $order_id = (int) str_replace('AJWPCHK', '', $order_details[0]);
            
                                $order = wc_get_order( $order_id );

                                $order->payment_complete( sanitize_text_field($_REQUEST['id']) );

                                /* translators: %s: transaction reference */
                                $order->add_order_note( sprintf( __( 'Payment via AjoMoney successful (Transaction Reference: %s)', 'ajomoney-woocommerce' ), sanitize_text_field($_REQUEST['id']) ) );
                                
                                $order->update_status( 'completed' );

                                wp_redirect($this->get_return_url($order));

                                exit;
                            }

                        } catch (\Throwable $th) {
                            //throw $th;
                        }
                    }
                }

                wp_redirect( wc_get_page_permalink( 'cart' ) );

		        exit;
            }

            function url(){
                return sprintf(
                    "%s://%s",
                    isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
                    sanitize_text_field($_SERVER['SERVER_NAME'])
                );
            }
        }
    }
}


add_filter( 'woocommerce_payment_gateways', 'add_to_ajomoney_payment_gateway');

function add_to_ajomoney_payment_gateway($gateways) {

    $gateways[] = 'WC_Ajomoney_Pay_Gateway';
    return $gateways;
}

