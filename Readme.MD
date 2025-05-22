# Pods to ACF Migrator

Easily migrate your [Pods](https://pods.io/) Custom Post Types (CPT) and fields to [Advanced Custom Fields PRO (ACF Pro)](https://www.advancedcustomfields.com/pro/) using a visual and user-friendly interface. This plugin helps you to convert and export Pods CPT structures and fields as ACF JSON files, ready for sync in ACF Pro.

## Features

- Export selected Pods Custom Post Types and fields to ACF 6.x+ JSON format.
- Supports both field groups and CPT structure export.
- Visual dashboard with search/filter, field selection, and "select all fields" per CPT.
- Progress bar during export for better user experience.
- Migration log with status indicators (success/failure) and details for each migration.
- AJAX-powered: clear migration log and bulk delete all exported JSON files without page reload.
- Download and delete individual exported JSON files.
- Fully admin-only and safe (no frontend exposure).
- Modern, WordPress-style design for optimal UX.

## Requirements

- WordPress 5.8 or higher
- [Pods](https://wordpress.org/plugins/pods/) plugin
- [Advanced Custom Fields PRO (ACF Pro)](https://www.advancedcustomfields.com/pro/) **version 6.0 or higher**

## Installation

1. Download or clone this repository to your `/wp-content/plugins/` directory.
2. Activate the plugin via the WordPress admin (Plugins > Installed Plugins).
3. Make sure both Pods and ACF Pro (6.0+) are activated.

## Usage

1. Go to **Pods to ACF Migrator** in your WordPress admin menu.
2. Select which Custom Post Types and fields you want to migrate.
3. Optionally, choose to export the CPT structure itself for ACF.
4. Click **Export selected**. A progress bar will indicate export progress.
5. After export, follow the steps shown to sync the new field groups and CPTs in ACF (via ACF → Field Groups and/or ACF → Post Types > "Sync available").
6. Check the **Migration Log** for details or download/delete exported files on the **Exported JSON files** page.

## Screenshots

_You may want to add screenshots here!_

## Advanced

- Use the "Select all fields" checkbox to quickly select all fields for a CPT.
- Use the **bulk delete** button on the Exported JSON files page to remove all exported files in one click (AJAX).
- Use the **Clear log** button on the Migration Log page to instantly clear the log (AJAX).
- All actions are restricted to WordPress admins.

## FAQ

**Does this modify my data?**  
No. All exports are non-destructive and create only JSON files for use with ACF.

**Where are the exported files stored?**  
In the plugin folder under `/acf-json/`.

**How do I sync with ACF?**  
Go to ACF → Field Groups and/or ACF → Post Types, and use the "Sync available" button.

**What field types are supported?**  
Most common Pods field types are mapped to ACF equivalents (see code for mappings).

## Support

This is an open-source plugin, provided as-is. For issues, requests or improvements, please open an [issue](https://github.com/your-github-username/pods-acf-migrator/issues) or submit a pull request.

## Disclaimer

Not affiliated with Pods, ACF, or Delicious Brains.  
Always back up your site and test migrations before using on production.

---

**Enjoy migrating!**