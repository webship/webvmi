# Web View Modes Inventory

A set of card view modes that we typically use (some of them) in each website, laid out with
[Display Builder](https://www.drupal.org/project/display_builder) and the components of the default theme. It works
with any theme: a theme ships its own layouts, sub-themes and generated themes use the layouts of their base or
starterkit theme, and every other theme gets the fields laid out without components.

The [View Modes Inventory](https://www.drupal.org/project/vmi) way, with Display Builder instead of Canvas.
Part of Webship: the [UI Suite UIkit](https://www.drupal.org/project/ui_suite_uikit) theme (UIkit and HTMX)
on top of Drupal and Display Builder.

## View modes

The layouts depend on the theme. With [UI Suite UIkit](https://www.drupal.org/project/ui_suite_uikit) and the
themes generated from it:

| View modes | Layout |
|---|---|
| Card | Card: image on top, title and trimmed body |
| Impressed card: xlarge, large, medium, small, xsmall | Card: image on top, title, trimmed body, date |
| Featured card: xlarge, large, medium, small, xsmall | Card: image on the left, title, trimmed body, date |
| Text card: large, medium, small | Card with a hover effect: title, trimmed body, date |
| Overlay card: xlarge, large, medium | Overlay: dark overlay with the title on the image |
| Hero card | Cover: large image with the title and the trimmed body |
| Full (core view mode) | Article: date, image and body |

The sizes change the card padding, the heading level, the image style and the trim length.

## Requirements

- Drupal 11.4 or 12
- [Display Builder](https://www.drupal.org/project/display_builder): *Display Builder for entity view*
- [UI Patterns 2](https://www.drupal.org/project/ui_patterns)

## Usage

1. Install the module: it adds the view modes for content.
2. Enable a view mode for a content type: *Structure > Content types > Manage display > Custom display settings*,
   or the content types of the view mode in *Structure > Display modes > View modes*.
3. The display is laid out with Display Builder from the template of the view mode. Change it in the builder like any
   other display.

Or with Drush:

```shell
# Enable and lay out all the view modes for articles.
drush webvmi:apply article

# Only some view modes, with the templates of a theme.
drush webvmi:apply article impressed_card_medium,hero_card --theme=ui_suite_uikit

# The view modes and the template used for each one.
drush webvmi:list
```

A template is only applied when a view mode is enabled: displays already built with Display Builder and imported
configuration (configuration synchronization, recipes, modules) are kept. `drush webvmi:apply` always applies the
templates.

The media field (`field_featured_image`, `field_main_image`, `field_image`, `field_media`…) and the description field
(`body`, `field_body`, `field_content`, `field_description`, `field_summary`) of the content type are detected. The
fields the content type does not have are removed from the layout; media reference fields render the media entity.

## Templates

A template is a `core.entity_view_display` config file with Display Builder sources, for a content type named
`CONTENT_TYPE_NAME`: `core.entity_view_display.node.CONTENT_TYPE_NAME.<view mode>.yml`. The placeholders
`CONTENT_TYPE_NAME`, `MEDIA_FIELD_NAME`, `DESCRIPTION_FIELD_NAME` and `DEFAULT_ACTIVE_THEME` are replaced when the
template is applied.

UIkit Admin, the administration theme, has a card but no overlay, cover or article component: its layouts use the
card for every view mode, so content shown in the back office (dashboards, previews) keeps the UIkit look. Apply
them with `drush webvmi:apply article --theme=uikit_admin`.

The template of a view mode is taken from the first of these folders:

1. For the default theme (or the `--theme` of the Drush commands), then each of its base themes:
   `webvmi/CONTENT_TYPE_NAME` in the theme, then `src/assets/config_templates/<theme>/CONTENT_TYPE_NAME` in this
   module. UI Suite UIkit ships its layouts in the theme; this module ships layouts for the themes it knows:
   UI Suite Bootstrap, UI Suite DaisyUI and UIkit Admin.
2. The same folders for the theme it was generated from (the `generator` of its info file), with the component IDs
   renamed: a theme generated from UI Suite UIkit uses the UIkit layouts with its own components.
3. `src/assets/config_templates/CONTENT_TYPE_NAME` in this module: the fields without components, for any theme.

To lay the view modes out with the components of your theme, copy the templates of this module or of UI Suite UIkit
to the `webvmi/CONTENT_TYPE_NAME` folder of your theme and change the sources. Modules can change the folders with
`hook_webvmi_template_directories_alter()` (see `webvmi.api.php`).

The templates can also be copied to the `config/install` folder of a module or a recipe, with `CONTENT_TYPE_NAME` and
the field placeholders replaced.

## Maintainers

- Rajab Natshah: [RajabNatshah](https://www.drupal.org/u/rajabnatshah)
