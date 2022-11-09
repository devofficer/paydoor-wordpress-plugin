<?php
/**
 * Plugin Name: WordPress All-In-One Payments - Paydoor
 * Plugin URI: https://github.com/devofficer/paydoor-wordpress-plugin
 * Description: All-in-one payments WP plugin including Bitcoin, IBan, Ethereum Coins, Credit card and Paypal
 * Version: 3.5.6
 * Author: Paydoor
 * License: MIT
 * Text Domain: paydoor-aio-payments
 * Domain Path: /languages/
 * WC tested up to: 6.8.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
require_once ABSPATH . 'wp-admin/install-helper.php';

/**
 * Initialize hooks needed for the payment gateway
 */
function paydoor_woocommerce_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'php' . DIRECTORY_SEPARATOR . 'WC_Gateway_Paydoor.php';
    include_once plugin_dir_path(__FILE__) . 'php' . DIRECTORY_SEPARATOR . 'Paydoor.php';
    
    add_action('admin_menu', 'add_page');
    add_action('init', 'load_plugin_translations');
    add_action('woocommerce_order_details_after_order_table', 'nolo_custom_field_display_cust_order_meta', 10, 1);
    add_action('woocommerce_email_customer_details', 'nolo_bnomics_woocommerce_email_customer_details', 10, 1);
    add_action('admin_enqueue_scripts', 'paydoor_load_admin_scripts' );
    add_action('restrict_manage_posts', 'filter_orders' , 20 );
    add_filter('request', 'filter_orders_by_address_or_txid' ); 
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_paydoor_gateway');
    add_filter('clean_url', 'bnomics_async_scripts', 11, 1 );
    /**
     * Add Styles to Paydoor Admin Page
     **/
    function paydoor_load_admin_scripts($hook){ 
        if ( $hook === 'settings_page_paydoor_options') {        
            wp_enqueue_style('bnomics-admin-style', plugin_dir_url(__FILE__) . "css/paydoor_options.css", '', get_plugin_data( __FILE__ )['Version']);
        }
    }
    /**
     * Adding new filter to WooCommerce orders
     **/
    function filter_orders() {
		global $typenow;
		if ( 'shop_order' === $typenow ) {
            $filter_by = isset($_GET['filter_by']) ? sanitize_text_field(wp_unslash($_GET['filter_by'])) : "";
			?>
			<input size='26' value="<?php echo($filter_by ); ?>" type='name' placeholder='Filter by crypto address/txid' name='filter_by'>
			<?php
		}
	}
	function filter_orders_by_address_or_txid( $vars ) {
		global $typenow;
		if ( 'shop_order' === $typenow && !empty( $_GET['filter_by'])) {
			$vars['meta_value'] = wc_clean( sanitize_text_field(wp_unslash($_GET['filter_by'])) );
		}
		return $vars;
	}
    /**
     * Add this Gateway to WooCommerce
     **/
    function woocommerce_add_paydoor_gateway($methods)
    {
        $methods[] = 'WC_Gateway_Paydoor';
        return $methods;
    }

    function load_plugin_translations()
    {
        load_plugin_textdomain('paydoor-bitcoin-payments', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    // Add entry in the settings menu
    function add_page()
    {
        $paydoor = new Paydoor;

        $nonce = isset($_REQUEST['_wpnonce']) ? wp_verify_nonce( sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'update-options' ) : "";
        if (isset($_POST['generateSecret']) && $nonce)
        {
            generate_secret(true);
        }

        $api_key = $paydoor->get_api_key();        
        // get_api_key() will return api key or temp api key
        // if both are null, generate new paydoor guest account with temporary wallet
        // temp wallet will be used with temp api key
        if (!$api_key)
        {
            generate_secret();
            $callback_url = get_callback_url();
            $response = $paydoor->get_temp_api_key($callback_url);
            if ($response->response_code != 200)
            {
                $message = __('Error while generating temporary APIKey: '. isset($response->message) ? $response->message : '', 'paydoor-bitcoin-payments');
                display_admin_message($message, 'error');
            }
            else
            {
                update_option("paydoor_temp_api_key", isset($response->apikey) ? $response->apikey : '');
            }
        }

        add_options_page(
            'Paydoor', 'Paydoor', 'manage_options',
            'paydoor_options', 'show_options'
        );
    }

    function display_admin_message($msg, $type)
    {
        add_settings_error('option_notice', 'option_notice', $msg, $type);
    }

    function get_started_message($domain = '', $label_class = 'bnomics-options-intendation', $message = 'To configure')
    {
        echo 
        "<label class=$label_class>".
            __("$message, click <b> Get Started for Free </b> on ", 'paydoor-bitcoin-payments').
            '<a href="https://'.$domain.'paydoor.co/merchants" target="_blank">'.
                __('https://'.$domain.'paydoor.co/merchants', 'paydoor-bitcoin-payments').
            '</a>
        </label>';
    }

    function success_message()
    {
        echo '<td colspan="2"class="notice notice-success bnomics-test-setup-message">'.__("Success", 'paydoor-bitcoin-payments').'</td>';
    }

    function error_message($error)
    {
        echo 
        '<td colspan="2" class="notice notice-error bnomics-test-setup-message">'.$error.'.<br/>'.
            __("Please consult ", 'paydoor-bitcoin-payments').
            '<a href="http://help.paydoor.co/support/solutions/articles/33000215104-unable-to-generate-new-address" target="_blank">'.
            __("this troubleshooting article", 'paydoor-bitcoin-payments').'</a>.
        </td>';
    }

    function generate_secret($force_generate = false)
    {
        $callback_secret = get_option("paydoor_callback_secret");
        if (!$callback_secret || $force_generate) {
            $callback_secret = sha1(openssl_random_pseudo_bytes(20));
            update_option("paydoor_callback_secret", $callback_secret);
        }
    }

    function get_callback_url()
    {
        $callback_secret = get_option('paydoor_callback_secret');
        $callback_url = WC()->api_request_url('WC_Gateway_Paydoor');
        $callback_url = add_query_arg('secret', $callback_secret, $callback_url);
        return $callback_url;
    }

    function show_options()
    {
        if( isset( $_GET[ 'tab' ] ) ) {
            $active_tab = sanitize_key($_GET[ 'tab' ]);
        } else {
            $active_tab = 'settings';
        }
        $settings_updated = isset($_GET['settings-updated']) ? wp_validate_boolean(sanitize_text_field(wp_unslash($_GET['settings-updated']))) : "";
        if ($active_tab == "currencies" && $settings_updated == 'true')
        {
            $paydoor = new Paydoor;
            $setup_errors = $paydoor->testSetup();
            $btc_error = isset($setup_errors['btc']) ? $setup_errors['btc'] : 'false';
            $bch_error = isset($setup_errors['bch']) ? $setup_errors['bch'] : 'false';
            $withdraw_requested = $paydoor->make_withdraw();
        }
        ?>
        <script type="text/javascript">
            function gen_secret() {
                document.generateSecretForm.submit();
            }
            function check_form(tab) {
                const urlParams = new URLSearchParams(window.location.href);
                const currentTab = urlParams.get('tab') ?? 'settings';
                if (currentTab == tab){
                    return;
                }
                if (document.getElementById('paydoor_form_updated').value == 'true' || document.getElementById('paydoor_api_updated').value == 'true'){
                    if(validatePaydoorForm()){
                        save_form_then_redirect(tab);
                    }
                } else {
                    window.location.href = "options-general.php?page=paydoor_options&tab="+tab;
                }
            }
            function save_form_then_redirect(tab) {
                const xhr = new XMLHttpRequest();
                xhr.open("POST", "options.php"); 
                xhr.onload = function(event){ 
                    window.location.href = "options-general.php?page=paydoor_options&tab="+tab;
                }; 
                const formData = new FormData(document.myform); 
                xhr.send(formData);
                document.getElementById('myform').innerHTML = "Saving Settings...";
            }
            function value_changed() {
                document.getElementById('paydoor_api_updated').value = 'true';
                add_asterisk("settings");
            }
            function add_asterisk(tab) {
                document.getElementById('paydoor_form_updated').value = 'true';
                document.getElementById(tab+'_nav_bar').style.background = "#e6d9cb";
                document.getElementById(tab+'_nav_bar').textContent = tab.charAt(0).toUpperCase() + tab.slice(1)+"*";
            }
            function validatePaydoorForm() {
                if(document.getElementById("paydoor_api_key")){
                    newApiKey = document.getElementById("paydoor_api_key").value;
                    apiKeyChanged = newApiKey != "<?php echo get_option("paydoor_api_key")?>";
                    if (apiKeyChanged && newApiKey.length != 43) {
                        alert("ERROR: Invalid APIKey");
                        return false
                    }
                }
                return true;
            }
            function show_advanced() {
                document.getElementById("advanced_title").style.display = 'none';
                document.getElementById("advanced_window").style.display = 'block';
            }
            function show_basic() {
                document.getElementById("advanced_title").style.display = 'block';
                document.getElementById("advanced_window").style.display = 'none';
            }
        </script>
        <div class="wrap">
            <h1><?php echo __('Paydoor', 'paydoor-bitcoin-payments')?></h1>
            <?php 
                if (isset($withdraw_requested)):?>
                <div class="bnomics-width-withdraw">
                    <td colspan='2' class="bnomics-options-no-padding bnomics-width">
                        <p class='notice notice-<?php echo $withdraw_requested[1]?>'>
                            <?php echo $withdraw_requested[0].'.' ?> 
                        </p>
                    </td>
                </div>
            <?php endif; ?>
            <form method="post" name="myform" id="myform" onsubmit="return validatePaydoorForm()" action="options.php">
                <h2 class="nav-tab-wrapper">
                    <a onclick="check_form('settings')" id='settings_nav_bar'  class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>"><?php echo __('Settings', 'paydoor-bitcoin-payments')?></a>
                    <a onclick="check_form('currencies')" id='currencies_nav_bar' class="nav-tab <?php echo $active_tab == 'currencies' ? 'nav-tab-active' : ''; ?>"><?php echo __('Currencies', 'paydoor-bitcoin-payments')?></a>
                </h2>
                <input type="hidden" name="paydoor_form_updated" id="paydoor_form_updated" value="false">
                <input type="hidden" name="paydoor_api_updated" id="paydoor_api_updated" value="false">
                <?php wp_nonce_field('update-options');
                switch ( $active_tab ){
                case 'settings' :?>
                <div class="bnomics-width">
                    <h4><?php echo __('API Key', 'paydoor-bitcoin-payments')?></h4>
                    <input class="bnomics-options-input" onchange="value_changed()" size="130" type="text" id="paydoor_api_key" name="paydoor_api_key" value="<?php echo get_option('paydoor_api_key'); ?>" />
                    <?php get_started_message('', '', 'To get your API Key');?>
                    <h4><?php echo __('Callback URL', 'paydoor-bitcoin-payments')?>
                        <a href="javascript:gen_secret()" id="generate-callback" class="bnomics-options-callback-icon" title="Generate New Callback URL">&#xf463;</a>
                    </h4>
                    <input class="bnomics-options-input" size="130" type="text" value="<?php echo get_callback_url();?>" disabled/>
                    <p id="advanced_title" class="bnomics-options-bold"><a href="javascript:show_advanced()"><?php echo __('Advanced Settings', 'paydoor-bitcoin-payments')?> &#9660;</a></p>
                    <div id="advanced_window" style="display:none">
                        <p class="bnomics-options-bold"><a href="javascript:show_basic()"><?php echo __('Advanced Settings', 'paydoor-bitcoin-payments')?> &#9650;</a></p>
                        <table class="form-table">
                            <tr valign="top"><th scope="row"><?php echo __('Time period of countdown timer on payment page (in minutes)', 'paydoor-bitcoin-payments')?></th>
                                <td>
                                    <select onchange="add_asterisk('settings')" name="paydoor_timeperiod" />
                                        <option value="10" <?php selected(get_option('paydoor_timeperiod'), 10); ?>>10</option>
                                        <option value="15" <?php selected(get_option('paydoor_timeperiod'), 15); ?>>15</option>
                                        <option value="20" <?php selected(get_option('paydoor_timeperiod'), 20); ?>>20</option>
                                        <option value="25" <?php selected(get_option('paydoor_timeperiod'), 25); ?>>25</option>
                                        <option value="30" <?php selected(get_option('paydoor_timeperiod'), 30); ?>>30</option>
                                    </select>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php echo __('Extra Currency Rate Margin % (Increase live fiat to BTC rate by small percent)', 'paydoor-bitcoin-payments')?></th>
                                <td><input onchange="add_asterisk('settings')" type="number" min="0" max="20" step="0.01" name="paydoor_margin" value="<?php echo esc_attr( get_option('paydoor_margin', 0) ); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php echo __('Underpayment Slack % (Allow payments that are off by a small percentage)', 'paydoor-bitcoin-payments')?></th>
                                <td><input onchange="add_asterisk('settings')" type="number" min="0" max="20" step="0.01" name="paydoor_underpayment_slack" value="<?php echo esc_attr( get_option('paydoor_underpayment_slack', 0) ); ?>" /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php echo __('Display Payment Page in Lite Mode (Enable this if you are having problems in rendering checkout page)', 'paydoor-bitcoin-payments')?></th>
                                <td><input onchange="add_asterisk('settings')" type="checkbox" name="paydoor_lite" value="1" <?php checked("1", get_option('paydoor_lite')); ?> /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php echo __('No Javascript checkout page (Enable this if you have majority customer that use tor like browser that block Javascript)', 'paydoor-bitcoin-payments')?></th>
                                <td><input onchange="add_asterisk('settings')" type="checkbox" name="paydoor_nojs" value="1" <?php checked("1", get_option('paydoor_nojs')); ?> /></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php echo __('Network Confirmations required for payment to complete)', 'paydoor-bitcoin-payments')?></th>
                                <td><select onchange="add_asterisk('settings')" name="paydoor_network_confirmation">
                                        <option value="2" <?php selected(get_option('paydoor_network_confirmation'), 2); ?>><?php echo __('2 (Recommended)', 'paydoor-bitcoin-payments')?></option>
                                        <option value="1" <?php selected(get_option('paydoor_network_confirmation'), 1); ?>>1</option>
                                        <option value="0" <?php selected(get_option('paydoor_network_confirmation'), 0); ?>>0</option>
                                    </select></td>
                            </tr>
                        </table>
                    </div>
                    <p class="submit">
                        <input type="submit" class="button-primary" value="<?php echo __("Save", 'paydoor-bitcoin-payments')?>"/>
                        <input type="hidden" name="action" value="update" />
                        <input type="hidden" name="page_options" value="paydoor_api_key,paydoor_timeperiod,paydoor_margin,paydoor_gen_callback,paydoor_api_updated,paydoor_underpayment_slack,paydoor_lite,paydoor_nojs,paydoor_network_confirmation" />
                    </p>
                </form>
                <form method="POST" name="generateSecretForm">
                    <p class="submit">
                        <?php wp_nonce_field('update-options');?>
                        <input type="hidden" name="generateSecret" value="true">
                    </p>
                </form>
                </div>
                    <?php
                    break;
                case 'currencies' :?>
                    <table width="100%" cellspacing="0" cellpadding="0" class="form-table bnomics-options-intendation bnomics-width">
                        <h2>
                            <input onchange="add_asterisk('currencies')" type="checkbox" name="paydoor_btc" value="1"<?php checked("1", get_option('paydoor_btc', true)); ?>" />
                            <?php echo __('Bitcoin (BTC)', 'paydoor-bitcoin-payments')?>
                        </h2>
                        <?php 
                        get_started_message();
                        $btc_enabled = get_option("paydoor_btc");
                        if ($btc_enabled || get_option("paydoor_btc") === false):  
                            $total_received = get_option('paydoor_temp_withdraw_amount') / 1.0e8;
                            $api_key = get_option("paydoor_api_key");
                            $temp_api_key = get_option("paydoor_temp_api_key");
                            if ($temp_api_key): ?>
                                <th class="paydoor-narrow-th" scope="row"><b><?php echo __('Temporary Destination', 'paydoor-bitcoin-payments')?></b></th>
                                <td colspan="2" class="bnomics-options-no-padding">
                                    <label><b><?php echo __("Paydoor Wallet (Balance: $total_received BTC)", 'paydoor-bitcoin-payments')?></b></label>
                                    <label><?php echo __("Our temporary wallet receives your payments until your configure your own wallet. Withdraw to your wallet is triggered automatically when configuration is done", 'paydoor-bitcoin-payments')?></label>
                                </td>
                            </tr>
                            <?php endif; 
                        endif; 
                        if (get_option('paydoor_btc') == '1' && isset($btc_error)):
                            if ($btc_error):
                                error_message($btc_error);
                            else:
                                success_message();
                            endif;
                        endif; ?>
                    </table>
                    <table class="form-table bnomics-options-intendation bnomics-width">
                        <h2>
                            <input onchange="add_asterisk('currencies')" type="checkbox" name="paydoor_bch" value="1"<?php checked("1", get_option('paydoor_bch')); ?>" />
                            <?php echo __("Bitcoin Cash (BCH)", 'paydoor-bitcoin-payments')?>
                        </h2>
                        <?php 
                        get_started_message('bch.');
                        $bch_enabled = get_option("paydoor_bch");
                        if ($bch_enabled == '1' && isset($bch_error)):
                            if ($bch_error):
                                error_message($bch_error);
                            else:
                                success_message();
                            endif; 
                        endif; ?>
                    </table>
                    <div class="bnomics-options-small-margin-top">
                        <input type="submit" class="button-primary" value="<?php echo __("Test Setup", 'paydoor-bitcoin-payments')?>" />
                        <input type="hidden" name="page_options" value="paydoor_bch, paydoor_btc" />
                        <input type="hidden" name="action" value="update" />
                    </div>
                    </form>
                    <?php
                    break;
                }
            ?>
        </div>
    <?php
    }
    function bnomics_display_tx_info($order, $email=false)
    {
        $paydoor = new Paydoor();
        $active_cryptos = $paydoor->getActiveCurrencies();
        foreach ($active_cryptos as $crypto) {
            $txid = get_post_meta($order->get_id(), 'paydoor_'.$crypto['code'].'_txid', true);
            $address = get_post_meta($order->get_id(), $crypto['code'].'_address', true);
            if ($txid && $address) {
                if ($crypto['code'] == 'btc') {
                    $base_url = Paydoor::BASE_URL;
                }else{
                    $base_url = Paydoor::BCH_BASE_URL;
                }
                echo '<b>'.__('Payment Details', 'paydoor-bitcoin-payments').'</b><p><strong>'.__('Transaction', 'paydoor-bitcoin-payments').':</strong>  <a href =\''. $base_url ."/api/tx?txid=$txid&addr=$address'>".substr($txid, 0, 10). '</a></p>';
                if (!$email) {
                   echo '<p>'.__('Your order will be processed on confirmation of above transaction by the bitcoin network.', 'paydoor-bitcoin-payments').'</p>';
                } 
            }
        }      
    }
    function nolo_custom_field_display_cust_order_meta($order)
    {
        bnomics_display_tx_info($order);
    }
    function nolo_bnomics_woocommerce_email_customer_details($order)
    {
        bnomics_display_tx_info($order, true);
    }

    function bnomics_enqueue_stylesheets(){
      wp_enqueue_style('bnomics-style', plugin_dir_url(__FILE__) . "css/order.css", '', get_plugin_data( __FILE__ )['Version']);
    }

    function bnomics_enqueue_scripts(){
        wp_enqueue_script( 'reconnecting-websocket', plugins_url('js/vendors/reconnecting-websocket.min.js#deferload', __FILE__), array(), get_plugin_data( __FILE__ )['Version'] );
        wp_enqueue_script( 'qrious', plugins_url('js/vendors/qrious.min.js#deferload', __FILE__), array(), get_plugin_data( __FILE__ )['Version'] );
        wp_enqueue_script( 'bnomics-checkout', plugins_url('js/checkout.js#deferload', __FILE__), array('reconnecting-websocket', 'qrious'), get_plugin_data( __FILE__ )['Version'] );
    }

    // Async load
    function bnomics_async_scripts($url)
    {
        if ( strpos( $url, '#deferload') === false )
            return $url;
        else if ( is_admin() )
            return str_replace( '#deferload', '', $url );
        else
        return str_replace( '#deferload', '', $url )."' defer='defer"; 
    }
}

// After all plugins have been loaded, initialize our payment gateway plugin
add_action('plugins_loaded', 'paydoor_woocommerce_init', 0);

register_activation_hook( __FILE__, 'paydoor_activation_hook' );
add_action('admin_notices', 'paydoor_plugin_activation');

global $paydoor_db_version;
$paydoor_db_version = '1.1';

function paydoor_create_table() {
    // Create paydoor_orders table
    // https://codex.wordpress.org/Creating_Tables_with_Plugins
    global $wpdb;
    global $paydoor_db_version;

    $table_name = $wpdb->prefix . 'paydoor_orders';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        order_id int NOT NULL,
        status int NOT NULL,
        crypto varchar(3) NOT NULL,
        address varchar(191) NOT NULL,
        satoshi int,
        currency varchar(3),
        value longtext,
        txid text,
        PRIMARY KEY  (address),
        KEY orderkey (order_id,crypto)
    ) $charset_collate;";
    dbDelta( $sql );

    update_option( 'paydoor_db_version', $paydoor_db_version );
}

function paydoor_activation_hook() {
    if(!is_plugin_active('woocommerce/woocommerce.php'))
    {
        trigger_error(__( 'Wordpress Bitcoin Payments - Paydoor requires WooCommerce plugin to be installed and active.', 'paydoor-bitcoin-payments' ).'<br>', E_USER_ERROR);
    }

    set_transient( 'paydoor_activation_hook_transient', true, 3);
}

// Since WP 3.1 the activation function registered with register_activation_hook() is not called when a plugin is updated.
function paydoor_update_db_check() {
    global $wpdb;
    global $paydoor_db_version;

    $installed_ver = get_site_option( 'paydoor_db_version' );
    if ( $installed_ver != $paydoor_db_version ) {
        $table_name = $wpdb->prefix . 'paydoor_orders';
        if ($installed_ver < 1.1) {
            maybe_drop_column($table_name, "time_remaining", "ALTER TABLE $table_name DROP COLUMN time_remaining");
            maybe_drop_column($table_name, "timestamp", "ALTER TABLE $table_name DROP COLUMN timestamp");
        }
        paydoor_create_table();
    }
}

add_action( 'plugins_loaded', 'paydoor_update_db_check' );
register_activation_hook( __FILE__, 'paydoor_create_table' );

//Show message when plugin is activated
function paydoor_plugin_activation() {
  if(!is_plugin_active('woocommerce/woocommerce.php'))
  {
      $html = '<div class="error">';
      $html .= '<p>';
      $html .= __( 'Wordpress Bitcoin Payments - Paydoor failed to load. Please activate WooCommerce plugin.', 'paydoor-bitcoin-payments' );
      $html .= '</p>';
      $html .= '</div>';
      echo $html;
  }
  if( get_transient( 'paydoor_activation_hook_transient' ) ){

    $html = '<div class="updated">';
    $html .= '<p>';
    $html .= __( 'Congrats, you are now accepting BTC payments! You can configure Paydoor <a href="options-general.php?page=paydoor_options">on this page</a>.', 'paydoor-bitcoin-payments' );
    $html .= '</p>';
    $html .= '</div>';

    echo $html;        
    delete_transient( 'fx-admin-notice-example' );
  }
}

// On uninstallation, clear every option the plugin has set
register_uninstall_hook( __FILE__, 'paydoor_uninstall_hook' );
function paydoor_uninstall_hook() {
    delete_option('paydoor_callback_secret');
    delete_option('paydoor_api_key');
    delete_option('paydoor_temp_api_key');
    delete_option('paydoor_temp_withdraw_amount');
    delete_option('paydoor_margin');
    delete_option('paydoor_timeperiod');
    delete_option('paydoor_api_updated');
    delete_option('paydoor_bch');
    delete_option('paydoor_btc');
    delete_option('paydoor_underpayment_slack');
    delete_option('paydoor_lite');
    delete_option('paydoor_nojs');
    delete_option('paydoor_network_confirmation');

    global $wpdb;
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS ".$wpdb->prefix."paydoor_orders"));
    delete_option("paydoor_db_version");
}


function paydoor_plugin_add_settings_link( $links ) {
    $settings_link = '<a href="options-general.php?page=paydoor_options">' . __( 'Settings' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin", 'paydoor_plugin_add_settings_link' );
