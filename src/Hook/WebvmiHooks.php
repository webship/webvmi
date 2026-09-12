<?php

declare(strict_types=1);

namespace Drupal\webvmi\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webvmi\ViewModesInventoryFactory;

/**
 * Hook implementations for the Web View Modes Inventory module.
 */
class WebvmiHooks {

  use StringTranslationTrait;

  public function __construct(
    protected ViewModesInventoryFactory $factory,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    if ($route_name !== 'help.page.webvmi') {
      return '';
    }
    $output = '<h3>' . $this->t('About') . '</h3>';
    $output .= '<p>' . $this->t('The Web View Modes Inventory module has a set of card view modes that we typically use (some of them) in each website.') . '</p>';
    $output .= '<p>' . $this->t('When one of these view modes is enabled for a content type, its display is laid out with Display Builder from a template: the layouts of the theme (or of its base or starterkit theme) with its components, or the fields without components for any other theme.') . '</p>';
    return $output;
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for 'entity_view_display'.
   *
   * Lays out a view mode of the inventory with its Display Builder template
   * when it is enabled for a content type: from Manage display, the view mode
   * form or the API. Imported configuration (config synchronization, recipes
   * and modules) and displays already built with Display Builder are kept.
   */
  #[Hook('entity_view_display_presave')]
  public function entityViewDisplayPresave(EntityViewDisplayInterface $display): void {
    // The config installer adds a default config hash to the configuration of
    // modules, themes, profiles and recipes.
    $installed = !empty($display->get('_core')['default_config_hash']);
    if ($display->isSyncing()
      || $installed
      || !$display->status()
      || $display->getTargetEntityTypeId() !== 'node'
      || !$this->factory->isInventoryViewMode($display->getMode())
      || $display->getThirdPartySetting('display_builder', 'profile')) {
      return;
    }

    if (!$display->isNew()) {
      $original = $display->getOriginal();
      if ($original instanceof EntityViewDisplayInterface && $original->status()) {
        return;
      }
    }

    if ($this->factory->applyTemplate($display)) {
      $display->calculateDependencies();
    }
  }

}
