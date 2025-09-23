<?php
/**
 * Plugin Name: Locker System
 * Description: A WordPress plugin to create and manage lockers with city/country configuration, email confirmation, and protected listing.
 * Version: 2.1
 * Author: Kenneth (kenaldertech)
 * License: GPL2
 * Text Domain: locker-system
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class LockerSystem {
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'create_table']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_shortcode('locker_form', [$this, 'render_form']);
        add_action('init', [$this, 'process_form']);
        add_shortcode('locker_list', [$this, 'protected_page']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('init', [$this, 'check_activation_link']);
    }

    /** Load translations */
    public function load_textdomain() {
        load_plugin_textdomain('locker-system', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /** Create DB table */
    public function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . "lockers";
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            locker_number VARCHAR(20) NOT NULL,
            country VARCHAR(50) DEFAULT 'Honduras',
            city VARCHAR(50) NOT NULL,
            address VARCHAR(255) DEFAULT '',
            name VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(30),
            status TINYINT DEFAULT 0,
            activation_key VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /** Locker creation form */
    public function render_form() {
        if (isset($_GET['success']) && $_GET['success'] == 1) {
            return '
            <div style="text-align:center; padding:30px; background:#f0fff0; border:2px solid #28a745; border-radius:10px; margin:20px auto; max-width:600px;">
                <h2 style="color:#28a745; font-size:28px;">✅ ' . __('Locker created successfully', 'locker-system') . '</h2>
                <p style="font-size:20px;">' . __('We sent you an email to activate your locker.', 'locker-system') . '</p>
                <p style="font-size:18px;">' . __('After activation, you will be able to use it.', 'locker-system') . '</p>
            </div>';
        }

        $settings = get_option('locker_system_settings', [
            'countries' => 'Honduras',
            'cities' => '1:San Pedro Sula,2:Tegucigalpa,3:Other city',
            'enable_address' => 0
        ]);

        $countries = array_map('trim', explode(',', $settings['countries']));
        $cities = array_map('trim', explode(',', $settings['cities']));

        ob_start(); ?>
        <div style="max-width:600px; margin:20px auto; padding:20px; border:2px solid #ccc; border-radius:10px; background:#fafafa;">
            <form method="post" style="font-size:18px;">
                <label><strong><?php _e('Country', 'locker-system'); ?>:</strong></label><br>
                <select name="country" required style="width:100%; padding:8px; margin-bottom:15px;">
                    <?php foreach ($countries as $c): ?>
                        <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select><br>

                <label><strong><?php _e('Destination City', 'locker-system'); ?>:</strong></label><br>
                <select name="city" required style="width:100%; padding:8px; margin-bottom:15px;">
                    <?php foreach ($cities as $c): 
                        list($code, $name) = explode(':', $c, 2); ?>
                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select><br>

                <?php if (!empty($settings['enable_address'])): ?>
                <label><strong><?php _e('Address', 'locker-system'); ?>:</strong></label><br>
                <input type="text" name="address" style="width:100%; padding:8px; margin-bottom:15px;"><br>
                <?php endif; ?>

                <label><strong><?php _e('Name', 'locker-system'); ?>:</strong></label><br>
                <input type="text" name="name" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <label><strong><?php _e('Email', 'locker-system'); ?>:</strong></label><br>
                <input type="email" name="email" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <label><strong><?php _e('Phone', 'locker-system'); ?>:</strong></label><br>
                <input type="text" name="phone" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <div style="background:#eef2f7; padding:10px; border:1px solid #ccc; border-radius:8px; margin-bottom:15px;">
                    <label style="font-size:16px;">
                        <input type="checkbox" name="notabot" value="1" required> <?php _e('I am not a bot', 'locker-system'); ?>
                    </label>
                </div>

                <input type="submit" name="locker_submit" value="<?php esc_attr_e('Create Locker', 'locker-system'); ?>"
                    style="background:#0073aa; color:#fff; padding:12px 20px; font-size:18px; border:none; border-radius:8px; cursor:pointer;">
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Process form */
    public function process_form() {
        if (isset($_POST['locker_submit'])) {
            if (!isset($_POST['notabot'])) {
                wp_die(__('You must check the "I am not a bot" box', 'locker-system'));
            }

            global $wpdb;
            $table = $wpdb->prefix . "lockers";

            $country = sanitize_text_field($_POST['country']);
            $city = sanitize_text_field($_POST['city']);
            $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
            $name = sanitize_text_field($_POST['name']);
            $email = sanitize_email($_POST['email']);
            $phone = sanitize_text_field($_POST['phone']);

            $last = $wpdb->get_var("SELECT locker_number FROM $table WHERE city='$city' ORDER BY id DESC LIMIT 1");
            $base = 100501;
            if ($last) {
                $last_number = intval(substr($last, 1));
                $new_number = $last_number + 1;
            } else {
                $new_number = $base;
            }
            $locker_number = $city . $new_number;

            $activation_key = md5(uniqid(rand(), true));

            $wpdb->insert($table, [
                'locker_number' => $locker_number,
                'country' => $country,
                'city' => $city,
                'address' => $address,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'status' => 0,
                'activation_key' => $activation_key
            ]);

            $activation_link = add_query_arg(['locker_activation' => $activation_key], home_url());
            $subject = __('Confirm your locker', 'locker-system');
            $message = sprintf(
                __("Hello %s,\n\nYour locker number is: %s\nCountry: %s\nCity: %s\n%sEmail: %s\nPhone: %s\n\nPlease confirm your locker here:\n%s", 'locker-system'),
                $name,
                $locker_number,
                $country,
                $city,
                $address ? "Address: $address\n" : "",
                $email,
                $phone,
                $activation_link
            );

            wp_mail($email, $subject, $message);

            wp_redirect(add_query_arg('success', '1', $_SERVER['REQUEST_URI']));
            exit;
        }
    }

    /** Check activation */
    public function check_activation_link() {
        if (isset($_GET['locker_activation'])) {
            global $wpdb;
            $table = $wpdb->prefix . "lockers";
            $key = sanitize_text_field($_GET['locker_activation']);
            $locker = $wpdb->get_row("SELECT * FROM $table WHERE activation_key='$key'");
            if ($locker) {
                $wpdb->update($table, ['status' => 1], ['activation_key' => $key]);
                wp_die("✅ " . __('Locker activated successfully. You can now use it.', 'locker-system'));
            } else {
                wp_die(__('Invalid or already activated link.', 'locker-system'));
            }
        }
    }

    /** Protected page */
    public function protected_page() {
        $settings = get_option('locker_system_settings', [
            'user' => 'admin',
            'pass' => '1234'
        ]);

        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            header('WWW-Authenticate: Basic realm="Locker System"');
            header('HTTP/1.0 401 Unauthorized');
            echo "Auth required.";
            exit;
        } else {
            if ($_SERVER['PHP_AUTH_USER'] !== $settings['user'] || $_SERVER['PHP_AUTH_PW'] !== $settings['pass']) {
                header('HTTP/1.0 403 Forbidden');
                echo "Access denied.";
                exit;
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . "lockers";
        $results = $wpdb->get_results("SELECT * FROM $table WHERE status=1 ORDER BY id DESC");

        ob_start();
        echo "<h2>" . __('Locker List', 'locker-system') . "</h2>
        <table border='1' cellpadding='6' style='border-collapse:collapse;'>
            <tr>
                <th>" . __('Name', 'locker-system') . "</th>
                <th>" . __('Locker Number', 'locker-system') . "</th>
                <th>" . __('Country', 'locker-system') . "</th>
                <th>" . __('City', 'locker-system') . "</th>
                <th>" . __('Address', 'locker-system') . "</th>
                <th>" . __('Email', 'locker-system') . "</th>
                <th>" . __('Phone', 'locker-system') . "</th>
            </tr>";
        foreach ($results as $row) {
            echo "<tr>
                <td>{$row->name}</td>
                <td>{$row->locker_number}</td>
                <td>{$row->country}</td>
                <td>{$row->city}</td>
                <td>{$row->address}</td>
                <td>{$row->email}</td>
                <td>{$row->phone}</td>
            </tr>";
        }
        echo "</table>";
        return ob_get_clean();
    }

    /** Settings page */
    public function add_admin_menu() {
        add_options_page(
            'Locker Settings',
            'Locker Settings',
            'manage_options',
            'locker-settings',
            [$this, 'settings_page']
        );
    }

    public function settings_page() {
        if (isset($_POST['save_settings'])) {
            update_option('locker_system_settings', [
                'user' => sanitize_text_field($_POST['user']),
                'pass' => sanitize_text_field($_POST['pass']),
                'countries' => sanitize_text_field($_POST['countries']),
                'cities' => sanitize_text_field($_POST['cities']),
                'enable_address' => isset($_POST['enable_address']) ? 1 : 0
            ]);
            echo "<div class='updated'><p>" . __('Saved!', 'locker-system') . "</p></div>";
        }

        $settings = get_option('locker_system_settings', [
            'user' => 'admin',
            'pass' => '1234',
            'countries' => 'Honduras',
            'cities' => '1:San Pedro Sula,2:Tegucigalpa,3:Other city',
            'enable_address' => 0
        ]);
        ?>
        <div class="wrap">
            <h2><?php _e('Locker System Settings', 'locker-system'); ?></h2>
            <form method="post">
                <h3><?php _e('Access', 'locker-system'); ?></h3>
                <label><?php _e('User', 'locker-system'); ?>:</label>
                <input type="text" name="user" value="<?php echo esc_attr($settings['user']); ?>"><br><br>
                <label><?php _e('Password', 'locker-system'); ?>:</label>
                <input type="text" name="pass" value="<?php echo esc_attr($settings['pass']); ?>"><br><br>

                <h3><?php _e('Countries', 'locker-system'); ?></h3>
                <textarea name="countries" rows="2" style="width:100%;"><?php echo esc_textarea($settings['countries']); ?></textarea>
                <p><?php _e('Separate with commas. Example: Honduras,El Salvador,Guatemala', 'locker-system'); ?></p>

                <h3><?php _e('Cities', 'locker-system'); ?></h3>
                <textarea name="cities" rows="3" style="width:100%;"><?php echo esc_textarea($settings['cities']); ?></textarea>
                <p><?php _e('Format: code:Name separated by commas. Example: 1:San Pedro Sula,2:Tegucigalpa,3:Other', 'locker-system'); ?></p>

                <h3><?php _e('Form Options', 'locker-system'); ?></h3>
                <label>
                    <input type="checkbox" name="enable_address" value="1" <?php checked($settings['enable_address'], 1); ?>>
                    <?php _e('Enable Address field in form', 'locker-system'); ?>
                </label><br><br>

                <input type="submit" name="save_settings" value="<?php esc_attr_e('Save Settings', 'locker-system'); ?>" class="button button-primary">
            </form>
        </div>
        <?php
    }
}

new LockerSystem();
