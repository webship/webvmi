<?php

declare(strict_types=1);

namespace Drupal\webvmi;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\display_builder\DisplayBuildablePluginManager;

/**
 * Lays out the view modes of the inventory with Display Builder templates.
 *
 * A template is a core.entity_view_display config file with Display Builder
 * sources, for a content type named CONTENT_TYPE_NAME, like
 * core.entity_view_display.node.CONTENT_TYPE_NAME.card.yml for the card.
 *
 * The template of a view mode is taken from the first of these folders:
 * - webvmi/CONTENT_TYPE_NAME in the default theme, then
 *   src/assets/config_templates/<theme>/CONTENT_TYPE_NAME in this module, for
 *   the theme and each of its base themes.
 * - The same folders for the theme a theme was generated from (the
 *   "generator" of its info file), with the component IDs renamed to the
 *   generated theme.
 * - src/assets/config_templates/CONTENT_TYPE_NAME in this module: the fields
 *   without components, for any theme.
 *
 * Modules can change these folders with the
 * hook_webvmi_template_directories_alter() hook.
 */
class ViewModesInventoryFactory {

  /**
   * The folder of the templates, relative to the theme.
   */
  public const string THEME_TEMPLATES = 'webvmi/CONTENT_TYPE_NAME';

  /**
   * The folder of the templates, relative to this module.
   */
  public const string MODULE_TEMPLATES = 'src/assets/config_templates';

  /**
   * Media field candidates, in priority order.
   */
  public const array MEDIA_FIELDS = [
    'field_featured_image',
    'field_main_image',
    'field_image',
    'field_media',
    'field_hero_image',
    'field_banner_image',
    'field_thumbnail',
    'field_cover_image',
    'field_photo',
    'field_media_image',
  ];

  /**
   * Description field candidates, in priority order.
   */
  public const array DESCRIPTION_FIELDS = [
    'body',
    'field_body',
    'field_content',
    'field_description',
    'field_summary',
  ];

  /**
   * The view modes of the inventory.
   */
  protected ?array $viewModes = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleExtensionList,
    protected ThemeExtensionList $themeExtensionList,
    protected DisplayBuildablePluginManager $displayBuildableManager,
  ) {}

  /**
   * Gets the view modes of the inventory.
   *
   * @return array
   *   The view modes, keyed by machine name, with their label.
   */
  public function getViewModesList(): array {
    if ($this->viewModes === NULL) {
      $file = $this->moduleExtensionList->getPath('webvmi') . '/src/assets/view_modes.list.webvmi.yml';
      $this->viewModes = Yaml::decode((string) file_get_contents($file))['view_modes'] ?? [];
    }
    return $this->viewModes;
  }

  /**
   * Tells if a view mode is part of the inventory.
   */
  public function isInventoryViewMode(string $view_mode): bool {
    return isset($this->getViewModesList()[$view_mode]);
  }

  /**
   * Gets the template folders, in priority order.
   *
   * @param string|null $theme
   *   The theme. The default theme when NULL.
   *
   * @return array
   *   A list of folders, each with:
   *   - path: the absolute path of the folder.
   *   - provider: the extension providing the templates.
   *   - theme: the theme of the components (for DEFAULT_ACTIVE_THEME).
   *   - rename: the theme the component IDs are renamed from, or NULL.
   */
  public function getTemplateDirectories(?string $theme = NULL): array {
    $theme = $theme ?: (string) $this->configFactory->get('system.theme')->get('default');
    $root = $this->getModuleTemplatesRoot();
    $directories = [];

    $chain = $this->getThemeChain($theme);
    foreach ($chain as $name) {
      $this->addDirectories($directories, $root, $name, $name, NULL);
    }
    // A theme generated from a starterkit theme has the same components.
    foreach ($chain as $name) {
      $generator = $this->getGenerator($name);
      if ($generator) {
        $this->addDirectories($directories, $root, $generator, $name, $generator);
      }
    }

    $directories[] = [
      'path' => $root . '/CONTENT_TYPE_NAME',
      'provider' => 'webvmi',
      'theme' => $theme,
      'rename' => NULL,
    ];

    $this->moduleHandler->alter('webvmi_template_directories', $directories, $theme);
    return $directories;
  }

  /**
   * Finds the template of a view mode.
   *
   * @param string $view_mode
   *   The view mode.
   * @param string|null $theme
   *   The theme. The default theme when NULL.
   *
   * @return array|null
   *   The template folder (see ::getTemplateDirectories()) with the "file"
   *   path of the template, or NULL when there is no template.
   */
  public function findTemplate(string $view_mode, ?string $theme = NULL): ?array {
    foreach ($this->getTemplateDirectories($theme) as $directory) {
      $file = $directory['path'] . '/core.entity_view_display.node.CONTENT_TYPE_NAME.' . $view_mode . '.yml';
      if (is_file($file)) {
        return $directory + ['file' => $file];
      }
    }
    return NULL;
  }

  /**
   * Enables and lays out a view mode of a content type with its template.
   *
   * @param string $view_mode
   *   The view mode.
   * @param string $entity_type
   *   The entity type. Only node is supported.
   * @param string $bundle
   *   The content type.
   * @param string|null $theme
   *   The theme of the templates. The default theme when NULL.
   *
   * @return bool
   *   TRUE when the display was laid out.
   */
  public function mapViewModeWithLayout(string $view_mode, string $entity_type, string $bundle, ?string $theme = NULL): bool {
    if ($entity_type !== 'node' || !$this->isInventoryViewMode($view_mode)) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display */
    $display = $storage->load("$entity_type.$bundle.$view_mode") ?? $storage->create([
      'targetEntityType' => $entity_type,
      'bundle' => $bundle,
      'mode' => $view_mode,
      'status' => TRUE,
    ]);
    // The template sets the profile, so the presave hook keeps the layout.
    if (!$this->applyTemplate($display, $theme)) {
      return FALSE;
    }
    $display->setStatus(TRUE);
    $display->save();

    // Refresh an open builder with the new layout.
    /** @var \Drupal\display_builder\DisplayBuildableInterface $buildable */
    $buildable = $this->displayBuildableManager->createInstance('entity_view', ['display' => $display]);
    $instance = $buildable->getInstance();
    if ($instance) {
      $instance->setNewPresent($buildable->getSources(), new TranslatableMarkup('Web View Modes Inventory template'));
      $instance->save();
    }
    return TRUE;
  }

  /**
   * Applies the template of its view mode to a display, without saving it.
   *
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display
   *   A node display.
   * @param string|null $theme
   *   The theme of the templates. The default theme when NULL.
   *
   * @return bool
   *   TRUE when a template was applied.
   */
  public function applyTemplate(EntityViewDisplayInterface $display, ?string $theme = NULL): bool {
    $template = $this->findTemplate($display->getMode(), $theme);
    if (!$template) {
      return FALSE;
    }
    $bundle = $display->getTargetBundle();
    $data = Yaml::decode(str_replace(
      ['CONTENT_TYPE_NAME', 'DEFAULT_ACTIVE_THEME', 'MEDIA_FIELD_NAME', 'DESCRIPTION_FIELD_NAME'],
      [$bundle, $template['theme'], $this->getMediaFieldName($bundle), $this->getDescriptionFieldName($bundle)],
      (string) file_get_contents($template['file']),
    ));
    $settings = $data['third_party_settings']['display_builder'] ?? [];
    if (empty($settings['sources'])) {
      return FALSE;
    }

    $sources = $this->prepareSources($settings['sources'], $bundle, $template['rename'], $template['theme']);
    $profile = $this->getProfile($settings['profile'] ?? 'default');
    if (!$profile) {
      return FALSE;
    }
    $display->setThirdPartySetting('display_builder', 'profile', $profile);
    $display->setThirdPartySetting('display_builder', 'sources', $sources);
    return TRUE;
  }

  /**
   * Resolves the media field of a content type.
   */
  public function getMediaFieldName(string $bundle): string {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    foreach (static::MEDIA_FIELDS as $field_name) {
      if (isset($definitions[$field_name]) && $this->isMediaField($definitions[$field_name])) {
        return $field_name;
      }
    }
    return 'field_image';
  }

  /**
   * Resolves the description field of a content type.
   */
  public function getDescriptionFieldName(string $bundle): string {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    foreach (static::DESCRIPTION_FIELDS as $field_name) {
      if (isset($definitions[$field_name])) {
        return $field_name;
      }
    }
    return 'body';
  }

  /**
   * Prepares the template sources for a content type.
   *
   * Removes the fields the content type does not have, adapts the formatters
   * to the field types and renames the components of a generated theme.
   *
   * @param array $sources
   *   The template sources.
   * @param string $bundle
   *   The content type.
   * @param string|null $rename
   *   The theme the component IDs are renamed from.
   * @param string $theme
   *   The theme the component IDs are renamed to.
   *
   * @return array
   *   The sources.
   */
  public function prepareSources(array $sources, string $bundle, ?string $rename, string $theme): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    $prepared = [];
    foreach ($sources as $key => $source) {
      if (!\is_array($source)) {
        $prepared[$key] = $source;
        continue;
      }
      $source_id = $source['source_id'] ?? NULL;
      if ($source_id === 'entity_field') {
        $field_name = substr((string) ($source['source']['derivable_context'] ?? ''), \strlen("field:node:$bundle:"));
        if (!isset($definitions[$field_name])) {
          continue;
        }
      }
      if (\is_string($source_id) && str_starts_with($source_id, "field_formatter:node:$bundle:")) {
        $field_name = substr($source_id, \strlen("field_formatter:node:$bundle:"));
        if (isset($definitions[$field_name])) {
          $source['source'] = $this->adaptFormatter($source['source'] ?? [], $definitions[$field_name]);
        }
      }
      if ($rename && isset($source['component_id']) && \is_string($source['component_id']) && str_starts_with($source['component_id'], "$rename:")) {
        $source['component_id'] = $theme . substr($source['component_id'], \strlen($rename));
      }
      $prepared[$key] = $this->prepareSources($source, $bundle, $rename, $theme);
    }
    // Keep lists as lists once fields are removed.
    return array_is_list($sources) ? array_values($prepared) : $prepared;
  }

  /**
   * Adapts a formatter of the template to the type of the field.
   *
   * @param array $formatter
   *   The formatter: type, label, settings and third party settings.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return array
   *   The formatter.
   */
  protected function adaptFormatter(array $formatter, FieldDefinitionInterface $definition): array {
    $field_type = $definition->getType();
    $type = $formatter['type'] ?? '';

    if ($type === 'image' && $field_type === 'entity_reference') {
      // Media: render the media entity, linked or not like the image.
      return [
        'type' => 'entity_reference_entity_view',
        'label' => 'hidden',
        'settings' => ['view_mode' => 'default', 'link' => FALSE],
        'third_party_settings' => [],
      ];
    }

    if (\in_array($type, ['text_summary_or_trimmed', 'text_default'], TRUE)) {
      $trim_length = $formatter['settings']['trim_length'] ?? NULL;
      [$type, $settings] = match ($field_type) {
        'text_with_summary' => [$type, $formatter['settings'] ?? []],
        'text', 'text_long' => $trim_length ? ['text_trimmed', ['trim_length' => $trim_length]] : ['text_default', []],
        default => ['basic_string', []],
      };
      $formatter['type'] = $type;
      $formatter['settings'] = $settings;
    }

    return $formatter;
  }

  /**
   * Tells if a field holds images or media.
   */
  protected function isMediaField(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'image'
      || ($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'media');
  }

  /**
   * Gets the Display Builder profile of a template, or the first one.
   */
  protected function getProfile(string $profile): ?string {
    $storage = $this->entityTypeManager->getStorage('display_builder_profile');
    if ($storage->load($profile)) {
      return $profile;
    }
    $ids = array_keys($storage->loadMultiple());
    return $ids ? (string) reset($ids) : NULL;
  }

  /**
   * Adds the template folders of a theme: in the theme, then in this module.
   *
   * @param array $directories
   *   The template folders.
   * @param string $root
   *   The folder of the module templates.
   * @param string $provider
   *   The theme the templates are made for.
   * @param string $theme
   *   The theme the component IDs are renamed to, when renamed.
   * @param string|null $rename
   *   The theme the component IDs are renamed from, or NULL.
   */
  protected function addDirectories(array &$directories, string $root, string $provider, string $theme, ?string $rename): void {
    $candidates = [
      $provider => DRUPAL_ROOT . '/' . $this->themeExtensionList->getPath($provider) . '/' . static::THEME_TEMPLATES,
      "webvmi/$provider" => "$root/$provider/CONTENT_TYPE_NAME",
    ];
    foreach ($candidates as $name => $path) {
      if (is_dir($path)) {
        $directories[] = [
          'path' => $path,
          'provider' => $name,
          'theme' => $rename ? $theme : $provider,
          'rename' => $rename,
        ];
      }
    }
  }

  /**
   * Gets the folder of the module templates.
   *
   * It has the templates for any theme in CONTENT_TYPE_NAME, and the templates
   * of the themes this module ships layouts for in <theme>/CONTENT_TYPE_NAME.
   */
  protected function getModuleTemplatesRoot(): string {
    return DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('webvmi') . '/' . static::MODULE_TEMPLATES;
  }

  /**
   * Gets a theme and its base themes.
   *
   * @return string[]
   *   The theme machine names, the theme first.
   */
  protected function getThemeChain(string $theme): array {
    $info = $this->themeExtensionList->getAllAvailableInfo();
    $chain = [];
    while (\is_string($theme) && isset($info[$theme]) && !\in_array($theme, $chain, TRUE)) {
      $chain[] = $theme;
      $theme = $info[$theme]['base theme'] ?? NULL;
    }
    return $chain;
  }

  /**
   * Gets the starterkit theme a theme was generated from.
   */
  protected function getGenerator(string $theme): ?string {
    $info = $this->themeExtensionList->getAllAvailableInfo();
    $generator = $info[$theme]['generator'] ?? NULL;
    if (!\is_string($generator)) {
      return NULL;
    }
    $name = explode(':', $generator)[0];
    return $name !== $theme && isset($info[$name]) ? $name : NULL;
  }

}
