<?php

declare(strict_types=1);

namespace Drupal\webvmi\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\webvmi\ViewModesInventoryFactory;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands of the Web View Modes Inventory module.
 */
final class WebvmiCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected ViewModesInventoryFactory $factory,
  ) {
    parent::__construct();
  }

  /**
   * Lays out view modes of a content type with their templates.
   */
  #[CLI\Command(name: 'webvmi:apply', aliases: ['webvmi-apply'])]
  #[CLI\Argument(name: 'bundle', description: 'The content type machine name.')]
  #[CLI\Argument(name: 'view_modes', description: 'A comma-separated list of view modes. All the view modes of the inventory by default.')]
  #[CLI\Option(name: 'theme', description: 'Use the templates of this theme instead of the default theme.')]
  #[CLI\Usage(name: 'drush webvmi:apply article', description: 'Enable and lay out all the view modes of the inventory for articles.')]
  #[CLI\Usage(name: 'drush webvmi:apply article impressed_card_medium,hero_card', description: 'Enable and lay out two view modes for articles.')]
  public function apply(string $bundle, string $view_modes = '', array $options = ['theme' => self::REQ]): void {
    $modes = $view_modes === ''
      ? array_keys($this->factory->getViewModesList())
      : array_map('trim', explode(',', $view_modes));

    foreach ($modes as $mode) {
      if (!$this->factory->isInventoryViewMode($mode)) {
        $this->logger()->warning(dt('@mode is not a view mode of the inventory.', ['@mode' => $mode]));
        continue;
      }
      if ($this->factory->mapViewModeWithLayout($mode, 'node', $bundle, $options['theme'] ?: NULL)) {
        $this->logger()->success(dt('Laid out the @mode view mode of @bundle.', [
          '@mode' => $mode,
          '@bundle' => $bundle,
        ]));
      }
      else {
        $this->logger()->warning(dt('No template found for the @mode view mode.', ['@mode' => $mode]));
      }
    }
  }

  /**
   * Lists the view modes of the inventory and the template of each one.
   */
  #[CLI\Command(name: 'webvmi:list', aliases: ['webvmi-list'])]
  #[CLI\Option(name: 'theme', description: 'Resolve the templates of this theme instead of the default theme.')]
  #[CLI\FieldLabels(labels: [
    'view_mode' => 'View mode',
    'label' => 'Label',
    'provider' => 'Provider',
    'template' => 'Template',
  ])]
  #[CLI\DefaultTableFields(fields: ['view_mode', 'label', 'provider'])]
  public function list(array $options = ['theme' => self::REQ, 'format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->factory->getViewModesList() as $mode => $info) {
      $template = $this->factory->findTemplate($mode, $options['theme'] ?: NULL);
      $rows[] = [
        'view_mode' => $mode,
        'label' => $info['label'] ?? $mode,
        'provider' => $template['provider'] ?? '',
        'template' => $template['file'] ?? '',
      ];
    }
    return new RowsOfFields($rows);
  }

}
