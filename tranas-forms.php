<?php
/**
 * Plugin Name: Tranås Forms
 * Description: Formulärplugin som använder ACF för fälten, skickar e‑post via wp_mail(), och sparar/listar inskick (inkl. misslyckade).
 * Version: 2
 * Author: Per Olov Näs
 * Text Domain: tranas-forms
 */

if (!defined('ABSPATH')) exit;

class Tranas_Forms_Plugin {
    private $last_mail_error = null; // fångar senaste wp_mail‑fel via hook

    public function __construct() {
        add_action('init', [$this, 'register_cpts']);
        add_shortcode('tranas_form', [$this, 'shortcode_render_form']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_tranas_form_submit', [$this, 'ajax_handle_submission']);
        add_action('wp_ajax_nopriv_tranas_form_submit', [$this, 'ajax_handle_submission']);

        // Admin: submenu för inskick under "Formulär"
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_filter('manage_tranas_submission_posts_columns', [$this, 'submission_columns']);
        add_action('manage_tranas_submission_posts_custom_column', [$this, 'submission_column_content'], 10, 2);

        // Fånga mailfel
        add_action('wp_mail_failed', [$this, 'on_mail_failed']);

        // ACF JSON sync
        add_filter('acf/settings/load_json', [$this, 'acf_json_load_point']);

        // Registrera dynamiska shortcodes när formulär sparas (efter ACF har sparat)
        add_action('acf/save_post', [$this, 'register_form_shortcode_after_save'], 20);
        add_action('init', [$this, 'register_all_form_shortcodes'], 20);
    }

    /**
     * Lägg till plugin-mappen som ACF JSON load point
     */
    public function acf_json_load_point($paths) {
        $paths[] = plugin_dir_path(__FILE__) . 'acf-json';
        return $paths;
    }

    /**
     * Registrera CPTs: Formulär och Inskick.
     */
    public function register_cpts() {
        // Formulär (adminhantering, ej publik)
        register_post_type('tranas_form', [
            'label' => __('Formulär', 'tranas-forms'),
            'labels' => [
                'name' => __('Formulär', 'tranas-forms'),
                'singular_name' => __('Formulär', 'tranas-forms'),
                'add_new' => __('Lägg till nytt', 'tranas-forms'),
                'add_new_item' => __('Lägg till nytt formulär', 'tranas-forms'),
                'edit_item' => __('Redigera formulär', 'tranas-forms'),
                'new_item' => __('Nytt formulär', 'tranas-forms'),
                'view_item' => __('Visa formulär', 'tranas-forms'),
                'search_items' => __('Sök formulär', 'tranas-forms'),
                'not_found' => __('Inga formulär hittades', 'tranas-forms'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-feedback',
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);

        // Inskick (sparade svar)
        register_post_type('tranas_submission', [
            'label' => __('Inskick', 'tranas-forms'),
            'labels' => [
                'name' => __('Inskick', 'tranas-forms'),
                'singular_name' => __('Inskick', 'tranas-forms'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // vi lägger dem under Formulär via submenu
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
        ]);
    }

    /**
     * Enqueue frontassets
     */
    public function enqueue_assets() {
        // Plugin CSS (kompilerad från SCSS med delade variabler från style-core)
        wp_enqueue_style(
            'tranas-forms-styles',
            plugins_url('assets/css/main.css', __FILE__),
            [],
            '1.0.0'
        );
        
        wp_enqueue_script('tranas-forms', plugins_url('assets/forms.js', __FILE__), [], '1.1.0', true);
        wp_localize_script('tranas-forms', 'tranasFormsAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    /**
     * Registrera alla formulär-shortcodes vid init
     */
    public function register_all_form_shortcodes() {
        if (!function_exists('get_field')) {
            return;
        }

        $forms = get_posts([
            'post_type' => 'tranas_form',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($forms as $form_id) {
            $slug = get_field('shortcode_slug', $form_id);
            if (!empty($slug)) {
                $slug = sanitize_key($slug);
                if (!empty($slug)) {
                    $shortcode_name = 'tranas_form_' . $slug;
                    // Ta bort eventuell befintlig shortcode först (för att uppdatera)
                    remove_shortcode($shortcode_name);
                    // Registrera shortcode
                    add_shortcode($shortcode_name, function() use ($form_id) {
                        return $this->shortcode_render_form(['id' => $form_id]);
                    });
                }
            }
        }
    }

    /**
     * Registrera shortcode för ett specifikt formulär när det sparas (efter ACF)
     */
    public function register_form_shortcode_after_save($post_id) {
        // Kontrollera att det är rätt post type
        if (get_post_type($post_id) !== 'tranas_form') {
            return;
        }

        if (!function_exists('get_field')) {
            return;
        }

        $slug = get_field('shortcode_slug', $post_id);
        if (!empty($slug)) {
            $slug = sanitize_key($slug);
            if (!empty($slug)) {
                $shortcode_name = 'tranas_form_' . $slug;
                // Ta bort eventuell befintlig shortcode först
                remove_shortcode($shortcode_name);
                // Registrera ny shortcode
                add_shortcode($shortcode_name, function() use ($post_id) {
                    return $this->shortcode_render_form(['id' => $post_id]);
                });
            }
        }
    }

    /**
     * Shortcode: [tranas_form id="FORM_ID"] eller [tranas_form_SLUG]
     */
    public function shortcode_render_form($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $form_id = intval($atts['id']);
        if (!$form_id) return '<p>Formulär-ID saknas.</p>';

        if (!function_exists('get_field')) {
            return '<p>ACF saknas. Installera/aktivera Advanced Custom Fields.</p>';
        }

        $fields = get_field('tranas_forms', $form_id); // Repeater: fältlista
        $submit_label = get_field('submit_label', $form_id) ?: 'Skicka';

        ob_start();
        ?>
        <form method="post" action="" enctype="multipart/form-data" class="tranas-form">
            <?php wp_nonce_field('tranas_form_submit_'.$form_id, 'tranas_form_nonce'); ?>
            <input type="hidden" name="tranas_form_id" value="<?php echo esc_attr($form_id); ?>" />
            <input type="hidden" name="tranas_submission_token" value="<?php echo esc_attr(wp_generate_uuid4()); ?>" />

            <?php if (!empty($fields) && is_array($fields)): ?>
                <?php foreach ($fields as $index => $field):
                    $name = sanitize_title($field['label'] ?? 'falt');
                    $field_id = 'tf-' . $form_id . '-' . $name;
                    $label = esc_html($field['label'] ?? '');
                    $type  = $field['type'] ?? 'text';
                    $required = !empty($field['required']);
                    $options = array_filter(array_map('trim', preg_split('/\r?\n/', $field['options'] ?? '')));
                    $required_attr = $required ? 'required aria-required="true"' : '';
                    ?>

                    <div class="tf-field">
                        <?php if ($type === 'textarea'): ?>
                            <label class="tf-label" for="<?php echo esc_attr($field_id); ?>">
                                <?php echo $label; ?><?php echo $required ? ' <span class="tf-required" aria-hidden="true">*</span><span class="screen-reader-text">(obligatoriskt)</span>' : ''; ?>
                            </label>
                            <textarea 
                                id="<?php echo esc_attr($field_id); ?>" 
                                name="<?php echo esc_attr($name); ?>" 
                                <?php echo $required_attr; ?>
                            ></textarea>

                        <?php elseif ($type === 'select'): ?>
                            <label class="tf-label" for="<?php echo esc_attr($field_id); ?>">
                                <?php echo $label; ?><?php echo $required ? ' <span class="tf-required" aria-hidden="true">*</span><span class="screen-reader-text">(obligatoriskt)</span>' : ''; ?>
                            </label>
                            <select 
                                id="<?php echo esc_attr($field_id); ?>" 
                                name="<?php echo esc_attr($name); ?>" 
                                <?php echo $required_attr; ?>
                            >
                                <option value="">-- Välj --</option>
                                <?php foreach ($options as $opt): ?>
                                    <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($type === 'radio'): ?>
                            <fieldset class="tf-fieldset">
                                <legend class="tf-label">
                                    <?php echo $label; ?><?php echo $required ? ' <span class="tf-required" aria-hidden="true">*</span><span class="screen-reader-text">(obligatoriskt)</span>' : ''; ?>
                                </legend>
                                <div class="tf-choices" role="radiogroup" aria-label="<?php echo esc_attr($label); ?>">
                                    <?php foreach ($options as $i => $opt): $opt_id = $field_id.'_'.$i; ?>
                                        <label for="<?php echo esc_attr($opt_id); ?>" class="tf-choice">
                                            <input 
                                                type="radio" 
                                                id="<?php echo esc_attr($opt_id); ?>" 
                                                name="<?php echo esc_attr($name); ?>" 
                                                value="<?php echo esc_attr($opt); ?>" 
                                                <?php echo $required_attr; ?>
                                            />
                                            <?php echo esc_html($opt); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                        <?php elseif ($type === 'checkbox'): ?>
                            <fieldset class="tf-fieldset">
                                <legend class="tf-label">
                                    <?php echo $label; ?><?php echo $required ? ' <span class="tf-required" aria-hidden="true">*</span><span class="screen-reader-text">(obligatoriskt)</span>' : ''; ?>
                                </legend>
                                <div class="tf-choices" role="group" aria-label="<?php echo esc_attr($label); ?>">
                                    <?php foreach ($options as $i => $opt): $opt_id = $field_id.'_'.$i; ?>
                                        <label for="<?php echo esc_attr($opt_id); ?>" class="tf-choice">
                                            <input 
                                                type="checkbox" 
                                                id="<?php echo esc_attr($opt_id); ?>" 
                                                name="<?php echo esc_attr($name); ?>[]" 
                                                value="<?php echo esc_attr($opt); ?>"
                                            />
                                            <?php echo esc_html($opt); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($required): ?>
                                    <input type="hidden" name="<?php echo esc_attr($name); ?>__required" value="1" />
                                <?php endif; ?>
                            </fieldset>

                        <?php elseif ($type === 'file'): 
                            $allowed_types = $field['allowed_types'] ?? '';
                            $max_size = $field['max_size'] ?? 5;
                            if (empty($allowed_types)) {
                                $allowed_types = 'pdf,doc,docx,jpg,jpeg,png,gif';
                            }
                            $accept = '.' . implode(',.', array_map('trim', explode(',', $allowed_types)));
                        ?>
                            <label class="tf-label" for="<?php echo esc_attr($field_id); ?>">
                                <?php echo $label; ?><?php echo $required ? ' <span class="tf-required" aria-hidden="true">*</span><span class="screen-reader-text">(obligatoriskt)</span>' : ''; ?>
                            </label>
                            <input 
                                type="file" 
                                id="<?php echo esc_attr($field_id); ?>" 
                                name="<?php echo esc_attr($name); ?>" 
                                accept="<?php echo esc_attr($accept); ?>"
                                data-max-size="<?php echo esc_attr($max_size); ?>"
                                <?php echo $required_attr; ?>
                            />
                            <span class="tf-file-info">
                                Tillåtna format: <?php echo esc_html(strtoupper(str_replace(',', ', ', $allowed_types))); ?>. 
                                Max <?php echo esc_html($max_size); ?> MB.
                            </span>

                        <?php else: ?>
                            <label class="tf-label" for="<?php echo esc_attr($field_id); ?>">
                                <?php echo $label; ?><?php echo $required ? ' <span class="tf-required" aria-hidden="true">*</span><span class="screen-reader-text">(obligatoriskt)</span>' : ''; ?>
                            </label>
                            <input 
                                type="<?php echo esc_attr($type); ?>" 
                                id="<?php echo esc_attr($field_id); ?>" 
                                name="<?php echo esc_attr($name); ?>" 
                                <?php echo $required_attr; ?>
                            />
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Inga fält definierade i ACF‑repeatern "tranas_forms".</p>
            <?php endif; ?>

            <!-- Honeypot (dold för skärmläsare och visuellt) -->
            <div class="tf-hp" aria-hidden="true">
                <label for="tf-hp-<?php echo $form_id; ?>">Lämna detta fält tomt</label>
                <input type="text" id="tf-hp-<?php echo $form_id; ?>" name="hp_field" value="" tabindex="-1" autocomplete="off" />
            </div>

            <!-- Meddelande-container för AJAX-svar -->
            <div class="tf-message-container" role="alert" aria-live="polite" aria-atomic="true"></div>

            <button type="submit" name="tranas_form_submit" class="tf-submit">
                <span class="tf-submit-text"><?php echo esc_html($submit_label); ?></span>
                <span class="tf-submit-loading" aria-hidden="true" style="display:none;">Skickar...</span>
            </button>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Hantera AJAX-inskick
     */
    public function ajax_handle_submission() {
        $form_id = intval($_POST['tranas_form_id'] ?? 0);
        if (!$form_id) {
            wp_send_json_error(['message' => 'Formulär-ID saknas.']);
        }

        $nonce = $_POST['tranas_form_nonce'] ?? '';
        if (!$nonce || !wp_verify_nonce($nonce, 'tranas_form_submit_'.$form_id)) {
            wp_send_json_error(['message' => 'Säkerhetsvalideringen misslyckades. Ladda om sidan och försök igen.']);
        }

        // Hämta submission token för duplikatdetektering
        $submission_token = sanitize_text_field($_POST['tranas_submission_token'] ?? '');

        if (!empty($_POST['hp_field'])) {
            // Honeypot triggered - låtsas att det lyckades
            wp_send_json_success(['message' => 'Tack för ditt meddelande!']);
        }

        $fields = function_exists('get_field') ? get_field('tranas_forms', $form_id) : [];
        
        // Hämta e-postadress: formulär-specifik -> global standard -> admin e-post
        $recipient = '';
        if (function_exists('get_field')) {
            $recipient = get_field('recipient_email', $form_id);
        }
        if (empty($recipient)) {
            // Kolla global standard från inställningar
            $global_recipient = get_option('tranas_forms_default_email', '');
            if (!empty($global_recipient)) {
                $recipient = $global_recipient;
            } else {
                $recipient = get_option('admin_email');
            }
        }

        $data = [];
        $errors = [];
        $attachments = []; // Filbilagor för e-post
        $uploaded_files = []; // Information om uppladdade filer

        if (is_array($fields)) {
            foreach ($fields as $field) {
                $name = sanitize_title($field['label'] ?? 'falt');
                $label = $field['label'] ?? $name;
                $required = !empty($field['required']);
                $type = $field['type'] ?? 'text';

                // Hantera fil-uppladdning
                if ($type === 'file') {
                    $file_result = $this->handle_file_upload($name, $label, $field, $required);
                    
                    if (is_wp_error($file_result)) {
                        $errors[] = $file_result->get_error_message();
                    } elseif ($file_result) {
                        $data[$label] = $file_result['filename'];
                        $attachments[] = $file_result['path'];
                        $uploaded_files[] = $file_result;
                    } elseif ($required) {
                        $errors[] = sprintf('%s är obligatoriskt.', $label);
                    }
                    continue;
                }

                $raw = $_POST[$name] ?? '';

                // Checkbox kan vara array
                if ($type === 'checkbox') {
                    $arr = isset($_POST[$name]) ? (array) $_POST[$name] : [];
                    $clean = array_map('sanitize_text_field', array_map('trim', $arr));
                    $value = $clean;
                    if ($required && empty($clean) && !empty($_POST[$name.'__required'])) {
                        $errors[] = sprintf('%s är obligatoriskt.', $label);
                    }
                } else {
                    $value = is_string($raw) ? trim($raw) : '';
                    if ($required && $value === '') {
                        $errors[] = sprintf('%s är obligatoriskt.', $label);
                    } elseif ($type === 'email' && $value !== '' && !is_email($value)) {
                        $errors[] = sprintf('%s måste vara en giltig e-postadress.', $label);
                    }
                    $value = ($type === 'email') ? sanitize_email($value) : sanitize_text_field($value);
                }

                $data[$label] = $value;
            }
        }

        // Om det finns valideringsfel, returnera dem
        if (!empty($errors)) {
            wp_send_json_error(['message' => implode(' ', $errors)]);
        }

        // Bygg e‑postmeddelande
        $subject = 'Nytt formulärsvar: ' . get_the_title($form_id);
        $body_parts = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $body_parts[] = $k . ': ' . implode(', ', $v);
            } else {
                $body_parts[] = $k . ': ' . $v;
            }
        }
        $body = implode("\n", $body_parts);
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        // Skicka e‑post med eventuella bilagor och fånga fel
        $this->last_mail_error = null;
        $sent = wp_mail($recipient, $subject, $body, $headers, $attachments);
        $mail_error = $this->last_mail_error ? $this->last_mail_error->get_error_message() : '';

        // Kolla om det redan finns ett inskick med samma token (duplikathantering)
        $existing_submission_id = null;
        if ($submission_token) {
            $existing = get_posts([
                'post_type' => 'tranas_submission',
                'post_status' => 'publish',
                'meta_key' => '_tranas_submission_token',
                'meta_value' => $submission_token,
                'posts_per_page' => 1,
                'fields' => 'ids',
            ]);
            if (!empty($existing)) {
                $existing_submission_id = $existing[0];
                // Kolla om det nya inskicket har filer - i så fall uppdatera det befintliga
                if (!empty($uploaded_files)) {
                    update_post_meta($existing_submission_id, '_tranas_files', $uploaded_files);
                    wp_update_post([
                        'ID' => $existing_submission_id,
                        'post_content' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
                    ]);
                    // Uppdatera mail-body om det finns filer
                    update_post_meta($existing_submission_id, '_tranas_mail_body', $body);
                }
                // Returnera success - det är ett duplikat
                $success_message = function_exists('get_field') ? get_field('success_message', $form_id) : '';
                if (empty($success_message)) {
                    $success_message = 'Tack för ditt meddelande!';
                }
                wp_send_json_success(['message' => $success_message]);
            }
        }

        // Spara inskick som CPT (oavsett skickat eller ej)
        $title = 'Svar: ' . get_the_title($form_id) . ' – ' . current_time('mysql');
        $post_id = wp_insert_post([
            'post_type' => 'tranas_submission',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);

        if ($post_id && !is_wp_error($post_id)) {
            update_post_meta($post_id, '_tranas_form_id', $form_id);
            update_post_meta($post_id, '_tranas_sent', $sent ? 1 : 0);
            update_post_meta($post_id, '_tranas_error', $mail_error);
            update_post_meta($post_id, '_tranas_ip', isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '');
            update_post_meta($post_id, '_tranas_ua', isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '');
            
            // Spara submission token för duplikatdetektering
            if ($submission_token) {
                update_post_meta($post_id, '_tranas_submission_token', $submission_token);
            }
            
            // Spara mail-detaljer för loggning
            update_post_meta($post_id, '_tranas_mail_to', $recipient);
            update_post_meta($post_id, '_tranas_mail_subject', $subject);
            update_post_meta($post_id, '_tranas_mail_body', $body);
            
            // Spara uppladdade filer
            if (!empty($uploaded_files)) {
                update_post_meta($post_id, '_tranas_files', $uploaded_files);
            }
        }

        // Hämta bekräftelsemeddelande från ACF om det finns
        $success_message = function_exists('get_field') ? get_field('success_message', $form_id) : '';
        if (empty($success_message)) {
            $success_message = 'Tack för ditt meddelande!';
        }

        if ($sent) {
            wp_send_json_success(['message' => $success_message]);
        } else {
            wp_send_json_error(['message' => 'Det gick inte att skicka meddelandet. Försök igen senare.']);
        }
    }

    /**
     * Fångar mailfel från wp_mail
     */
    public function on_mail_failed($wp_error) {
        if ($wp_error instanceof WP_Error) {
            $this->last_mail_error = $wp_error;
        }
    }

    /**
     * Hantera fil-uppladdning
     * 
     * @param string $name Fältnamn
     * @param string $label Fältetikett
     * @param array $field Fältkonfiguration
     * @param bool $required Om fältet är obligatoriskt
     * @return array|WP_Error|null Array med filinfo vid lyckat uppladdning, WP_Error vid fel, null om ingen fil
     */
    private function handle_file_upload($name, $label, $field, $required) {
        // Kolla om fil finns
        if (!isset($_FILES[$name]) || empty($_FILES[$name]['name']) || $_FILES[$name]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $file = $_FILES[$name];

        // Kolla för uppladdningsfel
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'Filen är för stor (serverinställning).',
                UPLOAD_ERR_FORM_SIZE => 'Filen är för stor.',
                UPLOAD_ERR_PARTIAL => 'Filen laddades bara upp delvis.',
                UPLOAD_ERR_NO_TMP_DIR => 'Temporär mapp saknas på servern.',
                UPLOAD_ERR_CANT_WRITE => 'Kunde inte skriva filen till disk.',
                UPLOAD_ERR_EXTENSION => 'Uppladdningen stoppades av en PHP-extension.',
            ];
            $msg = isset($error_messages[$file['error']]) ? $error_messages[$file['error']] : 'Okänt uppladdningsfel.';
            return new WP_Error('upload_error', sprintf('%s: %s', $label, $msg));
        }

        // Hämta tillåtna typer och max storlek
        $allowed_types = $field['allowed_types'] ?? '';
        $max_size = intval($field['max_size'] ?? 5);
        
        if (empty($allowed_types)) {
            $allowed_types = 'pdf,doc,docx,jpg,jpeg,png,gif';
        }
        $allowed_extensions = array_map('trim', array_map('strtolower', explode(',', $allowed_types)));
        
        // Validera filtyp
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            return new WP_Error('invalid_type', sprintf(
                '%s: Filtypen "%s" är inte tillåten. Tillåtna typer: %s.',
                $label,
                $file_extension,
                strtoupper(implode(', ', $allowed_extensions))
            ));
        }

        // Validera filstorlek
        $max_bytes = $max_size * 1024 * 1024;
        if ($file['size'] > $max_bytes) {
            return new WP_Error('file_too_large', sprintf(
                '%s: Filen är för stor. Max storlek är %d MB.',
                $label,
                $max_size
            ));
        }

        // Ladda in WordPress fil-funktioner
        require_once(ABSPATH . 'wp-admin/includes/file.php');

        // Skapa uppladdningsmapp för formulärinskick
        $upload_dir = wp_upload_dir();
        $tranas_forms_dir = $upload_dir['basedir'] . '/tranas-forms-uploads/' . date('Y/m');
        
        if (!file_exists($tranas_forms_dir)) {
            wp_mkdir_p($tranas_forms_dir);
            // Lägg till .htaccess för säkerhet
            $htaccess = $upload_dir['basedir'] . '/tranas-forms-uploads/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Options -Indexes\n");
            }
        }

        // Generera unikt filnamn
        $unique_filename = wp_unique_filename($tranas_forms_dir, sanitize_file_name($file['name']));
        $destination = $tranas_forms_dir . '/' . $unique_filename;

        // Flytta filen
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return new WP_Error('move_failed', sprintf('%s: Kunde inte spara filen.', $label));
        }

        // Sätt rätt filrättigheter
        chmod($destination, 0644);

        return [
            'filename' => $file['name'],
            'path' => $destination,
            'url' => str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $destination),
            'size' => $file['size'],
            'type' => $file_extension,
        ];
    }

    /**
     * Admin: lägg till submenu "Inskick" under Formulär
     */
    public function register_admin_pages() {
        add_submenu_page(
            'edit.php?post_type=tranas_form',
            __('Inskick', 'tranas-forms'),
            __('Inskick', 'tranas-forms'),
            'edit_posts',
            'tranas-forms-submissions',
            [$this, 'render_submissions_page']
        );
        
        add_submenu_page(
            'edit.php?post_type=tranas_form',
            __('Inställningar', 'tranas-forms'),
            __('Inställningar', 'tranas-forms'),
            'manage_options',
            'tranas-forms-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Kolumner i tranas_submission listan (CPT‑skärmar)
     */
    public function submission_columns($columns) {
        $columns['form'] = __('Formulär', 'tranas-forms');
        $columns['sent'] = __('Skickat', 'tranas-forms');
        $columns['error'] = __('Fel', 'tranas-forms');
        return $columns;
    }

    public function submission_column_content($column, $post_id) {
        if ($column === 'form') {
            $fid = intval(get_post_meta($post_id, '_tranas_form_id', true));
            if ($fid) {
                echo '<a href="'.esc_url(get_edit_post_link($fid)).'">'.esc_html(get_the_title($fid)).'</a>';
            } else {
                echo '-';
            }
        } elseif ($column === 'sent') {
            $sent = intval(get_post_meta($post_id, '_tranas_sent', true));
            echo $sent ? '✓' : '✗';
        } elseif ($column === 'error') {
            $err = get_post_meta($post_id, '_tranas_error', true);
            echo $err ? esc_html($err) : '-';
        }
    }

    /**
     * Admin‑sida: lista inskick (+ filter per formulär)
     */
    public function render_submissions_page() {
        if (!current_user_can('edit_posts')) return;

        $current_form = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        $query_args = [
            'post_type' => 'tranas_submission',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if ($current_form) {
            $query_args['meta_query'] = [[
                'key' => '_tranas_form_id',
                'value' => $current_form,
                'compare' => '=',
            ]];
        }
        $q = new WP_Query($query_args);

        // Dropdown med formulär
        $forms = get_posts([
            'post_type' => 'tranas_form',
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Inskick', 'tranas-forms').'</h1>';

        echo '<form method="get" style="margin-bottom:1em;">';
        echo '<input type="hidden" name="post_type" value="tranas_form" />';
        echo '<input type="hidden" name="page" value="tranas-forms-submissions" />';
        echo '<label>'.esc_html__('Filtrera på formulär:', 'tranas-forms').'</label> ';
        echo '<select name="form_id">';
        echo '<option value="0">'.esc_html__('Alla', 'tranas-forms').'</option>';
        foreach ($forms as $f) {
            $sel = ($current_form === $f->ID) ? 'selected' : '';
            echo '<option value="'.intval($f->ID).'" '.$sel.'>'.esc_html(get_the_title($f)).'</option>';
        }
        echo '</select> ';
        echo '<button class="button">'.esc_html__('Filtrera', 'tranas-forms').'</button>';
        echo '</form>';

        echo '<table class="widefat fixed striped tf-admin-table">';
        echo '<thead><tr>';
        echo '<th style="width:140px;">'.esc_html__('Datum', 'tranas-forms').'</th>';
        echo '<th style="width:150px;">'.esc_html__('Formulär', 'tranas-forms').'</th>';
        echo '<th style="width:70px;">'.esc_html__('Status', 'tranas-forms').'</th>';
        echo '<th>'.esc_html__('Detaljer', 'tranas-forms').'</th>';
        echo '</tr></thead><tbody>';

        if ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $fid = intval(get_post_meta($p->ID, '_tranas_form_id', true));
                $sent = intval(get_post_meta($p->ID, '_tranas_sent', true));
                $err = get_post_meta($p->ID, '_tranas_error', true);
                $data_json = get_post_field('post_content', $p->ID);
                $data = json_decode($data_json, true);
                if (!is_array($data)) $data = [];
                
                // Mail-detaljer
                $mail_to = get_post_meta($p->ID, '_tranas_mail_to', true);
                $mail_subject = get_post_meta($p->ID, '_tranas_mail_subject', true);
                $mail_body = get_post_meta($p->ID, '_tranas_mail_body', true);
                $ip = get_post_meta($p->ID, '_tranas_ip', true);

                echo '<tr>';
                echo '<td>'.esc_html(get_the_date('Y-m-d H:i', $p)).'</td>';
                echo '<td>'.($fid ? '<a href="'.esc_url(get_edit_post_link($fid)).'">'.esc_html(get_the_title($fid)).'</a>' : '-').'</td>';
                
                // Status med ikon och färg
                if ($sent) {
                    echo '<td><span style="color:#46b450;">✓ Skickat</span></td>';
                } else {
                    echo '<td><span style="color:#dc3232;" title="'.esc_attr($err).'">✗ Misslyckat</span></td>';
                }
                
                // Detaljer med expanderbar sektion
                echo '<td>';
                echo '<details class="tf-submission-details">';
                echo '<summary style="cursor:pointer;font-weight:600;">Visa detaljer</summary>';
                echo '<div style="margin-top:10px;">';
                
                // Formulärdata
                echo '<div style="background:#f9f9f9;padding:10px;border-radius:4px;margin-bottom:10px;">';
                echo '<strong style="display:block;margin-bottom:5px;">📝 Inskickad data:</strong>';
                foreach ($data as $k => $v) {
                    if (is_array($v)) {
                        echo '<div><em>'.esc_html($k).':</em> '.esc_html(implode(', ', $v)).'</div>';
                    } else {
                        echo '<div><em>'.esc_html($k).':</em> '.esc_html($v).'</div>';
                    }
                }
                echo '</div>';
                
                // Uppladdade filer
                $files = get_post_meta($p->ID, '_tranas_files', true);
                if (!empty($files) && is_array($files)) {
                    echo '<div style="background:#e8f5e9;padding:10px;border-radius:4px;margin-bottom:10px;border:1px solid #4caf50;">';
                    echo '<strong style="display:block;margin-bottom:5px;">📎 Bifogade filer:</strong>';
                    foreach ($files as $file) {
                        $file_size = isset($file['size']) ? size_format($file['size']) : '';
                        echo '<div style="margin:3px 0;">';
                        echo '<a href="'.esc_url($file['url']).'" target="_blank" style="color:#2e7d32;">'.esc_html($file['filename']).'</a>';
                        if ($file_size) {
                            echo ' <span style="color:#666;font-size:11px;">('.esc_html($file_size).')</span>';
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                }
                
                // Mail-logg
                if ($mail_to || $mail_subject) {
                    echo '<div style="background:#fff8e5;padding:10px;border-radius:4px;margin-bottom:10px;border:1px solid #ffcc00;">';
                    echo '<strong style="display:block;margin-bottom:5px;">📧 Mail-logg:</strong>';
                    echo '<div><em>Till:</em> '.esc_html($mail_to).'</div>';
                    echo '<div><em>Ämne:</em> '.esc_html($mail_subject).'</div>';
                    echo '<div style="margin-top:5px;"><em>Innehåll:</em></div>';
                    echo '<pre style="background:#fff;padding:8px;border-radius:3px;font-size:12px;white-space:pre-wrap;margin:5px 0 0 0;">'.esc_html($mail_body).'</pre>';
                    if ($err) {
                        echo '<div style="margin-top:8px;color:#dc3232;"><em>Fel:</em> '.esc_html($err).'</div>';
                    }
                    echo '</div>';
                }
                
                // Teknisk info
                if ($ip) {
                    echo '<div style="font-size:11px;color:#666;">';
                    echo '<em>IP:</em> '.esc_html($ip);
                    echo '</div>';
                }
                
                echo '</div>';
                echo '</details>';
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="4">'.esc_html__('Inga inskick hittades.', 'tranas-forms').'</td></tr>';
        }

        echo '</tbody></table>';

        // Pagination
        $total_pages = $q->max_num_pages;
        if ($total_pages > 1) {
            $base_url = remove_query_arg('paged');
            $links = paginate_links([
                'base' => add_query_arg('paged', '%#%', $base_url),
                'format' => '',
                'current' => $paged,
                'total' => $total_pages,
                'type' => 'array',
            ]);
            if (is_array($links)) {
                echo '<div class="tablenav"><div class="tablenav-pages">'.implode(' ', $links).'</div></div>';
            }
        }

        echo '</div>';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Hantera formulärpost
        if (isset($_POST['tranas_forms_settings_submit']) && check_admin_referer('tranas_forms_settings')) {
            $default_email = sanitize_email($_POST['tranas_forms_default_email'] ?? '');
            update_option('tranas_forms_default_email', $default_email);
            
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Inställningar sparade.', 'tranas-forms') . '</p></div>';
        }

        $version = $this->get_plugin_version();
        $default_email = get_option('tranas_forms_default_email', '');
        ?>
        <div class="wrap tranas-forms-wrap">
            <h1><?php _e('Inställningar', 'tranas-forms'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tranas_forms_settings'); ?>
                
                <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                    <h2><?php _e('E-postinställningar', 'tranas-forms'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="tranas_forms_default_email"><?php _e('Standard e-postadress', 'tranas-forms'); ?></label>
                            </th>
                            <td>
                                <input 
                                    type="email" 
                                    id="tranas_forms_default_email" 
                                    name="tranas_forms_default_email" 
                                    value="<?php echo esc_attr($default_email); ?>" 
                                    class="regular-text"
                                    placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
                                />
                                <p class="description">
                                    <?php _e('Standard e-postadress som används när inget formulär-specifik e-postadress är angiven. Lämna tomt för att använda admin-e-post.', 'tranas-forms'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                    <h2><?php _e('Version-information', 'tranas-forms'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Version', 'tranas-forms'); ?></th>
                            <td><strong>v<?php echo esc_html($version); ?></strong></td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="tranas_forms_settings_submit" class="button button-primary" value="<?php esc_attr_e('Spara inställningar', 'tranas-forms'); ?>" />
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Get plugin version from package.json or plugin header
     */
    private function get_plugin_version() {
        // Try to read from package.json first
        $package_json_path = plugin_dir_path(__FILE__) . 'package.json';
        if (file_exists($package_json_path)) {
            $package_json = json_decode(file_get_contents($package_json_path), true);
            if (isset($package_json['version'])) {
                return $package_json['version'];
            }
        }
        
        // Fallback to plugin header
        $plugin_data = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
        return isset($plugin_data['Version']) ? $plugin_data['Version'] : '1';
    }
}

new Tranas_Forms_Plugin();

