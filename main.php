<?php
/**
 * Plugin Name: Kaushik Sannidhi's FluentSupport Email Parser
 * Description: Automatically converts incoming emails to FluentSupport tickets via webhook
 * Version: 1.0.1
 * Author: Kaushik Sannidhi
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FluentSupportEmailParser {

    private $option_name = 'fluent_support_email_parser_settings';
    private $cron_hook = 'fluent_support_check_emails';
    private $log_option = 'fluent_support_email_logs';

    public function __construct() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        // Hook into WordPress
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('wp_ajax_test_email_connection', array($this, 'ajax_test_email_connection'));
        add_action('wp_ajax_test_webhook', array($this, 'ajax_test_webhook'));
        add_action('wp_ajax_check_emails_now', array($this, 'ajax_check_emails_now'));
        add_action('wp_ajax_clear_logs', array($this, 'ajax_clear_logs'));

        // Schedule cron job if not using direct trigger
        add_action($this->cron_hook, array($this, 'check_emails'));

        $options = get_option($this->option_name, array());
        if (!isset($options['use_direct_trigger']) || !$options['use_direct_trigger']) {
            $interval = isset($options['check_interval']) ? $options['check_interval'] : 'fluent_support_15min';
            if (!wp_next_scheduled($this->cron_hook)) {
                wp_schedule_event(time(), $interval, $this->cron_hook);
            } else {
                $current_schedule = wp_get_schedule($this->cron_hook);
                if ($current_schedule !== $interval) {
                    wp_clear_scheduled_hook($this->cron_hook);
                    wp_schedule_event(time(), $interval, $this->cron_hook);
                }
            }
        } else {
            wp_clear_scheduled_hook($this->cron_hook);
        }

        // Add custom cron intervals
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        // Enqueue scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Register REST API route for direct trigger
        add_action('rest_api_init', array($this, 'register_rest_route'));

        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function register_rest_route() {
        register_rest_route('fluent-support/v1', '/check-emails', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_direct_trigger'),
            'permission_callback' => '__return_true' // Checked via secret key
        ));
    }

    public function handle_direct_trigger($request) {
        $options = get_option($this->option_name);
        $provided_key = $request->get_param('key');

        if (empty($options['trigger_key']) || $provided_key !== $options['trigger_key']) {
            return new WP_Error('unauthorized', 'Invalid trigger key', array('status' => 403));
        }

        $result = $this->check_emails();

        if ($result['success']) {
            return new WP_REST_Response(array('message' => $result['message']), 200);
        } else {
            return new WP_Error('error', $result['message'], array('status' => 500));
        }
    }

    public function add_cron_interval($schedules) {
        $schedules['fluent_support_1min'] = array(
            'interval' => 60,
            'display' => __('Every 1 Minute')
        );
        $schedules['fluent_support_2min'] = array(
            'interval' => 120,
            'display' => __('Every 2 Minutes')
        );
        $schedules['fluent_support_5min'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes')
        );
        $schedules['fluent_support_15min'] = array(
            'interval' => 900,
            'display' => __('Every 15 Minutes')
        );
        return $schedules;
    }

    public function activate() {
        $default_options = array(
            'email' => '',
            'email_password' => '',
            'imap_server' => '',
            'imap_port' => '993',
            'webhook_url' => '',
            'enabled' => false,
            'debug_mode' => false,
            'check_interval' => 'fluent_support_15min',
            'connection_timeout' => 30,
            'max_emails_per_check' => 10,
            'use_direct_trigger' => false,
            'trigger_key' => '',
            'last_check' => 0,
            'connection_stats' => array(
                'total_connections' => 0,
                'successful_connections' => 0,
                'failed_connections' => 0,
                'last_reset' => time()
            )
        );

        if (!get_option($this->option_name)) {
            add_option($this->option_name, $default_options);
        }

        if (!get_option($this->log_option)) {
            add_option($this->log_option, array());
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook($this->cron_hook);
    }

    public function add_admin_menu() {
        add_options_page(
            'FluentSupport Email Parser',
            'Email Parser',
            'manage_options',
            'fluent-support-email-parser',
            array($this, 'admin_page')
        );
    }

    public function settings_init() {
        register_setting('fluent_support_email_parser', $this->option_name);

        add_settings_section(
            'fluent_support_email_parser_section',
            __('Email Configuration', 'fluent-support-email-parser'),
            array($this, 'settings_section_callback'),
            'fluent_support_email_parser'
        );

        $fields = array(
            'email' => 'Email Address',
            'email_password' => 'Email Password',
            'imap_server' => 'IMAP Server',
            'imap_port' => 'IMAP Port',
            'webhook_url' => 'Webhook URL',
            'check_interval' => 'Check Interval',
            'connection_timeout' => 'Connection Timeout (seconds)',
            'max_emails_per_check' => 'Max Emails Per Check',
            'enabled' => 'Enable Parser',
            'debug_mode' => 'Debug Mode',
            'use_direct_trigger' => 'Use Direct Trigger Endpoint'
        );

        foreach ($fields as $field => $label) {
            add_settings_field(
                $field,
                __($label, 'fluent-support-email-parser'),
                array($this, 'field_callback'),
                'fluent_support_email_parser',
                'fluent_support_email_parser_section',
                array('field' => $field, 'label' => $label)
            );
        }
    }

    public function settings_section_callback() {
        echo __('Configure your email settings and FluentSupport webhook URL.', 'fluent-support-email-parser');
    }

    public function field_callback($args) {
        $options = get_option($this->option_name);
        $field = $args['field'];
        $value = isset($options[$field]) ? $options[$field] : '';

        switch ($field) {
            case 'email_password':
                echo '<input type="password" name="' . $this->option_name . '[' . $field . ']" value="' . esc_attr($value) . '" class="regular-text" />';
                break;
            case 'enabled':
            case 'debug_mode':
            case 'use_direct_trigger':
                echo '<input type="checkbox" name="' . $this->option_name . '[' . $field . ']" value="1" ' . checked(1, $value, false) . ' />';
                if ($field === 'use_direct_trigger') {
                    echo '<p class="description">Use a server cron job to trigger email checks instead of WP-Cron. Recommended if WP-Cron is disabled.</p>';
                }
                break;
            case 'imap_port':
            case 'connection_timeout':
            case 'max_emails_per_check':
                $placeholder = ($field === 'imap_port') ? '993' : (($field === 'connection_timeout') ? '30' : '10');
                echo '<input type="number" name="' . $this->option_name . '[' . $field . ']" value="' . esc_attr($value) . '" class="small-text" placeholder="' . $placeholder . '" />';
                break;
            case 'check_interval':
                $intervals = array(
                    'fluent_support_1min' => 'Every 1 Minute (High Frequency)',
                    'fluent_support_2min' => 'Every 2 Minutes (Recommended)',
                    'fluent_support_5min' => 'Every 5 Minutes (Balanced)',
                    'fluent_support_15min' => 'Every 15 Minutes (Conservative)'
                );
                echo '<select name="' . $this->option_name . '[' . $field . ']" class="regular-text">';
                foreach ($intervals as $interval_key => $interval_label) {
                    echo '<option value="' . $interval_key . '" ' . selected($value, $interval_key, false) . '>' . $interval_label . '</option>';
                }
                echo '</select>';
                echo '<p class="description">Higher frequencies provide faster response but may trigger spam protection. 2-5 minutes is recommended.</p>';
                break;
            default:
                echo '<input type="text" name="' . $this->option_name . '[' . $field . ']" value="' . esc_attr($value) . '" class="regular-text" />';
                break;
        }
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_fluent-support-email-parser') {
            return;
        }

        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'fluent_support_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fluent_support_nonce')
        ));
    }

    public function admin_page() {
        $options = get_option($this->option_name);
        $logs = get_option($this->log_option, array());
        $logs = array_slice(array_reverse($logs), 0, 50);

        // Generate trigger key if enabled and missing
        if (isset($options['use_direct_trigger']) && $options['use_direct_trigger'] && empty($options['trigger_key'])) {
            $options['trigger_key'] = wp_generate_password(32, false);
            update_option($this->option_name, $options);
        }

        ?>
        <div class="wrap">
            <h1>FluentSupport Email Parser</h1>

            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON && (!isset($options['use_direct_trigger']) || !$options['use_direct_trigger'])): ?>
                <div class="notice notice-warning">
                    <p>WP-Cron is disabled on this site. Automatic email checking requires either enabling the Direct Trigger Endpoint below or setting up a server cron job to run <code>wp-cron.php</code>. Example: <code>*/5 * * * * wget -q -O - <?php echo esc_url(site_url('wp-cron.php?doing_wp_cron')); ?> > /dev/null 2>&1</code></p>
                </div>
            <?php endif; ?>

            <div id="message-container"></div>

            <form action="options.php" method="post">
                <?php
                settings_fields('fluent_support_email_parser');
                do_settings_sections('fluent_support_email_parser');
                submit_button();
                ?>
            </form>

            <?php if (isset($options['use_direct_trigger']) && $options['use_direct_trigger']): ?>
                <div style="margin-top: 20px;">
                    <h3>Direct Trigger Endpoint</h3>
                    <?php
                    $key = $options['trigger_key'];
                    $endpoint = rest_url('fluent-support/v1/check-emails?key=' . $key);
                    ?>
                    <p><strong>Endpoint URL:</strong> <?php echo esc_url($endpoint); ?></p>
                    <p>Use this in a server cron job (e.g., every 2 minutes):</p>
                    <code>*/2 * * * * curl -s <?php echo esc_url($endpoint); ?> > /dev/null 2>&1</code>
                </div>
            <?php endif; ?>

            <div style="margin-top: 30px;">
                <h2>Testing & Monitoring</h2>

                <div style="margin-bottom: 20px;">
                    <button type="button" id="test-email" class="button">Test Email Connection</button>
                    <button type="button" id="test-webhook" class="button">Test Webhook</button>
                    <button type="button" id="check-emails-now" class="button button-primary">Check Emails Now</button>
                    <button type="button" id="clear-logs" class="button">Clear Logs</button>
                </div>

                <div id="test-results" style="margin-bottom: 20px;"></div>

                <h3>Recent Activity Logs</h3>
                <div id="activity-logs" style="max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
                    <?php if (empty($logs)): ?>
                        <p>No activity logs yet.</p>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <div style="margin-bottom: 10px; padding: 5px; border-left: 3px solid <?php echo $log['type'] === 'error' ? '#dc3232' : ($log['type'] === 'success' ? '#46b450' : '#0073aa'); ?>;">
                                <strong><?php echo esc_html($log['timestamp']); ?></strong> -
                                <span style="color: <?php echo $log['type'] === 'error' ? '#dc3232' : ($log['type'] === 'success' ? '#46b450' : '#0073aa'); ?>;">
                                    <?php echo esc_html(strtoupper($log['type'])); ?>
                                </span><br>
                                <?php echo esc_html($log['message']); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 20px;">
                    <h3>Connection Statistics</h3>
                    <?php
                    $stats = $options['connection_stats'] ?? array();
                    $total = $stats['total_connections'] ?? 0;
                    $successful = $stats['successful_connections'] ?? 0;
                    $failed = $stats['failed_connections'] ?? 0;
                    $success_rate = $total > 0 ? round(($successful / $total) * 100, 1) : 0;
                    ?>
                    <p><strong>Total Connections:</strong> <?php echo $total; ?></p>
                    <p><strong>Successful:</strong> <?php echo $successful; ?></p>
                    <p><strong>Failed:</strong> <?php echo $failed; ?></p>
                    <p><strong>Success Rate:</strong> <?php echo $success_rate; ?>%</p>
                    <p><strong>Last Check:</strong> <?php echo $options['last_check'] > 0 ? date('Y-m-d H:i:s', $options['last_check']) : 'Never'; ?></p>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function showMessage(message, type) {
                    var messageClass = type === 'error' ? 'notice-error' : 'notice-success';
                    $('#message-container').html('<div class="notice ' + messageClass + ' is-dismissible"><p>' + message + '</p></div>');
                    setTimeout(function() { $('#message-container').html(''); }, 5000);
                }

                function makeAjaxRequest(action, button, successMessage) {
                    button.prop('disabled', true).text('Testing...');
                    $.ajax({
                        url: fluent_support_ajax.ajax_url,
                        type: 'POST',
                        data: { action: action, nonce: fluent_support_ajax.nonce },
                        success: function(response) {
                            if (response.success) {
                                showMessage(successMessage, 'success');
                                $('#test-results').html('<div style="color: green;">' + response.data.message + '</div>');
                            } else {
                                showMessage('Test failed: ' + response.data.message, 'error');
                                $('#test-results').html('<div style="color: red;">' + response.data.message + '</div>');
                            }
                        },
                        error: function() {
                            showMessage('AJAX request failed', 'error');
                            $('#test-results').html('<div style="color: red;">AJAX request failed</div>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text(button.data('original-text'));
                        }
                    });
                }

                $('#test-email').click(function() {
                    $(this).data('original-text', $(this).text());
                    makeAjaxRequest('test_email_connection', $(this), 'Email connection successful!');
                });

                $('#test-webhook').click(function() {
                    $(this).data('original-text', $(this).text());
                    makeAjaxRequest('test_webhook', $(this), 'Webhook test successful!');
                });

                $('#check-emails-now').click(function() {
                    $(this).data('original-text', $(this).text());
                    makeAjaxRequest('check_emails_now', $(this), 'Email check completed!');
                });

                $('#clear-logs').click(function() {
                    if (confirm('Are you sure you want to clear all logs?')) {
                        $(this).data('original-text', $(this).text());
                        makeAjaxRequest('clear_logs', $(this), 'Logs cleared!');
                        setTimeout(function() { location.reload(); }, 1000);
                    }
                });
            });
        </script>
        <?php
    }

    public function ajax_test_email_connection() {
        check_ajax_referer('fluent_support_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $options = get_option($this->option_name);
        if (empty($options['email']) || empty($options['email_password']) || empty($options['imap_server'])) {
            wp_send_json_error(array('message' => 'Please fill in all email configuration fields.'));
        }
        $result = $this->test_email_connection($options);
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    public function ajax_test_webhook() {
        check_ajax_referer('fluent_support_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $options = get_option($this->option_name);
        if (empty($options['webhook_url'])) {
            wp_send_json_error(array('message' => 'Please enter a webhook URL.'));
        }
        $result = $this->test_webhook($options['webhook_url']);
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    public function ajax_check_emails_now() {
        check_ajax_referer('fluent_support_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $result = $this->check_emails();
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    public function ajax_clear_logs() {
        check_ajax_referer('fluent_support_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        update_option($this->log_option, array());
        wp_send_json_success(array('message' => 'Logs cleared successfully.'));
    }

    private function test_email_connection($options) {
        try {
            $this->update_connection_stats('attempt');
            $imap_server = $options['imap_server'];
            $port = $options['imap_port'] ?: 993;
            $timeout = $options['connection_timeout'] ?: 30;
            ini_set('default_socket_timeout', $timeout);
            $connection = imap_open(
                '{' . $imap_server . ':' . $port . '/imap/ssl/novalidate-cert}INBOX',
                $options['email'],
                $options['email_password'],
                0,
                1,
                array('DISABLE_AUTHENTICATOR' => 'GSSAPI')
            );
            if ($connection) {
                $mailbox_info = imap_status($connection, '{' . $imap_server . ':' . $port . '/imap/ssl/novalidate-cert}INBOX', SA_ALL);
                imap_close($connection);
                $this->update_connection_stats('success');
                $message = 'Email connection successful! Found ' . $mailbox_info->messages . ' total messages, ' . $mailbox_info->unseen . ' unread.';
                $this->log('success', $message);
                return array('success' => true, 'message' => $message);
            } else {
                $this->update_connection_stats('failure');
                $error = 'Failed to connect: ' . imap_last_error();
                $this->log('error', $error);
                return array('success' => false, 'message' => $error);
            }
        } catch (Exception $e) {
            $this->update_connection_stats('failure');
            $error = 'Connection error: ' . $e->getMessage();
            $this->log('error', $error);
            return array('success' => false, 'message' => $error);
        } finally {
            ini_restore('default_socket_timeout');
        }
    }

    private function test_webhook($webhook_url) {
        try {
            $test_data = array(
                'title' => 'Test Ticket - FluentSupport Email Parser',
                'content' => 'This is a test ticket to verify webhook connectivity.',
                'priority' => 'Normal',
                'sender' => array('first_name' => 'Test', 'last_name' => 'User', 'email' => 'test@example.com')
            );
            $response = wp_remote_post($webhook_url, array(
                'method' => 'POST',
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'FluentSupport-EmailParser-WordPress/1.0'),
                'body' => json_encode($test_data),
                'sslverify' => true
            ));
            if (is_wp_error($response)) {
                $error = 'Webhook failed: ' . $response->get_error_message();
                $this->log('error', $error);
                return array('success' => false, 'message' => $error);
            }
            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            if ($status_code === 200) {
                $message = 'Webhook test successful! Response: ' . substr($response_body, 0, 200);
                $this->log('success', $message);
                return array('success' => true, 'message' => $message);
            } else {
                $error = 'Webhook status ' . $status_code . ': ' . $response_body;
                $this->log('error', $error);
                return array('success' => false, 'message' => $error);
            }
        } catch (Exception $e) {
            $error = 'Webhook error: ' . $e->getMessage();
            $this->log('error', $error);
            return array('success' => false, 'message' => $error);
        }
    }

    public function check_emails() {
        $options = get_option($this->option_name);
        if (empty($options['enabled'])) {
            return array('success' => false, 'message' => 'Email parser is disabled.');
        }
        $required = array('email', 'email_password', 'imap_server', 'webhook_url');
        foreach ($required as $field) {
            if (empty($options[$field])) {
                $this->log('error', 'Missing config: ' . $field);
                return array('success' => false, 'message' => 'Missing config: ' . $field);
            }
        }
        $options['last_check'] = time();
        update_option($this->option_name, $options);
        $last_check = get_transient('fluent_support_last_successful_check');
        if ($last_check && (time() - $last_check) < 60) {
            return array('success' => true, 'message' => 'Throttled - recent check detected');
        }
        try {
            $this->update_connection_stats('attempt');
            $imap_server = $options['imap_server'];
            $port = $options['imap_port'] ?: 993;
            $timeout = $options['connection_timeout'] ?: 30;
            $max_emails = $options['max_emails_per_check'] ?: 10;
            ini_set('default_socket_timeout', $timeout);
            $connection = imap_open(
                '{' . $imap_server . ':' . $port . '/imap/ssl/novalidate-cert}INBOX',
                $options['email'],
                $options['email_password'],
                0,
                1,
                array('DISABLE_AUTHENTICATOR' => 'GSSAPI')
            );
            if (!$connection) {
                $this->update_connection_stats('failure');
                $error = 'Connection failed: ' . imap_last_error();
                $this->log('error', $error);
                return array('success' => false, 'message' => $error);
            }
            $unread_emails = imap_search($connection, 'UNSEEN');
            if (!$unread_emails) {
                imap_close($connection);
                $this->update_connection_stats('success');
                set_transient('fluent_support_last_successful_check', time(), 300);
                $this->log('info', 'No unread emails found.');
                return array('success' => true, 'message' => 'No unread emails found.');
            }
            $emails_to_process = array_slice($unread_emails, 0, $max_emails);
            $processed_count = 0;
            $error_count = 0;
            foreach ($emails_to_process as $email_id) {
                try {
                    $result = $this->process_email($connection, $email_id, $options);
                    if ($result['success']) {
                        $processed_count++;
                        imap_setflag_full($connection, $email_id, '\\Seen');
                        usleep(500000);
                    } else {
                        $error_count++;
                        $this->log('error', 'Failed email ID ' . $email_id . ': ' . $result['message']);
                    }
                } catch (Exception $e) {
                    $error_count++;
                    $this->log('error', 'Error email ID ' . $email_id . ': ' . $e->getMessage());
                }
            }
            imap_close($connection);
            $this->update_connection_stats('success');
            set_transient('fluent_support_last_successful_check', time(), 300);
            $message = "Processed {$processed_count} emails";
            if ($error_count > 0) $message .= ", {$error_count} errors";
            if (count($unread_emails) > $max_emails) $message .= ", " . (count($unread_emails) - $max_emails) . " remaining";
            $this->log('success', $message);
            return array('success' => true, 'message' => $message);
        } catch (Exception $e) {
            $this->update_connection_stats('failure');
            $error = 'Check error: ' . $e->getMessage();
            $this->log('error', $error);
            return array('success' => false, 'message' => $error);
        } finally {
            ini_restore('default_socket_timeout');
        }
    }

    private function process_email($connection, $email_id, $options) {
        try {
            $headers = imap_headerinfo($connection, $email_id);
            $structure = imap_fetchstructure($connection, $email_id);
            $from = $headers->from[0];
            $sender_email = $from->mailbox . '@' . $from->host;
            $sender_name = $from->personal ? $this->decode_header($from->personal) : '';
            $name_parts = $this->process_name($sender_name);
            $subject = $headers->subject ? $this->decode_header($headers->subject) : 'No Subject';
            $body = $this->get_email_body($connection, $email_id, $structure);
            $ticket_data = array(
                'title' => $subject,
                'content' => $body,
                'priority' => 'Normal',
                'sender' => array(
                    'first_name' => $name_parts['first_name'],
                    'last_name' => $name_parts['last_name'],
                    'email' => $sender_email
                )
            );
            $response = wp_remote_post($options['webhook_url'], array(
                'method' => 'POST',
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json', 'User-Agent' => 'FluentSupport-EmailParser-WordPress/1.0'),
                'body' => json_encode($ticket_data),
                'sslverify' => true
            ));
            if (is_wp_error($response)) {
                return array('success' => false, 'message' => 'Webhook failed: ' . $response->get_error_message());
            }
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code === 200) {
                $this->log('success', 'Created ticket: ' . $subject . ' from ' . $sender_email);
                return array('success' => true, 'message' => 'Ticket created');
            } else {
                $response_body = wp_remote_retrieve_body($response);
                return array('success' => false, 'message' => 'Webhook status ' . $status_code . ': ' . $response_body);
            }
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Processing error: ' . $e->getMessage());
        }
    }

    private function get_email_body($connection, $email_id, $structure) {
        $body = '';
        $html_body = '';
        $text_body = '';

        if ($structure->type == 0) {
            // Single part message
            $body = imap_fetchbody($connection, $email_id, 1);
            if ($structure->encoding == 3) {
                $body = base64_decode($body);
            } elseif ($structure->encoding == 4) {
                $body = quoted_printable_decode($body);
            }

            // Check if it's HTML content
            if (isset($structure->subtype) && strtoupper($structure->subtype) == 'HTML') {
                $body = $this->html_to_text($body);
            }

        } elseif ($structure->type == 1) {
            // Multipart message
            $parts = $structure->parts;

            for ($i = 0; $i < count($parts); $i++) {
                $part = $parts[$i];
                $part_number = $i + 1;

                $part_body = imap_fetchbody($connection, $email_id, $part_number);

                // Decode based on encoding
                if ($part->encoding == 3) {
                    $part_body = base64_decode($part_body);
                } elseif ($part->encoding == 4) {
                    $part_body = quoted_printable_decode($part_body);
                }

                // Check content type
                if (isset($part->subtype)) {
                    if (strtoupper($part->subtype) == 'HTML') {
                        $html_body = $part_body;
                    } elseif (strtoupper($part->subtype) == 'PLAIN') {
                        $text_body = $part_body;
                    }
                }
            }

            // Prefer plain text, fall back to converted HTML
            if (!empty($text_body)) {
                $body = $text_body;
            } elseif (!empty($html_body)) {
                $body = $this->html_to_text($html_body);
            }
        }

        // Clean up the body text
        $body = $this->clean_email_body($body);

        return $body;
    }

    private function html_to_text($html) {
        // Remove style tags and their content
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);

        // Remove script tags and their content
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

        // Convert common HTML entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Add line breaks for block elements
        $html = preg_replace('/<\/(div|p|br|h[1-6]|li)>/i', "$0\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Remove all HTML tags
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/\n\s*\n/', "\n\n", $text); // Multiple newlines to double
        $text = preg_replace('/[ \t]+/', ' ', $text); // Multiple spaces to single

        return trim($text);
    }

// Add this new function to clean email body:
    private function clean_email_body($body) {
        // Remove excessive whitespace
        $body = preg_replace('/\r\n/', "\n", $body);
        $body = preg_replace('/\r/', "\n", $body);
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
        $body = preg_replace('/[ \t]+/', ' ', $body);

        // Remove common email signature separators
        $body = preg_replace('/^--\s*$/m', '', $body);

        // Remove leading/trailing whitespace
        $body = trim($body);

        return $body;
    }

    private function decode_header($header) {
        $decoded = imap_mime_header_decode($header);
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return $result;
    }

    private function process_name($full_name) {
        $parts = explode(' ', trim($full_name));
        $first_name = $parts[0] ?? '';
        $last_name = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        return array('first_name' => $first_name, 'last_name' => $last_name);
    }

    private function log($type, $message) {
        $logs = get_option($this->log_option, array());
        $logs[] = array(
            'timestamp' => current_time('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message
        );
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        update_option($this->log_option, $logs);
    }

    private function update_connection_stats($type) {
        $options = get_option($this->option_name);
        $stats = $options['connection_stats'] ?? array(
            'total_connections' => 0,
            'successful_connections' => 0,
            'failed_connections' => 0,
            'last_reset' => time()
        );
        switch ($type) {
            case 'attempt': $stats['total_connections']++; break;
            case 'success': $stats['successful_connections']++; break;
            case 'failure': $stats['failed_connections']++; break;
        }
        if (time() - $stats['last_reset'] > 604800) {
            $stats = array('total_connections' => 0, 'successful_connections' => 0, 'failed_connections' => 0, 'last_reset' => time());
        }
        $options['connection_stats'] = $stats;
        update_option($this->option_name, $options);
    }
}

new FluentSupportEmailParser();
?>
