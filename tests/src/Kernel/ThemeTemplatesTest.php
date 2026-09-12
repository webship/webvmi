<?php

declare(strict_types=1);

namespace Drupal\Tests\webvmi\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\Dependency;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Validates the theme templates of the module against the theme components.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[Group('webvmi')]
final class ThemeTemplatesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'ui_patterns',
  ];

  /**
   * {@inheritdoc}
   *
   * The UI Suite DaisyUI settings have a ui_skins_theme setting without a
   * schema: only the templates are tested.
   */
  protected static $configSchemaCheckerExclusions = [
    'ui_suite_daisyui.settings',
  ];

  /**
   * The themes having templates in the module.
   *
   * @return array
   *   The test cases, with the theme machine name.
   */
  public static function providerThemes(): array {
    return [
      'UI Suite Bootstrap' => ['ui_suite_bootstrap'],
      'UI Suite DaisyUI' => ['ui_suite_daisyui'],
    ];
  }

  /**
   * Tests the templates of a theme.
   *
   * The files are always checked. The components are checked when the theme
   * and the modules it depends on are available.
   */
  #[DataProvider('providerThemes')]
  public function testTemplates(string $theme): void {
    $module_path = \dirname(__DIR__, 3);
    $list = file_get_contents($module_path . '/src/assets/view_modes.list.webvmi.yml');
    $view_modes = array_keys(Yaml::decode((string) $list)['view_modes']);
    $folder = "$module_path/src/assets/config_templates/$theme/CONTENT_TYPE_NAME";
    $this->assertCount(\count($view_modes), glob("$folder/*.yml") ?: [], $theme);

    $templates = [];
    foreach ($view_modes as $mode) {
      $name = "core.entity_view_display.node.CONTENT_TYPE_NAME.$mode.yml";
      $this->assertFileExists("$folder/$name");
      $template = Yaml::decode((string) file_get_contents("$folder/$name"));
      $this->assertSame("node.CONTENT_TYPE_NAME.$mode", $template['id'], $name);
      $this->assertSame($mode, $template['mode'], $name);
      $this->assertSame('CONTENT_TYPE_NAME', $template['bundle'], $name);
      $this->assertContains("core.entity_view_mode.node.$mode", $template['dependencies']['config'], $name);
      $sources = $template['third_party_settings']['display_builder']['sources'] ?? [];
      $this->assertNotEmpty($sources, $name);
      $templates[$name] = $sources;
    }

    $this->installTheme($theme);
    foreach ($templates as $name => $sources) {
      $this->assertComponents($sources, $theme, $name);
    }
  }

  /**
   * Installs a theme and its modules, or skips the test.
   */
  private function installTheme(string $theme): void {
    $themes = $this->container->get('extension.list.theme')->getAllAvailableInfo();
    if (!isset($themes[$theme]) || !empty($themes[$theme]['core_incompatible'])) {
      $this->markTestSkipped("The $theme theme is not available.");
    }
    $available = $this->container->get('extension.list.module')->getAllAvailableInfo();
    $modules = [];
    foreach ($themes[$theme]['dependencies'] ?? [] as $dependency) {
      $module = Dependency::createFromString($dependency)->getName();
      if (!isset($available[$module])) {
        $this->markTestSkipped("The $module module needed by $theme is not available.");
      }
      $modules[] = $module;
    }
    $this->enableModules($modules);
    $this->container->get('theme_installer')->install([$theme]);
  }

  /**
   * Asserts the component sources match the component definitions.
   *
   * @param array $data
   *   The sources, or a part of them.
   * @param string $theme
   *   The theme of the components.
   * @param string $name
   *   The template file name.
   */
  private function assertComponents(array $data, string $theme, string $name): void {
    foreach ($data as $key => $value) {
      if (!\is_array($value)) {
        continue;
      }
      if ($key === 'component' && isset($value['component_id'])) {
        $this->assertComponent($value, $theme, $name);
      }
      $this->assertComponents($value, $theme, $name);
    }
  }

  /**
   * Asserts a component source matches its component definition.
   *
   * @param array $source
   *   The component source.
   * @param string $theme
   *   The theme of the components.
   * @param string $name
   *   The template file name.
   */
  private function assertComponent(array $source, string $theme, string $name): void {
    $id = $source['component_id'];
    $message = "$name: $id";
    $this->assertStringStartsWith("$theme:", $id, $message);

    /** @var \Drupal\Core\Plugin\Component $component */
    $component = $this->container->get('plugin.manager.sdc')->find($id);
    $definition = $component->getPluginDefinition();

    $variant = $source['variant_id']['source']['value'] ?? NULL;
    if ($variant !== NULL) {
      $this->assertArrayHasKey($variant, $definition['variants'] ?? [], "$message variant");
    }

    $properties = $component->metadata->schema['properties'] ?? [];
    foreach ($source['props'] ?? [] as $prop_id => $prop) {
      $this->assertArrayHasKey($prop_id, $properties, "$message prop $prop_id");
      $enum = $properties[$prop_id]['enum'] ?? NULL;
      if ($enum !== NULL) {
        $this->assertContains($prop['source']['value'], $enum, "$message prop $prop_id value");
      }
    }

    foreach (array_keys($source['slots'] ?? []) as $slot_id) {
      $this->assertArrayHasKey($slot_id, $definition['slots'] ?? [], "$message slot $slot_id");
    }
  }

}
