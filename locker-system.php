<?php
/**
 * Plugin Name: Locker System
 * Description: WordPress plugin to create and manage lockers with countries, cities, email confirmation, and protected listing.
 * Version: 3.1 Debug Login
 * Author: Kenneth (kenaldertech)
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

class LockerSystem {
    const COOKIE_NAME = 'locker_system_auth';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'create_table']);
        add_shortcode('locker_form',  [$this, 'render_form']);
        add_shortcode('locker_list',  [$this, 'protected_page']);
        add_action('init', [$this, 'process_form']);
        add_action('init', [$this, 'check_activation_link']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    /** Create table */
    public function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . "lockers";
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            locker_number VARCHAR(50) NOT NULL,
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

    /** Public form */
    public function render_form() {
        if (isset($_GET['success']) && $_GET['success'] == '1') {
            return '<div style="text-align:center; padding:20px; background:#f0fff0; border:2px solid #28a745; border-radius:10px; margin:20px auto; max-width:700px;">
                        <h2 style="color:#28a745;">✅ Casillero creado con Éxito</h2>
                        <p>Te enviamos un mensaje a tu correo electrónico para activar tu casillero.</p>
                        <p>Luego de activarlo ya podrás utilizarlo.</p>
                    </div>';
        }

        $settings = get_option('locker_system_settings', [
            'countries_cities' => '{"Honduras":{"1":"San Pedro Sula","2":"Tegucigalpa","3":"Otra ciudad"}}',
            'enable_address' => 0
        ]);

        $data = json_decode($settings['countries_cities'], true);
        if (!$data || !is_array($data)) $data = [];

        ob_start(); ?>
        <div style="max-width:700px; margin:20px auto; padding:20px; border:2px solid #ccc; border-radius:10px; background:#fafafa;">
            <form method="post" action="" style="font-size:16px;">
                <label><strong>Country:</strong></label><br>
                <select id="country" name="country" required style="width:100%; padding:8px; margin-bottom:15px;">
                    <option value="">Select a country</option>
                    <?php foreach ($data as $country => $cities): ?>
                        <option value="<?php echo esc_attr($country); ?>"><?php echo esc_html($country); ?></option>
                    <?php endforeach; ?>
                </select><br>

                <label><strong>City:</strong></label><br>
                <select id="city" name="city" required style="width:100%; padding:8px; margin-bottom:15px;">
                    <option value="">Select a city</option>
                </select><br>

                <?php if (!empty($settings['enable_address'])): ?>
                    <label><strong>Address:</strong></label><br>
                    <input type="text" name="address" style="width:100%; padding:8px; margin-bottom:15px;"><br>
                <?php endif; ?>

                <label><strong>Name:</strong></label><br>
                <input type="text" name="name" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <label><strong>Email:</strong></label><br>
                <input type="email" name="email" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <label><strong>Phone:</strong></label><br>
                <input type="text" name="phone" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <div style="background:#eef2f7; padding:10px; border:1px solid #ccc; border-radius:8px; margin-bottom:15px;">
                    <label><input type="checkbox" name="notabot" value="1" required> I am not a bot</label>
                </div>

                <input type="submit" name="locker_submit" value="Create Locker"
                       style="background:#0073aa; color:#fff; padding:10px 20px; border:none; border-radius:6px; cursor:pointer;">
            </form>
        </div>

        <script>
        const data = <?php echo json_encode($data); ?>;
        const countrySelect = document.getElementById('country');
        const citySelect = document.getElementById('city');
        countrySelect.addEventListener('change', function() {
            const country = this.value;
            citySelect.innerHTML = '<option value="">Select a city</option>';
            if (data[country]) {
                for (const code in data[country]) {
                    const cityName = data[country][code];
                    let opt = document.createElement('option');
                    opt.value = cityName;
                    opt.textContent = cityName;
                    citySelect.appendChild(opt);
                }
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /** Process form */
    public function process_form() {
        global $wpdb;
        $table = $wpdb->prefix . "lockers";

        // Create locker
        if (isset($_POST['locker_submit'])) {
            if (!isset($_POST['notabot'])) wp_die('You must check the box');

            $country = sanitize_text_field($_POST['country']);
            $city    = sanitize_text_field($_POST['city']);
            $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
            $name    = sanitize_text_field($_POST['name']);
            $email   = sanitize_email($_POST['email']);
            $phone   = sanitize_text_field($_POST['phone']);

            $last = $wpdb->get_var($wpdb->prepare(
                "SELECT locker_number FROM $table WHERE city=%s AND country=%s ORDER BY id DESC LIMIT 1",
                $city, $country
            ));
            $base = 100501;
            if ($last) {
                $last_number = intval(substr($last, strlen($city)));
                $new_number  = $last_number + 1;
            } else {
                $new_number  = $base;
            }
            $locker_number = $city . $new_number;
            $activation_key = md5(uniqid(rand(), true));

            $wpdb->insert($table, [
                'locker_number'  => $locker_number,
                'country'        => $country,
                'city'           => $city,
                'address'        => $address,
                'name'           => $name,
                'email'          => $email,
                'phone'          => $phone,
                'status'         => 0,
                'activation_key' => $activation_key
            ]);

            $settings = get_option('locker_system_settings', []);
            $custom_message = !empty($settings['verification_message']) ? wpautop($settings['verification_message']) : '';

            $activation_link = add_query_arg(['locker_activation' => $activation_key], home_url());
            $subject = 'Confirm your locker';
            $message = "Hello $name,<br>Your locker number is: $locker_number<br>Country: $country<br>City: $city<br>";
            if ($address) $message .= "Address: $address<br>";
            $message .= "Email: $email<br>Phone: $phone<br>";
            if ($custom_message) $message .= "<hr>$custom_message";
            $message .= "<br><a href='$activation_link'>Click here to confirm your locker</a>";

            wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
            wp_redirect(add_query_arg('success', '1', $_SERVER['REQUEST_URI']));
            exit;
        }

        // Delete
        if (isset($_POST['delete_locker'])) {
            $wpdb->delete($table, ['id' => intval($_POST['delete_locker'])]);
            wp_redirect(add_query_arg('msg', 'deleted', $_SERVER['REQUEST_URI']));
            exit;
        }

        // Save
        if (isset($_POST['save_locker'])) {
            $id = intval($_POST['save_locker']);
            $name    = sanitize_text_field($_POST['name'][$id]);
            $email   = sanitize_email($_POST['email'][$id]);
            $phone   = sanitize_text_field($_POST['phone'][$id]);
            $address = sanitize_text_field($_POST['address'][$id]);

            $wpdb->update($table, [
                'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address
            ], ['id' => $id]);

            wp_redirect(add_query_arg('msg', 'saved', $_SERVER['REQUEST_URI']));
            exit;
        }

        // Login
        if (isset($_POST['ls_login'])) {
            $settings = get_option('locker_system_settings', []);
            $user_ok = !empty($settings['user']) ? $settings['user'] : 'admin';
            $pass_ok = !empty($settings['pass']) ? $settings['pass'] : '1234';

            $user = sanitize_text_field($_POST['ls_user']);
            $pass = sanitize_text_field($_POST['ls_pass']);

            if ($user === $user_ok && $pass === $pass_ok) {
                $hash = hash('sha256', $user_ok . ':' . $pass_ok . ':' . wp_salt('auth'));
                setcookie(self::COOKIE_NAME, $hash, time() + 6 * HOUR_IN_SECONDS, COOKIEPATH, '', is_ssl(), true);
                echo "<div style='background:#e6ffe6; padding:10px; border:1px solid green;'>DEBUG: Login success</div>";
                // No redirect aquí, se queda en la misma carga
            } else {
                echo "<div style='background:#ffe6e6; padding:10px; border:1px solid red;'>DEBUG: Bad login</div>";
            }
        }

        // Logout
        if (isset($_GET['ls_logout'])) {
            setcookie(self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, '', is_ssl(), true);
            wp_redirect(add_query_arg('msg', 'logged_out', remove_query_arg('ls_logout', $_SERVER['REQUEST_URI'])));
            exit;
        }
    }

    /** Activation link */
    public function check_activation_link() {
        if (isset($_GET['locker_activation'])) {
            global $wpdb;
            $table = $wpdb->prefix . "lockers";
            $key = sanitize_text_field($_GET['locker_activation']);
            $locker = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE activation_key=%s", $key));
            if ($locker) {
                $wpdb->update($table, ['status' => 1], ['activation_key' => $key]);
                wp_die("✅ Locker activated successfully.");
            } else {
                wp_die("Invalid or already activated link.");
            }
        }
    }

    /** Protected list */
    public function protected_page() {
        $settings = get_option('locker_system_settings', [
            'user'=>'admin','pass'=>'1234','list_protect'=>1
        ]);

        $requires_login = !empty($settings['list_protect']);
        $expected = hash('sha256', ($settings['user']??'admin').':'.($settings['pass']??'1234').':'.wp_salt('auth'));
        $session_ok = (isset($_COOKIE[self::COOKIE_NAME]) && hash_equals($_COOKIE[self::COOKIE_NAME], $expected));

        echo "<div style='background:#eef; padding:5px; font-size:12px;'>DEBUG LIST: requires_login=" . ($requires_login?'YES':'NO') . " | session_ok=" . ($session_ok?'YES':'NO') . "</div>";

        if ($requires_login && !$session_ok) {
            ob_start(); ?>
            <form method="post" action="">
                <p>User: <input type="text" name="ls_user"></p>
                <p>Password: <input type="password" name="ls_pass"></p>
                <button type="submit" name="ls_login" value="1">Login</button>
            </form>
            <?php return ob_get_clean();
        }

        global $wpdb;
        $table = $wpdb->prefix . "lockers";
        $results = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");

        ob_start(); ?>
        <h2>Locker List</h2>
        <form method="post" action="">
            <table border="1" cellpadding="6" style="border-collapse:collapse; width:100%;">
                <tr><th>Name</th><th>Locker Number</th><th>Country</th><th>City</th><th>Address</th><th>Email</th><th>Phone</th><th>Status</th><th>Actions</th></tr>
                <?php if ($results): foreach ($results as $row): $id=$row->id; ?>
                <tr>
                    <td><input type="text" name="name[<?php echo $id; ?>]" value="<?php echo esc_attr($row->name); ?>"></td>
                    <td><?php echo esc_html($row->locker_number); ?></td>
                    <td><?php echo esc_html($row->country); ?></td>
                    <td><?php echo esc_html($row->city); ?></td>
                    <td><input type="text" name="address[<?php echo $id; ?>]" value="<?php echo esc_attr($row->address); ?>"></td>
                    <td><input type="text" name="email[<?php echo $id; ?>]" value="<?php echo esc_attr($row->email); ?>"></td>
                    <td><input type="text" name="phone[<?php echo $id; ?>]" value="<?php echo esc_attr($row->phone); ?>"></td>
                    <td><?php echo $row->status ? '✅ Active' : '⏳ Pending'; ?></td>
                    <td>
                        <button type="submit" name="save_locker" value="<?php echo $id; ?>">Save</button>
                        <button type="submit" name="delete_locker" value="<?php echo $id; ?>" onclick="return confirm('Delete?');">Delete</button>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="9">No lockers found.</td></tr>
                <?php endif; ?>
            </table>
        </form>
        <?php return ob_get_clean();
    }

    /** Admin settings */
    public function add_admin_menu() {
        add_options_page('Locker Settings','Locker Settings','manage_options','locker-settings',[$this,'settings_page']);
    }

    public function settings_page() {
        if (isset($_POST['save_settings'])) {
            update_option('locker_system_settings', [
                'user'=>sanitize_text_field($_POST['user']),
                'pass'=>sanitize_text_field($_POST['pass']),
                'countries_cities'=>stripslashes($_POST['countries_cities']),
                'enable_address'=>isset($_POST['enable_address']) ? 1 : 0,
                'verification_message'=>wp_kses_post($_POST['verification_message']),
                'list_protect'=>isset($_POST['list_protect']) ? 1 : 0,
            ]);
            echo "<div class='updated'><p>Saved!</p></div>";
        }

        $settings = get_option('locker_system_settings', [
            'user'=>'admin','pass'=>'1234',
            'countries_cities'=>'{"Honduras":{"1":"San Pedro Sula","2":"Tegucigalpa","3":"Otra ciudad"}}',
            'enable_address'=>0,'verification_message'=>'','list_protect'=>1
        ]);
        ?>
        <div class="wrap">
            <h2>Locker System Settings</h2>
            <form method="post">
                <h3>Access</h3>
                <p>User: <input type="text" name="user" value="<?php echo esc_attr($settings['user']); ?>"></p>
                <p>Password: <input type="text" name="pass" value="<?php echo esc_attr($settings['pass']); ?>"></p>
                <p><label><input type="checkbox" name="list_protect" value="1" <?php checked($settings['list_protect'],1); ?>> Require login for list</label></p>
                <h3>Countries and Cities</h3>
                <textarea name="countries_cities" rows="8" style="width:100%;"><?php echo esc_textarea($settings['countries_cities']); ?></textarea>
                <h3>Form Options</h3>
                <p><label><input type="checkbox" name="enable_address" value="1" <?php checked($settings['enable_address'],1); ?>> Enable Address field</label></p>
                <h3>Verification Email Message</h3>
                <textarea name="verification_message" rows="6" style="width:100%;"><?php echo esc_textarea($settings['verification_message']); ?></textarea>
                <p><input type="submit" name="save_settings" value="Save Settings" class="button button-primary"></p>
            </form>
        </div>
        <?php
    }
}
new LockerSystem();
