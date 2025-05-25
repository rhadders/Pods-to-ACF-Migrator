<?php
/*
Plugin Name: Pods to ACF Migrator
Description: Migrate Pods custom post types and fields to ACF Pro
Version: 1.1
Author: Ruben Hadders | Jona zinvolle communicatie
Text Domain: pods-acf-migrator
Domain Path: /languages
*/

if (!defined("ABSPATH")) {
    exit();
}

define("PODS_ACF_JSON_DIR", plugin_dir_path(__FILE__) . "acf-json/");
define("PODS_ACF_LOG_FILE", plugin_dir_path(__FILE__) . "migration.log");

// [NEW] Load translation domain
add_action("plugins_loaded", function () {
    load_plugin_textdomain("pods-acf-migrator", false, dirname(plugin_basename(__FILE__)) . "/languages");
});

add_filter("acf/settings/load_json", function ($paths) {
    $paths[] = PODS_ACF_JSON_DIR;
    return $paths;
});

add_action("admin_menu", function () {
    add_menu_page(
        __("Pods to ACF Migrator", "pods-acf-migrator"),
        __("Pods to ACF Migrator", "pods-acf-migrator"),
        "manage_options",
        "pods-acf-migrator",
        "pods_acf_migrator_dashboard",
        "dashicons-migrate",
        31
    );
    add_submenu_page(
        "pods-acf-migrator",
        __("Exported JSON files", "pods-acf-migrator"),
        __("Exported JSON files", "pods-acf-migrator"),
        "manage_options",
        "pods-acf-migrator-exports",
        "pods_acf_migrator_exports_page"
    );
    add_submenu_page(
        "pods-acf-migrator",
        __("Migration Log", "pods-acf-migrator"),
        __("Migration Log", "pods-acf-migrator"),
        "manage_options",
        "pods-acf-migrator-log",
        "pods_acf_migrator_log_page"
    );
});

add_action( 'init', function(){
    $to_disable = (array) get_option( 'pods_acf_migrated_post_types', [] );
    foreach( $to_disable as $slug ){
        if ( post_type_exists( $slug ) ){
            unregister_post_type( $slug );
        }
    }
}, 11 );

// Helper: check if field should be excluded (Pods-only or technical)
function pods_acf_is_field_excluded($fname)
{
    $excluded_prefixes = [
        "_pods_",
        "_podsrel_",
        "_podsmeta_",
        "_podsfield_",
        "_podsuid_",
        "_podsname_",
        "_podslabel_",
        "pods_",
        "podsmeta_",
        "podsrel_",
        "podsfield_",
    ];
    $excluded_fields = [
        "id",
        "pod_id",
        "created",
        "modified",
        "menu_order",
        "post_status",
        "guid",
        "post_type",
        "post_name",
        "post_title",
        "post_excerpt",
        "post_content",
        "post_parent",
        "post_author",
        "post_date",
        "post_date_gmt",
        "post_modified",
        "post_modified_gmt",
        "post_content_filtered",
        "comment_count",
        "ping_status",
        "to_ping",
        "pinged",
        "post_password",
        "post_mime_type",
        "comment_status",
        "filter",
        "slug",
        "type",
        "status",
    ];
    if (in_array($fname, $excluded_fields)) {
        return true;
    }
    foreach ($excluded_prefixes as $prefix) {
        if (strpos($fname, $prefix) === 0) {
            return true;
        }
    }
    if (strpos($fname, "@wp_") === 0) {
        return true;
    }
    return false;
}

function pods_acf_migrator_dashboard()
{
    if (!current_user_can("manage_options")) {
        wp_die(__("You do not have sufficient permissions to access this page.", "pods-acf-migrator"));
    }
    echo '<div class="wrap">';
    echo "<h1>" . esc_html__("Pods to ACF Migrator", "pods-acf-migrator") . "</h1>";

    // Navigation: three links with icon
    echo '<div class="pods-acf-nav" style="margin:16px 0 18px 0;">
        <span style="margin-right:18px;">
            <span class="dashicons dashicons-media-default" style="vertical-align:middle;font-size:17px;margin-right:3px;"></span>
            <a href="' .
        admin_url("admin.php?page=pods-acf-migrator-exports") .
        '">' .
        __("Exported JSON files", "pods-acf-migrator") .
        '</a>
        </span>
        <span style="margin-right:18px;">
            <span class="dashicons dashicons-list-view" style="vertical-align:middle;font-size:17px;margin-right:3px;"></span>
            <a href="' .
        admin_url("admin.php?page=pods-acf-migrator-log") .
        '">' .
        __("Migration Log", "pods-acf-migrator") .
        '</a>
        </span>
        <span>
            <span class="dashicons dashicons-book-alt" style="vertical-align:middle;font-size:17px;margin-right:3px;"></span>
            <a href="https://www.advancedcustomfields.com/resources/synchronized-json/" target="_blank" rel="noopener">ACF JSON Sync documentation</a>
        </span>
    </div>';

    // Pods check
    if (!class_exists("Pods")) {
        echo '<div class="notice notice-error"><p>' .
            __("Pods plugin is not active. Please install and activate Pods.", "pods-acf-migrator") .
            "</p></div>";
        echo "</div>";
        return;
    }

    // ACF Pro check
    $acf_version = null;
    if (class_exists("ACF")) {
        if (defined("ACF_PRO")) {
            if (defined("ACF_VERSION")) {
                $acf_version = ACF_VERSION;
            } elseif (method_exists("acf", "get_version")) {
                $acf_version = acf()->get_version();
            } else {
                $acf_version = get_option("acf_pro_version", get_option("acf_version", "0"));
            }
        } else {
            $acf_version = get_option("acf_pro_version", get_option("acf_version", "0"));
        }
    }
    $acf_ok = $acf_version && version_compare($acf_version, "6.0.0", ">=");
    if (!$acf_ok) {
        echo '<div class="notice notice-error"><p><b>' .
            __("ACF Pro 6.0 or higher is required.", "pods-acf-migrator") .
            "</b> " .
            __("Please install and activate ACF Pro 6+ before migrating.", "pods-acf-migrator") .
            ' <a href="https://www.advancedcustomfields.com/pro/" target="_blank" rel="noopener">' .
            __("More info", "pods-acf-migrator") .
            "</a></p></div>";
    }

    // Handle export
    if (
        !empty($_POST["pods_acf_export_nonce"]) &&
        wp_verify_nonce($_POST["pods_acf_export_nonce"], "pods_acf_export_action")
    ) {
        if (!$acf_ok) {
            pods_acf_migrator_modal(
                __("Migration failed: ACF Pro 6.0+ is not available.", "pods-acf-migrator"),
                false,
                true
            );
        } else {
            $export_result = pods_acf_migrator_handle_export();
            echo $export_result["notice"];
            if ($export_result["success"]) {
                $summary = $export_result["summary"];
                pods_acf_migrator_modal(
                    sprintf(
                        __(
                            "Migration complete!<br><br><b>Exported:</b><br>%s<br><br><b>Next steps:</b><ol><li>Go to <b>ACF → Field Groups</b> and/or <b>ACF → Post Types</b> in your dashboard.</li><li>Click on <b>Sync available</b> at the top to import the newly generated field groups and/or post types.</li><li>Review, adjust and publish the imported ACF items as needed.</li></ol>",
                            "pods-acf-migrator"
                        ),
                        nl2br(esc_html($summary))
                    ),
                    true
                );
            } else {
                pods_acf_migrator_modal(
                    sprintf(__("Migration failed: %s", "pods-acf-migrator"), $export_result["error"]),
                    false,
                    true
                );
            }
        }
    }

    pods_acf_migrator_export_form($acf_ok);
    echo '<style>
        .pods-acf-nav a { text-decoration:none; color:#2271b1; font-weight:500;}
        .pods-acf-nav a:hover { text-decoration:underline; }
        .pods-acf-nav .dashicons { color:#555; }
    </style>';
    echo "</div>";
}

function pods_acf_migrator_modal($content, $success = true, $forceShow = false)
{
    $color = $success ? "#46b450" : "#d63638"; // WordPress green or red
    $icon = $success ? "yes" : "no-alt";

    echo '<div id="pods-acf-modal" style="display:block;position:fixed;z-index:99999;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,.25);font-family:inherit;">';
    echo '<div style="background:#fff;max-width:500px;margin:6% auto;padding:36px 32px 28px 32px;border-radius:8px;box-shadow:0 8px 32px #0002;position:relative;">';
echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;">';
echo '<span class="dashicons dashicons-' . $icon . '" style="font-size:2.2em;color:' . $color . ';"></span>';
echo '<h2 style="margin:0;font-size:1.7em;font-weight:700;color:#1d2327;line-height:1.2;">'
     . ( $success ? __( 'Success', 'pods-acf-migrator' ) : __( 'Error', 'pods-acf-migrator' ) )
   . '</h2>';
echo '</div>';
    echo '<div style="color:#2c3338;font-size:1.09em;line-height:1.6;">' . $content . "</div>";

    if ($success) {
        // English developer note: dynamically detect Pods plugin basename
        $active_plugins = (array) get_option("active_plugins", []);
        $pods_basename = "";
        foreach ($active_plugins as $plugin_file) {
            if (false !== strpos($plugin_file, "pods/")) {
                $pods_basename = $plugin_file;
                break;
            }
        }
        if (empty($pods_basename)) {
            $pods_basename = "pods/pods.php"; // fallback
        }
        $ajax_nonce = wp_create_nonce("pods_acf_deactivate_pods");
        $redirect_to = admin_url("edit.php?post_type=acf-post-type");

        echo '<p style="margin-top:20px;">';
        echo '<button type="button" id="pods-acf-deactivate-btn" class="button button-secondary">';
        esc_html_e("Deactivate Pods and sync ACF", "pods-acf-migrator");
        echo "</button>";
        echo "</p>";

        echo "<script>";
        echo "(function(){";
        echo '  var btn = document.getElementById("pods-acf-deactivate-btn");';
        echo '  btn.addEventListener("click", function(e){';
        echo "    e.preventDefault();";
        echo "    btn.disabled = true;";
        echo '    btn.textContent = "' . esc_js(__("Deactivating...", "pods-acf-migrator")) . '";';
        echo "    var data = new URLSearchParams();";
        echo '    data.append("action", "pods_acf_deactivate_pods");';
        echo '    data.append("nonce", "' . esc_js($ajax_nonce) . '");';
        echo '    data.append("plugin", "' . esc_js($pods_basename) . '");';
        echo "    fetch(ajaxurl, {";
        echo '      method: "POST",';
        echo '      credentials: "same-origin",';
        echo '      headers: {"Content-Type": "application/x-www-form-urlencoded"},';
        echo "      body: data.toString()";
        echo "    })";
        echo "    .then(function(res){ return res.json(); })";
        echo "    .then(function(json){";
        echo "      if ( json.success ) {";
        echo '        window.location.href = "' . esc_js($redirect_to) . '";';
        echo "      } else {";
        echo '        alert( json.data || "' . esc_js(__("Error deactivating Pods", "pods-acf-migrator")) . '" );';
        echo "        btn.disabled = false;";
        echo '        btn.textContent = "' . esc_js(__("Deactivate Pods and sync ACF", "pods-acf-migrator")) . '";';
        echo "      }";
        echo "    });";
        echo "  });";
        echo "})();";
        echo "</script>";
    }

    echo '<a href="#" onclick="document.getElementById(\'pods-acf-modal\').remove();document.body.style.overflow=\'auto\';return false;" class="button button-primary" style="margin-top:15px;min-width:90px;font-size:1.05em;font-weight:500;border-radius:6px;padding:0.5em 1.5em;">';
    echo esc_html__("Close", "pods-acf-migrator");
    echo "</a>";
    echo "</div></div>";
    echo '<script>document.body.style.overflow=\'hidden\';</script>';

    if ($forceShow) {
        echo "<script>window.setTimeout(function(){document.getElementById('pods-acf-modal').style.display='block';},50);</script>";
    }
}

// --- EXPORT FORM UI ---
function pods_acf_migrator_export_form($acf_ok)
{
    $all_pods = pods_api()->load_pods([]);
    $found = 0;
    $pods_types = [];
    foreach ($all_pods as $key => $pod) {
        $type = $name = $label = "-";
        $pod_arr = (array) $pod;
        if (isset($pod_arr["\0*\0args"])) {
            $args = $pod_arr["\0*\0args"];
            $type = $args["type"] ?? "-";
            $name = $args["name"] ?? "-";
            $label = $args["label"] ?? "-";
        }
        if ($type === "post_type") {
            $found++;
            $pods_types[$name] = [
                "label" => $label,
                "name" => $name,
                "fields" => [],
            ];
            $pod_obj = pods($name);
            if ($pod_obj && method_exists($pod_obj, "fields")) {
                foreach ($pod_obj->fields() as $fname => $fdata) {
                    if (pods_acf_is_field_excluded($fname)) {
                        continue;
                    } // skip Pods-only fields
                    $pods_types[$name]["fields"][$fname] = [
                        "label" => $fdata["label"] ?? $fname,
                        "type" => $fdata["type"] ?? "text",
                    ];
                }
            }
        }
    }
    if ($found === 0) {
        echo '<div class="notice notice-warning"><p>' .
            __("No custom post types found in Pods to migrate.", "pods-acf-migrator") .
            "</p></div>";
        return;
    }
    ?>
                <form method="post" id="pods-acf-export-form" style="margin-top:20px;">
                <?php wp_nonce_field("pods_acf_export_action", "pods_acf_export_nonce"); ?>
                <div style="margin-bottom:15px;">
                <input type="text" id="pods-search-cpt" placeholder="Search post types or fields..." style="width:340px;padding:5px 9px;border-radius:4px;border:1px solid #ccc;font-size:1em;">
                <button type="button" class="button" id="pods-select-all-btn" style="margin-left:18px;"><?php esc_html_e(
                    "Select all",
                    "pods-acf-migrator"
                ); ?></button>
                <button type="button" class="button" id="pods-reset-btn" style="margin-left:4px;"><?php esc_html_e(
                    "Reset selection",
                    "pods-acf-migrator"
                ); ?></button>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Global select all
                var selectAllBtn = document.getElementById('pods-select-all-btn');
                selectAllBtn.addEventListener('click', function(){
                    document.querySelectorAll('#pods-acf-export-form input[type=checkbox]').forEach(function(cb){
                        if (!cb.disabled) cb.checked = true;
                    });
                });
            });
            </script>
    <div id="pods-acf-accordions">
    <?php foreach ($pods_types as $pt_slug => $ptdata): ?>
        <div class="pods-accordion" data-cpt="<?php echo esc_attr(strtolower($ptdata["label"] . " " . $pt_slug)); ?>">
            <div class="pods-acc-title" onclick="podsAccToggle('<?php echo esc_attr($pt_slug); ?>')">
                <span class="pods-acc-label"><?php echo esc_html($ptdata["label"]); ?></span>
                <span style="color:#999;font-size:0.96em;">(<?php echo esc_html($pt_slug); ?>)</span>
                <span style="float:right;color:#888;">&#x25BC;</span>
            </div>
            <div class="pods-acc-panel" id="panel_<?php echo esc_attr($pt_slug); ?>">
                <p style="margin-bottom:6px;color:#444;">
                    <?php printf(
                        __("Select which fields and/or structure to migrate for <b>%s</b>:", "pods-acf-migrator"),
                        esc_html($ptdata["label"])
                    ); ?>
                </p>
                <label style="margin-bottom:7px; display:block;">
                    <input type="checkbox" name="pods_structure[<?php echo esc_attr(
                        $pt_slug
                    ); ?>]" value="1" class="struct_<?php echo esc_attr($pt_slug); ?>">
                    <b><?php _e("Export post type structure", "pods-acf-migrator"); ?></b>
                </label>
                <?php if (!empty($ptdata["fields"])): ?>
                    <div class="pods-select-all" style="margin-bottom:5px;">
    <input type="checkbox"
           id="selectall_<?php echo esc_attr($pt_slug); ?>"
           onclick="podsSelectAllFields('<?php echo esc_attr($pt_slug); ?>', this)">
    <label for="selectall_<?php echo esc_attr($pt_slug); ?>"><b><?php _e(
    "Select all fields",
    "pods-acf-migrator"
); ?></b></label>
</div>
                    <div>
                    <?php foreach ($ptdata["fields"] as $fname => $fdata): ?>
                        <label style="margin-right:22px;" data-field="<?php echo esc_attr(
                            strtolower($fdata["label"] . " " . $fname)
                        ); ?>">
                            <input type="checkbox" name="pods_fields[<?php echo esc_attr(
                                $pt_slug
                            ); ?>][]" value="<?php echo esc_attr($fname); ?>" class="field_<?php echo esc_attr(
    $pt_slug
); ?>">
                            <?php echo esc_html($fdata["label"]); ?> <small>(<?php echo esc_html(
     $fdata["type"]
 ); ?>)</small>
                        </label><br>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <em><?php _e("No custom fields defined for this post type.", "pods-acf-migrator"); ?></em>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <input type="hidden" id="pods-export-summary" name="pods_export_summary" value="">
    <button type="submit" class="button button-primary pods-export-btn" id="pods-export-submit" style="margin-top:18px;<?php if (
        !$acf_ok
    ) {
        echo "opacity:0.5;cursor:not-allowed;";
    } ?>" <?php if (!$acf_ok) {
    echo "disabled";
} ?>><?php _e("Export selected", "pods-acf-migrator"); ?></button>
    <!-- Progress bar -->
    <div id="pods-progressbar-container" style="margin-top:12px;display:none;max-width:280px;">
        <div id="pods-progressbar-bg" style="background:#e5e5e5;height:18px;border-radius:4px;">
            <div id="pods-progressbar-bar" style="background:#2271b1;height:18px;width:0%;border-radius:4px;transition:width 0.3s;"></div>
        </div>
        <div id="pods-progressbar-label" style="font-size:13px;color:#666;padding-left:3px;margin-top:2px;"><?php echo esc_js(
            __("Exporting...", "pods-acf-migrator")
        ); ?></div>
    </div>
    <?php if (!$acf_ok): ?>
        <div style="margin-top:8px;color:#a00;font-weight:500;"><?php _e(
            "ACF Pro 6.0+ is required to export. Please install and activate ACF Pro.",
            "pods-acf-migrator"
        ); ?></div>
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
document.addEventListener('DOMContentLoaded', function() {

    // 1) Search/filter
    document.getElementById('pods-search-cpt').addEventListener('input', function() {
        var v = this.value.toLowerCase();
        document.querySelectorAll('.pods-accordion').forEach(function(acc){
            var label = acc.getAttribute('data-cpt');
            var matches = label.indexOf(v) > -1;
            if (!matches) {
                var found = false;
                acc.querySelectorAll('label[data-field]').forEach(function(lab){
                    if (lab.getAttribute('data-field').indexOf(v) > -1) {
                        found = true;
                    }
                });
                matches = found;
            }
            acc.style.display = matches ? '' : 'none';
        });
    });

    // 2) Reset selection: alleen uitvinken, niet disable’n
    document.getElementById('pods-reset-btn').addEventListener('click', function() {
        document.querySelectorAll('#pods-acf-export-form input[type=checkbox]').forEach(function(cb){
            cb.checked = false;
            cb.disabled = false;
        });
    });

    // 3) Global “Select all”
    document.getElementById('pods-select-all-btn').addEventListener('click', function(){
        document.querySelectorAll('#pods-acf-export-form input[type=checkbox]').forEach(function(cb){
            if (!cb.disabled) cb.checked = true;
        });
    });

    // 4) Per‐CPT “Select all fields” helper
    window.podsSelectAllFields = function(slug, cb) {
        document.querySelectorAll('.field_' + slug).forEach(function(f){
            if (!f.disabled) f.checked = cb.checked;
        });
        // update icon in title immediately
        var panel = document.getElementById('panel_' + slug);
        if (panel) updateAccordionTitleIcon(panel);
    };

    // 5) Accordion toggle (bestaande code)
    window.podsAccToggle = function(slug) {
        var panel = document.getElementById('panel_' + slug);
        panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
    };

    // 6) Handler om een blauw vinkje vóór de titel te tonen als er iets is aangevinkt
    function updateAccordionTitleIcon(panel) {
        var anyChecked = Array.from(panel.querySelectorAll('input[type=checkbox]'))
                              .some(cb => cb.checked);
        var titleDiv   = panel.previousElementSibling; // .pods-acc-title
        var existing   = titleDiv.querySelector('.pods-acc-checked-icon');

        if (anyChecked) {
            if (!existing) {
                var icon = document.createElement('span');
                icon.className = 'dashicons dashicons-yes pods-acc-checked-icon';
                icon.style.color         = '#0073aa';
                icon.style.marginRight   = '6px';
                icon.style.verticalAlign = 'middle';
                titleDiv.insertBefore(icon, titleDiv.firstChild);
            }
        } else if (existing) {
            existing.remove();
        }
    }

    // 7) Hook icon‐updater op elk paneel + initialisatie
    document.querySelectorAll('.pods-acc-panel').forEach(function(panel){
        // alle checkboxes in dit paneel
        panel.querySelectorAll('input[type=checkbox]').forEach(function(cb){
            cb.addEventListener('change', function(){
                updateAccordionTitleIcon(panel);
            });
        });
        // meteen bij laden
        updateAccordionTitleIcon(panel);
    });

});va
    </script>
    <?php
}

function pods_acf_migrator_handle_export()
{
    $notice = "";
    $user = wp_get_current_user();
    $username = $user ? $user->user_login : "unknown";

// ‪Collect user selections‬
    $sel_struct  = $_POST['pods_structure'] ?? [];
    $sel_fields  = $_POST['pods_fields']    ?? [];
    $sel_export  = $_POST['pods_export']    ?? [];

    // ‪Als pods_export[] niet meer gebruikt wordt, leiden we de selectie af van structure en fields‬
    if ( empty( $sel_export ) ) {
        // slugs uit structure én uit fields halen
        $sel_export = array_unique(
            array_merge(
                array_keys( $sel_struct ),
                array_keys( $sel_fields )
            )
        );
    }

    // ‪Nog steeds niks, dan écht error‬
    if ( empty( $sel_export ) ) {
        $notice = '<div class="notice notice-error"><p>' . __( 'No post types selected for export.', 'pods-acf-migrator' ) . '</p></div>';
        $log_line = date( "Y-m-d H:i" ) . " | ' . $username . ' | fieldgroup | - | No post types selected | Failed\n";
        file_put_contents( PODS_ACF_LOG_FILE, $log_line, FILE_APPEND );
        return [
            'notice'  => $notice,
            'success' => false,
            'error'   => __( 'No post types selected.', 'pods-acf-migrator' ),
        ];
    }

    // gebruik de afgeleide selectie verder in de functie
    $sel         = $sel_export;
    $exported_fg = 0;
    $exported_cpt = 0;
    $log = [];
    $summary = [];
    $any_error = false;

    foreach ($sel as $pt_slug) {
        $exported = false;
        $log_line = date("Y-m-d H:i") . " | $username | ";
        $details = [];
        $field_count = 0;

        // Save counters for rollback on exception
        $prev_cpt = $exported_cpt;
        $prev_fg = $exported_fg;

        try {
            //
            // 1) EXPORT CPT STRUCTURE
            //
            if (isset($sel_struct[$pt_slug])) {
                // Load Pods settings
                $pod_obj = pods($pt_slug);
                $pod_arr = (array) $pod_obj;
                $settings = [];

                if (isset($pod_obj->pod_data)) {
                    $settings = $pod_obj->pod_data;
                } elseif (isset($pod_arr["object"])) {
                    $poddata = (array) $pod_arr["object"];
                    if (isset($poddata["options"])) {
                        $settings = $poddata["options"];
                    }
                } else {
                    $api = pods_api();
                    $pods = $api->load_pods([]);
                    if (isset($pods[$pt_slug])) {
                        $objarr = (array) $pods[$pt_slug];
                        if (isset($objarr["\0*\0args"])) {
                            $settings = $objarr["\0*\0args"];
                        }
                    }
                }

                // Build ACF JSON for CPT
                $acf_post_type = [
                    "key" => "post_type_" . $pt_slug,
                    "name" => $pt_slug,
                    "slug" => $pt_slug,
                    "post_type" => $pt_slug,
                    "title" => $pt_slug,
                    "label" => $settings["label"] ?? ucfirst($pt_slug),
                    "description" => $settings["description"] ?? "",
                    "public" => !empty($settings["public"]),
                    "hierarchical" => !empty($settings["hierarchical"]),
                    "has_archive" => !empty($settings["has_archive"]),
                    "show_ui" => !empty($settings["show_ui"]),
                    "show_in_nav_menus" => !empty($settings["show_in_nav_menus"]),
                    "show_in_admin_bar" => isset($settings["show_in_admin_bar"])
                        ? (bool) $settings["show_in_admin_bar"]
                        : true,
                    "exclude_from_search" => !empty($settings["exclude_from_search"]),
                    "publicly_queryable" => !empty($settings["publicly_queryable"]),
                    "show_in_menu" => !empty($settings["show_ui"]),
                    "show_in_rest" => !empty($settings["rest_enable"]),
                    "query_var" => $settings["query_var"] ?? $pt_slug,
                    "capability_type" => $settings["capability_type"] ?? "post",
                    "map_meta_cap" => !empty($settings["map_meta_cap"]),
                    "menu_position" => isset($settings["menu_position"]) ? intval($settings["menu_position"]) : null,
                    "menu_icon" => $settings["menu_icon"] ?? "",
                    "supports" => $supports,
                    "labels" => [
                        "name" => $settings["label"] ?? ucfirst($pt_slug),
                        "singular_name" => $settings["singular_label"] ?? ucfirst($pt_slug),
                        "menu_name" => $settings["menu_name"] ?? ($settings["label"] ?? ucfirst($pt_slug)),
                        "all_items" =>
                            $settings["all_items"] ?? sprintf(__("All %s"), $settings["label"] ?? ucfirst($pt_slug)),
                    ],
                ];

                // Capabilities array
                if (!empty($settings["capabilities"]) && is_array($settings["capabilities"])) {
                    $acf_post_type["capabilities"] = $settings["capabilities"];
                }

                // Rewrite rules
                if (!empty($settings["rewrite"]) && is_array($settings["rewrite"])) {
                    $acf_post_type["rewrite"] = $settings["rewrite"];
                } else {
                    $acf_post_type["rewrite"] = ["slug" => $pt_slug];
                }

                // REST API
                if (!empty($settings["rest_base"])) {
                    $acf_post_type["rest_base"] = $settings["rest_base"];
                }
                if (!empty($settings["rest_controller_class"])) {
                    $acf_post_type["rest_controller_class"] = $settings["rest_controller_class"];
                }

                // Taxonomies
                $acf_post_type["taxonomies"] = $settings["taxonomies"] ?? [];

                // Labels: full set if provided, otherwise fallback
                if (!empty($settings["labels"]) && is_array($settings["labels"])) {
                    $acf_post_type["labels"] = $settings["labels"];
                } else {
                    $acf_post_type["labels"] = [
                        "name" => $settings["label"] ?? ucfirst($pt_slug),
                        "singular_name" => $settings["singular_label"] ?? ucfirst($pt_slug),
                        "menu_name" => $settings["menu_name"] ?? ($settings["label"] ?? ucfirst($pt_slug)),
                        "all_items" =>
                            $settings["all_items"] ?? sprintf(__("All %s"), $settings["label"] ?? ucfirst($pt_slug)),
                    ];
                }

                // Supports (title, editor, etc.)
                $supports = [];
                foreach (
                    [
                        "title" => "supports_title",
                        "editor" => "supports_editor",
                        "thumbnail" => "supports_thumbnail",
                        "excerpt" => "supports_excerpt",
                        "revisions" => "supports_revisions",
                        "author" => "supports_author",
                    ]
                    as $wp => $podsopt
                ) {
                    if (!empty($settings[$podsopt])) {
                        $supports[] = $wp;
                    }
                }
                $acf_post_type["supports"] = $supports;

                // Write JSON file
                $filename = "acf-post_type-" . $pt_slug . ".json";
                $json = json_encode($acf_post_type, JSON_PRETTY_PRINT);
                if (!file_exists(PODS_ACF_JSON_DIR)) {
                    wp_mkdir_p(PODS_ACF_JSON_DIR);
                }
                file_put_contents(PODS_ACF_JSON_DIR . $filename, $json);

                $exported_cpt++;
                $details[] = "structure";
                $exported = true;
            }

            //
            // 2) EXPORT FIELD GROUP
            //
            $pod_obj = pods($pt_slug);
            if (!$pod_obj || !method_exists($pod_obj, "fields")) {
                throw new Exception("Pod $pt_slug not found or invalid.");
            }
            $all_fields = $pod_obj->fields();
            $field_defs = [];

            if (!empty($sel_fields[$pt_slug])) {
                foreach ($sel_fields[$pt_slug] as $fname) {
                    if (!isset($all_fields[$fname])) {
                        continue;
                    }
                    if (pods_acf_is_field_excluded($fname)) {
                        continue;
                    }
                    $fdata = $all_fields[$fname];
                    $field_defs[] = pods_acf_migrator_map_field($fname, $fdata);
                    $field_count++;
                }
            }

            if (count($field_defs) > 0) {
                $acf_fg = [
                    "key" => "group_" . $pt_slug,
                    "title" => ucfirst($pt_slug) . " Fields",
                    "fields" => $field_defs,
                    "location" => [[["param" => "post_type", "operator" => "==", "value" => $pt_slug]]],
                    "menu_order" => 0,
                    "position" => "normal",
                    "style" => "default",
                    "label_placement" => "top",
                    "instruction_placement" => "label",
                    "hide_on_screen" => "",
                    "active" => true,
                    "description" => "Migrated from Pods",
                ];

                $filename = "acfgroup-" . $pt_slug . ".json";
                $json = json_encode($acf_fg, JSON_PRETTY_PRINT);
                if (!file_exists(PODS_ACF_JSON_DIR)) {
                    wp_mkdir_p(PODS_ACF_JSON_DIR);
                }
                file_put_contents(PODS_ACF_JSON_DIR . $filename, $json);

                $exported_fg++;
                $details[] = "fields: " . $field_count;
                $exported = true;
            }

            // Log result for this CPT
            if (!$exported) {
                $log_line .= "fieldgroup | $pt_slug | no export | Failed";
                $any_error = true;
            } else {
                $log_line .= "fieldgroup | $pt_slug | " . strtolower(implode(", ", $details)) . " | Success";
                $summary[] = ucfirst($pt_slug) . ": " . implode(", ", $details);
            }
            $log[] = $log_line . "\n";
        } catch (Exception $e) {
            // Rollback counters
            $exported_cpt = $prev_cpt;
            $exported_fg = $prev_fg;
            $any_error = true;
            $log_line .= "fieldgroup | $pt_slug | ERROR: " . $e->getMessage() . " | Failed";
            $log[] = $log_line . "\n";
            continue;
        }
    }

    // Append to log file
    file_put_contents(PODS_ACF_LOG_FILE, implode("", $log), FILE_APPEND);

    // --- Build final notice ---
    $msg = [];
    if ($exported_fg > 0) {
        $msg[] = "$exported_fg field group(s)";
    }
    if ($exported_cpt > 0) {
        $msg[] = "$exported_cpt post type structure(s)";
    }

    if (!empty($msg)) {
        $already = (array) get_option("pods_acf_migrated_post_types", []);
        $migrated_slugs = array_keys($sel_struct);
        $merged = array_unique(array_merge($already, $migrated_slugs));
        update_option("pods_acf_migrated_post_types", $merged);

        $notice =
            '<div class="notice notice-success"><p>' .
            __("Migration completed!", "pods-acf-migrator") .
            " " .
            implode(" and ", $msg) .
            " exported.</p></div>";

        return [
            "notice" => $notice,
            "success" => true,
            "summary" => implode("\n", $summary),
        ];
    } else {
        // geen export gedaan
        $notice =
            '<div class="notice notice-warning"><p>' .
            __("No fields or structures were exported. Check your selections.", "pods-acf-migrator") .
            "</p></div>";

        return [
            "notice" => $notice,
            "success" => false,
            "error" => __("No fields or structures exported.", "pods-acf-migrator"),
        ];
    }
}

function pods_acf_migrator_map_field($fname, $fdata)
{
    $acf_type = "text";
    $pods_type = isset($fdata["type"]) ? strtolower($fdata["type"]) : "text";
    switch ($pods_type) {
        case "text":
        case "plain text":
            $acf_type = "text";
            break;
        case "paragraph":
            $acf_type = "textarea";
            break;
        case "wysiwyg":
        case "visual editor":
        case "rich text":
            $acf_type = "wysiwyg";
            break;
        case "date":
            $acf_type = "date_picker";
            break;
        case "time":
            $acf_type = "time_picker";
            break;
        case "number":
            $acf_type = "number";
            break;
        case "yes/no":
        case "boolean":
            $acf_type = "true_false";
            break;
        case "select":
        case "pick":
        case "radio":
            $acf_type = "select";
            break;
        case "checkbox":
            $acf_type = "checkbox";
            break;
        case "relationship":
        case "posttype":
            $acf_type = "relationship";
            break;
        case "taxonomy":
            $acf_type = "taxonomy";
            break;
        case "file":
            $acf_type = "file";
            break;
        case "image":
            $acf_type = "image";
            break;
        case "oembed":
            $acf_type = "oembed";
            break;
        case "color":
            $acf_type = "color_picker";
            break;
        case "user":
            $acf_type = "user";
            break;
        default:
            $acf_type = "text";
            break;
    }
    $result = [
        "key" => "field_" . md5($fname . time() . rand()),
        "label" => $fdata["label"] ?? $fname,
        "name" => $fname,
        "type" => $acf_type,
        "instructions" => "",
        "required" => !empty($fdata["required"]) ? 1 : 0,
        "conditional_logic" => 0,
        "wrapper" => ["width" => "", "class" => "", "id" => ""],
    ];
    // [NEW] Adjust field mapping for relational field types
    if ($pods_type === "relationship" || $pods_type === "posttype") {
        $limit_val = $fdata["limit"] ?? ($fdata["options"]["limit"] ?? null);
        if ($limit_val === null || $limit_val === "" || intval($limit_val) === 1) {
            $result["type"] = "post_object";
        } else {
            $result["type"] = "relationship";
        }
    } elseif ($pods_type === "taxonomy") {
        $taxonomy_target = $fdata["options"]["taxonomy"] ?? ($fdata["options"]["pod"] ?? "");
        if (!$taxonomy_target) {
            $taxonomy_target = "category";
        }
        $limit_val = $fdata["limit"] ?? ($fdata["options"]["limit"] ?? null);
        $field_type = "checkbox";
        if ($limit_val === null || $limit_val === "" || intval($limit_val) === 1) {
            $field_type = "select";
        }
        $result["type"] = "taxonomy";
        $result["taxonomy"] = $taxonomy_target;
        $result["field_type"] = $field_type;
        $result["allow_null"] = 0;
        $result["add_term"] = 0;
        $result["save_terms"] = 0;
        $result["load_terms"] = 0;
        $result["return_format"] = "id";
    } elseif ($pods_type === "user") {
        $result["type"] = "user";
    }
    return $result;
}

// --- EXPORTS PAGE ---
function pods_acf_migrator_exports_page()
{
    if (!current_user_can("manage_options")) {
        wp_die(__("You do not have sufficient permissions to access this page.", "pods-acf-migrator"));
    }
    echo '<div class="wrap"><h1>' . __("Exported JSON files", "pods-acf-migrator") . "</h1>";
    echo '<a href="' .
        admin_url("admin.php?page=pods-acf-migrator") .
        '" class="button" style="margin-bottom:15px;">&#8592; ' .
        __("Back to Dashboard", "pods-acf-migrator") .
        "</a>";
    pods_acf_migrator_exports_table();
    echo "</div>";
}
function pods_acf_migrator_exports_table()
{
    $dir = PODS_ACF_JSON_DIR;
    if (!file_exists($dir)) {
        return;
    }
    $files = glob($dir . "*.json");
    if (empty($files)) {
        echo "<p><em>" . __("No exported field groups or CPT structures found.", "pods-acf-migrator") . "</em></p>";
        return;
    }

    // Delete all button + AJAX feedback
    echo '<div style="margin-bottom:18px;">
        <button id="pods-acf-delete-all-btn" class="button" style="margin-right:16px;">' .
        __("Delete all JSON files", "pods-acf-migrator") .
        '</button>
        <span id="pods-acf-delete-all-msg" style="font-weight:500;"></span>
    </div>';

    echo '<table class="widefat fixed striped"><thead>
        <tr>
            <th style="width:35%;">' .
        __("Filename", "pods-acf-migrator") .
        '</th>
            <th>' .
        __("Type", "pods-acf-migrator") .
        '</th>
            <th>' .
        __("Post Type", "pods-acf-migrator") .
        '</th>
            <th>' .
        __("Date", "pods-acf-migrator") .
        '</th>
            <th>' .
        __("Size", "pods-acf-migrator") .
        '</th>
            <th>' .
        __("Action", "pods-acf-migrator") .
        '</th>
        </tr>
    </thead><tbody>';
    foreach ($files as $file) {
        $fname = basename($file);
        $type =
            strpos($fname, "acfgroup-") === 0
                ? __("Field group", "pods-acf-migrator")
                : (strpos($fname, "acf-cpt-") === 0
                    ? __("Post Type", "pods-acf-migrator")
                    : __("Other", "pods-acf-migrator"));
        $pt = preg_replace('/^acfgroup-|^acf-cpt-|\..+$/', "", $fname);
        $date = date("Y-m-d H:i", filemtime($file));
        $size = round(filesize($file) / 1024, 1) . " KB";
        $download_url = admin_url(
            "admin-ajax.php?action=pods_acf_download&file=" .
                urlencode($fname) .
                "&_wpnonce=" .
                wp_create_nonce("pods_acf_download")
        );
        $delete_url = admin_url(
            "admin-ajax.php?action=pods_acf_delete&file=" .
                urlencode($fname) .
                "&_wpnonce=" .
                wp_create_nonce("pods_acf_delete")
        );
        echo "<tr>
    <td><span class='dashicons dashicons-media-default' style='color:#2271b1;'></span> $fname</td>
    <td>$type</td>
    <td>$pt</td>
    <td>$date</td>
    <td>$size</td>
    <td>";

        echo '<a href="' .
            esc_url($download_url) .
            '" class="button" title="' .
            esc_attr__("Download", "pods-acf-migrator") .
            '" style="margin-right:6px;">
    <span class="dashicons dashicons-download" style="vertical-align:-2px;"></span>
</a>';

        echo '<a href="' .
            esc_url($delete_url) .
            '" class="button" title="' .
            esc_attr__("Delete", "pods-acf-migrator") .
            '"
        onclick="return confirm(\'' .
            esc_js(__("Are you sure you want to delete this file?", "pods-acf-migrator")) .
            '\')">
        <span class="dashicons dashicons-trash" style="vertical-align:-2px;color:#b32d2e;"></span>
    </a>';

        echo "</td></tr>";
    }
    echo "</tbody></table>";
    // AJAX for delete all
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var delBtn = document.getElementById('pods-acf-delete-all-btn');
        if (!delBtn) return;
        delBtn.addEventListener('click', function(e){
            e.preventDefault();
            if (!confirm("<?php echo esc_js(
                __("Are you sure you want to delete ALL exported JSON files?", "pods-acf-migrator")
            ); ?>")) return;
            delBtn.disabled = true;
            var msg = document.getElementById('pods-acf-delete-all-msg');
            msg.textContent = '<?php echo esc_js(__("Deleting...", "pods-acf-migrator")); ?>';
            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ action: 'pods_acf_delete_all_json', security: '<?php echo wp_create_nonce(
                    "pods_acf_delete_all_action"
                ); ?>' })
            })
                .then(resp => resp.json())
                .then(data => {
                    if (data.success) {
                        // Remove all table rows except thead
                        var tbl = delBtn.parentNode.nextElementSibling;
                        if (tbl && tbl.tagName === "TABLE") {
                            var rows = tbl.querySelectorAll('tbody tr');
                            rows.forEach(function(row){ row.parentNode.removeChild(row); });
                        }
                        msg.textContent = '<?php echo esc_js(__("All files deleted!", "pods-acf-migrator")); ?>';
                    } else {
                        msg.textContent = data.data && data.data.message ? data.data.message : '<?php echo esc_js(
                            __("Error deleting files.", "pods-acf-migrator")
                        ); ?>';
                    }
                    delBtn.disabled = false;
                })
                .catch(err => {
                    msg.textContent = '<?php echo esc_js(__("Error deleting files.", "pods-acf-migrator")); ?>';
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
function pods_acf_migrator_log_page()
{
    if (!current_user_can("manage_options")) {
        wp_die(__("You do not have sufficient permissions to access this page.", "pods-acf-migrator"));
    }
    echo '<div class="wrap"><h1>' . __("Migration Log", "pods-acf-migrator") . "</h1>";
    echo '<a href="' .
        admin_url("admin.php?page=pods-acf-migrator") .
        '" class="button" style="margin-bottom:15px;">&#8592; ' .
        __("Back to Dashboard", "pods-acf-migrator") .
        "</a>";
    pods_acf_migrator_log_table();
    echo "</div>";
}
function pods_acf_migrator_log_table()
{
    $file = PODS_ACF_LOG_FILE;
    if (!file_exists($file) || filesize($file) === 0) {
        echo "<p><em>" . __("No migrations logged yet.", "pods-acf-migrator") . "</em></p>";
        return;
    }
    $lines = explode("\n", trim(file_get_contents($file)));
    echo '<table class="widefat fixed striped" style="max-width:1050px;">';
    echo '<thead><tr>
        <th>' .
        __("Date", "pods-acf-migrator") .
        '</th>
        <th>' .
        __("User", "pods-acf-migrator") .
        '</th>
        <th>' .
        __("Type", "pods-acf-migrator") .
        '</th>
        <th>' .
        __("Post Type", "pods-acf-migrator") .
        '</th>
        <th>' .
        __("Details", "pods-acf-migrator") .
        '</th>
        <th>' .
        __("Status", "pods-acf-migrator") .
        '</th>
    </tr></thead><tbody>';
    foreach ($lines as $line) {
        if (!$line) {
            continue;
        }
        $cols = explode("|", $line);
        $cols = array_map("trim", $cols);
        $status_raw = isset($cols[5]) ? strtolower($cols[5]) : "";
        $status_class = "";
        $status_label = "";
        if ($status_raw === "success") {
            $status_class = "pods-acf-log-success";
            $status_label = "success";
        } elseif ($status_raw === "failed" || $status_raw === "fail" || strpos($status_raw, "no export") !== false) {
            $status_class = "pods-acf-log-failed";
            $status_label = "failed";
        } else {
            $status_class = "";
            $status_label = esc_html($status_raw);
        }
        echo "<tr>";
        echo "<td>" . esc_html($cols[0] ?? "") . "</td>";
        echo "<td>" . esc_html($cols[1] ?? "") . "</td>";
        echo "<td>" . esc_html($cols[2] ?? "") . "</td>";
        echo "<td>" . esc_html($cols[3] ?? "") . "</td>";
        echo '<td style="text-transform:lowercase;">' . esc_html($cols[4] ?? "") . "</td>";
        echo '<td class="' . $status_class . '">' . ucfirst($status_label) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    echo '<div style="margin-top:18px;">';
    echo '<button id="pods-acf-clear-log-btn" class="button" style="margin-right:16px;">' .
        __("Clear log", "pods-acf-migrator") .
        "</button>";
    $download_url = admin_url(
        "admin-ajax.php?action=pods_acf_download_log&_wpnonce=" . wp_create_nonce("pods_acf_download_log")
    );
    echo '<a href="' . $download_url . '" class="button">' . __("Download log (.txt)", "pods-acf-migrator") . "</a>";
    echo '<span id="pods-acf-log-msg" style="margin-left:16px;font-weight:500;"></span>';
    echo "</div>";
    ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var clearBtn = document.getElementById('pods-acf-clear-log-btn');
    if (!clearBtn) return;
    clearBtn.addEventListener('click', function(e){
        e.preventDefault();
        if (!confirm("<?php echo esc_js(
            __("Are you sure you want to clear the migration log?", "pods-acf-migrator")
        ); ?>")) return;
        clearBtn.disabled = true;
        var msg = document.getElementById('pods-acf-log-msg');
        msg.textContent = '<?php echo esc_js(__("Clearing...", "pods-acf-migrator")); ?>';
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
add_action("wp_ajax_pods_acf_download", function () {
    if (!current_user_can("manage_options")) {
        exit("No permission");
    }
    check_admin_referer("pods_acf_download");
    $fname = isset($_GET["file"]) ? basename($_GET["file"]) : "";
    $fpath = PODS_ACF_JSON_DIR . $fname;
    if (!$fname || !file_exists($fpath)) {
        wp_die("File not found");
    }
    header("Content-Type: application/json");
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    readfile($fpath);
    exit();
});
add_action("wp_ajax_pods_acf_delete", function () {
    if (!current_user_can("manage_options")) {
        exit("No permission");
    }
    check_admin_referer("pods_acf_delete");
    $fname = isset($_GET["file"]) ? basename($_GET["file"]) : "";
    $fpath = PODS_ACF_JSON_DIR . $fname;
    if ($fname && file_exists($fpath)) {
        unlink($fpath);
    }
    wp_redirect(admin_url("admin.php?page=pods-acf-migrator-exports"));
    exit();
});
add_action("wp_ajax_pods_acf_download_log", function () {
    if (!current_user_can("manage_options")) {
        exit("No permission");
    }
    check_admin_referer("pods_acf_download_log");
    $file = PODS_ACF_LOG_FILE;
    if (!file_exists($file)) {
        wp_die("No log found");
    }
    header("Content-Type: text/plain");
    header('Content-Disposition: attachment; filename="pods-acf-migrator-log.txt"');
    readfile($file);
    exit();
});
add_action("wp_ajax_pods_acf_clear_log", function () {
    if (!current_user_can("manage_options")) {
        wp_send_json_error(["message" => "No permission"]);
    }
    file_put_contents(PODS_ACF_LOG_FILE, "");
    wp_send_json_success(["message" => "Log cleared"]);
});
// AJAX: Delete all JSON
add_action("wp_ajax_pods_acf_delete_all_json", function () {
    if (!current_user_can("manage_options")) {
        wp_send_json_error(["message" => "No permission"]);
    }
    // [NEW] Nonce validation
    check_ajax_referer("pods_acf_delete_all_action", "security");
    $dir = PODS_ACF_JSON_DIR;
    $files = glob($dir . "*.json");
    $removed = 0;
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
            $removed++;
        }
    }
    if ($removed > 0) {
        wp_send_json_success(["message" => "All JSON files deleted."]);
    } else {
        wp_send_json_error(["message" => "No files to delete."]);
    }
});

// AJAX handler to deactivate Pods plugin programmatically.
add_action("wp_ajax_pods_acf_deactivate_pods", function () {
    if (!current_user_can("activate_plugins")) {
        wp_send_json_error(__("Insufficient permissions", "pods-acf-migrator"));
    }
    check_ajax_referer("pods_acf_deactivate_pods", "nonce");
    $plugin = sanitize_text_field(wp_unslash($_POST["plugin"] ?? ""));
    if (!in_array($plugin, (array) get_option("active_plugins", []), true)) {
        wp_send_json_error(__("Pods plugin not active", "pods-acf-migrator"));
    }
    deactivate_plugins($plugin);
    wp_send_json_success();
});

/**
 * Inject our accordion-icon script in the admin footer, but only on our plugin page.
 */
add_action( 'admin_footer', function() {
    $screen = get_current_screen();
    if ( $screen->id !== 'toplevel_page_pods-acf-migrator' ) {
        return;
    }
    ?>
    <script>
    (function(){
        // 1) Helper om alle velden in een panel te togglen
        window.podsSelectAllFields = function(slug, cb) {
            document.querySelectorAll('.field_' + slug).forEach(function(f){
                if (!f.disabled) f.checked = cb.checked;
            });
            // update icon
            var panel = document.getElementById('panel_' + slug);
            if (panel) updateAccordionTitleIcon(panel);
        };

        // 2) Accordion toggle (bestond al, maar definieer hier voor zekerheid)
        window.podsAccToggle = function(slug) {
            var panel = document.getElementById('panel_' + slug);
            panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        };

        // 3) Icon-updater
        function updateAccordionTitleIcon(panel) {
            var anyChecked = Array.from(panel.querySelectorAll('input[type=checkbox]'))
                                  .some(cb => cb.checked);
            var titleDiv = panel.previousElementSibling; // .pods-acc-title
            var existing = titleDiv.querySelector('.pods-acc-checked-icon');

            if ( anyChecked ) {
                if ( ! existing ) {
                    var icon = document.createElement('span');
                    icon.className = 'dashicons dashicons-yes pods-acc-checked-icon';
                    icon.style.color         = '#0073aa';
                    icon.style.marginRight   = '6px';
                    icon.style.verticalAlign = 'middle';
                    titleDiv.insertBefore(icon, titleDiv.firstChild);
                }
            } else if ( existing ) {
                existing.remove();
            }
        }

        // 4) Hook up events once DOM is ready
        document.addEventListener('DOMContentLoaded', function(){
            // Reset & global select-all al in jouw form, dus alleen icon-logic hier:
            document.querySelectorAll('.pods-acc-panel').forEach(function(panel){
                panel.querySelectorAll('input[type=checkbox]').forEach(function(cb){
                    cb.addEventListener('change', function(){
                        updateAccordionTitleIcon(panel);
                    });
                });
                // initialisatie
                updateAccordionTitleIcon(panel);
            });
        });
    })();
        // 2) Reset selection: alleen uitvinken, niet disable’n én change afvuren
document.getElementById('pods-reset-btn').addEventListener('click', function() {
  document.querySelectorAll('#pods-acf-export-form input[type=checkbox]').forEach(function(cb){
    cb.checked  = false;
    cb.disabled = false;
    // forceer change event
    cb.dispatchEvent(new Event('change'));
  });
});

// 3) Global “Select all”: vink álle checkboxen aan én trigger change
document.getElementById('pods-select-all-btn').addEventListener('click', function(){
  document.querySelectorAll('#pods-acf-export-form input[type=checkbox]').forEach(function(cb){
    if (!cb.disabled) {
      cb.checked = true;
      cb.dispatchEvent(new Event('change'));
    }
  });
});
    </script>
    <?php
} );