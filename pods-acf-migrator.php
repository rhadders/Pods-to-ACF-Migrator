<?php
/*
Plugin Name: Pods to ACF Migrator
Description: Migrate Pods custom post types and fields to ACF Pro
Version: 1.0
Author: Ruben Hadders | Jona zinvolle communicatie
*/

if (!defined('ABSPATH')) exit;

define('PODS_ACF_JSON_DIR', plugin_dir_path(__FILE__) . 'acf-json/');
define('PODS_ACF_LOG_FILE', plugin_dir_path(__FILE__) . 'migration.log');

register_activation_hook(__FILE__, function() {
    if (!file_exists(PODS_ACF_JSON_DIR)) wp_mkdir_p(PODS_ACF_JSON_DIR);
    if (!file_exists(PODS_ACF_LOG_FILE)) file_put_contents(PODS_ACF_LOG_FILE, "");
});

add_filter('acf/settings/load_json', function($paths) {
    $paths[] = PODS_ACF_JSON_DIR;
    return $paths;
});

add_action('admin_menu', function() {
    add_menu_page(
        'Pods to ACF Migrator',
        'Pods to ACF Migrator',
        'manage_options',
        'pods-acf-migrator',
        'pods_acf_migrator_dashboard',
        'dashicons-migrate',
        31
    );
    add_submenu_page(
        'pods-acf-migrator',
        'Exported JSON files',
        'Exported JSON files',
        'manage_options',
        'pods-acf-migrator-exports',
        'pods_acf_migrator_exports_page'
    );
    add_submenu_page(
        'pods-acf-migrator',
        'Migration Log',
        'Migration Log',
        'manage_options',
        'pods-acf-migrator-log',
        'pods_acf_migrator_log_page'
    );
});

// Helper: check if field should be excluded (Pods-only or technical)
function pods_acf_is_field_excluded($fname) {
    $excluded_prefixes = [
        '_pods_', '_podsrel_', '_podsmeta_', '_podsfield_', '_podsuid_', '_podsname_', '_podslabel_', 'pods_', 'podsmeta_', 'podsrel_', 'podsfield_'
    ];
    $excluded_fields = [
        'id', 'pod_id', 'created', 'modified', 'menu_order', 'post_status', 'guid', 'post_type', 'post_name', 'post_title', 'post_excerpt', 'post_content', 'post_parent',
        'post_author', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'comment_count', 'ping_status', 'to_ping', 'pinged',
        'post_password', 'post_mime_type', 'comment_status', 'filter', 'slug', 'type', 'status'
    ];
    if (in_array($fname, $excluded_fields)) return true;
    foreach ($excluded_prefixes as $prefix) {
        if (strpos($fname, $prefix) === 0) return true;
    }
    if (strpos($fname, '@wp_') === 0) return true;
    return false;
}

function pods_acf_migrator_dashboard() {
    if (!current_user_can('manage_options')) wp_die("You do not have sufficient permissions to access this page.");
    echo '<div class="wrap">';
    echo '<h1>Pods to ACF Migrator</h1>';

    // Navigation: three links with icon
    echo '<div class="pods-acf-nav" style="margin:16px 0 18px 0;">
        <span style="margin-right:18px;">
            <span class="dashicons dashicons-media-default" style="vertical-align:middle;font-size:17px;margin-right:3px;"></span>
            <a href="'.admin_url('admin.php?page=pods-acf-migrator-exports').'">Exported JSON files</a>
        </span>
        <span style="margin-right:18px;">
            <span class="dashicons dashicons-list-view" style="vertical-align:middle;font-size:17px;margin-right:3px;"></span>
            <a href="'.admin_url('admin.php?page=pods-acf-migrator-log').'">Migration Log</a>
        </span>
        <span>
            <span class="dashicons dashicons-book-alt" style="vertical-align:middle;font-size:17px;margin-right:3px;"></span>
            <a href="https://www.advancedcustomfields.com/resources/synchronized-json/" target="_blank" rel="noopener">ACF JSON Sync documentation</a>
        </span>
    </div>';

    // Pods check
    if (!class_exists('Pods')) {
        echo '<div class="notice notice-error"><p>Pods plugin is not active. Please install and activate Pods.</p></div>';
        echo '</div>'; return;
    }

    // ACF Pro check
    $acf_version = null;
    if (class_exists('ACF')) {
        if (defined('ACF_PRO')) {
            if (defined('ACF_VERSION')) $acf_version = ACF_VERSION;
            elseif (method_exists('acf', 'get_version')) $acf_version = acf()->get_version();
            else $acf_version = get_option('acf_pro_version', get_option('acf_version', '0'));
        } else {
            $acf_version = get_option('acf_pro_version', get_option('acf_version', '0'));
        }
    }
    $acf_ok = ($acf_version && version_compare($acf_version, '6.0.0', '>='));
    if (!$acf_ok) {
        echo '<div class="notice notice-error"><p><b>ACF Pro 6.0 or higher is required.</b> Please install and activate ACF Pro 6+ before migrating. <a href="https://www.advancedcustomfields.com/pro/" target="_blank" rel="noopener">More info</a></p></div>';
    }

    // Handle export
    if (!empty($_POST['pods_acf_export_nonce']) && wp_verify_nonce($_POST['pods_acf_export_nonce'], 'pods_acf_export_action')) {
        if (!$acf_ok) {
            pods_acf_migrator_modal('Migration failed: ACF Pro 6.0+ is not available.', false, true);
        } else {
            $export_result = pods_acf_migrator_handle_export();
            echo $export_result['notice'];
            if ($export_result['success']) {
                $summary = $export_result['summary'];
                pods_acf_migrator_modal(
                    'Migration complete!<br><br>
                    <b>Exported:</b><br>' . nl2br(esc_html($summary)) . '<br><br>
                    <b>Next steps:</b>
                    <ol>
                        <li>Go to <b>ACF → Field Groups</b> and/or <b>ACF → Post Types</b> in your dashboard.</li>
                        <li>Click on <b>Sync available</b> at the top to import the newly generated field groups and/or post types.</li>
                        <li>Review, adjust and publish the imported ACF items as needed.</li>
                    </ol>',
                    true
                );
            } else {
                pods_acf_migrator_modal('Migration failed: '.$export_result['error'], false, true);
            }
        }
    }

    pods_acf_migrator_export_form($acf_ok);
    echo '<style>
        .pods-acf-nav a { text-decoration:none; color:#2271b1; font-weight:500;}
        .pods-acf-nav a:hover { text-decoration:underline; }
        .pods-acf-nav .dashicons { color:#555; }
    </style>';
    echo '</div>';
}

// --- MODAL FUNCTION ---
function pods_acf_migrator_modal($content, $success = true, $forceShow = false) {
    $color = $success ? '#46b450' : '#d63638'; // WP groen of rood
    $icon = $success ? 'yes' : 'no-alt';
    echo '
    <div id="pods-acf-modal" style="display:block;position:fixed;z-index:99999;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,.25);font-family:inherit;">
        <div style="
            background:#fff;
            max-width:500px;
            margin:6% auto;
            padding:36px 32px 28px 32px;
            border-radius:8px;
            box-shadow:0 8px 32px #0002;
            position:relative;
        ">
            <div style="display:flex;align-items:center;margin-bottom:30px;">
                <span class="dashicons dashicons-'.$icon.'" style="font-size:40px;color:'.$color.';line-height:1;flex-shrink:0;display:flex;align-items:center;padding-right:14px;"></span>
                <span style="font-size:1.7em; font-weight:700; color:#1d2327; line-height:1.13;">'.($success?'Success':'Error').'</span>
            </div>
            <div style="color:#2c3338;font-size:1.09em;line-height:1.6;">'.$content.'</div>
            <a href="#" onclick="document.getElementById(\'pods-acf-modal\').remove();document.body.style.overflow=\'auto\';return false;"
                class="button button-primary"
                style="
                    margin-top:30px;
                    min-width:90px;
                    font-size:1.05em;
                    font-weight:500;
                    border-radius:6px;
                    padding-left:28px;
                    padding-right:28px;
                "
            >Close</a>
        </div>
    </div>
    <script>document.body.style.overflow=\'hidden\';</script>';
    if ($forceShow) {
        echo "<script>window.setTimeout(function(){document.getElementById('pods-acf-modal').style.display='block';},50);</script>";
    }
}

// --- EXPORT FORM UI ---
function pods_acf_migrator_export_form($acf_ok) {
    $all_pods = pods_api()->load_pods([]);
    $found = 0; $pods_types = [];
    foreach ($all_pods as $key => $pod) {
        $type = $name = $label = '-';
        $pod_arr = (array) $pod;
        if (isset($pod_arr["\0*\0args"])) {
            $args = $pod_arr["\0*\0args"];
            $type = $args['type'] ?? '-';
            $name = $args['name'] ?? '-';
            $label = $args['label'] ?? '-';
        }
        if ($type === 'post_type') {
            $found++;
            $pods_types[$name] = [
                'label' => $label,
                'name'  => $name,
                'fields'=> []
            ];
            $pod_obj = pods($name);
            if ($pod_obj && method_exists($pod_obj, 'fields')) {
                foreach ($pod_obj->fields() as $fname => $fdata) {
                    if (pods_acf_is_field_excluded($fname)) continue; // skip Pods-only fields
                    $pods_types[$name]['fields'][$fname] = [
                        'label' => $fdata['label'] ?? $fname,
                        'type'  => $fdata['type'] ?? 'text'
                    ];
                }
            }
        }
    }
    if ($found === 0) {
        echo '<div class="notice notice-warning"><p>No custom post types found in Pods to migrate.</p></div>'; return;
    }
    ?>
    <form method="post" id="pods-acf-export-form" style="margin-top:20px;">
    <?php wp_nonce_field('pods_acf_export_action', 'pods_acf_export_nonce'); ?>
    <div style="margin-bottom:15px;">
        <input type="text" id="pods-search-cpt" placeholder="Search post types or fields..." style="width:340px;padding:5px 9px;border-radius:4px;border:1px solid #ccc;font-size:1em;">
        <button type="button" class="button" id="pods-reset-btn" style="margin-left:18px;">Reset selection</button>
    </div>
    <div id="pods-acf-accordions">
    <?php foreach($pods_types as $pt_slug => $ptdata): ?>
        <div class="pods-accordion" data-cpt="<?php echo esc_attr(strtolower($ptdata['label'].' '.$pt_slug)); ?>">
            <div class="pods-acc-title" onclick="podsAccToggle('<?php echo esc_attr($pt_slug); ?>')">
                <input type="checkbox" id="cpt_<?php echo esc_attr($pt_slug); ?>" name="pods_export[]" value="<?php echo esc_attr($pt_slug); ?>" style="margin-right:12px;" onchange="podsAccCheckFields('<?php echo esc_attr($pt_slug); ?>')">
                <span class="pods-acc-label"><?php echo esc_html($ptdata['label']); ?></span>
                <span style="color:#999;font-size:0.96em;">(<?php echo esc_html($pt_slug); ?>)</span>
                <span style="float:right;color:#888;">&#x25BC;</span>
            </div>
            <div class="pods-acc-panel" id="panel_<?php echo esc_attr($pt_slug); ?>">
                <p style="margin-bottom:6px;color:#444;">
                    Select which fields and/or structure to migrate for <b><?php echo esc_html($ptdata['label']); ?></b>:
                </p>
                <label style="margin-bottom:7px; display:block;">
                    <input type="checkbox" name="pods_structure[<?php echo esc_attr($pt_slug); ?>]" value="1" class="struct_<?php echo esc_attr($pt_slug); ?>">
                    <b>Export post type structure</b>
                </label>
                <?php if (!empty($ptdata['fields'])): ?>
                    <div class="pods-select-all" style="margin-bottom:5px;">
                        <input type="checkbox" id="selectall_<?php echo esc_attr($pt_slug); ?>" onclick="podsSelectAllFields('<?php echo esc_attr($pt_slug); ?>', this)" disabled>
                        <label for="selectall_<?php echo esc_attr($pt_slug); ?>"><b>Select all fields</b></label>
                    </div>
                    <div>
                    <?php foreach($ptdata['fields'] as $fname => $fdata): ?>
                        <label style="margin-right:22px;" data-field="<?php echo esc_attr(strtolower($fdata['label'].' '.$fname)); ?>">
                            <input type="checkbox" name="pods_fields[<?php echo esc_attr($pt_slug); ?>][]" value="<?php echo esc_attr($fname); ?>" class="field_<?php echo esc_attr($pt_slug); ?>" disabled>
                            <?php echo esc_html($fdata['label']); ?> <small>(<?php echo esc_html($fname); ?>, <?php echo esc_html($fdata['type']); ?>)</small>
                        </label><br>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <em>No custom fields defined for this post type.</em>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <input type="hidden" id="pods-export-summary" name="pods_export_summary" value="">
    <button type="submit" class="button button-primary pods-export-btn" id="pods-export-submit" style="margin-top:18px;<?php if(!$acf_ok) echo 'opacity:0.5;cursor:not-allowed;'; ?>" <?php if(!$acf_ok) echo 'disabled'; ?>>Export selected</button>
    <!-- Progress bar -->
    <div id="pods-progressbar-container" style="margin-top:12px;display:none;max-width:280px;">
        <div id="pods-progressbar-bg" style="background:#e5e5e5;height:18px;border-radius:4px;">
            <div id="pods-progressbar-bar" style="background:#2271b1;height:18px;width:0%;border-radius:4px;transition:width 0.3s;"></div>
        </div>
        <div id="pods-progressbar-label" style="font-size:13px;color:#666;padding-left:3px;margin-top:2px;">Exporting...</div>
    </div>
    <?php if (!$acf_ok): ?>
        <div style="margin-top:8px;color:#a00;font-weight:500;">ACF Pro 6.0+ is required to export. Please install and activate ACF Pro.</div>
    <?php endif; ?>
    </form>
    <style>
        .pods-accordion { border: 1px solid #eee; border-radius:7px; margin-bottom: 14px; background: #fafbfc; }
        .pods-acc-title { cursor: pointer; padding: 12px; font-weight: bold; font-size: 1.1em; border-bottom: 1px solid #e6e6e6; }
        .pods-acc-title:hover { background: #f2f6ff; }
        .pods-acc-panel { display: none; padding: 10px 18px 14px 18px; }
        .pods-acc-panel input[type=checkbox] { margin-right:7px; }
        .pods-acc-label { font-size: 1.1em; margin-left: 3px;}
        .pods-export-btn { font-size:1.12em; }
        .pods-select-all { margin-bottom:10px;display:inline-block;font-size:0.97em; }
        .pods-accordion[style*="display: none"] { display:none !important; }
        .pods-acf-log-success { color: #27ae60; font-weight: bold; }
        .pods-acf-log-failed { color: #e3342f; font-weight: bold; }
    </style>
    <script>
    function podsAccToggle(slug) {
        var panel = document.getElementById('panel_'+slug);
        panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
    }
    function podsAccCheckFields(slug) {
        var cpt_cb = document.getElementById('cpt_'+slug);
        var checkboxes = document.getElementsByClassName('field_'+slug);
        var selectall = document.getElementById('selectall_'+slug);
        for (var i=0; i<checkboxes.length; i++) {
            checkboxes[i].disabled = !cpt_cb.checked;
            if (!cpt_cb.checked) checkboxes[i].checked = false;
        }
        if (selectall) {
            selectall.disabled = !cpt_cb.checked;
            if (!cpt_cb.checked) selectall.checked = false;
        }
    }
    function podsSelectAllFields(slug, cb) {
        var checkboxes = document.getElementsByClassName('field_'+slug);
        for (var i=0; i<checkboxes.length; i++) {
            if (!checkboxes[i].disabled) {
                checkboxes[i].checked = cb.checked;
            }
        }
    }
    // Advanced: keep select-all in sync with all checkboxes
    document.addEventListener('DOMContentLoaded', function() {
        // Search/filter
        document.getElementById('pods-search-cpt').addEventListener('input', function() {
            var v = this.value.toLowerCase();
            document.querySelectorAll('.pods-accordion').forEach(function(acc){
                var label = acc.getAttribute('data-cpt');
                var matches = label.indexOf(v) > -1;
                if (!matches) {
                    // Search in fields
                    var found = false;
                    acc.querySelectorAll('label[data-field]').forEach(function(lab){
                        if(lab.getAttribute('data-field').indexOf(v) > -1) found = true;
                    });
                    matches = found;
                }
                acc.style.display = matches ? '' : 'none';
            });
        });
        // Reset selection
        document.getElementById('pods-reset-btn').addEventListener('click', function() {
            document.querySelectorAll('#pods-acf-export-form input[type=checkbox]').forEach(function(cb){cb.checked=false;cb.disabled=(cb.className.indexOf('field_')>-1||cb.id.indexOf('selectall_')===0)?true:cb.disabled;});
        });
        // Select all sync
        document.querySelectorAll('.pods-accordion').forEach(function(acc) {
            var slug = acc.querySelector('.pods-acc-title input[type=checkbox]').value;
            var checkboxes = acc.querySelectorAll('.field_' + slug);
            var selectall = acc.querySelector('#selectall_' + slug);
            var cpt_cb = document.getElementById('cpt_'+slug);
            if (!selectall || !checkboxes.length) return;
            checkboxes.forEach(function(cb) {
                cb.addEventListener('change', function() {
                    if (!selectall.disabled) {
                        var allChecked = true;
                        checkboxes.forEach(function(fieldcb) {
                            if (!fieldcb.checked) allChecked = false;
                        });
                        selectall.checked = allChecked;
                    }
                });
            });
            // Also, when individual checkboxes are enabled/disabled
            cpt_cb.addEventListener('change', function(){
                selectall.checked = false;
            });
        });

        // Progress bar logic
        document.getElementById('pods-acf-export-form').addEventListener('submit', function(e){
            var sel = [];
            var total = 0;
            document.querySelectorAll('.pods-acc-title input[type=checkbox]').forEach(function(cpt_cb){
                if (!cpt_cb.checked) return;
                var slug = cpt_cb.value;
                var label = cpt_cb.parentNode.querySelector('.pods-acc-label').textContent;
                var fields = [];
                document.querySelectorAll('.field_'+slug+':checked').forEach(function(fcb){
                    var fname = fcb.parentNode.textContent.trim();
                    fields.push(fname);
                });
                var structure = document.querySelector('.struct_'+slug) && document.querySelector('.struct_'+slug).checked;
                var text = '- '+label+' ('+slug+'): ';
                if (fields.length>0) text += fields.length+' field(s)';
                if (structure) text += (fields.length>0? ' + ':'')+'structure';
                sel.push(text);
                total++;
            });
            if (sel.length === 0) {
                alert('No post types or fields selected for export.');
                e.preventDefault(); return false;
            }
            var summary = sel.join('\n');
            // Voortgangsbalk
            var bar = document.getElementById('pods-progressbar-bar');
            var container = document.getElementById('pods-progressbar-container');
            var label = document.getElementById('pods-progressbar-label');
            bar.style.width = "0%";
            container.style.display = 'block';
            label.textContent = 'Exporting...';
            // Simuleer voortgang (instant completion na 0.9s, want alles server-side)
            setTimeout(function(){
                bar.style.width = "100%";
                label.textContent = 'Done!';
            }, 900);
        });
    });
    </script>
    <?php
}

// --- EXPORT HANDLER ---
function pods_acf_migrator_handle_export() {
    $notice = '';
    $user = wp_get_current_user();
    $username = $user ? $user->user_login : 'unknown';
    if (empty($_POST['pods_export'])) {
        $notice = '<div class="notice notice-error"><p>No post types selected for export.</p></div>';
        return ['notice' => $notice, 'success'=>false, 'error'=>'No post types selected.'];
    }
    $sel = $_POST['pods_export'];
    $sel_fields = $_POST['pods_fields'] ?? [];
    $sel_struct = $_POST['pods_structure'] ?? [];
    $exported_fg = 0; $exported_cpt = 0; $log = []; $summary = [];
    $any_error = false;
    foreach($sel as $pt_slug) {
        $exported = false;
        $log_line = date("Y-m-d H:i") . " | $username | ";
        $details = [];
        $field_count = 0;
        // Export CPT structure?
        if (isset($sel_struct[$pt_slug])) {
            $pod_obj = pods($pt_slug);
            $pod_arr = (array) $pod_obj;
            $settings = [];
            if (isset($pod_arr['pod_data'])) $settings = $pod_arr['pod_data'];
            elseif (isset($pod_arr['object'])) {
                $poddata = (array)$pod_arr['object'];
                if (isset($poddata['options'])) $settings = $poddata['options'];
            } else {
                $api = pods_api();
                $pods = $api->load_pods([]);
                if (isset($pods[$pt_slug])) {
                    $objarr = (array) $pods[$pt_slug];
                    if (isset($objarr["\0*\0args"])) $settings = $objarr["\0*\0args"];
                }
            }
            $acf_post_type = [
                'key' => 'post_type_' . $pt_slug,
                'name' => $pt_slug,
                'label' => $settings['label'] ?? ucfirst($pt_slug),
                'description' => $settings['description'] ?? '',
                'public' => !empty($settings['public']),
                'show_in_menu' => !empty($settings['show_ui']),
                'show_in_rest' => !empty($settings['rest_enable']),
                'has_archive' => !empty($settings['has_archive']),
                'hierarchical' => !empty($settings['hierarchical']),
                'supports' => []
            ];
            $supports = [];
            foreach(['title'=>'supports_title','editor'=>'supports_editor','thumbnail'=>'supports_thumbnail','excerpt'=>'supports_excerpt','revisions'=>'supports_revisions','author'=>'supports_author'] as $wp => $podsopt) {
                if (!empty($settings[$podsopt])) $supports[] = $wp;
            }
            $acf_post_type['supports'] = $supports;
            $filename = 'acf-cpt-' . $pt_slug . '.json';
            $json = json_encode($acf_post_type, JSON_PRETTY_PRINT);
            if (!file_exists(PODS_ACF_JSON_DIR)) wp_mkdir_p(PODS_ACF_JSON_DIR);
            file_put_contents(PODS_ACF_JSON_DIR . $filename, $json);
            $exported_cpt++;
            $details[] = 'structure';
            $exported = true;
        }
        $pod_obj = pods($pt_slug);
        if (!$pod_obj || !method_exists($pod_obj, 'fields')) continue;
        $all_fields = $pod_obj->fields();
        $field_defs = [];
        if (!empty($sel_fields[$pt_slug])) {
            foreach ($sel_fields[$pt_slug] as $fname) {
                if (!isset($all_fields[$fname])) continue;
                if (pods_acf_is_field_excluded($fname)) continue; // skip Pods-only fields
                $fdata = $all_fields[$fname];
                $field_defs[] = pods_acf_migrator_map_field($fname, $fdata);
                $field_count++;
            }
        }
        if (count($field_defs) > 0) {
            $acf_fg = [
                'key' => 'group_' . $pt_slug,
                'title' => ucfirst($pt_slug) . ' Fields',
                'fields' => $field_defs,
                'location' => [[['param'=>'post_type','operator'=>'==','value'=>$pt_slug]]],
                'menu_order'=>0,
                'position'=>'normal',
                'style'=>'default',
                'label_placement'=>'top',
                'instruction_placement'=>'label',
                'hide_on_screen'=>'',
                'active'=>true,
                'description'=>'Migrated from Pods'
            ];
            $filename = 'acfgroup-' . $pt_slug . '.json';
            $json = json_encode($acf_fg, JSON_PRETTY_PRINT);
            if (!file_exists(PODS_ACF_JSON_DIR)) wp_mkdir_p(PODS_ACF_JSON_DIR);
            file_put_contents(PODS_ACF_JSON_DIR . $filename, $json);
            $exported_fg++;
            $details[] = 'fields: '.$field_count;
            $exported = true;
        }
        if (!$exported) {
            $log_line .= "fieldgroup | $pt_slug | no export | Failed";
            $any_error = true;
        } else {
            $log_line .= "fieldgroup | $pt_slug | " . strtolower(implode(', ', $details)) . " | Success";
            $summary[] = ucfirst($pt_slug) . ': ' . implode(', ', $details);
        }
        $log[] = $log_line . "\n";
    }
    file_put_contents(PODS_ACF_LOG_FILE, implode("", $log), FILE_APPEND);
    $msg = [];
    if ($exported_fg > 0) $msg[] = "$exported_fg field group(s)";
    if ($exported_cpt > 0) $msg[] = "$exported_cpt post type structure(s)";
    if (!empty($msg)) {
        $notice = '<div class="notice notice-success"><p>Migration completed! ' . implode(" and ", $msg) . ' exported.</p></div>';
        return ['notice' => $notice, 'success'=>true, 'summary'=>implode("\n", $summary)];
    } else {
        $notice = '<div class="notice notice-warning"><p>No fields or structures were exported. Check your selections.</p></div>';
        return ['notice' => $notice, 'success'=>false, 'error'=>'No fields or structures exported.'];
    }
}

function pods_acf_migrator_map_field($fname, $fdata) {
    $acf_type = 'text';
    $pods_type = isset($fdata['type']) ? strtolower($fdata['type']) : 'text';
    switch($pods_type) {
        case 'text': case 'plain text': $acf_type = 'text'; break;
        case 'paragraph': $acf_type = 'textarea'; break;
        case 'wysiwyg': case 'visual editor': case 'rich text': $acf_type = 'wysiwyg'; break;
        case 'date': $acf_type = 'date_picker'; break;
        case 'time': $acf_type = 'time_picker'; break;
        case 'number': $acf_type = 'number'; break;
        case 'yes/no': case 'boolean': $acf_type = 'true_false'; break;
        case 'select': case 'pick': case 'radio': $acf_type = 'select'; break;
        case 'checkbox': $acf_type = 'checkbox'; break;
        case 'relationship': case 'posttype': $acf_type = 'relationship'; break;
        case 'taxonomy': $acf_type = 'taxonomy'; break;
        case 'file': $acf_type = 'file'; break;
        case 'image': $acf_type = 'image'; break;
        case 'oembed': $acf_type = 'oembed'; break;
        case 'color': $acf_type = 'color_picker'; break;
        default: $acf_type = 'text'; break;
    }
    return [
        'key' => 'field_' . md5($fname . time() . rand()),
        'label' => $fdata['label'] ?? $fname,
        'name' => $fname,
        'type' => $acf_type,
        'instructions' => '',
        'required' => 0,
        'conditional_logic' => 0,
        'wrapper' => ['width'=>'','class'=>'','id'=>'']
    ];
}

// --- EXPORTS PAGE ---
function pods_acf_migrator_exports_page() {
    if (!current_user_can('manage_options')) wp_die("You do not have sufficient permissions to access this page.");
    echo '<div class="wrap"><h1>Exported JSON files</h1>';
    echo '<a href="'.admin_url('admin.php?page=pods-acf-migrator').'" class="button" style="margin-bottom:15px;">&#8592; Back to Dashboard</a>';
    pods_acf_migrator_exports_table();
    echo '</div>';
}
function pods_acf_migrator_exports_table() {
    $dir = PODS_ACF_JSON_DIR;
    if (!file_exists($dir)) return;
    $files = glob($dir . '*.json');
    if (empty($files)) {
        echo '<p><em>No exported field groups or CPT structures found.</em></p>';
        return;
    }

    // Delete all button + AJAX feedback
    echo '<div style="margin-bottom:18px;">
        <button id="pods-acf-delete-all-btn" class="button" style="margin-right:16px;">Delete all JSON files</button>
        <span id="pods-acf-delete-all-msg" style="font-weight:500;"></span>
    </div>';

    echo '<table class="widefat fixed striped"><thead>
        <tr>
            <th style="width:35%;">Filename</th>
            <th>Type</th>
            <th>Post Type</th>
            <th>Date</th>
            <th>Size</th>
            <th>Action</th>
        </tr>
    </thead><tbody>';
    foreach($files as $file) {
        $fname = basename($file);
        $type = strpos($fname, 'acfgroup-') === 0 ? 'Field group' : (strpos($fname, 'acf-cpt-') === 0 ? 'Post Type' : 'Other');
        $pt = preg_replace('/^acfgroup-|^acf-cpt-|\..+$/', '', $fname);
        $date = date("Y-m-d H:i", filemtime($file));
        $size = round(filesize($file)/1024,1).' KB';
        $download_url = admin_url('admin-ajax.php?action=pods_acf_download&file=' . urlencode($fname));
        $delete_url = admin_url('admin-ajax.php?action=pods_acf_delete&file=' . urlencode($fname) . '&_wpnonce=' . wp_create_nonce('pods_acf_delete'));
        echo "<tr>
            <td><span class='dashicons dashicons-media-default' style='color:#2271b1;'></span> $fname</td>
            <td>$type</td>
            <td>$pt</td>
            <td>$date</td>
            <td>$size</td>
            <td>
                <a href='$download_url' class='button' title='Download'><span class='dashicons dashicons-download' style='vertical-align:-2px;'></span></a>
                <a href='$delete_url' class='button' title='Delete' onclick='return confirm(\"Delete this file?\")'><span class='dashicons dashicons-trash' style='vertical-align:-2px;color:#b32d2e;'></span></a>
            </td>
        </tr>";
    }
    echo '</tbody></table>';

    // AJAX voor delete all
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var delBtn = document.getElementById('pods-acf-delete-all-btn');
        if (!delBtn) return;
        delBtn.addEventListener('click', function(e){
            e.preventDefault();
            if (!confirm('Are you sure you want to delete ALL exported JSON files?')) return;
            delBtn.disabled = true;
            var msg = document.getElementById('pods-acf-delete-all-msg');
            msg.textContent = 'Deleting...';
            fetch(ajaxurl + '?action=pods_acf_delete_all_json', {method: 'POST', credentials: 'same-origin'})
                .then(resp => resp.json())
                .then(data => {
                    if (data.success) {
                        // Remove all table rows except thead
                        var tbl = delBtn.parentNode.nextElementSibling;
                        if (tbl && tbl.tagName === "TABLE") {
                            var rows = tbl.querySelectorAll('tbody tr');
                            rows.forEach(function(row){ row.parentNode.removeChild(row); });
                        }
                        msg.textContent = 'All files deleted!';
                    } else {
                        msg.textContent = data.data && data.data.message ? data.data.message : 'Error deleting files.';
                    }
                    delBtn.disabled = false;
                })
                .catch(err => {
                    msg.textContent = 'Error deleting files.';
                    delBtn.disabled = false;
                });
        });
    });
    </script>
    <style>
    #pods-acf-delete-all-msg { color: #b32d2e; }
    </style>
    <?php
}

// --- LOG PAGE ---
function pods_acf_migrator_log_page() {
    if (!current_user_can('manage_options')) wp_die("You do not have sufficient permissions to access this page.");
    echo '<div class="wrap"><h1>Migration Log</h1>';
    echo '<a href="'.admin_url('admin.php?page=pods-acf-migrator').'" class="button" style="margin-bottom:15px;">&#8592; Back to Dashboard</a>';
    pods_acf_migrator_log_table();
    echo '</div>';
}
function pods_acf_migrator_log_table() {
    $file = PODS_ACF_LOG_FILE;
    if (!file_exists($file) || filesize($file) === 0) {
        echo '<p><em>No migrations logged yet.</em></p>'; return;
    }
    $lines = explode("\n", trim(file_get_contents($file)));
    echo '<table class="widefat fixed striped" style="max-width:1050px;">';
    echo '<thead><tr>
        <th>Date</th>
        <th>User</th>
        <th>Type</th>
        <th>Post Type</th>
        <th>Details</th>
        <th>Status</th>
    </tr></thead><tbody>';
    foreach ($lines as $line) {
        if (!$line) continue;
        $cols = explode('|', $line);
        $cols = array_map('trim', $cols);
        $status_raw = isset($cols[5]) ? strtolower($cols[5]) : '';
        $status_class = '';
        $status_label = '';
        if ($status_raw === 'success') {
            $status_class = 'pods-acf-log-success';
            $status_label = 'success';
        } elseif ($status_raw === 'failed' || $status_raw === 'fail' || strpos($status_raw, 'no export') !== false) {
            $status_class = 'pods-acf-log-failed';
            $status_label = 'failed';
        } else {
            $status_class = '';
            $status_label = esc_html($status_raw);
        }
        echo "<tr>";
        echo '<td>'.esc_html($cols[0]??'').'</td>';
        echo '<td>'.esc_html($cols[1]??'').'</td>';
        echo '<td>'.esc_html($cols[2]??'').'</td>';
        echo '<td>'.esc_html($cols[3]??'').'</td>';
        echo '<td style="text-transform:lowercase;">'.esc_html($cols[4]??'').'</td>';
        echo '<td class="'.$status_class.'">'.ucfirst($status_label).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<div style="margin-top:18px;">';
    echo '<button id="pods-acf-clear-log-btn" class="button" style="margin-right:16px;">Clear log</button>';
    $download_url = admin_url('admin-ajax.php?action=pods_acf_download_log&_wpnonce=' . wp_create_nonce('pods_acf_download_log'));
    echo '<a href="'.$download_url.'" class="button">Download log (.txt)</a>';
    echo '<span id="pods-acf-log-msg" style="margin-left:16px;font-weight:500;"></span>';
    echo '</div>';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var clearBtn = document.getElementById('pods-acf-clear-log-btn');
    if (!clearBtn) return;
    clearBtn.addEventListener('click', function(e){
        e.preventDefault();
        if (!confirm('Are you sure you want to clear the migration log?')) return;
        clearBtn.disabled = true;
        var msg = document.getElementById('pods-acf-log-msg');
        msg.textContent = 'Clearing...';
        fetch(ajaxurl + '?action=pods_acf_clear_log', {method: 'POST', credentials: 'same-origin'})
            .then(resp => resp.json())
            .then(data => {
                if (data.success) {
                    // Remove table rows except header
                    var tbl = clearBtn.closest('.wrap').querySelector('table');
                    if (tbl) {
                        var rows = tbl.querySelectorAll('tbody tr');
                        rows.forEach(function(row){ row.parentNode.removeChild(row); });
                    }
                    msg.textContent = 'Log cleared!';
                } else {
                    msg.textContent = data.data && data.data.message ? data.data.message : 'Error clearing log.';
                }
                clearBtn.disabled = false;
            })
            .catch(err => {
                msg.textContent = 'Error clearing log.';
                clearBtn.disabled = false;
            });
    });
});
</script>
<style>
#pods-acf-log-msg { color: #2271b1; }
</style>
<?php
}

// --- AJAX download/delete actions ---
add_action('wp_ajax_pods_acf_download', function() {
    if (!current_user_can('manage_options')) exit('No permission');
    $fname = isset($_GET['file']) ? basename($_GET['file']) : '';
    $fpath = PODS_ACF_JSON_DIR . $fname;
    if (!$fname || !file_exists($fpath)) wp_die('File not found');
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    readfile($fpath); exit;
});
add_action('wp_ajax_pods_acf_delete', function() {
    if (!current_user_can('manage_options')) exit('No permission');
    check_admin_referer('pods_acf_delete');
    $fname = isset($_GET['file']) ? basename($_GET['file']) : '';
    $fpath = PODS_ACF_JSON_DIR . $fname;
    if ($fname && file_exists($fpath)) unlink($fpath);
    wp_redirect(admin_url('admin.php?page=pods-acf-migrator-exports'));
    exit;
});
add_action('wp_ajax_pods_acf_download_log', function() {
    if (!current_user_can('manage_options')) exit('No permission');
    check_admin_referer('pods_acf_download_log');
    $file = PODS_ACF_LOG_FILE;
    if (!file_exists($file)) wp_die('No log found');
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="pods-acf-migrator-log.txt"');
    readfile($file); exit;
});
add_action('wp_ajax_pods_acf_clear_log', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'No permission']);
    }
    file_put_contents(PODS_ACF_LOG_FILE, '');
    wp_send_json_success(['message' => 'Log cleared']);
});
// AJAX: Delete all JSON
add_action('wp_ajax_pods_acf_delete_all_json', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'No permission']);
    }
    $dir = PODS_ACF_JSON_DIR;
    $files = glob($dir . '*.json');
    $removed = 0;
    foreach($files as $file) {
        if (is_file($file)) {
            unlink($file);
            $removed++;
        }
    }
    if ($removed > 0) {
        wp_send_json_success(['message' => 'All JSON files deleted.']);
    } else {
        wp_send_json_error(['message' => 'No files to delete.']);
    }
});