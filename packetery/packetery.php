<?php
/**
* 2017 Zlab Solutions
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    Eugene Zubkov <magrabota@gmail.com>
*  @copyright 2017 Zlab Solutions
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

include_once(dirname(__file__).'/packetery.class.php');
include_once(dirname(__file__).'/packetery.api.php');

class Packetery extends CarrierModule
{
    protected $config_form = false;
    public $widget_type = 1;
    public function __construct()
    {
        $this->widget_type = Packeteryclass::getConfigValueByOption('WIDGET_TYPE');
        $this->name = 'packetery';
        $this->tab = 'shipping_logistics';
        $this->version = '2.0.0rc2';
        $this->author = 'ZLab Solutions';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;
        $this->limited_countries = array('cz', 'sk', 'pl', 'hu', 'de', 'ro', 'ua');
        parent::__construct();

        $this->displayName = $this->l('Packetery');
        $this->description = $this->l('Get your customers access to pick-up point in Packetery delivery network. 
            Export orders to Packetery system. Presatshop 1.7.0 or higher.');
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
        
        // This is only used in admin of modules, and we're accessing Packetery API here, so don't do that elsewhere.
        $errors = array();
        $this->configurationErrors($errors);
        foreach ($errors as $error) {
            $this->warning .= $error;
        }
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        $db = Db::getInstance();
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }
        Configuration::updateValue('PACKETERY_LIVE_MODE', false);

        // backup possible old order table
        if (count($db->executeS('SHOW TABLES LIKE "' . _DB_PREFIX_ . 'packetery_order"')) > 0) {
            $db->execute('RENAME TABLE `' . _DB_PREFIX_ . 'packetery_order` TO `'. _DB_PREFIX_ .'packetery_order_old`');
            $have_old_table = true;
        } else {
            $have_old_table = false;
        }
        
        include(dirname(__FILE__).'/sql/install.php');

        // copy data from old order table
        if ($have_old_table) {
            $fields = array();
            foreach ($db->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'packetery_order_old`') as $field) {
                $fields[] = $field['Field'];
            }
            $db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'packetery_order`(`' . implode('`, `', $fields) . '`)
                SELECT * FROM `' . _DB_PREFIX_ . 'packetery_order_old`'
            );
            $db->execute('DROP TABLE `' . _DB_PREFIX_ . 'packetery_order_old`');
        }

        return parent::install() &&
            $this->registerHook('actionOrderHistoryAddAfter') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('displayCarrierExtraContent') &&
            $this->registerHook('displayBeforeCarrier') &&
            $this->registerHook('displayHeader') &&
            $this->registerHook('displayFooter') &&
            $this->registerHook('actionCarrierUpdate') &&
            Packeteryclass::insertTab();
    }

    public function uninstall()
    {
        Packeteryclass::deleteTab();

        include(dirname(__FILE__).'/sql/uninstall.php');

        return parent::uninstall();
    }

    public function hookActionCarrierUpdate($params)
    {
        Packeteryclass::actionCarrierUpdate($params);
    }

    private static function transportMethod()
    {
        if (extension_loaded('curl')) {
            $have_curl = true;
        }
        if (ini_get('allow_url_fopen')) {
            $have_url_fopen = true;
        }
        // Disabled - more trouble than it's worth
        if ($have_curl) {
            return 'curl';
        }
        if ($have_url_fopen) {
            return 'fopen';
        }
        return false;
    }

    public function configurationErrors(&$error = null)
    {
        $error = array();
        $have_error = false;

        $fn = _PS_MODULE_DIR_ . "packetery/views/js/write-test.js";
        @touch($fn);
        if (!is_writable($fn)) {
            $error[] = $this->l(
                'The Packetery module folder must be writable for the branch selection to work properly.'
            );
            $have_error = true;
        }

        if (!self::transportMethod()) {
            $error[] = $this->l(
                'No way to access Packetery API is available on the web server:
                please allow CURL module or allow_url_fopen setting.'
            );
            $have_error = true;
        }

        $key = Configuration::get('PACKETERY_API_KEY');
        $test = "http://www.zasilkovna.cz/api/$key/test";
        if (!$key) {
            $error[] = $this->l('Packetery API key is not set.');
            $have_error = true;
        } elseif (!$error) {
            if ($this->fetch($test) != 1) {
                $error[] = $this->l('Cannot access Packetery API with specified key. Possibly the API key is wrong.');
                $have_error = true;
            } else {
                $data = Tools::jsonDecode(
                    $this->fetch("http://www.zasilkovna.cz/api/$key/version-check-prestashop?my=" . $this->version)
                );
                if (self::compareVersions($data->version, $this->version) > 0) {
                    $cookie = Context::getContext()->cookie;
                    $def_lang = (int)($cookie->id_lang ? $cookie->id_lang : Configuration::get('PS_LANG_DEFAULT'));
                    $def_lang_iso = Language::getIsoById($def_lang);
                    $error[] = $this->l('New version of Prestashop Packetery module is available.') . ' '
                        . $data->message->$def_lang_iso;
                }
            }
        }

        return $have_error;
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $labels_format = Packeteryclass::getConfigValueByOption('LABEL_FORMAT');
        $this->context->smarty->assign('labels_format', $labels_format);
        $this->context->smarty->assign('widget_type', $this->widget_type);

        $langs = Language::getLanguages();
        $this->context->smarty->assign('langs', $langs);

        $this->context->smarty->assign('module_dir', $this->_path);
        $id_employee = $this->context->employee->id;
        $settings = Packeteryclass::getConfig();
        if ($settings[3][1] == '') {
            $shop = new Shop(Context::getContext()->shop->id);
            Packeteryclass::updateSetting(3, $shop->domain);
            $settings[3][1] = $shop->domain;
        }
        $this->context->smarty->assign(array('ps_version'=> _PS_VERSION_));
        $this->context->smarty->assign(array('check_e'=> $id_employee));

        $this->context->smarty->assign(array('settings'=> $settings));
        $base_uri = __PS_BASE_URI__ == '/'?'':Tools::substr(__PS_BASE_URI__, 0, Tools::strlen(__PS_BASE_URI__) - 1);
        $this->context->smarty->assign('module_dir', $this->_path);
        $this->context->smarty->assign('baseuri', $base_uri);

        /*ORDERS*/
        $packetery_orders_array = Packeteryclass::getListOrders();
        $packetery_orders = $packetery_orders_array[0];
        $packetery_orders_pages = $packetery_orders_array[1];
        $this->context->smarty->assign('po_pages', $packetery_orders_pages);
        $this->context->smarty->assign(array(
            'packetery_orders' => Tools::jsonEncode(array(
                'columns' => array(
                    array('content' => $this->l('Ord.nr.'), 'key' => 'id_order', 'center' => true),
                    array('content' => $this->l('Customer'), 'key' => 'customer', 'center' => true),
                    array('content' => $this->l('Total Price'), 'key' => 'total', 'center' => true),
                    array('content' => $this->l('Order Date'), 'key' => 'date', 'center' => true),
                    array('content' => $this->l('Is COD'), 'bool' => true, 'key' => 'is_cod'),
                    array('content' => $this->l('Destination branch'), 'key' => 'name_branch', 'center' => true),
                    array('content' => $this->l('Address delivery'), 'key' => 'is_ad', 'bool' => true,'center' => true),
                    array('content' => $this->l('Exported'), 'key' => 'exported', 'bool' => true, 'center' => true),
                    array('content' => $this->l('Tracking number'), 'key' => 'tracking_number', 'center' => true)
                ),
                'rows' => $packetery_orders,
                'url_params' => array('configure' => $this->name),
                'identifier' => 'id_order',
            ))
        ));
        /*END ORDERS*/

        /*CARRIERS*/
        $ad_array = PacketeryApi::getAdBranchesList();
        $json_ad_array = json_encode($ad_array);
        $raw_ad_array = rawurlencode($json_ad_array);
        $this->context->smarty->assign('ad_array', $raw_ad_array);
        
        /*AD CARRIER LIST*/
        $packetery_list_ad_carriers = array();
        $packetery_list_ad_carriers = Packeteryclass::getListAddressDeliveryCarriers();
        $this->context->smarty->assign(array(
            'packetery_list_ad_carriers' => Tools::jsonEncode(array(
                'columns' => array(
                    array('content' => $this->l('ID'), 'key' => 'id_carrier', 'center' => true),
                    array('content' => $this->l('Carrier'), 'key' => 'name', 'center' => true),
                    array(
                        'content' => $this->l('Is Address Delivery via Packetery'),
                        'key' => 'id_branch',
                        'center' => true
                    ),
                    array('content' => $this->l('Is COD'), 'key' => 'is_cod', 'bool' => true, 'center' => true),
                ),
                'rows' => $packetery_list_ad_carriers,
                'url_params' => array('configure' => $this->name),
                'identifier' => 'id_carrier',
            ))
        ));
        /*END AD CARRIER LIST*/

        /*CARRIER LIST*/
        $packetery_carriers_list = array();
        $packetery_carriers_list = Packeteryclass::getCarriersList();
        $this->context->smarty->assign(array(
            'packetery_carriers_list' => Tools::jsonEncode(array(
                'columns' => array(
                    array('content' => $this->l('ID'), 'key' => 'id_carrier', 'center' => true),
                    array('content' => $this->l('Carrier Name'), 'key' => 'name', 'center' => true),
                    array('content' => $this->l('Countries'), 'key' => 'country', 'center' => true),
                    array('content' => $this->l('Is COD'), 'key' => 'is_cod', 'bool' => true, 'center' => true),
                ),
                'rows' => $packetery_carriers_list,
                'rows_actions' => array(
                    array(
                        'title' => 'remove',
                        'action' => 'remove_carrier',
                        'icon' => 'delete',
                    ),
                ),
                'top_actions' => array(
                    array(
                        'title' => $this->l('Add Carrier'),
                        'action' => 'add_carrier',
                        'icon' => 'add',
                        'img' => 'themes/default/img/process-icon-new.png',
                        'fa' => 'plus'
                    ),
                ),
                'url_params' => array('configure' => $this->name),
                'identifier' => 'id_carrier'
            ))
        ));
        /*END CARRIER LIST*/

        /*PAYMENT LIST*/
        $payment_list = array();
        $payment_list = Packeteryclass::getListPayments();
        $this->context->smarty->assign(array(
            'payment_list' => Tools::jsonEncode(array(
                'columns' => array(
                    array('content' => $this->l('Module'), 'key' => 'name', 'center' => true),
                    array('content' => $this->l('Is COD'), 'key' => 'is_cod', 'bool' => true, 'center' => true),
                    array('content' => $this->l('module_name'), 'key' => 'module_name', 'center' => true),
                ),
                'rows' => $payment_list,
                'url_params' => array('configure' => $this->name),
                'identifier' => 'id_branch'
            ))
        ));
        /*END PAYMENT LIST*/

        /*BRANCHES*/
        $total_branches = PacketeryApi::countBranches();
        $last_branches_update = '';
        if ($settings[5][1] != '') {
            $date = new DateTime();
            $date->setTimestamp($settings[5][1]);
            $last_branches_update = $date->format('Y-m-d H:i:s');
        }
        $this->context->smarty->assign(
            array('total_branches' => $total_branches, 'last_branches_update' => $last_branches_update)
        );
        $packetery_branches = array();
        $this->context->smarty->assign(array(
            'packetery_branches' => Tools::jsonEncode(array(
                'columns' => array(
                    array('content' => $this->l('ID'), 'key' => 'id_branch', 'center' => true),
                    array('content' => $this->l('Name'), 'key' => 'name', 'center' => true),
                    array('content' => $this->l('Country'), 'key' => 'country', 'center' => true),
                    array('content' => $this->l('City'), 'key' => 'city', 'center' => true),
                    array('content' => $this->l('Street'), 'key' => 'street', 'center' => true),
                    array('content' => $this->l('Zip'), 'key' => 'zip', 'center' => true),
                    array('content' => $this->l('Url'), 'key' => 'url', 'center' => true),
                    array('content' => $this->l('Max weight'), 'key' => 'max_weight', 'center' => true),
                ),
                'rows' => $packetery_branches,
                'rows_actions' => array(
                    array('title' => 'Change', 'action' => 'remove'),
                ),
                'url_params' => array('configure' => $this->name),
                'identifier' => 'id_branch'
            ))
        ));
        /*END CARRIERS*/
        $this->hookDisplayWidget();

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
        $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/prestui/ps-tags.tpl');
        return $output;
    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookBackOfficeHeader()
    {
        if ((Tools::getValue('module_name') == $this->name) || (Tools::getValue('configure') == $this->name)) {
            $this->context->controller->addjquery();
            $this->context->controller->addJS('https://cdn.jsdelivr.net/riot/2.4.1/riot+compiler.min.js');
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addJS($this->_path.'views/js/widget.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
            $this->context->controller->addJS($this->_path.'views/js/notify.js');
            $this->context->controller->addJS($this->_path.'views/js/jquery.popupoverlay.js');
        }
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'PACKETERY_LIVE_MODE' => Configuration::get('PACKETERY_LIVE_MODE', true),
            'PACKETERY_ACCOUNT_EMAIL' => Configuration::get('PACKETERY_ACCOUNT_EMAIL', 'contact@prestashop.com'),
            'PACKETERY_ACCOUNT_PASSWORD' => Configuration::get('PACKETERY_ACCOUNT_PASSWORD', null),
        );
    }


    public function getOrderShippingCost($params, $shipping_cost)
    {
        if (Context::getContext()->customer->logged == true) {
            $id_address_delivery = Context::getContext()->cart->id_address_delivery;
            $address = new Address($id_address_delivery);
            return 10;
        }

        return $shipping_cost;
    }

    public function getOrderShippingCostExternal($params)
    {
        return true;
    }

    protected function addCarrier()
    {
        $carrier = new Carrier();
        $carrier->name = $this->l('Zasilkovna');
        $carrier->is_module = true;
        $carrier->active = 1;
        $carrier->range_behavior = 1;
        $carrier->need_range = 1;
        $carrier->shipping_external = false;
        $carrier->range_behavior = 0;
        $carrier->external_module_name = $this->name;
        $carrier->shipping_method = 2;

        foreach (Language::getLanguages() as $lang) {
            $carrier->delay[$lang['id_lang']] = $this->l('Packetery super fast delivery');
        }

        if ($carrier->add() == true) {
            @copy(dirname(__FILE__).'/views/img/carrier_image.png', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.png');
            Configuration::updateValue('PACKETERY_CARRIER_ID', (int)$carrier->id);
            return $carrier;
        }

        return false;
    }

    protected function addGroups($carrier)
    {
        $groups_ids = array();
        $groups = Group::getGroups(Context::getContext()->language->id);
        foreach ($groups as $group) {
            $groups_ids[] = $group['id_group'];
        }

        $carrier->setGroups($groups_ids);
    }

    protected function addRanges($carrier)
    {
        $range_price = new RangePrice();
        $range_price->id_carrier = $carrier->id;
        $range_price->delimiter1 = '0';
        $range_price->delimiter2 = '10000';
        $range_price->add();

        $range_weight = new RangeWeight();
        $range_weight->id_carrier = $carrier->id;
        $range_weight->delimiter1 = '0';
        $range_weight->delimiter2 = '10000';
        $range_weight->add();
    }

    protected function addZones($carrier)
    {
        $zones = Zone::getZones();

        foreach ($zones as $zone) {
            $carrier->addZone($zone['id_zone']);
        }
    }

    public function displayCarrierExtraContentPrototypeOPC($id_carrier, $id_cart)
    {
        //  $cart_carrier = $params['cart']->id_carrier;
        $this->context->smarty->assign('id_carrier', $id_carrier);
        $countries = PacketeryApi::getCountriesList($id_carrier);
        $this->context->smarty->assign('countries', $countries);
        $p_order_row = Packeteryclass::getPacketeryOrderRowByCart($id_cart);
        if ($p_order_row) {
            if (!isset($p_order_row['name_branch']) || ($p_order_row['id_carrier'] != $id_carrier)) {
                $name_branch = '0';
            } else {
                $name_branch = $p_order_row['name_branch'];
            }
        } else {
            $name_branch = '0';
        }
        $this->context->smarty->assign('choosed_branch', $name_branch);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/front/widget_popup.tpl');
        return $output;
    }
    /*WIDGET FO*/

    /*WIDGET FO for OPC*/
    public function hookDisplayFooter()
    {
        if (($this->widget_type == 0) && (Module::isEnabled('easypay') == true)) {
            $packetery_carriers_list = array();
            $packetery_carriers_list = Packeteryclass::getCarriersList();
            if (count($packetery_carriers_list) > 0) {
                $output = '';
                $carriers = array();
                foreach ($packetery_carriers_list as $carrier) {
                    $id_carrier = $carrier['id_carrier'];
                    $id_cart = Context::getContext()->cart->id;
                    $output .= $this->displayCarrierExtraContentPrototypeOPC($id_carrier, $id_cart);
                    $carriers[] = $id_carrier;
                }
                $this->context->smarty->assign('js_packetery_carriers', implode(',', $carriers));
                if (__PS_BASE_URI__ == '/') {
                    $base_uri = '';
                } else {
                    $base_uri = Tools::substr(__PS_BASE_URI__, 0, Tools::strlen(__PS_BASE_URI__) - 1);
                }
                $this->context->smarty->assign('baseuri', $base_uri);
                /*FIELDS FOR AJAX*/
                $ajaxfields = array(
                    'zip' => $this->l('ZIP'),
                    'moredetails' => $this->l('More details'),
                    'max_weight' => $this->l('Max weight'),
                    'dressing_room' => $this->l('Dressing room'),
                    'packet_consignment' => $this->l('Packet consignment'),
                    'claim_assistant' => $this->l('Claim assistant'),
                    'yes' => $this->l('Yes'),
                    'no' => $this->l('No')
                    );
                $ajaxfields_json = json_encode($ajaxfields);
                $this->context->smarty->assign('ajaxfields', $ajaxfields_json);
                /*END FIELDS FOR AJAX*/
                $this->context->smarty->assign('choosed_carrier', 100);
                $this->context->smarty->assign('widget_type', 0);

                $output_vars = $this->context->smarty->fetch(
                    $this->local_path.'views/templates/front/widget_popup_vars.tpl'
                );
            } else {
                return '';
            }
            return $output_vars.$output;
        }
    }

    public function hookDisplayBeforeCarrier($params)
    {
        if (!Module::isEnabled('easypay')) {
            if ($this->widget_type == 0) {
                $packetery_carriers_list = array();
                $packetery_carriers_list = Packeteryclass::getCarriersList();
                if (count($packetery_carriers_list) > 0) {
                    $output = '';
                    $carriers = array();
                    foreach ($packetery_carriers_list as $carrier) {
                        $id_carrier = $carrier['id_carrier'];
                        $id_cart = $params['cart']->id;
                        $output .= $this->displayCarrierExtraContentPrototypeOPC($id_carrier, $id_cart, $params);
                        $carriers[] = $id_carrier;
                    }
                    $this->context->smarty->assign('js_packetery_carriers', implode(',', $carriers));
                    if (__PS_BASE_URI__ == '/') {
                        $base_uri = '';
                    } else {
                        $base_uri = Tools::substr(__PS_BASE_URI__, 0, Tools::strlen(__PS_BASE_URI__) - 1);
                    }
                    $this->context->smarty->assign('baseuri', $base_uri);
                    /*FIELDS FOR AJAX*/
                    $ajaxfields = array(
                        'zip' => $this->l('ZIP'),
                        'moredetails' => $this->l('More details'),
                        'max_weight' => $this->l('Max weight'),
                        'dressing_room' => $this->l('Dressing room'),
                        'packet_consignment' => $this->l('Packet consignment'),
                        'claim_assistant' => $this->l('Claim assistant'),
                        'yes' => $this->l('Yes'),
                        'no' => $this->l('No')
                        );
                    $ajaxfields_json = json_encode($ajaxfields);
                    $this->context->smarty->assign('ajaxfields', $ajaxfields_json);
                    /*END FIELDS FOR AJAX*/
                    $this->context->smarty->assign('choosed_carrier', $params['cart']->id_carrier);
                    $this->context->smarty->assign('widget_type', 0);

                    $output_vars = $this->context->smarty->fetch(
                        $this->local_path.'views/templates/front/widget_popup_vars.tpl'
                    );
                } else {
                    return '';
                }
                return $output_vars.$output;
            }
        }
    }
    /*END WIDGET FO for OPC*/

    public function hookDisplayCarrierExtraContent($params)
    {
        $this->context->smarty->assign('widget_type', $this->widget_type);

        if ($this->widget_type == 1) {
            $id_carrier = $params['carrier']['id'];
            $this->context->smarty->assign('widget_carrier', $id_carrier);
            /*FIELDS FOR AJAX*/
            $ajaxfields = array(
                'zip' => $this->l('ZIP'),
                'moredetails' => $this->l('More details'),
                'max_weight' => $this->l('Max weight'),
                'dressing_room' => $this->l('Dressing room'),
                'packet_consignment' => $this->l('Packet consignment'),
                'claim_assistant' => $this->l('Claim assistant'),
                'yes' => $this->l('Yes'),
                'no' => $this->l('No')
                );
            $ajaxfields_json = json_encode($ajaxfields);
            $this->context->smarty->assign('ajaxfields', $ajaxfields_json);
            /*END FIELDS FOR AJAX*/

            $base_uri = __PS_BASE_URI__ == '/'?'':Tools::substr(__PS_BASE_URI__, 0, Tools::strlen(__PS_BASE_URI__) - 1);
            $this->context->smarty->assign('baseuri', $base_uri);
            $countries = PacketeryApi::getCountriesList($id_carrier);
            $this->context->smarty->assign('countries', $countries);
            $output = $this->context->smarty->fetch($this->local_path.'views/templates/front/widget.tpl');
            return $output;
        } else {
            return '';
        }
    }
    /*END WIDGET FO*/

    /*WIDGET BO*/
    public function hookDisplayWidget()
    {
        /*FIELDS FOR AJAX*/
        $ajaxfields = array(
            'zip' => $this->l('ZIP'),
            'moredetails' => $this->l('More details'),
            'max_weight' => $this->l('Max weight'),
            'dressing_room' => $this->l('Dressing room'),
            'packet_consignment' => $this->l('Packet consignment'),
            'claim_assistant' => $this->l('Claim assistant'),
            'yes' => $this->l('Yes'),
            'no' => $this->l('No'),
            'error' => $this->l('Error'),
            'success' => $this->l('Success'),
            'success_export' => $this->l('Successfuly exported'),
            'success_download_branches' => $this->l('Branches successfuly updated.'),
            'reload5sec' => $this->l('Page will be reloaded in 5 seconds...'),
            'try_download_branches' => $this->l('Trying to download branches. Please wait for download process end...'),
            'err_no_branch' => $this->l('Please select destination branch for order(s) - '),
            'error_export' => $this->l('not exported. Error: '),
            'err_country' => $this->l('Please select country')
            );
        $ajaxfields_json = json_encode($ajaxfields);
        $ajaxfields_json = rawurlencode($ajaxfields_json);
        $this->context->smarty->assign('ajaxfields', $ajaxfields_json);
        /*END FIELDS FOR AJAX*/

        $base_uri = __PS_BASE_URI__ == '/'?'':Tools::substr(__PS_BASE_URI__, 0, Tools::strlen(__PS_BASE_URI__) - 1);
        $this->context->smarty->assign('baseuri', $base_uri);

        $countries = PacketeryApi::getCountriesList();
        $this->context->smarty->assign('countries', $countries);
    }
    /*END WIDGET BO*/

    public function hookDisplayHeader()
    {
        PacketeryApi::updateBranchCron();
        $this->context->controller->addJS($this->_path.'views/js/jquery.popupoverlay.js');
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        if ($this->widget_type != 1) {
            $this->context->controller->addJS($this->_path.'views/js/widget_popup.js');
        }
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    /*ORDERS*/
    public function hookActionOrderHistoryAddAfter($params)
    {
        Packeteryclass::hookNewOrder($params);
    }
    /*END ORDERS*/
}
