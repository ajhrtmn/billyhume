<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-site DMCA designated-agent contact info, admin-editable, plus a
 * [bh_dmca_notice] shortcode that renders it publicly.
 *
 * The Self-Hosted Self is open-source software installed by independent site
 * owners — it is NOT itself a hosted service, so it cannot register a
 * DMCA agent on anyone's behalf. Each site owner who wants safe-harbor
 * protection has to register their OWN agent directly with the U.S.
 * Copyright Office (copyright.gov/dmca-directory) and keep that
 * registration current. This class only stores what THEY entered and
 * displays it — it makes no claim about whether the info is actually
 * registered, valid, or current. See the admin notice on the settings
 * page itself for that reminder.
 *
 * Deliberately NOT the takedown-notice intake/processing workflow —
 * that's BHI_Reports (the existing report queue). This is just the
 * "who do I send a notice to" contact-info half.
 */
class OUS_DMCA {
    const OPTION = 'ous_dmca_agent';
    const FIELDS = ['agent_name', 'org_name', 'email', 'phone', 'address'];

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ous_save_dmca_agent', [self::class, 'handle_save']);
        add_shortcode('bh_dmca_notice', [self::class, 'render_shortcode']);
    }

    /** @return array<string, string> */
    public static function get(): array {
        $saved = get_option(self::OPTION, []);
        return array_merge(array_fill_keys(self::FIELDS, ''), is_array($saved) ? $saved : []);
    }

    public static function is_configured(): bool {
        $agent = self::get();
        return $agent['email'] !== '' && ($agent['agent_name'] !== '' || $agent['org_name'] !== '');
    }

    public static function add_menu(): void {
        add_submenu_page('ous', 'DMCA Agent', 'DMCA Agent', 'manage_options', 'ous-dmca-agent', [self::class, 'render']);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;
        $agent = self::get();

        echo '<div class="wrap"><h1>DMCA Agent</h1>';
        echo '<p class="description">This info is shown publicly wherever you place the <code>[bh_dmca_notice]</code> shortcode, as the contact point for copyright takedown notices.</p>';
        echo '<div class="notice notice-warning"><p><strong>This form does not register anything with the U.S. Copyright Office.</strong> ';
        echo 'The Self-Hosted Self is open-source software you run on your own site — you, not this plugin, are the legal service provider. ';
        echo 'To actually get DMCA safe-harbor protection, register your own agent at ';
        echo '<a href="https://www.copyright.gov/dmca-directory/" target="_blank" rel="noopener">copyright.gov/dmca-directory</a> ';
        echo '(small fee, renews every 3 years) and keep it current there. Enter the same info here so it displays on your site.</p></div>';

        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>DMCA agent info saved.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ous_save_dmca_agent">';
        wp_nonce_field('ous_save_dmca_agent', 'ous_dmca_agent_nonce');

        echo '<table class="form-table"><tbody>';
        $labels = [
            'agent_name' => 'Agent name',
            'org_name' => 'Organization / site name',
            'email' => 'Email',
            'phone' => 'Phone (optional)',
            'address' => 'Mailing address',
        ];
        foreach ($labels as $field => $label) {
            $value = esc_attr($agent[$field]);
            echo '<tr><th><label for="' . esc_attr($field) . '">' . esc_html($label) . '</label></th><td>';
            if ($field === 'address') {
                echo '<textarea id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" rows="3" class="large-text">' . esc_textarea($agent[$field]) . '</textarea>';
            } else {
                $type = $field === 'email' ? 'email' : 'text';
                echo '<input type="' . $type . '" id="' . esc_attr($field) . '" name="' . esc_attr($field) . '" value="' . $value . '" class="regular-text">';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">Save</button></p>';
        echo '</form></div>';
    }

    public static function handle_save(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('ous_save_dmca_agent', 'ous_dmca_agent_nonce')) {
            wp_die('Invalid request.');
        }

        $data = [];
        foreach (self::FIELDS as $field) {
            $raw = wp_unslash($_POST[$field] ?? '');
            $data[$field] = $field === 'address' ? sanitize_textarea_field($raw) : sanitize_text_field($raw);
        }
        if ($data['email'] !== '' && !is_email($data['email'])) {
            $data['email'] = '';
        }

        update_option(self::OPTION, $data);

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=ous-dmca-agent')));
        exit;
    }

    public static function render_shortcode(): string {
        if (!self::is_configured()) {
            return current_user_can('manage_options')
                ? '<p><em>DMCA agent info not yet configured — set it under The Self-Hosted Self &gt; DMCA Agent.</em></p>'
                : '';
        }

        $agent = self::get();
        $out = '<div class="bh-dmca-notice">';
        $out .= '<p>' . esc_html__('To submit a copyright takedown notice, contact our designated agent:', 'own-ur-shit') . '</p><ul>';
        if ($agent['agent_name'] !== '') $out .= '<li>' . esc_html($agent['agent_name']) . '</li>';
        if ($agent['org_name'] !== '') $out .= '<li>' . esc_html($agent['org_name']) . '</li>';
        if ($agent['email'] !== '') $out .= '<li><a href="mailto:' . esc_attr($agent['email']) . '">' . esc_html($agent['email']) . '</a></li>';
        if ($agent['phone'] !== '') $out .= '<li>' . esc_html($agent['phone']) . '</li>';
        if ($agent['address'] !== '') $out .= '<li>' . nl2br(esc_html($agent['address'])) . '</li>';
        $out .= '</ul></div>';
        return $out;
    }
}
