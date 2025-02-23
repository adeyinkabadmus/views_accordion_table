<?php

namespace Drupal\views_accordion_table\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\style\Table;

/**
 * Style plugin to render grouped tables as accordions.
 *
 * @ViewsStyle(
 *   id = "accordion_table",
 *   title = @Translation("Accordion Table"),
 *   help = @Translation("Displays the content in a table with grouping headers as accordions."),
 *   theme = "views_view_accordion_table",
 *   display_types = {"normal"},
 *   uses_fields = TRUE
 * )
 */
class AccordionTable extends Table {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['accordion_initially_open'] = ['default' => TRUE];
    $options['group_date_format'] = ['default' => 'none'];
    $options['group_date_custom_format'] = ['default' => 'F Y'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['accordion_initially_open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Initially open'),
      '#description' => $this->t('Whether accordion sections should be initially open.'),
      '#default_value' => $this->options['accordion_initially_open'],
    ];

    // Only show date format options if grouping by a date field
    $grouping_field = '';
    if (!empty($this->options['grouping'][0]['field'])) {
      $grouping_field = $this->options['grouping'][0]['field'];
      // \Drupal::logger('views_accordion_table')->debug('Grouping field: @field', ['@field' => $grouping_field]);
      
      $handler = $this->displayHandler->getHandler('field', $grouping_field);
      // if ($handler) {
      //   \Drupal::logger('views_accordion_table')->debug('Field handler plugin ID: @plugin', ['@plugin' => $handler->getPluginId()]);
      // }
      
      // Check if the grouping field is a date field
      $is_date_field = FALSE;
      
      if ($handler) {
        $plugin_id = $handler->getPluginId();
        $field_id = $handler->field;
        
        // \Drupal::logger('views_accordion_table')->debug('Field info: @info', [
        //   '@info' => print_r([
        //     'plugin_id' => $plugin_id,
        //     'field_id' => $field_id,
        //   ], TRUE),
        // ]);
        
        // Check various ways a field might be a date
        $is_date_field = (
          // Plugin ID checks
          $plugin_id === 'datetime' || 
          $plugin_id === 'date' ||
          strpos($plugin_id, 'date') !== FALSE ||
          
          // Field name checks
          strpos($field_id, 'date') !== FALSE ||
          strpos($field_id, 'created') !== FALSE ||
          strpos($field_id, 'changed') !== FALSE ||
          strpos($field_id, 'timestamp') !== FALSE ||
          
          // For entity fields, check if it's a datetime field
          ($handler instanceof \Drupal\views\Plugin\views\field\EntityField && 
           $handler->getFieldStorageDefinition() && 
           in_array($handler->getFieldStorageDefinition()->getType(), ['datetime', 'date', 'timestamp']))
        );
      }
      
      // \Drupal::logger('views_accordion_table')->debug('Is date field: @is_date', ['@is_date' => $is_date_field ? 'yes' : 'no']);

      if ($is_date_field) {
        $form['group_date_format'] = [
          '#type' => 'select',
          '#title' => $this->t('Group header date format'),
          '#description' => $this->t('Select how to format date values in group headers.'),
          '#options' => [
            'none' => $this->t('No formatting (use raw value)'),
            'month_year' => $this->t('Month Year (January 2025)'),
            'month' => $this->t('Month only (January)'),
            'year' => $this->t('Year only (2025)'),
            'custom' => $this->t('Custom format'),
          ],
          '#default_value' => $this->options['group_date_format'] ?? 'none',
        ];

        $form['group_date_custom_format'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Custom date format'),
          '#description' => $this->t('Enter a custom date format using PHP date format characters (e.g., F Y for "January 2025").'),
          '#default_value' => $this->options['group_date_custom_format'] ?? 'F Y',
          '#states' => [
            'visible' => [
              ':input[name="style_options[group_date_format]"]' => ['value' => 'custom'],
            ],
          ],
        ];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    if (empty($this->view->result)) {
      return [];
    }

    $rows = [];
    $groups = [];
    
    // Get field handlers
    $handlers = $this->displayHandler->getHandlers('field');
    
    if (empty($handlers)) {
      // \Drupal::logger('views_accordion_table')->error('No fields configured in view.');
      return [
        '#markup' => $this->t('Please add some fields to your view.'),
      ];
    }

    // Get the grouping field if set
    $grouping_field = '';
    if (!empty($this->options['grouping'][0]['field'])) {
      $grouping_field = $this->options['grouping'][0]['field'];
    }

    // Filter out excluded fields
    $visible_handlers = [];
    foreach ($handlers as $field => $handler) {
      if (empty($handler->options['exclude'])) {
        $visible_handlers[$field] = $handler;
      }
    }

    foreach ($this->view->result as $row_index => $row) {
      $this->view->row_index = $row_index;
      
      // Build row data from fields
      $row_output = [];
      foreach ($visible_handlers as $field => $handler) {
        $row_output[$field] = $this->getField($row_index, $field);
      }

      // Get the group value for this row
      if ($grouping_field) {
        $group_content = $this->getField($row_index, $grouping_field);
        $group_value = trim(strip_tags($group_content));
        
        // Format date if enabled and value matches YYYY-MM format
        if ($this->options['group_date_format'] !== 'none' && preg_match('/^\d{4}-\d{2}/', $group_value)) {
          try {
            $date = \DateTime::createFromFormat('Y-m', $group_value);
            if ($date) {
              switch ($this->options['group_date_format']) {
                case 'month_year':
                  $group_value = $date->format('F Y');
                  break;
                case 'month':
                  $group_value = $date->format('F');
                  break;
                case 'year':
                  $group_value = $date->format('Y');
                  break;
                case 'custom':
                  $group_value = $date->format($this->options['group_date_custom_format']);
                  break;
              }
            }
          }
          catch (\Exception $e) {
            \Drupal::logger('views_accordion_table')->warning('Failed to parse date: @date', [
              '@date' => $group_value,
            ]);
          }
        }
        
        if (!isset($groups[$group_value])) {
          $groups[$group_value] = [];
        }
        $groups[$group_value][] = $row_output;
      } else {
        $rows[] = $row_output;
      }
    }

    unset($this->view->row_index);

    // Generate header from field labels
    $header = [];
    foreach ($visible_handlers as $field => $handler) {
      $header[$field] = [
        'content' => $handler->label(),
        'attributes' => new \Drupal\Core\Template\Attribute(),
      ];
    }

    return [
      '#theme' => $this->themeFunctions(),
      '#view' => $this->view,
      '#options' => $this->options,
      '#rows' => empty($grouping_field) ? $rows : [],
      '#groups' => !empty($grouping_field) ? $groups : [],
      '#header' => $header,
      '#attributes' => ['class' => ['views-accordion-table']],
      '#attached' => [
        'library' => ['views_accordion_table/accordion_table'],
      ],
    ];
  }
}
