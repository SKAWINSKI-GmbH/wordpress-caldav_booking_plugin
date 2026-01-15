<?php
/**
 * Plugin Name: CalDAV Booking
 * Plugin URI: https://github.com/skawinski/caldav-booking-plugin
 * Description: Terminbuchung mit CalDAV-Synchronisation (SOGo/Mailcow kompatibel) - Nur explizit freigegebene Slots buchbar
 * Version: 0.2.1
 * Author: SKAWINSKI GmbH
 * Author URI: https://skawinski.at
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: caldav-booking
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * 
 * Copyright (c) 2025 SKAWINSKI GmbH (https://skawinski.at)
 * Licensed under the MIT License. See LICENSE file for details.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CALDAV_BOOKING_VERSION', '0.2.1');
define('CALDAV_BOOKING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CALDAV_BOOKING_PLUGIN_URL', plugin_dir_url(__FILE__));

class CalDAV_Booking {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_shortcode('caldav_booking', [$this, 'render_booking_form']);
        add_action('wp_ajax_caldav_get_slots', [$this, 'ajax_get_slots']);
        add_action('wp_ajax_nopriv_caldav_get_slots', [$this, 'ajax_get_slots']);
        add_action('wp_ajax_caldav_check_dates', [$this, 'ajax_check_dates']);
        add_action('wp_ajax_nopriv_caldav_check_dates', [$this, 'ajax_check_dates']);
        add_action('wp_ajax_caldav_book_slot', [$this, 'ajax_book_slot']);
        add_action('wp_ajax_nopriv_caldav_book_slot', [$this, 'ajax_book_slot']);
        add_action('wp_ajax_caldav_get_meeting_types', [$this, 'ajax_get_meeting_types']);
        add_action('wp_ajax_nopriv_caldav_get_meeting_types', [$this, 'ajax_get_meeting_types']);
        
        // Cron für asynchrone Kalender-Eintragung
        add_action('caldav_process_booking', [$this, 'process_booking_async']);

        // Reservierte Slots bei Slot-Abfrage berücksichtigen
        add_filter('caldav_busy_slots', [$this, 'add_reserved_slots'], 10, 2);

        // Cache leeren wenn Einstellungen gespeichert werden
        add_action('update_option_caldav_booking_options', [$this, 'clear_all_caches'], 10, 0);
    }

    public function clear_all_caches() {
        // Object Cache für Options leeren
        wp_cache_delete('caldav_booking_options', 'options');
        wp_cache_delete('alloptions', 'options');

        // Alle CalDAV Event-Caches leeren (Transients)
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_caldav_events_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_caldav_events_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_caldav_range_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_caldav_range_%'");

        // Object Cache komplett leeren falls verfügbar
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('options');
        }
    }

    /**
     * Get the timezone for calendar operations.
     * Uses caldav_timezone setting if set, otherwise falls back to WordPress timezone.
     */
    private function get_calendar_timezone() {
        $options = get_option('caldav_booking_options', []);
        $tz_string = !empty($options['caldav_timezone']) ? $options['caldav_timezone'] : wp_timezone_string();
        return new DateTimeZone($tz_string);
    }

    /**
     * Get the timezone name for iCal (must be a named timezone, not offset).
     */
    private function get_calendar_timezone_name() {
        $options = get_option('caldav_booking_options', []);
        $tz_string = !empty($options['caldav_timezone']) ? $options['caldav_timezone'] : wp_timezone_string();
        return $tz_string;
    }

    public function add_admin_menu() {
        add_options_page(
            'CalDAV Booking Einstellungen',
            'CalDAV Booking',
            'manage_options',
            'caldav-booking',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('caldav_booking_settings', 'caldav_booking_options', [
            'sanitize_callback' => [$this, 'sanitize_options']
        ]);
        
        // Connection Section
        add_settings_section(
            'caldav_connection',
            'CalDAV Verbindung (SOGo/Mailcow)',
            [$this, 'section_connection_callback'],
            'caldav-booking'
        );
        
        add_settings_field('caldav_url', 'CalDAV URL', [$this, 'field_caldav_url'], 'caldav-booking', 'caldav_connection');
        add_settings_field('caldav_username', 'Benutzername', [$this, 'field_caldav_username'], 'caldav-booking', 'caldav_connection');
        add_settings_field('caldav_password', 'Passwort', [$this, 'field_caldav_password'], 'caldav-booking', 'caldav_connection');
        add_settings_field('caldav_calendar', 'Kalender-Pfad', [$this, 'field_caldav_calendar'], 'caldav-booking', 'caldav_connection');
        add_settings_field('caldav_timezone', 'Kalender-Zeitzone', [$this, 'field_caldav_timezone'], 'caldav-booking', 'caldav_connection');
        
        // Availability Section
        add_settings_section(
            'caldav_availability',
            'Verfügbarkeits-Erkennung',
            [$this, 'section_availability_callback'],
            'caldav-booking'
        );
        
        add_settings_field('availability_keyword', 'Verfügbarkeits-Keyword', [$this, 'field_availability_keyword'], 'caldav-booking', 'caldav_availability');
        add_settings_field('availability_keywords_extra', 'Zusätzliche Keywords', [$this, 'field_availability_keywords_extra'], 'caldav-booking', 'caldav_availability');
        
        // Meeting Types Section
        add_settings_section(
            'caldav_meeting_types',
            'Meeting-Typen',
            [$this, 'section_meeting_types_callback'],
            'caldav-booking'
        );
        
        add_settings_field('meeting_types', 'Verfügbare Meeting-Typen', [$this, 'field_meeting_types'], 'caldav-booking', 'caldav_meeting_types');
        
        // Booking Settings Section
        add_settings_section(
            'caldav_booking_settings_section',
            'Buchungseinstellungen',
            null,
            'caldav-booking'
        );
        
        add_settings_field('booking_days_ahead', 'Tage im Voraus', [$this, 'field_days_ahead'], 'caldav-booking', 'caldav_booking_settings_section');
        add_settings_field('slot_buffer', 'Puffer zwischen Terminen (Min)', [$this, 'field_slot_buffer'], 'caldav-booking', 'caldav_booking_settings_section');
        add_settings_field('min_notice', 'Mindestvorlauf (Stunden)', [$this, 'field_min_notice'], 'caldav-booking', 'caldav_booking_settings_section');
        add_settings_field('required_fields', 'Pflichtfelder', [$this, 'field_required_fields'], 'caldav-booking', 'caldav_booking_settings_section');
        add_settings_field('submit_button_id', 'Submit-Button ID (Analytics)', [$this, 'field_submit_button_id'], 'caldav-booking', 'caldav_booking_settings_section');
        add_settings_field('rate_limit_ip', 'Rate-Limit pro IP', [$this, 'field_rate_limit_ip'], 'caldav-booking', 'caldav_booking_settings_section');
        add_settings_field('rate_limit_global', 'Globales Rate-Limit', [$this, 'field_rate_limit_global'], 'caldav-booking', 'caldav_booking_settings_section');
        add_settings_field('async_processing', 'Asynchrone Verarbeitung', [$this, 'field_async_processing'], 'caldav-booking', 'caldav_booking_settings_section');

        // Custom CSS Section
        add_settings_section(
            'caldav_custom_css',
            'Eigenes CSS',
            [$this, 'section_custom_css_callback'],
            'caldav-booking'
        );
        
        add_settings_field('custom_css', 'CSS Code', [$this, 'field_custom_css'], 'caldav-booking', 'caldav_custom_css');
        
        // Email Settings Section
        add_settings_section(
            'caldav_email_settings',
            'E-Mail Einstellungen',
            [$this, 'section_email_callback'],
            'caldav-booking'
        );
        
        add_settings_field('email_customer', 'Kunden-Bestätigung', [$this, 'field_email_customer'], 'caldav-booking', 'caldav_email_settings');
        add_settings_field('email_admin', 'Admin-Benachrichtigung', [$this, 'field_email_admin'], 'caldav-booking', 'caldav_email_settings');
        add_settings_field('email_debug', 'E-Mail Debug-Modus', [$this, 'field_email_debug'], 'caldav-booking', 'caldav_email_settings');
        add_settings_field('console_debug', 'Console Debug-Modus', [$this, 'field_console_debug'], 'caldav-booking', 'caldav_email_settings');

        // Email Templates Section
        add_settings_section(
            'caldav_email_templates',
            'E-Mail Vorlagen',
            [$this, 'section_email_templates_callback'],
            'caldav-booking'
        );
        
        add_settings_field('email_subject_customer', 'Betreff (Kunde)', [$this, 'field_email_subject_customer'], 'caldav-booking', 'caldav_email_templates');
        add_settings_field('email_body_customer', 'Text (Kunde)', [$this, 'field_email_body_customer'], 'caldav-booking', 'caldav_email_templates');
        add_settings_field('email_subject_admin', 'Betreff (Admin)', [$this, 'field_email_subject_admin'], 'caldav-booking', 'caldav_email_templates');
        add_settings_field('email_body_admin', 'Text (Admin)', [$this, 'field_email_body_admin'], 'caldav-booking', 'caldav_email_templates');
    }
    
    public function sanitize_options($input) {
        $sanitized = [];
        
        // Connection
        $sanitized['caldav_url'] = esc_url_raw($input['caldav_url'] ?? '');
        $sanitized['caldav_username'] = sanitize_text_field($input['caldav_username'] ?? '');
        $sanitized['caldav_password'] = $input['caldav_password'] ?? '';
        $sanitized['caldav_calendar'] = sanitize_text_field($input['caldav_calendar'] ?? '');
        $sanitized['caldav_timezone'] = sanitize_text_field($input['caldav_timezone'] ?? '');
        
        // Availability
        $sanitized['availability_keyword'] = sanitize_text_field($input['availability_keyword'] ?? 'VERFÜGBAR');
        $sanitized['availability_keywords_extra'] = sanitize_text_field($input['availability_keywords_extra'] ?? '');
        
        // Meeting Types
        $meeting_types = [];
        if (!empty($input['meeting_types']) && is_array($input['meeting_types'])) {
            foreach ($input['meeting_types'] as $type) {
                if (!empty($type['name']) && !empty($type['duration'])) {
                    $meeting_types[] = [
                        'name' => sanitize_text_field($type['name']),
                        'duration' => absint($type['duration']),
                        'description' => sanitize_textarea_field($type['description'] ?? ''),
                        'color' => sanitize_hex_color($type['color'] ?? '#0073aa')
                    ];
                }
            }
        }
        if (empty($meeting_types)) {
            $meeting_types[] = [
                'name' => 'Standardtermin',
                'duration' => 60,
                'description' => '',
                'color' => '#0073aa'
            ];
        }
        $sanitized['meeting_types'] = $meeting_types;
        
        // Booking Settings
        $sanitized['booking_days_ahead'] = absint($input['booking_days_ahead'] ?? 14);
        $sanitized['slot_buffer'] = absint($input['slot_buffer'] ?? 15);
        $sanitized['min_notice'] = absint($input['min_notice'] ?? 2);
        $sanitized['submit_button_id'] = sanitize_html_class($input['submit_button_id'] ?? '');
        $sanitized['rate_limit_ip'] = absint($input['rate_limit_ip'] ?? 5);
        $sanitized['rate_limit_global'] = absint($input['rate_limit_global'] ?? 50);

        // Required fields (name is always required)
        $allowed_fields = ['email', 'phone', 'message'];
        $required = isset($input['required_fields']) && is_array($input['required_fields'])
            ? array_intersect($input['required_fields'], $allowed_fields)
            : ['email'];
        $sanitized['required_fields'] = array_values($required);

        // Async processing
        $sanitized['async_processing'] = isset($input['async_processing']) ? 1 : 0;

        // Custom CSS - strip tags but allow CSS
        $sanitized['custom_css'] = wp_strip_all_tags($input['custom_css'] ?? '');
        
        // Email Settings
        $sanitized['email_customer'] = isset($input['email_customer']) ? 1 : 0;
        $sanitized['email_admin'] = isset($input['email_admin']) ? 1 : 0;
        $sanitized['email_debug'] = isset($input['email_debug']) ? 1 : 0;
        $sanitized['console_debug'] = isset($input['console_debug']) ? 1 : 0;

        // Cleanup debug log when debug mode is disabled
        if (!$sanitized['console_debug']) {
            $log_file = WP_CONTENT_DIR . '/caldav-debug.log';
            if (file_exists($log_file)) {
                @unlink($log_file);
            }
        }

        // Email Templates
        $sanitized['email_subject_customer'] = sanitize_text_field($input['email_subject_customer'] ?? '');
        $sanitized['email_body_customer'] = sanitize_textarea_field($input['email_body_customer'] ?? '');
        $sanitized['email_subject_admin'] = sanitize_text_field($input['email_subject_admin'] ?? '');
        $sanitized['email_body_admin'] = sanitize_textarea_field($input['email_body_admin'] ?? '');
        
        return $sanitized;
    }
    
    public function section_connection_callback() {
        echo '<p>Verbindungsdaten für deinen SOGo/Mailcow CalDAV-Server.</p>';
        echo '<p><strong>Typische SOGo URL:</strong> <code>https://mail.example.com/SOGo/dav/</code></p>';
    }
    
    public function section_availability_callback() {
        echo '<div style="background:#f0f7fc;border-left:4px solid #0073aa;padding:15px 20px;margin:15px 0;">';
        echo '<h4 style="margin-top:0;color:#0073aa;">So funktioniert das Plugin:</h4>';
        echo '<p>Das Plugin zeigt <strong>NUR</strong> Zeitfenster an, die du explizit im Kalender als verfügbar markiert hast.</p>';
        echo '<p><strong>Anleitung:</strong></p>';
        echo '<ol>';
        echo '<li>Lege im Kalender Termine mit dem Keyword im Titel an (z.B. "VERFÜGBAR")</li>';
        echo '<li>Diese Zeitblöcke werden dann in buchbare Slots unterteilt</li>';
        echo '<li>Bestehende Termine innerhalb der Verfügbarkeitsblöcke werden automatisch ausgeschlossen</li>';
        echo '</ol>';
        echo '</div>';
    }
    
    public function section_meeting_types_callback() {
        echo '<p>Definiere verschiedene Meeting-Typen mit unterschiedlichen Dauern.</p>';
    }
    
    public function field_caldav_url() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['caldav_url'] ?? '';
        echo '<input type="url" name="caldav_booking_options[caldav_url]" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://mail.example.com/SOGo/dav/" />';
    }
    
    public function field_caldav_username() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['caldav_username'] ?? '';
        echo '<input type="text" name="caldav_booking_options[caldav_username]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function field_caldav_password() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['caldav_password'] ?? '';
        echo '<input type="password" name="caldav_booking_options[caldav_password]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function field_caldav_calendar() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['caldav_calendar'] ?? 'Calendar/personal/';
        echo '<input type="text" name="caldav_booking_options[caldav_calendar]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Pfad zum Kalender (z.B. Calendar/personal/)</p>';
    }

    public function field_caldav_timezone() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['caldav_timezone'] ?? '';
        $wp_tz = wp_timezone_string();

        // Common European timezones
        $timezones = [
            '' => 'WordPress-Zeitzone verwenden (' . esc_html($wp_tz) . ')',
            'Europe/Vienna' => 'Europe/Vienna (Österreich)',
            'Europe/Berlin' => 'Europe/Berlin (Deutschland)',
            'Europe/Zurich' => 'Europe/Zurich (Schweiz)',
            'Europe/London' => 'Europe/London (UK)',
            'Europe/Paris' => 'Europe/Paris (Frankreich)',
            'Europe/Amsterdam' => 'Europe/Amsterdam (Niederlande)',
            'Europe/Rome' => 'Europe/Rome (Italien)',
            'Europe/Madrid' => 'Europe/Madrid (Spanien)',
            'Europe/Warsaw' => 'Europe/Warsaw (Polen)',
            'Europe/Prague' => 'Europe/Prague (Tschechien)',
            'UTC' => 'UTC',
        ];

        echo '<select name="caldav_booking_options[caldav_timezone]">';
        foreach ($timezones as $tz => $label) {
            $selected = ($value === $tz) ? ' selected' : '';
            echo '<option value="' . esc_attr($tz) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Zeitzone für Kalendereinträge (falls abweichend von WordPress)</p>';
    }

    public function field_availability_keyword() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['availability_keyword'] ?? 'VERFÜGBAR';
        echo '<input type="text" name="caldav_booking_options[availability_keyword]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Termine mit diesem Wort im Titel werden als buchbare Zeitfenster erkannt</p>';
    }
    
    public function field_availability_keywords_extra() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['availability_keywords_extra'] ?? '';
        echo '<input type="text" name="caldav_booking_options[availability_keywords_extra]" value="' . esc_attr($value) . '" class="regular-text" placeholder="BOOKABLE, FREI, Available" />';
        echo '<p class="description">Zusätzliche Keywords, kommagetrennt (optional)</p>';
    }
    
    public function field_meeting_types() {
        $options = get_option('caldav_booking_options', []);
        
        // Nur Default verwenden wenn meeting_types gar nicht existiert
        if (!isset($options['meeting_types']) || !is_array($options['meeting_types'])) {
            $meeting_types = [
                ['name' => 'Kurzes Gespräch', 'duration' => 15, 'description' => '', 'color' => '#28a745'],
                ['name' => 'Standardtermin', 'duration' => 30, 'description' => '', 'color' => '#0073aa'],
                ['name' => 'Ausführliche Beratung', 'duration' => 60, 'description' => '', 'color' => '#6f42c1']
            ];
        } else {
            $meeting_types = $options['meeting_types'];
        }
        
        // Wenn leer, mindestens einen Standard
        if (empty($meeting_types)) {
            $meeting_types = [
                ['name' => 'Standardtermin', 'duration' => 60, 'description' => '', 'color' => '#0073aa']
            ];
        }
        
        echo '<div id="meeting-types-container">';
        $durations = [15, 30, 45, 60, 90, 120];
        
        foreach ($meeting_types as $index => $type) {
            echo '<div class="meeting-type-row" style="display:flex;gap:10px;margin-bottom:10px;align-items:flex-start;padding:10px;background:#f9f9f9;border-radius:4px;">';
            echo '<input type="text" name="caldav_booking_options[meeting_types][' . $index . '][name]" value="' . esc_attr($type['name']) . '" placeholder="Name" style="width:150px;" />';
            echo '<select name="caldav_booking_options[meeting_types][' . $index . '][duration]" style="height:28px;">';
            foreach ($durations as $d) {
                $selected = ($type['duration'] == $d) ? 'selected' : '';
                echo "<option value=\"$d\" $selected>{$d} Min</option>";
            }
            echo '</select>';
            echo '<textarea name="caldav_booking_options[meeting_types][' . $index . '][description]" placeholder="Beschreibung" style="width:250px;height:60px;resize:vertical;">' . esc_textarea($type['description'] ?? '') . '</textarea>';
            echo '<input type="color" name="caldav_booking_options[meeting_types][' . $index . '][color]" value="' . esc_attr($type['color'] ?? '#0073aa') . '" style="height:28px;" />';
            echo '<button type="button" class="button remove-meeting-type" style="height:28px;">✕</button>';
            echo '</div>';
        }
        
        echo '</div>';
        echo '<button type="button" class="button" id="add-meeting-type">+ Meeting-Typ hinzufügen</button>';
        
        // Debug-Info anzeigen
        echo '<p class="description" style="margin-top:10px;">Aktuell gespeichert: ' . count($meeting_types) . ' Meeting-Typ(en)</p>';
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            var typeIndex = <?php echo count($meeting_types); ?>;
            
            $('#add-meeting-type').on('click', function() {
                var html = '<div class="meeting-type-row" style="display:flex;gap:10px;margin-bottom:10px;align-items:flex-start;padding:10px;background:#f9f9f9;border-radius:4px;">' +
                    '<input type="text" name="caldav_booking_options[meeting_types][' + typeIndex + '][name]" placeholder="Name" style="width:150px;" />' +
                    '<select name="caldav_booking_options[meeting_types][' + typeIndex + '][duration]" style="height:28px;">' +
                    '<option value="15">15 Min</option><option value="30" selected>30 Min</option><option value="45">45 Min</option>' +
                    '<option value="60">60 Min</option><option value="90">90 Min</option><option value="120">120 Min</option></select>' +
                    '<textarea name="caldav_booking_options[meeting_types][' + typeIndex + '][description]" placeholder="Beschreibung" style="width:250px;height:60px;resize:vertical;"></textarea>' +
                    '<input type="color" name="caldav_booking_options[meeting_types][' + typeIndex + '][color]" value="#0073aa" style="height:28px;" />' +
                    '<button type="button" class="button remove-meeting-type" style="height:28px;">✕</button></div>';
                $('#meeting-types-container').append(html);
                typeIndex++;
            });
            
            $(document).on('click', '.remove-meeting-type', function() {
                $(this).closest('.meeting-type-row').remove();
            });
        });
        </script>
        <?php
    }
    
    public function field_days_ahead() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['booking_days_ahead'] ?? 14;
        echo '<input type="number" name="caldav_booking_options[booking_days_ahead]" value="' . esc_attr($value) . '" min="1" max="90" />';
    }
    
    public function field_slot_buffer() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['slot_buffer'] ?? 15;
        echo '<input type="number" name="caldav_booking_options[slot_buffer]" value="' . esc_attr($value) . '" min="0" max="60" step="5" /> Minuten';
        echo '<p class="description">Pause zwischen zwei Terminen</p>';
    }
    
    public function field_min_notice() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['min_notice'] ?? 2;
        echo '<input type="number" name="caldav_booking_options[min_notice]" value="' . esc_attr($value) . '" min="0" max="72" /> Stunden';
        echo '<p class="description">Mindestens X Stunden im Voraus buchbar</p>';
    }

    public function field_submit_button_id() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['submit_button_id'] ?? '';
        echo '<input type="text" name="caldav_booking_options[submit_button_id]" value="' . esc_attr($value) . '" placeholder="z.B. booking-submit-btn" />';
        echo '<p class="description">ID-Attribut für den "Termin buchen"-Button</p>';
    }

    public function field_rate_limit_ip() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['rate_limit_ip'] ?? 5;
        echo '<input type="number" name="caldav_booking_options[rate_limit_ip]" value="' . esc_attr($value) . '" min="1" max="100" /> pro Stunde';
        echo '<p class="description">Max. Buchungen pro IP-Adresse pro Stunde</p>';
    }

    public function field_rate_limit_global() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['rate_limit_global'] ?? 50;
        echo '<input type="number" name="caldav_booking_options[rate_limit_global]" value="' . esc_attr($value) . '" min="1" max="1000" /> pro Stunde';
        echo '<p class="description">Max. Buchungen gesamt pro Stunde (Schutz gegen verteilte Angriffe)</p>';
    }

    public function field_required_fields() {
        $options = get_option('caldav_booking_options', []);
        $required = $options['required_fields'] ?? ['email'];

        $fields = [
            'email' => 'E-Mail',
            'phone' => 'Telefon',
            'message' => 'Nachricht'
        ];

        echo '<fieldset>';
        echo '<label style="display:block;margin-bottom:5px;color:#666;"><input type="checkbox" disabled checked /> Name (immer Pflicht)</label>';
        foreach ($fields as $key => $label) {
            $checked = in_array($key, $required) ? 'checked' : '';
            echo '<label style="display:block;margin-bottom:5px;">';
            echo '<input type="checkbox" name="caldav_booking_options[required_fields][]" value="' . esc_attr($key) . '" ' . $checked . ' /> ';
            echo esc_html($label);
            echo '</label>';
        }
        echo '</fieldset>';
        echo '<p class="description">Wähle welche Felder Pflichtfelder sein sollen</p>';
    }

    public function field_async_processing() {
        $options = get_option('caldav_booking_options', []);
        $checked = !empty($options['async_processing']) ? 'checked' : '';
        $debug_on = !empty($options['console_debug']);

        echo '<label>';
        echo '<input type="checkbox" name="caldav_booking_options[async_processing]" value="1" ' . $checked . ' /> ';
        echo 'E-Mail und CalDAV asynchron verarbeiten';
        echo '</label>';
        echo '<p class="description">Wenn aktiv: Antwort wird sofort gesendet, E-Mail/CalDAV im Hintergrund verarbeitet.</p>';
        if ($debug_on) {
            echo '<p class="description" style="color:#d63638;"><strong>Hinweis:</strong> Debug-Modus ist aktiv - Verarbeitung erfolgt immer synchron!</p>';
        }
    }

    public function section_custom_css_callback() {
        echo '<p>Eigenes CSS um das Buchungsformular an dein Theme anzupassen.</p>';
    }
    
    public function field_custom_css() {
        $options = get_option('caldav_booking_options', []);
        $value = $options['custom_css'] ?? '';
        
        echo '<textarea name="caldav_booking_options[custom_css]" rows="12" style="width:100%;max-width:600px;font-family:monospace;font-size:13px;">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description">Verfügbare CSS-Klassen:</p>';
        echo '<div style="background:#f5f5f5;padding:10px;border-radius:4px;font-family:monospace;font-size:12px;margin-top:10px;">';
        echo '<strong>Container:</strong> .caldav-booking-container, .caldav-booking-title<br>';
        echo '<strong>Schritte:</strong> .caldav-step, .caldav-step-active<br>';
        echo '<strong>Meeting-Typen:</strong> .caldav-meeting-types, .caldav-type-btn, .caldav-type-btn.selected<br>';
        echo '<strong>Datum:</strong> .caldav-date-picker, .caldav-date-btn, .caldav-date-btn.selected, .caldav-nav-btn<br>';
        echo '<strong>Zeitslots:</strong> .caldav-slots, .caldav-slot-btn, .caldav-slot-btn.selected<br>';
        echo '<strong>Formular:</strong> .caldav-booking-form, .caldav-form-group, .caldav-submit-btn, .caldav-back-btn<br>';
        echo '<strong>Bestätigung:</strong> .caldav-confirmation, .caldav-success-icon<br>';
        echo '</div>';
        
        echo '<p class="description" style="margin-top:15px;"><strong>Beispiel:</strong></p>';
        echo '<pre style="background:#f5f5f5;padding:10px;border-radius:4px;font-size:12px;overflow-x:auto;">';
        echo esc_html('.caldav-booking-container {
    max-width: 500px;
}

.caldav-submit-btn {
    background: #28a745;
}

.caldav-date-btn.selected,
.caldav-slot-btn.selected {
    background: #28a745;
    border-color: #28a745;
}');
        echo '</pre>';
    }
    
    public function section_email_callback() {
        echo '<p>Einstellungen für E-Mail-Benachrichtigungen.</p>';
    }
    
    public function field_email_customer() {
        $options = get_option('caldav_booking_options', []);
        $checked = isset($options['email_customer']) ? $options['email_customer'] : 1;
        echo '<label><input type="checkbox" name="caldav_booking_options[email_customer]" value="1" ' . checked($checked, 1, false) . ' /> ';
        echo 'Bestätigungs-E-Mail an Kunden senden</label>';
    }
    
    public function field_email_admin() {
        $options = get_option('caldav_booking_options', []);
        $checked = isset($options['email_admin']) ? $options['email_admin'] : 1;
        echo '<label><input type="checkbox" name="caldav_booking_options[email_admin]" value="1" ' . checked($checked, 1, false) . ' /> ';
        echo 'Benachrichtigung an Admin senden</label>';
        echo '<p class="description">Bei neuen Buchungen und bei Kalender-Fehlern</p>';
    }
    
    public function field_email_debug() {
        $options = get_option('caldav_booking_options', []);
        $checked = $options['email_debug'] ?? 0;
        echo '<label><input type="checkbox" name="caldav_booking_options[email_debug]" value="1" ' . checked($checked, 1, false) . ' /> ';
        echo 'E-Mail-Fehler protokollieren</label>';
        echo '<p class="description">Schreibt E-Mail-Debug-Infos in <code>wp-content/caldav-email-debug.log</code></p>';
        
        // Log-Datei anzeigen wenn vorhanden
        $log_file = WP_CONTENT_DIR . '/caldav-email-debug.log';
        if (file_exists($log_file)) {
            $log_size = filesize($log_file);
            $log_content = file_get_contents($log_file);
            $log_lines = array_slice(explode("\n", $log_content), -20); // Letzte 20 Zeilen
            
            echo '<div style="margin-top:15px;">';
            echo '<strong>Log-Datei:</strong> ' . size_format($log_size) . ' ';
            echo '<a href="' . admin_url('options-general.php?page=caldav-booking&clear_email_log=1') . '" class="button button-small">Log löschen</a>';
            echo '<pre style="background:#1e1e1e;color:#d4d4d4;padding:10px;max-height:200px;overflow:auto;font-size:11px;margin-top:10px;">';
            echo esc_html(implode("\n", $log_lines));
            echo '</pre></div>';
        }
    }

    public function field_console_debug() {
        $options = get_option('caldav_booking_options', []);
        $checked = $options['console_debug'] ?? 0;
        echo '<label><input type="checkbox" name="caldav_booking_options[console_debug]" value="1" ' . checked($checked, 1, false) . ' /> ';
        echo 'Debug-Ausgaben in Browser-Konsole</label>';
        echo '<p class="description">Zeigt Debug-Meldungen in der Browser-Entwicklerkonsole (F12)</p>';
    }

    public function section_email_templates_callback() {
        echo '<p>Passen Sie die E-Mail-Texte an. Verfügbare Platzhalter:</p>';
        echo '<code>{name}</code> Kundenname, ';
        echo '<code>{email}</code> E-Mail, ';
        echo '<code>{phone}</code> Telefon, ';
        echo '<code>{date}</code> Datum, ';
        echo '<code>{time}</code> Uhrzeit, ';
        echo '<code>{end_time}</code> Endzeit, ';
        echo '<code>{type}</code> Terminart, ';
        echo '<code>{message}</code> Kundennachricht, ';
        echo '<code>{site_name}</code> Website-Name';
    }
    
    public function field_email_subject_customer() {
        $options = get_option('caldav_booking_options', []);
        $default = 'Terminbestätigung: {type} am {date}';
        $value = $options['email_subject_customer'] ?? $default;
        echo '<input type="text" name="caldav_booking_options[email_subject_customer]" value="' . esc_attr($value) . '" class="large-text" />';
        echo '<p class="description">Standard: <code>' . esc_html($default) . '</code></p>';
    }
    
    public function field_email_body_customer() {
        $options = get_option('caldav_booking_options', []);
        $default = 'Hallo {name},

vielen Dank für Ihre Buchung! Ihr Termin wurde erfolgreich reserviert.

═══════════════════════════════════
TERMINDETAILS
═══════════════════════════════════

Terminart: {type}
Datum:     {date}
Uhrzeit:   {time} - {end_time} Uhr

═══════════════════════════════════

Bei Fragen oder falls Sie den Termin absagen müssen, antworten Sie einfach auf diese E-Mail.

Mit freundlichen Grüßen
{site_name}';
        $value = $options['email_body_customer'] ?? $default;
        echo '<textarea name="caldav_booking_options[email_body_customer]" rows="15" class="large-text code">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description"><a href="#" onclick="document.querySelector(\'[name=\\\'caldav_booking_options[email_body_customer]\\\']\').value = ' . esc_js(json_encode($default)) . '; return false;">Auf Standard zurücksetzen</a></p>';
    }
    
    public function field_email_subject_admin() {
        $options = get_option('caldav_booking_options', []);
        $default = '[Neue Buchung] {type} - {name}';
        $value = $options['email_subject_admin'] ?? $default;
        echo '<input type="text" name="caldav_booking_options[email_subject_admin]" value="' . esc_attr($value) . '" class="large-text" />';
        echo '<p class="description">Standard: <code>' . esc_html($default) . '</code></p>';
    }
    
    public function field_email_body_admin() {
        $options = get_option('caldav_booking_options', []);
        $default = 'Neue Terminbuchung über die Website:

═══════════════════════════════════
KUNDE
═══════════════════════════════════
Name:    {name}
E-Mail:  {email}
Telefon: {phone}

═══════════════════════════════════
TERMIN
═══════════════════════════════════
Art:     {type}
Datum:   {date}
Uhrzeit: {time} - {end_time} Uhr

═══════════════════════════════════
NACHRICHT
═══════════════════════════════════
{message}

✓ Termin wurde automatisch im Kalender eingetragen.';
        $value = $options['email_body_admin'] ?? $default;
        echo '<textarea name="caldav_booking_options[email_body_admin]" rows="15" class="large-text code">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description"><a href="#" onclick="document.querySelector(\'[name=\\\'caldav_booking_options[email_body_admin]\\\']\').value = ' . esc_js(json_encode($default)) . '; return false;">Auf Standard zurücksetzen</a></p>';
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Log löschen
        if (isset($_GET['clear_email_log'])) {
            $log_file = WP_CONTENT_DIR . '/caldav-email-debug.log';
            if (file_exists($log_file)) {
                unlink($log_file);
            }
            echo '<div class="notice notice-success"><p>E-Mail-Log gelöscht.</p></div>';
        }
        
        // E-Mail-Test
        if (isset($_POST['test_email'])) {
            check_admin_referer('caldav_test_email');
            $result = $this->send_test_email();
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>✓ ' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>✗ ' . esc_html($result['message']) . '</p></div>';
            }
        }
        
        if (isset($_POST['test_caldav_connection'])) {
            check_admin_referer('caldav_test_connection');
            $result = $this->test_connection();
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>✓ ' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>✗ ' . esc_html($result['message']) . '</p></div>';
            }
        }
        
        if (isset($_POST['test_availability'])) {
            check_admin_referer('caldav_test_availability');
            $result = $this->test_availability();
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>✓ ' . esc_html($result['message']) . '</p></div>';
                if (!empty($result['slots'])) {
                    echo '<div class="notice notice-info"><p><strong>Gefundene Verfügbarkeits-Blöcke:</strong><br>';
                    foreach ($result['slots'] as $slot) {
                        echo esc_html($slot) . '<br>';
                    }
                    echo '</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning"><p>' . esc_html($result['message']) . '</p></div>';
            }
        }
        
        // Pending Bookings anzeigen
        $pending_bookings = $this->get_pending_bookings();
        if (!empty($pending_bookings)) {
            echo '<div class="notice notice-warning"><p><strong>Ausstehende Buchungen:</strong> ' . count($pending_bookings) . ' Termin(e) warten auf Kalender-Eintragung.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>CalDAV Booking Einstellungen</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('caldav_booking_settings');
                do_settings_sections('caldav-booking');
                submit_button('Einstellungen speichern');
                ?>
            </form>
            
            <hr />
            
            <h2>Tests</h2>
            <form method="post" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field('caldav_test_connection'); ?>
                <button type="submit" name="test_caldav_connection" class="button">CalDAV-Verbindung testen</button>
            </form>
            <form method="post" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field('caldav_test_availability'); ?>
                <button type="submit" name="test_availability" class="button">Verfügbarkeiten prüfen</button>
            </form>
            <form method="post" style="display:inline-block;">
                <?php wp_nonce_field('caldav_test_email'); ?>
                <button type="submit" name="test_email" class="button">Test-E-Mail senden</button>
            </form>
            
            <hr />
            
            <h2>Shortcode</h2>
            <code>[caldav_booking]</code>
            <p>Optionen: <code>[caldav_booking title="Termin" days="7" type="Beratung"]</code></p>
        </div>
        <?php
    }
    
    public function enqueue_scripts() {
        // Scripts werden jetzt im Shortcode geladen
    }
    
    private function enqueue_booking_scripts() {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;
        
        $cache_bust = CALDAV_BOOKING_VERSION . '.' . time(); // Force fresh load
        
        wp_enqueue_style('caldav-booking', CALDAV_BOOKING_PLUGIN_URL . 'assets/booking.css', [], $cache_bust);
        wp_enqueue_script('caldav-booking', CALDAV_BOOKING_PLUGIN_URL . 'assets/booking.js', ['jquery'], $cache_bust, true);
        
        $options = get_option('caldav_booking_options', []);
        
        // Add custom CSS if set
        if (!empty($options['custom_css'])) {
            wp_add_inline_style('caldav-booking', $options['custom_css']);
        }
        
        wp_localize_script('caldav-booking', 'caldavBooking', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('caldav_booking_nonce'),
            'meetingTypes' => $options['meeting_types'] ?? [],
            'debug' => !empty($options['console_debug']),
            'emailEnabled' => !empty($options['email_customer'])
        ]);
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_caldav-booking') {
            return;
        }
        wp_enqueue_style('caldav-booking-admin', CALDAV_BOOKING_PLUGIN_URL . 'assets/admin.css', [], CALDAV_BOOKING_VERSION);
    }
    
    public function render_booking_form($atts) {
        // Enqueue scripts when shortcode is used
        $this->enqueue_booking_scripts();
        
        $atts = shortcode_atts([
            'title' => 'Termin buchen',
            'days' => null,
            'type' => null
        ], $atts);
        
        $options = get_option('caldav_booking_options', []);
        $days_ahead = $atts['days'] ? absint($atts['days']) : ($options['booking_days_ahead'] ?? 14);
        
        // Meeting types aus den gespeicherten Optionen laden
        $meeting_types = [];
        if (isset($options['meeting_types']) && is_array($options['meeting_types']) && !empty($options['meeting_types'])) {
            $meeting_types = $options['meeting_types'];
        } else {
            // Fallback auf Defaults nur wenn wirklich nichts gespeichert ist
            $meeting_types = [
                ['name' => 'Kurzes Gespräch', 'duration' => 15, 'description' => '', 'color' => '#28a745'],
                ['name' => 'Standardtermin', 'duration' => 30, 'description' => '', 'color' => '#0073aa'],
                ['name' => 'Ausführliche Beratung', 'duration' => 60, 'description' => '', 'color' => '#6f42c1']
            ];
        }
        
        if ($atts['type']) {
            $meeting_types = array_filter($meeting_types, function($t) use ($atts) {
                return stripos($t['name'], $atts['type']) !== false;
            });
            $meeting_types = array_values($meeting_types);
        }
        
        ob_start();
        ?>
        <div class="caldav-booking-container" data-days="<?php echo esc_attr($days_ahead); ?>">
            <h3 class="caldav-booking-title"><?php echo esc_html($atts['title']); ?></h3>
            
            <div class="caldav-booking-steps">
                <div class="caldav-step caldav-step-active" data-step="0">
                    <h4 class="caldav-step-title-types">1. Terminart wählen</h4>
                    <div class="caldav-meeting-types">
                        <p class="caldav-loading-types"><span class="caldav-spinner"></span> Lade Terminarten...</p>
                    </div>
                </div>
                
                <div class="caldav-step" data-step="1">
                    <h4 class="caldav-step-title-date">2. Datum wählen</h4>
                    <div class="caldav-date-picker">
                        <button type="button" class="caldav-nav-btn caldav-prev-week" disabled>&laquo;</button>
                        <div class="caldav-dates"></div>
                        <button type="button" class="caldav-nav-btn caldav-next-week">&raquo;</button>
                    </div>
                    <button type="button" class="caldav-back-btn caldav-back-to-types" data-to="0">&laquo; Zurück</button>
                </div>
                
                <div class="caldav-step" data-step="2">
                    <h4 class="caldav-step-title-time">3. Uhrzeit wählen</h4>
                    <div class="caldav-slots-loading" style="display:none;">
                        <span class="caldav-spinner"></span> Lade verfügbare Zeiten...
                    </div>
                    <div class="caldav-no-slots" style="display:none;">
                        <p>Keine verfügbaren Termine an diesem Tag.</p>
                    </div>
                    <div class="caldav-slots"></div>
                    <button type="button" class="caldav-back-btn" data-to="1">&laquo; Zurück</button>
                </div>
                
                <div class="caldav-step" data-step="3">
                    <h4 class="caldav-step-title-contact">4. Kontaktdaten</h4>
                    <?php
                    $required_fields = $options['required_fields'] ?? ['email'];
                    $email_required = in_array('email', $required_fields);
                    $phone_required = in_array('phone', $required_fields);
                    $message_required = in_array('message', $required_fields);
                    ?>
                    <form class="caldav-booking-form">
                        <!-- Honeypot - hidden from humans, bots will fill it -->
                        <div style="position:absolute;left:-9999px;" aria-hidden="true">
                            <label for="caldav-website">Website</label>
                            <input type="text" id="caldav-website" name="website" tabindex="-1" autocomplete="off" />
                        </div>
                        <div class="caldav-form-group">
                            <label for="caldav-name">Name *</label>
                            <input type="text" id="caldav-name" name="name" required />
                        </div>
                        <div class="caldav-form-group">
                            <label for="caldav-email">E-Mail<?php echo $email_required ? ' *' : ''; ?></label>
                            <input type="email" id="caldav-email" name="email"<?php echo $email_required ? ' required' : ''; ?> />
                        </div>
                        <div class="caldav-form-group">
                            <label for="caldav-phone">Telefon<?php echo $phone_required ? ' *' : ''; ?></label>
                            <input type="tel" id="caldav-phone" name="phone"<?php echo $phone_required ? ' required' : ''; ?> />
                        </div>
                        <div class="caldav-form-group">
                            <label for="caldav-message">Nachricht<?php echo $message_required ? ' *' : ''; ?></label>
                            <textarea id="caldav-message" name="message" rows="3"<?php echo $message_required ? ' required' : ''; ?>></textarea>
                        </div>
                        <div class="caldav-selected-slot"></div>
                        <div class="caldav-form-buttons">
                            <button type="button" class="caldav-back-btn" data-to="2">&laquo; Zurück</button>
                            <button type="submit" class="caldav-submit-btn"<?php echo !empty($options['submit_button_id']) ? ' id="' . esc_attr($options['submit_button_id']) . '"' : ''; ?>>Termin buchen</button>
                        </div>
                    </form>
                </div>
                
                <div class="caldav-step" data-step="4">
                    <div class="caldav-confirmation">
                        <div class="caldav-success-icon">✓</div>
                        <h4>Termin gebucht!</h4>
                        <p class="caldav-confirmation-details"></p>
                        <button type="button" class="caldav-new-booking-btn">Neuen Termin buchen</button>
                    </div>
                </div>
            </div>
            
            <div class="caldav-error-message" style="display:none;"></div>
        </div>
        
        <?php
        return ob_get_clean();
    }
    
    public function ajax_get_slots() {
        check_ajax_referer('caldav_booking_nonce', 'nonce');
        
        $date = sanitize_text_field($_POST['date'] ?? '');
        $duration = absint($_POST['duration'] ?? 60);
        
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(['message' => 'Ungültiges Datum']);
        }
        
        $options = get_option('caldav_booking_options', []);
        $caldav = new CalDAV_Client($options);
        
        $events = $caldav->get_events_for_date($date);
        
        if (is_wp_error($events)) {
            wp_send_json_error(['message' => $events->get_error_message()]);
        }
        
        $available_slots = $this->calculate_available_slots($date, $events, $duration, $options);
        
        wp_send_json_success(['slots' => $available_slots]);
    }
    
    public function ajax_get_meeting_types() {
        check_ajax_referer('caldav_booking_nonce', 'nonce');
        
        // Force fresh read from database (bypass object cache)
        wp_cache_delete('caldav_booking_options', 'options');
        $options = get_option('caldav_booking_options', []);
        $meeting_types = $options['meeting_types'] ?? [];
        
        // Fallback wenn keine Meeting-Types gesetzt
        if (empty($meeting_types)) {
            $meeting_types = [
                ['name' => 'Standardtermin', 'duration' => 60, 'description' => '', 'color' => '#0073aa']
            ];
        }
        
        wp_send_json_success(['types' => $meeting_types]);
    }
    
    public function ajax_check_dates() {
        check_ajax_referer('caldav_booking_nonce', 'nonce');
        
        $dates = isset($_POST['dates']) ? array_map('sanitize_text_field', $_POST['dates']) : [];
        $duration = absint($_POST['duration'] ?? 60);
        
        if (empty($dates)) {
            wp_send_json_error(['message' => 'Keine Daten angegeben']);
        }
        
        $options = get_option('caldav_booking_options', []);
        $caldav = new CalDAV_Client($options);
        
        // Datumsbereich ermitteln
        sort($dates);
        $start_date = reset($dates);
        $end_date = end($dates);
        
        // Alle Events für den gesamten Zeitraum in EINEM Request holen
        $all_events = $caldav->get_events_for_range($start_date, $end_date);
        
        $availability = [];
        
        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            
            if (is_wp_error($all_events)) {
                $availability[$date] = ['available' => false, 'slots' => 0];
                continue;
            }
            
            // Events für dieses spezifische Datum filtern
            $day_events = $this->filter_events_for_date($all_events, $date);
            
            $slots = $this->calculate_available_slots($date, $day_events, $duration, $options);
            $availability[$date] = [
                'available' => count($slots) > 0,
                'slots' => count($slots)
            ];
        }
        
        wp_send_json_success(['availability' => $availability]);
    }
    
    private function filter_events_for_date($events, $date) {
        $filtered = [];
        $date_start = $date . 'T00:00:00';
        $date_end = $date . 'T23:59:59';
        
        foreach ($events as $event) {
            $event_start = $event['start'] ?? '';
            $event_end = $event['end'] ?? '';
            
            // Event überschneidet sich mit diesem Tag
            if ($event_start <= $date_end && $event_end >= $date_start) {
                $filtered[] = $event;
            }
        }
        
        return $filtered;
    }
    
    public function ajax_book_slot() {
        check_ajax_referer('caldav_booking_nonce', 'nonce');

        $options = get_option('caldav_booking_options', []);
        $rate_limit_ip = absint($options['rate_limit_ip'] ?? 5);
        $rate_limit_global = absint($options['rate_limit_global'] ?? 50);

        // Globales Rate-Limit (Schutz gegen verteilte Angriffe)
        $global_rate_key = 'caldav_global_rate';
        $global_count = (int) get_transient($global_rate_key);
        if ($global_count >= $rate_limit_global) {
            wp_send_json_error(['message' => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.']);
        }

        // Rate-Limit pro IP
        $ip = $this->get_client_ip();
        $rate_key = 'caldav_rate_' . md5($ip);
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= $rate_limit_ip) {
            wp_send_json_error(['message' => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.']);
        }

        // Honeypot check - if filled, it's a bot
        if (!empty($_POST['website'])) {
            // Silently reject but return success to confuse bots
            wp_send_json_success([
                'message' => 'Termin erfolgreich gebucht!',
                'date' => date('d.m.Y'),
                'time' => '00:00 - 00:00',
                'type' => 'Termin',
                'booking_id' => 'blocked'
            ]);
        }
        
        $date = sanitize_text_field($_POST['date'] ?? '');
        $time = sanitize_text_field($_POST['time'] ?? '');
        $duration = absint($_POST['duration'] ?? 60);
        $meeting_type = sanitize_text_field($_POST['meeting_type'] ?? 'Termin');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        // Get required fields from settings
        $required_fields = $options['required_fields'] ?? ['email'];

        // Validierung - Name ist immer Pflicht
        if (!$date || !$time || !$name) {
            wp_send_json_error(['message' => 'Bitte alle Pflichtfelder ausfüllen']);
        }

        // Check configurable required fields
        if (in_array('email', $required_fields) && empty($email)) {
            wp_send_json_error(['message' => 'Bitte E-Mail-Adresse angeben']);
        }
        if (in_array('phone', $required_fields) && empty($phone)) {
            wp_send_json_error(['message' => 'Bitte Telefonnummer angeben']);
        }
        if (in_array('message', $required_fields) && empty($message)) {
            wp_send_json_error(['message' => 'Bitte Nachricht angeben']);
        }

        // Date format validation
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(['message' => 'Ungültiges Datumsformat']);
        }

        // Time format validation
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            wp_send_json_error(['message' => 'Ungültiges Zeitformat']);
        }

        // Email validation (only if provided)
        if (!empty($email) && !is_email($email)) {
            wp_send_json_error(['message' => 'Ungültige E-Mail-Adresse']);
        }
        
        // Duration validation (nur erlaubte Werte)
        $allowed_durations = [15, 30, 45, 60, 90, 120];
        if (!in_array($duration, $allowed_durations)) {
            $duration = 60;
        }
        
        // Datum darf nicht in der Vergangenheit liegen
        $cal_tz = $this->get_calendar_timezone();
        $booking_date = new DateTime($date . ' ' . $time, $cal_tz);
        $now = new DateTime('now', $cal_tz);
        if ($booking_date < $now) {
            wp_send_json_error(['message' => 'Termine in der Vergangenheit können nicht gebucht werden']);
        }
        
        // Atomarer Slot-Lock mit Datenbank-Lock
        $slot_key = 'caldav_slot_' . $date . '_' . str_replace(':', '', $time);
        
        global $wpdb;
        
        // Transaktion starten für atomare Operation
        $wpdb->query('START TRANSACTION');
        
        // Lock mit SELECT FOR UPDATE (row-level lock)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
            '_transient_' . $slot_key
        ));
        
        if ($existing) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'Dieser Termin wurde gerade von jemand anderem gebucht. Bitte wähle einen anderen Zeitpunkt.']);
        }
        
        // Slot reservieren (15 Minuten für langsame Server)
        set_transient($slot_key, [
            'name' => $name,
            'email' => $email,
            'time' => time()
        ], 900);
        
        $wpdb->query('COMMIT');

        // Rate limits erhöhen
        set_transient($rate_key, $rate_count + 1, HOUR_IN_SECONDS);
        set_transient($global_rate_key, $global_count + 1, HOUR_IN_SECONDS);
        $tz = $this->get_calendar_timezone();
        $start = new DateTime($date . ' ' . $time, $tz);
        $end = clone $start;
        $end->modify("+{$duration} minutes");
        
        // Buchung in Queue speichern
        $booking_id = uniqid('booking_', true);
        $booking_data = [
            'id' => $booking_id,
            'date' => $date,
            'time' => $time,
            'duration' => $duration,
            'meeting_type' => $meeting_type,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
            'status' => 'pending',
            'created' => time(),
            'slot_key' => $slot_key,
            'ip' => $ip
        ];
        
        // Cache für dieses Datum invalidieren
        $base_url = rtrim($options['caldav_url'] ?? '', '/');
        $username = $options['caldav_username'] ?? '';
        $calendar_path = trim($options['caldav_calendar'] ?? 'Calendar/personal/', '/');
        $calendar_url = $base_url . '/' . $username . '/' . $calendar_path . '/';
        $cache_key = 'caldav_events_' . md5($calendar_url . $date);
        delete_transient($cache_key);
        
        // In Datenbank speichern
        $this->save_booking($booking_data);

        $debug_mode = !empty($options['console_debug']);
        $async_mode = !empty($options['async_processing']) && !$debug_mode;

        if ($debug_mode) {
            // DEBUG: Synchron verarbeiten und alles loggen
            $debug_log = [];
            $debug_log[] = ['step' => 'booking_saved', 'booking_id' => $booking_id];

            $result = $this->process_booking_with_debug($booking_id, $debug_log);

            wp_send_json_success([
                'message' => 'Termin erfolgreich gebucht!',
                'date' => $start->format('d.m.Y'),
                'time' => $start->format('H:i') . ' - ' . $end->format('H:i'),
                'type' => $meeting_type,
                'booking_id' => $booking_id,
                'debug' => $debug_log,
                'caldav_result' => $result
            ]);
        } elseif ($async_mode) {
            // ASYNC: Antwort sofort senden, im Hintergrund verarbeiten
            ignore_user_abort(true);
            $response = wp_json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Termin erfolgreich gebucht!',
                    'date' => $start->format('d.m.Y'),
                    'time' => $start->format('H:i') . ' - ' . $end->format('H:i'),
                    'type' => $meeting_type,
                    'booking_id' => $booking_id
                ]
            ]);

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($response));
            echo $response;

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                flush();
                if (function_exists('ob_flush')) {
                    ob_flush();
                }
            }

            $this->process_booking_async($booking_id);
            exit;
        } else {
            // SYNC: Synchron verarbeiten ohne Debug-Logging
            $this->process_booking_async($booking_id);

            wp_send_json_success([
                'message' => 'Termin erfolgreich gebucht!',
                'date' => $start->format('d.m.Y'),
                'time' => $start->format('H:i') . ' - ' . $end->format('H:i'),
                'type' => $meeting_type,
                'booking_id' => $booking_id
            ]);
        }
    }

    public function debug_file_log($message) {
        $log_file = WP_CONTENT_DIR . '/caldav-debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    }

    private function process_booking_with_debug($booking_id, &$debug_log) {
        $this->debug_file_log("=== START process_booking_with_debug: $booking_id ===");

        $booking = $this->get_booking($booking_id);

        if (!$booking || $booking['status'] !== 'pending') {
            $this->debug_file_log("ERROR: Booking not found or not pending");
            $debug_log[] = ['step' => 'error', 'message' => 'Booking not found or not pending', 'status' => $booking['status'] ?? 'null'];
            return ['success' => false, 'error' => 'Booking not found'];
        }

        $this->debug_file_log("Booking loaded: " . $booking['name']);
        $debug_log[] = ['step' => 'booking_loaded', 'data' => $booking];

        // E-Mail senden
        if (empty($booking['email_sent'])) {
            $this->debug_file_log("Sending email to: " . $booking['email']);
            $debug_log[] = ['step' => 'sending_email', 'to' => $booking['email']];
            $email_result = $this->send_customer_confirmation($booking);
            $this->debug_file_log("Email result: " . ($email_result ? 'success' : 'failed'));
            $debug_log[] = ['step' => 'email_sent', 'result' => $email_result];
            $this->update_booking_status($booking_id, 'pending', ['email_sent' => true]);
        }

        $options = get_option('caldav_booking_options', []);
        $tz = $this->get_calendar_timezone();

        $start = new DateTime($booking['start'], $tz);
        $end = new DateTime($booking['end'], $tz);

        $event_data = [
            'summary' => $booking['meeting_type'] . ': ' . $booking['name'],
            'description' => "Terminart: {$booking['meeting_type']}\nName: {$booking['name']}\nE-Mail: {$booking['email']}\nTelefon: {$booking['phone']}\n\nNachricht:\n{$booking['message']}",
            'start' => $start,
            'end' => $end,
            'attendee_email' => $booking['email'],
            'attendee_name' => $booking['name']
        ];

        $debug_log[] = ['step' => 'caldav_event_data', 'data' => [
            'summary' => $event_data['summary'],
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s')
        ]];

        $this->debug_file_log("CalDAV URL: " . ($options['caldav_url'] ?? 'not set'));
        $this->debug_file_log("CalDAV User: " . ($options['caldav_username'] ?? 'not set'));
        $debug_log[] = ['step' => 'caldav_connecting', 'url' => $options['caldav_url'] ?? '', 'user' => $options['caldav_username'] ?? ''];

        $caldav = new CalDAV_Client($options);
        $caldav->set_debug_log($debug_log);
        $caldav->set_file_logger([$this, 'debug_file_log']);

        $this->debug_file_log("Calling create_event...");
        $result = $caldav->create_event($event_data);
        $this->debug_file_log("create_event returned");

        if (is_wp_error($result)) {
            $error_msg = $result->get_error_message();
            $debug_log[] = ['step' => 'caldav_error', 'error' => $error_msg];
            $this->update_booking_status($booking_id, 'failed', ['error' => $error_msg]);
            $this->send_admin_error_notification($booking, $error_msg);
            return ['success' => false, 'error' => $error_msg];
        } else {
            $debug_log[] = ['step' => 'caldav_success', 'uid' => $result['uid']];
            $this->update_booking_status($booking_id, 'completed', ['uid' => $result['uid']]);
            delete_transient($booking['slot_key']);
            $this->send_admin_notification($booking);
            return ['success' => true, 'uid' => $result['uid']];
        }
    }
    
    private function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Bei X-Forwarded-For kann es mehrere IPs geben
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
    
    private function trigger_async_cron() {
        // Non-blocking request to trigger cron
        $cron_url = add_query_arg('doing_wp_cron', wp_hash('doing_wp_cron'), site_url('wp-cron.php'));
        
        wp_remote_post($cron_url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false
        ]);
    }
    
    private function save_booking($booking_data) {
        $bookings = get_option('caldav_bookings', []);
        $bookings[$booking_data['id']] = $booking_data;
        
        // Alte Buchungen aufräumen (älter als 24h und erfolgreich)
        $now = time();
        foreach ($bookings as $id => $booking) {
            if ($booking['status'] === 'completed' && ($now - $booking['created']) > 86400) {
                unset($bookings[$id]);
            }
            // Fehlgeschlagene nach 1h löschen
            if ($booking['status'] === 'failed' && ($now - $booking['created']) > 3600) {
                unset($bookings[$id]);
            }
        }
        
        update_option('caldav_bookings', $bookings);
    }
    
    private function get_booking($booking_id) {
        $bookings = get_option('caldav_bookings', []);
        return $bookings[$booking_id] ?? null;
    }
    
    private function update_booking_status($booking_id, $status, $extra = []) {
        $bookings = get_option('caldav_bookings', []);
        if (isset($bookings[$booking_id])) {
            $bookings[$booking_id]['status'] = $status;
            $bookings[$booking_id] = array_merge($bookings[$booking_id], $extra);
            update_option('caldav_bookings', $bookings);
        }
    }
    
    public function process_booking_async($booking_id) {
        $booking = $this->get_booking($booking_id);
        
        if (!$booking || $booking['status'] !== 'pending') {
            return;
        }
        
        // E-Mail an Kunden senden (falls noch nicht gesendet)
        if (empty($booking['email_sent'])) {
            $this->send_customer_confirmation($booking);
            $this->update_booking_status($booking_id, 'pending', ['email_sent' => true]);
        }
        
        $options = get_option('caldav_booking_options', []);
        $tz = $this->get_calendar_timezone();

        $start = new DateTime($booking['start'], $tz);
        $end = new DateTime($booking['end'], $tz);

        $event_data = [
            'summary' => $booking['meeting_type'] . ': ' . $booking['name'],
            'description' => "Terminart: {$booking['meeting_type']}\nName: {$booking['name']}\nE-Mail: {$booking['email']}\nTelefon: {$booking['phone']}\n\nNachricht:\n{$booking['message']}",
            'start' => $start,
            'end' => $end,
            'attendee_email' => $booking['email'],
            'attendee_name' => $booking['name']
        ];

        $caldav = new CalDAV_Client($options);
        $result = $caldav->create_event($event_data);
        
        if (is_wp_error($result)) {
            $this->update_booking_status($booking_id, 'failed', ['error' => $result->get_error_message()]);
            
            // Admin benachrichtigen
            $this->send_admin_error_notification($booking, $result->get_error_message());
        } else {
            $this->update_booking_status($booking_id, 'completed', ['uid' => $result['uid']]);
            
            // Slot-Lock kann früher freigegeben werden, da jetzt im Kalender
            delete_transient($booking['slot_key']);
            
            // Admin-Benachrichtigung senden
            $this->send_admin_notification($booking);
        }
    }
    
    private function send_customer_confirmation($booking) {
        $options = get_option('caldav_booking_options', []);
        
        // Prüfen ob Kunden-E-Mail aktiviert ist (Standard: ja)
        if (isset($options['email_customer']) && !$options['email_customer']) {
            return true; // Als erfolgreich melden, aber nicht senden
        }
        
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        ];
        
        $tz = new DateTimeZone(wp_timezone_string());
        $start = new DateTime($booking['start'], $tz);
        $end = new DateTime($booking['end'], $tz);
        
        // Platzhalter ersetzen
        $placeholders = $this->get_email_placeholders($booking, $start, $end);
        
        // Subject aus Template oder Standard
        $default_subject = 'Terminbestätigung: {type} am {date}';
        $subject = $options['email_subject_customer'] ?? $default_subject;
        $subject = $this->replace_placeholders($subject, $placeholders);
        
        // Body aus Template oder Standard
        $default_body = 'Hallo {name},

vielen Dank für Ihre Buchung! Ihr Termin wurde erfolgreich reserviert.

═══════════════════════════════════
TERMINDETAILS
═══════════════════════════════════

Terminart: {type}
Datum:     {date}
Uhrzeit:   {time} - {end_time} Uhr

═══════════════════════════════════

Bei Fragen oder falls Sie den Termin absagen müssen, antworten Sie einfach auf diese E-Mail.

Mit freundlichen Grüßen
{site_name}';
        $message = $options['email_body_customer'] ?? $default_body;
        $message = $this->replace_placeholders($message, $placeholders);
        
        // Debug-Logging aktivieren wenn eingestellt
        if (!empty($options['email_debug'])) {
            $this->enable_mail_debug();
        }
        
        $result = wp_mail($booking['email'], $subject, $message, $headers);
        
        if (!empty($options['email_debug'])) {
            $log_file = WP_CONTENT_DIR . '/caldav-email-debug.log';
            $status = $result ? 'GESENDET' : 'FEHLGESCHLAGEN';
            $log_entry = '[' . current_time('Y-m-d H:i:s') . '] KUNDEN-EMAIL ' . $status . ': ' . $booking['email'] . "\n";
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
            $this->disable_mail_debug();
        }
        
        return $result;
    }
    
    private function get_email_placeholders($booking, $start, $end) {
        return [
            '{name}' => $booking['name'],
            '{email}' => $booking['email'],
            '{phone}' => $booking['phone'] ?? '',
            '{date}' => $start->format('d.m.Y'),
            '{time}' => $start->format('H:i'),
            '{end_time}' => $end->format('H:i'),
            '{type}' => $booking['meeting_type'],
            '{message}' => $booking['message'] ?? '',
            '{site_name}' => get_bloginfo('name')
        ];
    }
    
    private function replace_placeholders($text, $placeholders) {
        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }
    
    private function send_admin_notification($booking) {
        $options = get_option('caldav_booking_options', []);
        
        // Prüfen ob Admin-E-Mail aktiviert ist (Standard: ja)
        if (isset($options['email_admin']) && !$options['email_admin']) {
            return;
        }
        
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        ];
        
        $tz = new DateTimeZone(wp_timezone_string());
        $start = new DateTime($booking['start'], $tz);
        $end = new DateTime($booking['end'], $tz);
        
        // Platzhalter ersetzen
        $placeholders = $this->get_email_placeholders($booking, $start, $end);
        
        // Subject aus Template oder Standard
        $default_subject = '[Neue Buchung] {type} - {name}';
        $subject = $options['email_subject_admin'] ?? $default_subject;
        $subject = $this->replace_placeholders($subject, $placeholders);
        
        // Body aus Template oder Standard
        $default_body = 'Neue Terminbuchung über die Website:

═══════════════════════════════════
KUNDE
═══════════════════════════════════
Name:    {name}
E-Mail:  {email}
Telefon: {phone}

═══════════════════════════════════
TERMIN
═══════════════════════════════════
Art:     {type}
Datum:   {date}
Uhrzeit: {time} - {end_time} Uhr

═══════════════════════════════════
NACHRICHT
═══════════════════════════════════
{message}

✓ Termin wurde automatisch im Kalender eingetragen.';
        $message = $options['email_body_admin'] ?? $default_body;
        $message = $this->replace_placeholders($message, $placeholders);
        
        wp_mail($admin_email, $subject, $message, $headers);
    }
    
    private function send_admin_error_notification($booking, $error) {
        $options = get_option('caldav_booking_options', []);
        
        // Fehler-Mails immer senden wenn Admin-Mails aktiviert
        if (isset($options['email_admin']) && !$options['email_admin']) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        ];
        
        $tz = new DateTimeZone(wp_timezone_string());
        $start = new DateTime($booking['start'], $tz);
        
        $subject = '[ACHTUNG] Kalendereintrag fehlgeschlagen - ' . $booking['name'];
        
        $message = "⚠️ ACHTUNG: Ein Termin konnte nicht automatisch im Kalender eingetragen werden!\n\n";
        $message .= "Bitte manuell eintragen:\n\n";
        $message .= "Name:    " . $booking['name'] . "\n";
        $message .= "E-Mail:  " . $booking['email'] . "\n";
        $message .= "Telefon: " . $booking['phone'] . "\n";
        $message .= "Art:     " . $booking['meeting_type'] . "\n";
        $message .= "Datum:   " . $start->format('d.m.Y H:i') . "\n\n";
        $message .= "Fehler: " . $error . "\n";
        
        wp_mail($admin_email, $subject, $message, $headers);
    }
    
    private function calculate_available_slots($date, $events, $duration, $options) {
        $slots = [];
        $buffer = $options['slot_buffer'] ?? 15;
        $min_notice = $options['min_notice'] ?? 2;
        $keywords = $this->get_availability_keywords($options);
        
        $tz = new DateTimeZone(wp_timezone_string());
        $now = new DateTime('now', $tz);
        $min_start = clone $now;
        $min_start->modify("+{$min_notice} hours");
        
        $availability_windows = [];
        $busy_times = [];
        
        foreach ($events as $event) {
            $is_availability = false;
            $summary = strtolower($event['summary'] ?? '');
            
            foreach ($keywords as $keyword) {
                if (strpos($summary, strtolower($keyword)) !== false) {
                    $is_availability = true;
                    break;
                }
            }
            
            if ($is_availability) {
                $availability_windows[] = $event;
            } elseif (empty($event['transparent'])) {
                $busy_times[] = $event;
            }
        }
        
        // Reservierte Slots (pending bookings) als busy hinzufügen
        $reserved = $this->get_reserved_slots_for_date($date);
        $busy_times = array_merge($busy_times, $reserved);
        
        if (empty($availability_windows)) {
            return [];
        }
        
        foreach ($availability_windows as $window) {
            $window_start = new DateTime($window['start'], $tz);
            $window_end = new DateTime($window['end'], $tz);
            
            $current = clone $window_start;
            
            while ($current < $window_end) {
                $slot_end = clone $current;
                $slot_end->modify("+{$duration} minutes");
                
                if ($slot_end > $window_end) {
                    break;
                }
                
                if ($current < $min_start) {
                    $current->modify("+15 minutes");
                    continue;
                }
                
                $is_free = true;
                foreach ($busy_times as $busy) {
                    $busy_start = new DateTime($busy['start'], $tz);
                    $busy_end = new DateTime($busy['end'], $tz);
                    
                    $busy_start->modify("-{$buffer} minutes");
                    $busy_end->modify("+{$buffer} minutes");
                    
                    if ($current < $busy_end && $slot_end > $busy_start) {
                        $is_free = false;
                        break;
                    }
                }
                
                if ($is_free) {
                    $slots[] = [
                        'time' => $current->format('H:i'),
                        'display' => $current->format('H:i') . ' - ' . $slot_end->format('H:i')
                    ];
                }
                
                $current->modify("+15 minutes");
            }
        }
        
        $unique_slots = [];
        foreach ($slots as $slot) {
            $unique_slots[$slot['time']] = $slot;
        }
        ksort($unique_slots);
        
        return array_values($unique_slots);
    }
    
    private function get_availability_keywords($options) {
        $keywords = [$options['availability_keyword'] ?? 'VERFÜGBAR'];
        
        if (!empty($options['availability_keywords_extra'])) {
            $extra = explode(',', $options['availability_keywords_extra']);
            foreach ($extra as $kw) {
                $kw = trim($kw);
                if (!empty($kw)) {
                    $keywords[] = $kw;
                }
            }
        }
        
        return $keywords;
    }
    
    private function get_reserved_slots_for_date($date) {
        $bookings = get_option('caldav_bookings', []);
        $reserved = [];
        
        foreach ($bookings as $booking) {
            // Nur pending Buchungen berücksichtigen
            if ($booking['status'] !== 'pending') {
                continue;
            }
            
            // Prüfen ob Buchung für dieses Datum ist
            if (strpos($booking['start'], $date) === 0) {
                $reserved[] = [
                    'summary' => 'Reserviert',
                    'start' => str_replace(' ', 'T', $booking['start']),
                    'end' => str_replace(' ', 'T', $booking['end']),
                    'transparent' => false
                ];
            }
        }
        
        return $reserved;
    }
    
    private function test_connection() {
        $options = get_option('caldav_booking_options', []);
        $caldav = new CalDAV_Client($options);
        return $caldav->test_connection();
    }
    
    private function send_test_email() {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        ];
        
        $subject = '[CalDAV Booking] Test-E-Mail';
        $message = "Dies ist eine Test-E-Mail vom CalDAV Booking Plugin.\n\n";
        $message .= "Wenn du diese E-Mail siehst, funktioniert der E-Mail-Versand.\n\n";
        $message .= "Zeitstempel: " . current_time('d.m.Y H:i:s') . "\n";
        $message .= "Website: " . home_url() . "\n";
        
        // Debug aktivieren für diesen Test
        $this->enable_mail_debug();
        
        $result = wp_mail($admin_email, $subject, $message, $headers);
        
        $this->disable_mail_debug();
        
        if ($result) {
            return ['success' => true, 'message' => 'Test-E-Mail wurde an ' . $admin_email . ' gesendet. Prüfe dein Postfach (auch Spam-Ordner).'];
        } else {
            return ['success' => false, 'message' => 'E-Mail-Versand fehlgeschlagen. Aktiviere den Debug-Modus für Details.'];
        }
    }
    
    private function get_pending_bookings() {
        $bookings = get_option('caldav_bookings', []);
        return array_filter($bookings, function($b) {
            return $b['status'] === 'pending';
        });
    }
    
    private function enable_mail_debug() {
        add_action('wp_mail_failed', [$this, 'log_mail_error']);
        add_filter('wp_mail', [$this, 'log_mail_attempt']);
    }
    
    private function disable_mail_debug() {
        remove_action('wp_mail_failed', [$this, 'log_mail_error']);
        remove_filter('wp_mail', [$this, 'log_mail_attempt']);
    }
    
    public function log_mail_error($wp_error) {
        $options = get_option('caldav_booking_options', []);
        if (empty($options['email_debug'])) return;
        
        $log_file = WP_CONTENT_DIR . '/caldav-email-debug.log';
        $log_entry = '[' . current_time('Y-m-d H:i:s') . '] FEHLER: ' . $wp_error->get_error_message() . "\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    public function log_mail_attempt($args) {
        $options = get_option('caldav_booking_options', []);
        if (empty($options['email_debug'])) return $args;
        
        $log_file = WP_CONTENT_DIR . '/caldav-email-debug.log';
        $log_entry = '[' . current_time('Y-m-d H:i:s') . '] VERSUCH: An=' . (is_array($args['to']) ? implode(', ', $args['to']) : $args['to']) . ' | Betreff=' . $args['subject'] . "\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        return $args;
    }
    
    private function test_availability() {
        $options = get_option('caldav_booking_options', []);
        $caldav = new CalDAV_Client($options);
        $keywords = $this->get_availability_keywords($options);
        
        $tz = new DateTimeZone(wp_timezone_string());
        $found_slots = [];
        
        for ($i = 0; $i < 7; $i++) {
            $date = new DateTime('now', $tz);
            $date->modify("+{$i} days");
            $date_str = $date->format('Y-m-d');
            
            $events = $caldav->get_events_for_date($date_str);
            
            if (is_wp_error($events)) {
                continue;
            }
            
            foreach ($events as $event) {
                $summary = strtolower($event['summary'] ?? '');
                foreach ($keywords as $keyword) {
                    if (strpos($summary, strtolower($keyword)) !== false) {
                        $start = new DateTime($event['start'], $tz);
                        $end = new DateTime($event['end'], $tz);
                        $found_slots[] = $start->format('d.m.Y H:i') . ' - ' . $end->format('H:i') . ' (' . $event['summary'] . ')';
                        break;
                    }
                }
            }
        }
        
        if (empty($found_slots)) {
            return [
                'success' => false,
                'message' => 'Keine Verfügbarkeits-Termine gefunden. Lege Termine mit "' . implode('" oder "', $keywords) . '" im Titel an.'
            ];
        }
        
        return [
            'success' => true,
            'message' => count($found_slots) . ' Verfügbarkeits-Block(s) gefunden',
            'slots' => $found_slots
        ];
    }
}

class CalDAV_Client {

    private $base_url;
    private $username;
    private $password;
    private $calendar_path;
    private $calendar_url;
    private $debug_log = null;
    private $file_logger = null;

    public function __construct($options) {
        $this->base_url = rtrim($options['caldav_url'] ?? '', '/');
        $this->username = $options['caldav_username'] ?? '';
        $this->password = $options['caldav_password'] ?? '';
        $this->calendar_path = trim($options['caldav_calendar'] ?? 'Calendar/personal/', '/');
        $this->calendar_url = $this->base_url . '/' . $this->username . '/' . $this->calendar_path . '/';
    }

    public function set_debug_log(&$log) {
        $this->debug_log = &$log;
    }

    public function set_file_logger($callback) {
        $this->file_logger = $callback;
    }

    private function file_log($message) {
        if ($this->file_logger !== null) {
            call_user_func($this->file_logger, "[CalDAV_Client] $message");
        }
    }

    private function log_debug($step, $data = null) {
        if ($this->debug_log !== null) {
            $entry = ['step' => $step, 'time' => microtime(true)];
            if ($data !== null) {
                $entry['data'] = $data;
            }
            $this->debug_log[] = $entry;
        }
    }
    
    private function get_calendar_url() {
        return $this->calendar_url;
    }
    
    private function request($method, $url, $body = null, $headers = []) {
        $this->log_debug('request_start', ['method' => $method, 'url' => $url]);
        $this->file_log("REQUEST START: $method $url");

        $default_headers = [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password)
        ];

        $headers = array_merge($default_headers, $headers);

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 45,
            'sslverify' => true
        ];

        if ($body) {
            $args['body'] = $body;
            $this->log_debug('request_body_size', strlen($body));
            $this->file_log("Body size: " . strlen($body) . " bytes");
        }

        $this->log_debug('request_sending');
        $this->file_log("Sending request...");
        $start_time = microtime(true);
        $response = wp_remote_request($url, $args);
        $elapsed = round((microtime(true) - $start_time) * 1000);
        $this->log_debug('request_complete', ['elapsed_ms' => $elapsed]);
        $this->file_log("Request completed in {$elapsed}ms");

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $this->log_debug('request_error', $error_msg);
            $this->file_log("REQUEST ERROR: $error_msg");
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_size = strlen(wp_remote_retrieve_body($response));
        $this->log_debug('request_response', ['code' => $code, 'body_size' => $body_size]);
        $this->file_log("RESPONSE: HTTP $code, body size: $body_size");

        return [
            'code' => $code,
            'body' => wp_remote_retrieve_body($response),
            'headers' => wp_remote_retrieve_headers($response)
        ];
    }
    
    public function test_connection() {
        $url = $this->get_calendar_url();
        
        $body = '<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">
  <d:prop>
    <d:displayname />
    <cs:getctag />
  </d:prop>
</d:propfind>';
        
        $response = $this->request('PROPFIND', $url, $body, ['Depth' => '0']);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        
        if ($response['code'] === 207) {
            if (preg_match('/<d:displayname>([^<]+)<\/d:displayname>/i', $response['body'], $matches)) {
                return ['success' => true, 'message' => 'Kalender gefunden: ' . $matches[1]];
            }
            return ['success' => true, 'message' => 'Kalender erreichbar'];
        } elseif ($response['code'] === 401) {
            return ['success' => false, 'message' => 'Authentifizierung fehlgeschlagen'];
        } elseif ($response['code'] === 404) {
            return ['success' => false, 'message' => 'Kalender nicht gefunden'];
        }
        
        return ['success' => false, 'message' => 'HTTP ' . $response['code']];
    }
    
    public function get_events_for_date($date) {
        // Cache prüfen (3 Minuten)
        $cache_key = 'caldav_events_' . md5($this->calendar_url . $date);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $tz = new DateTimeZone(wp_timezone_string());
        $start = new DateTime($date . ' 00:00:00', $tz);
        $end = new DateTime($date . ' 23:59:59', $tz);
        
        $start_utc = clone $start;
        $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_utc = clone $end;
        $end_utc->setTimezone(new DateTimeZone('UTC'));
        
        $url = $this->get_calendar_url();
        
        $body = '<?xml version="1.0" encoding="utf-8" ?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="' . $start_utc->format('Ymd\THis\Z') . '" end="' . $end_utc->format('Ymd\THis\Z') . '"/>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>';
        
        $response = $this->request('REPORT', $url, $body, ['Depth' => '1']);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if ($response['code'] !== 207) {
            return new WP_Error('caldav_error', 'Fehler: HTTP ' . $response['code']);
        }
        
        $events = $this->parse_events($response['body'], $tz);
        
        // Cache für 3 Minuten
        set_transient($cache_key, $events, 180);
        
        return $events;
    }
    
    public function get_events_for_range($start_date, $end_date) {
        // Cache prüfen (3 Minuten)
        $cache_key = 'caldav_range_' . md5($this->calendar_url . $start_date . $end_date);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $tz = new DateTimeZone(wp_timezone_string());
        $start = new DateTime($start_date . ' 00:00:00', $tz);
        $end = new DateTime($end_date . ' 23:59:59', $tz);
        
        $start_utc = clone $start;
        $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_utc = clone $end;
        $end_utc->setTimezone(new DateTimeZone('UTC'));
        
        $url = $this->get_calendar_url();
        
        $body = '<?xml version="1.0" encoding="utf-8" ?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="' . $start_utc->format('Ymd\THis\Z') . '" end="' . $end_utc->format('Ymd\THis\Z') . '"/>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>';
        
        $response = $this->request('REPORT', $url, $body, ['Depth' => '1']);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if ($response['code'] !== 207) {
            return new WP_Error('caldav_error', 'Fehler: HTTP ' . $response['code']);
        }
        
        $events = $this->parse_events($response['body'], $tz);
        
        // Cache für 3 Minuten
        set_transient($cache_key, $events, 180);
        
        return $events;
    }
    
    private function parse_events($xml_body, $tz) {
        $events = [];
        
        preg_match_all('/<c:calendar-data[^>]*>(.*?)<\/c:calendar-data>/si', $xml_body, $matches);
        
        foreach ($matches[1] as $ical_data) {
            $ical_data = html_entity_decode($ical_data);
            // Decode CDATA if present
            $ical_data = preg_replace('/^<!\[CDATA\[(.*)\]\]>$/s', '$1', trim($ical_data));
            
            // Extract ALL VEVENT blocks (there can be multiple, and we must ignore VTIMEZONE blocks)
            preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/si', $ical_data, $vevent_matches);
            
            foreach ($vevent_matches[1] as $vevent) {
                $summary = '';
                if (preg_match('/SUMMARY[^:]*:([^\r\n]+)/i', $vevent, $summary_match)) {
                    $summary = trim($summary_match[1]);
                }
                
                // Parse DTSTART - handle TZID parameter
                $start = null;
                if (preg_match('/DTSTART(?:;TZID=([^:;]+))?(?:;[^:]*)?:([^\r\n]+)/i', $vevent, $start_match)) {
                    $start_tzid = !empty($start_match[1]) ? trim($start_match[1]) : null;
                    $start_str = trim($start_match[2]);
                    $start = $this->parse_ical_datetime($start_str, $tz, $start_tzid);
                }
                
                // Parse DTEND - handle TZID parameter
                $end = null;
                if (preg_match('/DTEND(?:;TZID=([^:;]+))?(?:;[^:]*)?:([^\r\n]+)/i', $vevent, $end_match)) {
                    $end_tzid = !empty($end_match[1]) ? trim($end_match[1]) : null;
                    $end_str = trim($end_match[2]);
                    $end = $this->parse_ical_datetime($end_str, $tz, $end_tzid);
                } elseif ($start && preg_match('/DURATION:([^\r\n]+)/i', $vevent, $duration_match)) {
                    $end = clone $start;
                    try {
                        $duration = new DateInterval(trim($duration_match[1]));
                        $end->add($duration);
                    } catch (Exception $e) {
                        $end = null;
                    }
                }
                
                if ($start && $end) {
                    $transparent = (bool) preg_match('/TRANSP:TRANSPARENT/i', $vevent);
                    
                    $events[] = [
                        'summary' => $summary,
                        'start' => $start->format('Y-m-d\TH:i:s'),
                        'end' => $end->format('Y-m-d\TH:i:s'),
                        'transparent' => $transparent
                    ];
                }
            }
        }
        
        return $events;
    }
    
    private function parse_ical_datetime($str, $default_tz, $tzid = null) {
        $str = trim($str);
        
        // UTC time (ends with Z) - format: 20250120T120000Z
        if (substr($str, -1) === 'Z') {
            $dt = DateTime::createFromFormat('Ymd\THis\Z', $str, new DateTimeZone('UTC'));
            if ($dt && $dt->format('Y') > 1970) {
                $dt->setTimezone($default_tz);
                return $dt;
            }
        }
        
        // Determine timezone for non-UTC times
        $event_tz = $default_tz;
        if ($tzid) {
            try {
                $event_tz = new DateTimeZone($tzid);
            } catch (Exception $e) {
                // Invalid timezone, use default
            }
        }
        
        // Date only (all-day event) - format: 20250120
        if (preg_match('/^\d{8}$/', $str)) {
            $dt = DateTime::createFromFormat('Ymd|', $str, $event_tz);
            if ($dt && $dt->format('Y') > 1970) {
                $dt->setTime(0, 0, 0);
                return $dt;
            }
        }
        
        // Local time - format: 20250120T120000
        if (preg_match('/^\d{8}T\d{6}$/', $str)) {
            $dt = DateTime::createFromFormat('Ymd\THis', $str, $event_tz);
            if ($dt && $dt->format('Y') > 1970) {
                // Keep the time as-is in the event's timezone, don't convert
                return $dt;
            }
        }
        
        // Try ISO format as fallback
        try {
            $dt = new DateTime($str, $event_tz);
            if ($dt->format('Y') > 1970) {
                return $dt;
            }
        } catch (Exception $e) {
            // Fall through
        }
        
        return null;
    }
    
    public function create_event($event_data) {
        $this->log_debug('create_event_start');
        $this->file_log("=== CREATE EVENT START ===");

        $uid = wp_generate_uuid4();
        $this->log_debug('create_event_uid', $uid);
        $this->file_log("Generated UID: $uid");

        $now = new DateTime('now', new DateTimeZone('UTC'));
        $tz_name = $event_data['start']->getTimezone()->getName();
        $this->log_debug('create_event_timezone', $tz_name);
        $this->file_log("Timezone: $tz_name");

        // Check if timezone is an offset format (like +00:00, +01:00, etc.)
        // These are not valid TZID values, so we use UTC instead
        $use_utc = preg_match('/^[+-]\d{2}:\d{2}$/', $tz_name) || $tz_name === 'UTC';
        $this->file_log("Use UTC format: " . ($use_utc ? 'yes' : 'no'));

        $start = clone $event_data['start'];
        $end = clone $event_data['end'];

        if ($use_utc) {
            // Convert to UTC for offset-based timezones
            $start->setTimezone(new DateTimeZone('UTC'));
            $end->setTimezone(new DateTimeZone('UTC'));
        }

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//CalDAV Booking//WordPress Plugin//DE\r\n";

        if (!$use_utc) {
            // Only add VTIMEZONE for named timezones
            $ical .= "BEGIN:VTIMEZONE\r\n";
            $ical .= "TZID:" . $tz_name . "\r\n";
            $ical .= "END:VTIMEZONE\r\n";
        }
        
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:" . $uid . "\r\n";
        $ical .= "DTSTAMP:" . $now->format('Ymd\THis\Z') . "\r\n";
        if ($use_utc) {
            // UTC format with Z suffix
            $ical .= "DTSTART:" . $start->format('Ymd\THis\Z') . "\r\n";
            $ical .= "DTEND:" . $end->format('Ymd\THis\Z') . "\r\n";
            $this->file_log("DTSTART: " . $start->format('Ymd\THis\Z'));
            $this->file_log("DTEND: " . $end->format('Ymd\THis\Z'));
        } else {
            // Named timezone format
            $ical .= "DTSTART;TZID=" . $tz_name . ":" . $start->format('Ymd\THis') . "\r\n";
            $ical .= "DTEND;TZID=" . $tz_name . ":" . $end->format('Ymd\THis') . "\r\n";
            $this->file_log("DTSTART: " . $start->format('Ymd\THis') . " (TZID: $tz_name)");
            $this->file_log("DTEND: " . $end->format('Ymd\THis') . " (TZID: $tz_name)");
        }
        $ical .= "SUMMARY:" . $this->escape_ical_text($event_data['summary']) . "\r\n";
        $ical .= "DESCRIPTION:" . $this->escape_ical_text($event_data['description']) . "\r\n";
        
        if (!empty($event_data['attendee_email'])) {
            // Quote CN value to handle special characters (colons, semicolons, etc.)
            $cn_escaped = str_replace('"', '\\"', $event_data['attendee_name']);
            $ical .= "ATTENDEE;RSVP=TRUE;CN=\"" . $cn_escaped . "\";PARTSTAT=NEEDS-ACTION:mailto:" . $event_data['attendee_email'] . "\r\n";
        }
        
        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "TRANSP:OPAQUE\r\n";
        $ical .= "CLASS:PUBLIC\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";
        
        $url = $this->get_calendar_url() . $uid . '.ics';
        $this->log_debug('create_event_url', $url);
        $this->log_debug('create_event_ical_size', strlen($ical));
        $this->file_log("PUT URL: $url");
        $this->file_log("iCal size: " . strlen($ical) . " bytes");

        $response = $this->request('PUT', $url, $ical, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'If-None-Match' => '*'
        ]);

        if (is_wp_error($response)) {
            $this->file_log("CREATE EVENT ERROR: " . $response->get_error_message());
            return $response;
        }

        $this->file_log("CREATE EVENT RESPONSE CODE: " . $response['code']);

        if ($response['code'] === 201 || $response['code'] === 204) {
            $this->file_log("CREATE EVENT SUCCESS");
            return ['success' => true, 'uid' => $uid];
        }

        $this->file_log("CREATE EVENT FAILED: HTTP " . $response['code']);
        return new WP_Error('caldav_error', 'Fehler: HTTP ' . $response['code']);
    }
    
    private function escape_ical_text($text) {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\n', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace(';', '\;', $text);
        return $text;
    }
}

// Initialize plugin
CalDAV_Booking::get_instance();
