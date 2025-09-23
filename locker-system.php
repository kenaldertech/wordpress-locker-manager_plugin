<?php
/**
 * Plugin Name: Locker System
 * Plugin URI: https://github.com/kenaldertech/wordpress-locker-manager_plugin
 * Description: A plugin to manage lockers with customizable countries, cities, and email confirmation.
 * Version: 1.0
 * Author: Kenneth (kenaldertech)
 * Author URI: https://github.com/kenaldertech
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: locker-system
 */


if (!defined('ABSPATH')) exit;

class LockerSystem {
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'create_table']);
        add_shortcode('locker_form', [$this, 'render_form']);
        add_action('init', [$this, 'process_form']);
        add_shortcode('locker_list', [$this, 'protected_page']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('init', [$this, 'check_activation_link']);
    }

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

    public function render_form() {
        if (isset($_GET['success']) && $_GET['success'] == 1) {
            return '
            <div style="text-align:center; padding:30px; background:#f0fff0; border:2px solid #28a745; border-radius:10px; margin:20px auto; max-width:600px;">
                <h2 style="color:#28a745; font-size:28px;">✅ Casillero creado con éxito</h2>
                <p style="font-size:20px;">Te enviamos un mensaje a tu correo electrónico para activar tu casillero.</p>
                <p style="font-size:18px;">Luego de activarlo ya podrás utilizarlo.</p>
            </div>';
        }

        $settings = get_option('locker_system_settings', [
            'countries' => 'Honduras',
            'cities' => '1:San Pedro Sula,2:Tegucigalpa,3:Otra ciudad',
            'enable_address' => 0
        ]);

        $countries = array_map('trim', explode(',', $settings['countries']));
        $cities = array_map('trim', explode(',', $settings['cities']));

        ob_start(); ?>
        <div style="max-width:600px; margin:20px auto; padding:20px; border:2px solid #ccc; border-radius:10px; background:#fafafa;">
            <form method="post" style="font-size:18px;">
                <label><strong>País:</strong></label><br>
                <select name="country" required style="width:100%; padding:8px; margin-bottom:15px;">
                    <?php foreach ($countries as $c): ?>
                        <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select><br>

                <label><strong>Destino (Ciudad):</strong></label><br>
                <select name="city" required style="width:100%; padding:8px; margin-bottom:15px;">
                    <?php foreach ($cities as $c): 
                        list($code, $name) = explode(':', $c, 2); ?>
                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select><br>

                <?php if (!empty($settings['enable_address'])): ?>
                <label><strong>Dirección:</strong></label><br>
                <input type="text" name="address" style="width:100%; padding:8px; margin-bottom:15px;"><br>
                <?php endif; ?>

                <label><strong>Nombre:</strong></label><br>
                <input type="text" name="name" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <label><strong>Correo electrónico:</strong></label><br>
                <input type="email" name="email" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <label><strong>Teléfono:</strong></label><br>
                <input type="text" name="phone" required style="width:100%; padding:8px; margin-bottom:15px;"><br>

                <div style="background:#eef2f7; padding:10px; border:1px solid #ccc; border-radius:8px; margin-bottom:15px;">
                    <label style="font-size:16px;">
                        <input type="checkbox" name="notabot" value="1" required> No soy un bot
                    </label>
                </div>

                <input type="submit" name="locker_submit" value="Crear Casillero"
                    style="background:#0073aa; color:#fff; padding:12px 20px; font-size:18px; border:none; border-radius:8px; cursor:pointer;">
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function process_form() {
        if (isset($_POST['locker_submit'])) {
            if (!isset($_POST['notabot'])) {
                wp_die("Debes marcar la casilla 'No soy un bot'");
            }

            global $wpdb;
            $table = $wpdb->prefix . "lockers";

            $country = sanitize_text_field($_POST['country']);
            $city = sanitize_text_field($_POST['city']);
            $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
            $name = sanitize_text_field($_POST['name']);
            $email = sanitize_email($_POST['email']);
            $phone = sanitize_text_field($_POST['phone']);

            // Generar número único
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

            // Enviar email
            $activation_link = add_query_arg(['locker_activation' => $activation_key], home_url());
            $subject = "Confirma tu casillero";
            $message = "Hola $name,\n\n".
                       "Tu casillero es: $locker_number\n".
                       "País: $country\n".
                       "Ciudad: $city\n".
                       ($address ? "Dirección: $address\n" : "").
                       "Correo: $email\n".
                       "Teléfono: $phone\n\n".
                       "Confirma tu casillero aquí:\n$activation_link";

            wp_mail($email, $subject, $message);

            wp_redirect(add_query_arg('success', '1', $_SERVER['REQUEST_URI']));
            exit;
        }
    }

    public function check_activation_link() {
        if (isset($_GET['locker_activation'])) {
            global $wpdb;
            $table = $wpdb->prefix . "lockers";
            $key = sanitize_text_field($_GET['locker_activation']);
            $locker = $wpdb->get_row("SELECT * FROM $table WHERE activation_key='$key'");
            if ($locker) {
                $wpdb->update($table, ['status' => 1], ['activation_key' => $key]);
                wp_die("✅ Casillero activado con éxito. Ya puedes usarlo.");
            } else {
                wp_die("Enlace inválido o ya activado.");
            }
        }
    }

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
        echo "<h2>Listado de Casilleros</h2>
        <table border='1' cellpadding='6' style='border-collapse:collapse;'>
            <tr><th>Nombre</th><th>Casillero</th><th>País</th><th>Destino</th><th>Dirección</th><th>Correo</th><th>Teléfono</th></tr>";
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
            echo "<div class='updated'><p>Guardado!</p></div>";
        }

        $settings = get_option('locker_system_settings', [
            'user' => 'admin',
            'pass' => '1234',
            'countries' => 'Honduras',
            'cities' => '1:San Pedro Sula,2:Tegucigalpa,3:Otra ciudad',
            'enable_address' => 0
        ]);
        ?>
        <div class="wrap">
            <h2>Locker System Settings</h2>
            <form method="post">
                <h3>Access</h3>
                <label>User:</label>
                <input type="text" name="user" value="<?php echo esc_attr($settings['user']); ?>"><br><br>
                <label>Password:</label>
                <input type="text" name="pass" value="<?php echo esc_attr($settings['pass']); ?>"><br><br>

                <h3>Countries</h3>
                <textarea name="countries" rows="2" style="width:100%;"><?php echo esc_textarea($settings['countries']); ?></textarea>
                <p>Separate with commas. Example: Honduras,El Salvador,Guatemala</p>

                <h3>Cities</h3>
                <textarea name="cities" rows="3" style="width:100%;"><?php echo esc_textarea($settings['cities']); ?></textarea>
                <p>Format: code:Name separated by commas. Example: 1:San Pedro Sula,2:Tegucigalpa,3:Otra ciudad</p>

                <h3>Form Options</h3>
                <label>
                    <input type="checkbox" name="enable_address" value="1" <?php checked($settings['enable_address'], 1); ?>>
                    Enable Address field in form
                </label><br><br>

                <input type="submit" name="save_settings" value="Save Settings" class="button button-primary">
            </form>
        </div>
        <?php
    }
}

new LockerSystem();
