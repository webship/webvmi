<?php

declare(strict_types=1);

namespace Drupal\Tests\webvmi\Kernel;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\webvmi\ViewModesInventoryFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the view modes inventory templates.
 */
#[CoversClass(ViewModesInventoryFactory::class)]
#[RunTestsInSeparateProcesses]
#[Group('webvmi')]
final class ViewModesInventoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'field_ui',
    'file',
    'filter',
    'image',
    'text',
    'ui_patterns',
    'ui_patterns_field',
    'ui_patterns_field_formatters',
    'ui_patterns_library',
    'display_builder',
    'display_builder_entity_view',
    'webvmi',
  ];

  /**
   * The view modes inventory factory.
   */
  protected ViewModesInventoryFactory $factory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('display_builder_instance');
    $this->installConfig(['system', 'field', 'filter', 'node', 'image', 'webvmi']);
    $this->config('system.theme')->set('default', 'stark')->save();

    $this->container->get('entity_type.manager')->getStorage('display_builder_profile')
      ->create(['id' => 'default', 'label' => 'Default', 'description' => ''])
      ->save();

    // Articles with a body and an image, pages with a long text description.
    foreach (['article' => 'Article', 'page' => 'Page'] as $type => $label) {
      NodeType::create(['type' => $type, 'name' => $label])->save();
    }
    $this->addField('article', 'body', 'text_with_summary');
    $this->addField('article', 'field_image', 'image');
    $this->addField('page', 'field_description', 'text_long');

    $this->factory = $this->container->get('webvmi.factory');
  }

  /**
   * Tests the view modes of the inventory.
   */
  public function testViewModes(): void {
    $view_modes = $this->factory->getViewModesList();
    $this->assertCount(19, $view_modes);
    foreach (array_keys($view_modes) as $mode) {
      $this->assertTrue($this->factory->isInventoryViewMode($mode));
      $this->assertSame('webvmi', $this->factory->findTemplate($mode)['provider'], $mode);
      if ($mode !== 'full') {
        $this->assertNotNull(EntityViewMode::load("node.$mode"), $mode);
      }
    }
    $this->assertFalse($this->factory->isInventoryViewMode('teaser'));
  }

  /**
   * Tests enabling a view mode lays it out with its template.
   */
  public function testEnableViewMode(): void {
    $this->assertSame('field_image', $this->factory->getMediaFieldName('article'));
    $this->assertSame('body', $this->factory->getDescriptionFieldName('article'));

    $display = $this->saveDisplay('article', 'impressed_card_medium');
    $this->assertSame('default', $display->getThirdPartySetting('display_builder', 'profile'));
    $sources = json_encode($display->getThirdPartySetting('display_builder', 'sources'));
    $this->assertStringContainsString('"source_id":"field_formatter:node:article:field_image","source":{"type":"image"', $sources);
    $this->assertStringContainsString('"source_id":"field_formatter:node:article:title"', $sources);
    $this->assertStringContainsString('"source_id":"field_formatter:node:article:body","source":{"type":"text_summary_or_trimmed"', $sources);
    $this->assertStringNotContainsString('_NAME', $sources);
  }

  /**
   * Tests the templates are adapted to the fields of the content type.
   */
  public function testFieldsAdaptedToContentType(): void {
    $this->assertSame('field_description', $this->factory->getDescriptionFieldName('page'));

    $display = $this->saveDisplay('page', 'impressed_card_medium');
    $sources = json_encode($display->getThirdPartySetting('display_builder', 'sources'));
    $this->assertStringNotContainsString('field_image', $sources);
    $this->assertStringContainsString('"source_id":"field_formatter:node:page:field_description","source":{"type":"text_trimmed","label":"hidden","settings":{"trim_length":150}', $sources);
  }

  /**
   * Tests the templates of a theme and of a theme generated from it.
   */
  public function testThemeTemplates(): void {
    $this->config('system.theme')->set('default', 'webvmi_test_theme')->save();
    $template = $this->factory->findTemplate('card');
    $this->assertSame('webvmi_test_theme', $template['provider']);
    $this->assertNull($template['rename']);
    $this->assertSame('webvmi', $this->factory->findTemplate('hero_card')['provider']);

    $this->config('system.theme')->set('default', 'webvmi_test_generated')->save();
    $template = $this->factory->findTemplate('card');
    $this->assertSame('webvmi_test_theme', $template['provider']);
    $this->assertSame('webvmi_test_theme', $template['rename']);

    $this->assertTrue($this->factory->mapViewModeWithLayout('card', 'node', 'article'));
    $display = EntityViewDisplay::load('node.article.card');
    $this->assertTrue($display->status());
    $sources = $display->getThirdPartySetting('display_builder', 'sources');
    $this->assertSame('webvmi_test_generated:box', $sources[0]['source']['component']['component_id']);
    $this->assertCount(2, $sources[0]['source']['component']['slots']['content']['sources']);

    // Pages have no image: the image source is removed.
    $this->assertTrue($this->factory->mapViewModeWithLayout('card', 'node', 'page'));
    $sources = EntityViewDisplay::load('node.page.card')->getThirdPartySetting('display_builder', 'sources');
    $this->assertCount(1, $sources[0]['source']['component']['slots']['content']['sources']);

    $this->assertFalse($this->factory->mapViewModeWithLayout('teaser', 'node', 'article'));
  }

  /**
   * Tests the templates are resolved for any theme.
   */
  public function testAnyTheme(): void {
    // Themes without templates, and unknown themes: the module templates.
    $this->assertSame('webvmi', $this->factory->findTemplate('card', 'stark')['provider']);
    $this->assertSame('webvmi', $this->factory->findTemplate('card', 'olivero')['provider']);
    $this->assertSame('webvmi', $this->factory->findTemplate('card', 'unknown_theme')['provider']);

    // Any theme, not only the default theme.
    $this->assertSame('webvmi_test_theme', $this->factory->findTemplate('card', 'webvmi_test_theme')['provider']);

    // A sub-theme: its own templates, then the templates of its base theme,
    // then the module templates.
    $template = $this->factory->findTemplate('text_card_small', 'webvmi_test_subtheme');
    $this->assertSame('webvmi_test_subtheme', $template['provider']);
    $template = $this->factory->findTemplate('card', 'webvmi_test_subtheme');
    $this->assertSame('webvmi_test_theme', $template['provider']);
    $this->assertNull($template['rename']);
    $this->assertSame('webvmi', $this->factory->findTemplate('hero_card', 'webvmi_test_subtheme')['provider']);

    // DEFAULT_ACTIVE_THEME is the theme providing the template.
    $this->assertTrue($this->factory->mapViewModeWithLayout('text_card_small', 'node', 'article', 'webvmi_test_subtheme'));
    $sources = EntityViewDisplay::load('node.article.text_card_small')->getThirdPartySetting('display_builder', 'sources');
    $this->assertSame('webvmi_test_subtheme:box', $sources[0]['source']['component']['component_id']);

    // The theme argument wins over the default theme.
    $this->config('system.theme')->set('default', 'webvmi_test_generated')->save();
    $this->assertSame('webvmi', $this->factory->findTemplate('card', 'stark')['provider']);
    $this->assertTrue($this->factory->mapViewModeWithLayout('card', 'node', 'article', 'webvmi_test_theme'));
    $sources = EntityViewDisplay::load('node.article.card')->getThirdPartySetting('display_builder', 'sources');
    $this->assertSame('webvmi_test_theme:box', $sources[0]['source']['component']['component_id']);
  }

  /**
   * Tests the templates this module ships for a theme.
   */
  public function testModuleThemeTemplates(): void {
    $factory = new class(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('module_handler'),
      $this->container->get('extension.list.module'),
      $this->container->get('extension.list.theme'),
      $this->container->get('plugin.manager.display_buildable'),
    ) extends ViewModesInventoryFactory {

      /**
       * {@inheritdoc}
       */
      protected function getModuleTemplatesRoot(): string {
        return \dirname(__DIR__, 2) . '/fixtures/config_templates';
      }

    };

    // The theme folder first, then the module folder of the theme.
    $template = $factory->findTemplate('card', 'webvmi_test_theme');
    $this->assertSame('webvmi_test_theme', $template['provider']);
    $template = $factory->findTemplate('hero_card', 'webvmi_test_theme');
    $this->assertSame('webvmi/webvmi_test_theme', $template['provider']);
    $this->assertSame('webvmi_test_theme', $template['theme']);

    // Sub-themes and generated themes.
    $this->assertSame('webvmi/webvmi_test_theme', $factory->findTemplate('hero_card', 'webvmi_test_subtheme')['provider']);
    $template = $factory->findTemplate('hero_card', 'webvmi_test_generated');
    $this->assertSame('webvmi/webvmi_test_theme', $template['provider']);
    $this->assertSame('webvmi_test_theme', $template['rename']);

    $this->assertTrue($factory->mapViewModeWithLayout('hero_card', 'node', 'article', 'webvmi_test_generated'));
    $sources = EntityViewDisplay::load('node.article.hero_card')->getThirdPartySetting('display_builder', 'sources');
    $this->assertSame('webvmi_test_generated:hero', $sources[0]['source']['component']['component_id']);

    // Other themes have no module folder.
    $this->assertNull($factory->findTemplate('hero_card', 'stark'));
  }

  /**
   * Tests imported, disabled and already built displays are kept.
   */
  public function testDisplaysKept(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_view_display');

    // Installed configuration, with the hash added by the config installer.
    $values = ['targetEntityType' => 'node', 'bundle' => 'article', 'status' => TRUE];
    $display = $storage->create(['mode' => 'hero_card'] + $values);
    $display->set('_core', ['default_config_hash' => 'test'])->save();
    $this->assertNull($storage->loadUnchanged('node.article.hero_card')->getThirdPartySetting('display_builder', 'profile'));

    // Synchronized configuration.
    $display = $storage->create(['mode' => 'impressed_card_large'] + $values);
    $display->setSyncing(TRUE)->save();
    $this->assertNull($storage->loadUnchanged('node.article.impressed_card_large')->getThirdPartySetting('display_builder', 'profile'));

    // Disabled, then enabled.
    $display = $this->saveDisplay('article', 'text_card_small', FALSE);
    $this->assertNull($display->getThirdPartySetting('display_builder', 'profile'));
    $display->setStatus(TRUE)->save();
    $this->assertSame('default', $storage->loadUnchanged('node.article.text_card_small')->getThirdPartySetting('display_builder', 'profile'));

    // Already built with Display Builder.
    $display = $storage->create(['mode' => 'overlay_card_large'] + $values);
    $display->setThirdPartySetting('display_builder', 'profile', 'default');
    $display->setThirdPartySetting('display_builder', 'sources', []);
    $display->save();
    $this->assertSame([], $storage->loadUnchanged('node.article.overlay_card_large')->getThirdPartySetting('display_builder', 'sources'));

    // Not a view mode of the inventory.
    $display = $this->saveDisplay('article', 'rss');
    $this->assertNull($display->getThirdPartySetting('display_builder', 'profile'));
  }

  /**
   * Adds a field to a content type.
   */
  private function addField(string $bundle, string $field_name, string $type): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create(['field_name' => $field_name, 'entity_type' => 'node', 'type' => $type])->save();
    }
    FieldConfig::create(['field_name' => $field_name, 'entity_type' => 'node', 'bundle' => $bundle])->save();
  }

  /**
   * Saves a display from the UI or the API, and loads it back.
   */
  private function saveDisplay(string $bundle, string $mode, bool $status = TRUE): EntityViewDisplayInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_view_display');
    $storage->create(['targetEntityType' => 'node', 'bundle' => $bundle, 'mode' => $mode, 'status' => $status])->save();
    return $storage->loadUnchanged("node.$bundle.$mode");
  }

}
