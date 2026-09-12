<?php

/**
 * @file
 * Hooks provided by the Web View Modes Inventory module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the folders of the view mode templates.
 *
 * The template of a view mode is taken from the first folder having it.
 *
 * @param array $directories
 *   The template folders, in priority order, each with:
 *   - path: the absolute path of the folder.
 *   - provider: the extension providing the templates.
 *   - theme: the theme replacing the DEFAULT_ACTIVE_THEME placeholder.
 *   - rename: the theme the component IDs are renamed from, or NULL.
 * @param string $theme
 *   The theme the templates are resolved for.
 *
 * @see \Drupal\webvmi\ViewModesInventoryFactory::getTemplateDirectories()
 */
function hook_webvmi_template_directories_alter(array &$directories, string $theme): void {
  // Use the templates of a site template recipe module first.
  array_unshift($directories, [
    'path' => DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('my_module') . '/webvmi/CONTENT_TYPE_NAME',
    'provider' => 'my_module',
    'theme' => $theme,
    'rename' => NULL,
  ]);
}

/**
 * @} End of "addtogroup hooks".
 */
