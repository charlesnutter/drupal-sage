<?php

namespace Drupal\sage_content_discovery\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Full-page SAGE Content Discovery chat interface.
 */
class SageDiscoveryForm extends FormBase {

  public function getFormId(): string {
    return 'sage_discovery_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $chat_url = Url::fromRoute('sage_content_discovery.chat', [], ['absolute' => TRUE])->toString();

    $form['#attached']['library'][] = 'sage_content_discovery/discovery';
    $form['#attached']['drupalSettings']['sage']['chatUrl'] = $chat_url;
    $form['#attached']['drupalSettings']['sage']['siteUrl'] = \Drupal::request()->getSchemeAndHttpHost();
    $form['#attributes']['class'][] = 'sage-discovery-form';
    $form['#attributes']['id'] = 'sage-discovery-form';

    // Wrap the entire form in the pinned input area container.
    $form['#prefix'] = '<div class="sage-input-area"><div class="sage-input-area__inner">';
    $form['#suffix'] = '</div></div>';

    // ── Filters row ──────────────────────────────────────────────────────── //

    $form['filters'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['sage-filters']],
    ];

    $form['filters']['filter_label'] = [
      '#markup' => '<span class="sage-filter-label">' . $this->t('Filter:') . '</span>',
    ];

    $form['filters']['age_range'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Age Range'),
      '#title_display' => 'invisible',
      '#options'       => [
        'All'   => $this->t('All Ages'),
        '5-8'   => $this->t('Ages 5–8'),
        '9-12'  => $this->t('Ages 9–12'),
        '13-15' => $this->t('Ages 13–15'),
        '16-18' => $this->t('Ages 16–18'),
      ],
      '#default_value' => 'All',
      '#attributes'    => [
        'class' => ['sage-filter-select'],
        'id'    => 'sage-age-range',
      ],
    ];

    // Multi-select dropdown for entity types.
    $options = [
      'PERSON' => $this->t('People'),
      'ORG'    => $this->t('Organizations'),
      'GPE'    => $this->t('Places'),
      'EVENT'  => $this->t('Events'),
      'NORP'   => $this->t('Movements'),
    ];
    $chevron = '<svg class="sage-multiselect__arrow" width="10" height="6" viewBox="0 0 10 6" fill="none" aria-hidden="true">'
      . '<path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
      . '</svg>';
    $rows = '';
    foreach ($options as $value => $label) {
      $rows .= '<label class="sage-multiselect__option">'
        . '<input type="checkbox" class="sage-multiselect__check" value="' . $value . '">'
        . '<span>' . $label . '</span>'
        . '</label>';
    }
    $form['filters']['entity_multiselect'] = [
      '#markup' => Markup::create(
        '<div class="sage-multiselect" id="sage-entity-picker">'
        . '<button type="button" class="sage-filter-select sage-multiselect__toggle" id="sage-entity-toggle" aria-haspopup="listbox" aria-expanded="false">'
        . '<span id="sage-entity-label">' . $this->t('All types') . '</span>'
        . $chevron
        . '</button>'
        . '<div class="sage-multiselect__dropdown" id="sage-entity-dropdown" hidden>'
        . $rows
        . '</div>'
        . '</div>'
      ),
    ];

    // ── Input wrap: textarea + send button ───────────────────────────────── //

    $form['input_wrap'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['sage-input-wrap']],
    ];

    $form['input_wrap']['query'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Your question'),
      '#title_display' => 'invisible',
      '#rows'          => 1,
      '#attributes'    => [
        'id'          => 'sage-textarea',
        'class'       => ['sage-textarea'],
        'placeholder' => $this->t('Ask anything about the collection…'),
      ],
    ];

    $form['input_wrap']['submit'] = [
      '#type'       => 'submit',
      '#value'      => '→',
      '#attributes' => [
        'id'         => 'sage-submit-btn',
        'class'      => ['sage-submit-btn'],
        'aria-label' => $this->t('Send'),
      ],
    ];

    $form['hint'] = [
      '#markup' => '<p class="sage-input-hint">'
        . $this->t('Try: <em>Who led the Montgomery Bus Boycott?</em>')
        . '</p>',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Handled entirely by JS — the PHP submit path is never reached.
  }

}
