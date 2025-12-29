
# Tranås Forms – Komplett instruktionsguide (för Cursor)

Den här guiden beskriver en **helhetslösning** för ett WordPress‑plugin som heter **Tranås Forms**:

- Skapar **Custom Post Types** för **Formulär** och **Inskick**.
- Läser fältdefinitioner från **ACF** (upprepningsfält) i wp‑admin.
- Renderar formulär via **shortcode**.
- Tar emot och **validerar** inskick.
- **Skickar e‑post** via `wp_mail()` (servern), med stöd för SMTP.
- **Sparar alla inskick** (även misslyckade utskick) i en CPT och listar dem på en **sub‑sida** under menyn **Formulär** i wp‑admin.

> **ACF‑upprepningsfält**: Anta att ditt repeaterfält för formulärheter **`tranas_forms`**. Du kan ändra namnet i koden om du använder annat.

## [1] SMTP konfigurerat (plugin eller kod)
## [2] SPF/DKIM på domänen (hos din DNS-leverantör)
## [3] Testa att mail kommer fram
## [4] Kolla att recipient_email är satt på formulären

---

## 1) Filstruktur
Skapa mappen:
```
wp-content/plugins/tranas-forms/
```
Lägg följande filer:
```
tranas-forms.php
assets/
  forms.css
```

---

## 2) Huvudfil: `tranas-forms.php`
Klistra in koden nedan.

```php
<?php
/**
 * Plugin Name: Tranås Forms
 * Description: Formulärplugin som använder ACF för fälten, skickar e‑post via wp_mail(), och sparar/listar inskick (inkl. misslyckade).
 * Version: 1.0.0
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
        add_action('init', [$this, 'maybe_handle_submission']);

        // Admin: submenu för inskick under "Formulär"
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_filter('manage_tranas_submission_posts_columns', [$this, 'submission_columns']);
        add_action('manage_tranas_submission_posts_custom_column', [$this, 'submission_column_content'], 10, 2);

        // Fånga mailfel
        add_action('wp_mail_failed', [$this, 'on_mail_failed']);
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
        wp_enqueue_style('tranas-forms', plugins_url('assets/forms.css', __FILE__), [], '1.0.0');
    }

    /**
     * Shortcode: [tranas_form id="FORM_ID"]
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
        <form method="post" action="" enctype="application/x-www-form-urlencoded">
            <?php wp_nonce_field('tranas_form_submit_'.$form_id, 'tranas_form_nonce'); ?>
            <input type="hidden" name="tranas_form_id" value="<?php echo esc_attr($form_id); ?>" />

            <?php if (!empty($fields) && is_array($fields)): ?>
                <?php foreach ($fields as $field):
                    $name = sanitize_title($field['label'] ?? 'falt');
                    $label = esc_html($field['label'] ?? '');
                    $type  = $field['type'] ?? 'text';
                    $required = !empty($field['required']);
                    $options = array_filter(array_map('trim', preg_split('/\r?\n/', $field['options'] ?? '')));
                    ?>

                    <div class="tf-field">
                        <label class="tf-label">
                            <?php echo $label; ?><?php echo $required ? ' *' : ''; ?>
                        </label>

                        <?php if ($type === 'textarea'): ?>
                            <textarea name="<?php echo esc_attr($name); ?>" <?php echo $required ? 'required' : ''; ?>></textarea>
                        <?php elseif ($type === 'select'): ?>
                            <select name="<?php echo esc_attr($name); ?>" <?php echo $required ? 'required' : ''; ?>>
                                <option value="">-- Välj --</option>
                                <?php foreach ($options as $opt): ?>
                                    <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($type === 'radio'): ?>
                            <?php foreach ($options as $i => $opt): $id = $name.'_'.$i; ?>
                                <label for="<?php echo esc_attr($id); ?>" class="tf-choice">
                                    <input type="radio" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($opt); ?>" <?php echo $required ? 'required' : ''; ?> />
                                    <?php echo esc_html($opt); ?>
                                </label>
                            <?php endforeach; ?>
                        <?php elseif ($type === 'checkbox'): ?>
                            <?php foreach ($options as $i => $opt): $id = $name.'_'.$i; ?>
                                <label for="<?php echo esc_attr($id); ?>" class="tf-choice">
                                    <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>[]" value="<?php echo esc_attr($opt); ?>" />
                                    <?php echo esc_html($opt); ?>
                                </label>
                            <?php endforeach; ?>
                            <?php if ($required): ?>
                                <input type="hidden" name="<?php echo esc_attr($name); ?>__required" value="1" />
                            <?php endif; ?>
                        <?php else: ?>
                            <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" <?php echo $required ? 'required' : ''; ?> />
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Inga fält definierade i ACF‑repeatern "tranas_forms".</p>
            <?php endif; ?>

            <!-- Honeypot -->
            <div style="display:none">
                <label>Leave this field empty</label>
                <input type="text" name="hp_field" value="" />
            </div>

            <button type="submit" name="tranas_form_submit" class="tf-submit"><?php echo esc_html($submit_label); ?></button>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Hantera inskick
     */
    public function maybe_handle_submission() {
        if (!isset($_POST['tranas_form_submit'])) return;

        $form_id = intval($_POST['tranas_form_id'] ?? 0);
        if (!$form_id) return;

        if (!isset($_POST['tranas_form_nonce']) || !wp_verify_nonce($_POST['tranas_form_nonce'], 'tranas_form_submit_'.$form_id)) return;
        if (!empty($_POST['hp_field'])) return; // honeypot

        $fields = function_exists('get_field') ? get_field('tranas_forms', $form_id) : [];
        $recipient = function_exists('get_field') ? get_field('recipient_email', $form_id) : get_option('admin_email');

        $data = [];
        $errors = [];

        if (is_array($fields)) {
            foreach ($fields as $field) {
                $name = sanitize_title($field['label'] ?? 'falt');
                $label = $field['label'] ?? $name;
                $required = !empty($field['required']);
                $type = $field['type'] ?? 'text';

                $raw = $_POST[$name] ?? '';

                // Checkbox kan vara array
                if ($type === 'checkbox') {
                    $arr = isset($_POST[$name]) ? (array) $_POST[$name] : [];
                    $clean = array_map('sanitize_text_field', array_map('trim', $arr));
                    $value = $clean;
                    if ($required && empty($clean) && !empty($_POST[$name.'__required'])) {
                        $errors[] = sprintf('%s är obligatoriskt.', esc_html($label));
                    }
                } else {
                    $value = is_string($raw) ? trim($raw) : '';
                    if ($required && $value === '') {
                        $errors[] = sprintf('%s är obligatoriskt.', esc_html($label));
                    } elseif ($type === 'email' && $value !== '' && !is_email($value)) {
                        $errors[] = sprintf('%s måste vara en giltig e‑postadress.', esc_html($label));
                    }
                    $value = ($type === 'email') ? sanitize_email($value) : sanitize_text_field($value);
                }

                $data[$label] = $value;
            }
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

        // Skicka e‑post och fånga fel
        $this->last_mail_error = null; // nollställ
        $sent = wp_mail($recipient, $subject, $body, $headers);
        $mail_error = $this->last_mail_error ? $this->last_mail_error->get_error_message() : '';

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
        }

        // Redirect för att undvika dubbelpostningar
        wp_safe_redirect(add_query_arg('tf_thanks', $sent ? '1' : '0', wp_get_referer() ?: get_permalink($form_id)));
        exit;
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

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Datum', 'tranas-forms').'</th>';
        echo '<th>'.esc_html__('Formulär', 'tranas-forms').'</th>';
        echo '<th>'.esc_html__('Skickat', 'tranas-forms').'</th>';
        echo '<th>'.esc_html__('Fel', 'tranas-forms').'</th>';
        echo '<th>'.esc_html__('Förhandsvisning', 'tranas-forms').'</th>';
        echo '</tr></thead><tbody>';

        if ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $fid = intval(get_post_meta($p->ID, '_tranas_form_id', true));
                $sent = intval(get_post_meta($p->ID, '_tranas_sent', true));
                $err = get_post_meta($p->ID, '_tranas_error', true);
                $data_json = get_post_field('post_content', $p->ID);
                $data = json_decode($data_json, true);
                if (!is_array($data)) $data = [];

                echo '<tr>';
                echo '<td>'.esc_html(get_the_date('Y-m-d H:i', $p)).'</td>';
                echo '<td>'.($fid ? '<a href="'.esc_url(get_edit_post_link($fid)).'">'.esc_html(get_the_title($fid)).'</a>' : '-').'</td>';
                echo '<td>'.($sent ? '✓' : '✗').'</td>';
                echo '<td>'.($err ? esc_html($err) : '-').'</td>';
                echo '<td>';
                foreach ($data as $k => $v) {
                    if (is_array($v)) {
                        echo '<div><strong>'.esc_html($k).'</strong>: '.esc_html(implode(', ', $v)).'</div>';
                    } else {
                        echo '<div><strong>'.esc_html($k).'</strong>: '.esc_html($v).'</div>';
                    }
                }
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="5">'.esc_html__('Inga inskick hittades.', 'tranas-forms').'</td></tr>';
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
}

new Tranas_Forms_Plugin();
```

---

## 3) CSS: `assets/forms.css`
Valfri grundstil.

```css
.tf-field { margin-bottom: 1rem; }
.tf-label { display:block; font-weight:600; margin-bottom:0.25rem; }
.tf-choice { display:block; margin:0.25rem 0; }
.tf-submit { display:inline-block; padding:0.6rem 1rem; background:#0073aa; color:#fff; border:none; border-radius:4px; }
.tf-submit:hover { background:#006097; }
```

---

## 4) ACF – fältgrupp för Formulär (`tranas_form`)
Skapa en **Field Group** som visas när Post Type = **tranas_form**. Lägg till:

- **`tranas_forms`** (*Repeater*)
  - **`label`** (Text) – fältets etikett (används också för `name` via slug)
  - **`type`** (Select) – `text`, `email`, `textarea`, `select`, `radio`, `checkbox`
  - **`required`** (True/False)
  - **`options`** (Textarea) – *valfritt*, för `select/radio/checkbox` (en rad per alternativ)
- **`recipient_email`** (Text) – mottagare för aviseringar (tomt ⇒ `admin_email`)
- **`submit_label`** (Text) – knapptext (standard: “Skicka”)

> **Obs:** Repeater kräver **ACF Pro**. Utan Pro kan du bygga med fasta fält eller Flexible Content.

---

## 5) Shortcode‑användning
Placera formuläret där du vill (sida/inlägg) med:
```
[tranas_form id="FORMULÄR_ID"]
```
Hitta `FORMULÄR_ID` på den “Formulär”‑post du skapade.

Efter inskick redirectas användaren tillbaka med `?tf_thanks=1` (lyckat) eller `?tf_thanks=0` (misslyckat). Du kan i ditt tema visa ett meddelande baserat på den parametern.

---

## 6) Admin – Inskick (sub‑sida)
Under **Formulär** i wp‑admin finns en undermeny **Inskick** som listar alla svar, med:
- **Datum**
- **Formulär** (länk till redigering)
- **Skickat** (✓/✗)
- **Fel** (om utskicket misslyckats)
- **Förhandsvisning** av inskickade fält

Du kan filtrera listan per formulär via dropdown.

> **Tips:** Inskicken sparas som CPT `tranas_submission`. Öppna ett enskilt inskick för att se JSON‑innehållet.

---

## 7) E‑post & SMTP
Pluginet skickar med `wp_mail()`.

**A) Enklast:** Installera *WP Mail SMTP* och konfigurera din leverantör (Brevo/Sendinblue, Mailgun, Postmark m.fl.).

**B) Kodvägen:** Lägg i tema eller mu‑plugin:
```php
add_action('phpmailer_init', function($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host = 'smtp.example.com';
    $phpmailer->Port = 587;
    $phpmailer->SMTPAuth = true;
    $phpmailer->Username = 'user@example.com';
    $phpmailer->Password = 'hemligt';
    $phpmailer->SMTPSecure = 'tls';
    $phpmailer->From = 'no-reply@example.com';
    $phpmailer->FromName = 'Webb';
});
```

> **Felhantering:** Pluginet fångar mailfel via `wp_mail_failed` och sparar meddelandet på inskicket.

---

## 8) Säkerhet, validering & spam
- **Nonce**: `wp_nonce_field` + `wp_verify_nonce`
- **Sanitering**: `sanitize_text_field`, `sanitize_email`, hantering av arrays för checkbox
- **Spam**: Honeypotfält
- **Tillgänglighet**: Alla inputs har label; förbättra vid behov med aria‑attribut

---

## 9) Felsökning
- **Formulär renderas inte**: Är ACF aktivt? Har du fält i `tranas_forms`? Stämmer shortcode‑ID?
- **E‑post skickas inte**: Testa med WP Mail SMTP → *Email Test*. Kolla SPF/DKIM på domänen; prova port 587/465.
- **Ingen listning i Inskick**: Har du rätt kapacitet (`edit_posts`)? Finns det inskick?

---

## 10) Vidareutveckling (frivilligt)
- **Filuppladdning**: Lägg `enctype="multipart/form-data"` och hantera med `wp_handle_upload` samt begränsa MIME/storlek.
- **Villkorlig logik**: Lagra regler i ACF och visa/dölj fält via JS.
- **Egen DB‑tabell**: För hög volym/rapportering, skapa tabell i `register_activation_hook` och spara strukturerat.
- **Gutenberg‑block**: Registrera block som SSR:ar formuläret.
- **GDPR**: Samtycke, gallring, syfte, export.

---

## 11) Snabb checklista
- [ ] Skapa pluginmapp och lägg in `tranas-forms.php` + `assets/forms.css`
- [ ] Skapa ACF Field Group kopplad till `tranas_form` med `tranas_forms`, `recipient_email`, `submit_label`
- [ ] Skapa ett formulärinlägg (titel och fält via ACF)
- [ ] Placera `[tranas_form id="ID"]` på en sida
- [ ] Konfigurera SMTP (plugin eller kod)
- [ ] Testa inskick: kontrollera **Inskick**‑sidan samt e‑postleverans

---

## 12) Support
Om du vill kan jag generera färdiga filer (inkl. minst antal JS/CSS, metaboxer för visning av inskick) som du klistrar in direkt i projektet.
