<?php

declare(strict_types=1);

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function sage_theme_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {
  $form['sage_theme_options'] = [
    '#type'  => 'details',
    '#title' => t('SAGE Theme Options'),
    '#open'  => TRUE,
  ];

  $form['sage_theme_options']['dark_mode'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Enable dark mode'),
    '#default_value' => theme_get_setting('dark_mode'),
    '#description'   => t('Apply the dark color scheme site-wide. Individual users can also toggle this via the theme switcher button (data-sage-dark-toggle).'),
  ];
}
