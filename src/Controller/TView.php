<?php

namespace Drupal\tview\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Controller for tview.page route.
 *
 * Based on pager example.
 */
class TView extends ControllerBase {

  /**
   * Entity storage for node entities.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Stuff here.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * PagerExamplePage constructor.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $node_storage
   *   Entity storage for node entities.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   */
  public function __construct(EntityStorageInterface $node_storage, AccountInterface $current_user, LanguageManagerInterface $language_manager) {
    $this->nodeStorage = $node_storage;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $controller = new static(
      $container->get('entity_type.manager')->getStorage('node'),
      $container->get('current_user'),
      $container->get('language_manager')
    );
    $controller->setStringTranslation($container->get('string_translation'));
    return $controller;
  }

  /**
   * Content callback for the tview.page route.
   */
  public function getContent() {
    // First we'll tell the user what's going on. This content can be found
    // in the twig template file: templates/description.html.twig.
    // @todo: Set up links to create nodes and point to devel module.
    $build = [
      'description' => [
        '#theme' => 'tview_description',
        '#description' => 'foo',
        '#attributes' => [],
      ],
    ];

    // We need to count the number of nodes so that we can tell the user to add
    // some if there aren't any.
    $query = $this->nodeStorage->getQuery()
      ->addTag('node_access')
      ->count();

    // The node_access tag does not trigger a check on whether a user has the
    // ability to view unpublished content. The 'bypass node access' permission
    // is really more than we need. But, there is no separate permission for
    // viewing unpublished content. There is a permission to 'view own
    // unpublished content', but we don't have a good way of using that in this
    // query. So, unfortunately this query will incorrectly eliminate even those
    // unpublished nodes that the user may, in fact, be allowed to view.

    $count_nodes = $query->execute();

    if ($count_nodes == 0) {
      if ($this->currentUser->hasPermission('create page content')) {
        $build['no-nodes'] = [
          '#markup' => $this->t('There are no nodes to display.
            Please <a href=":url">create a node</a>.',
            [
              ':url' => Url::fromRoute('node.add', ['node_type' => 'page'])->toString(),
            ]
          ),
        ];
      }
      else {
        $build['no-nodes'] = [
          '#markup' => $this->t('There are no nodes to display.'),
        ];
      }

      // Ensure that Drupal clears the cache when nodes have been published,
      // unpublished, added or deleted; and when user permissions change.
      $build['#cache']['tags'][] = 'node_list';
      $build['#cache']['contexts'][] = 'user.permissions';
      return $build;
    }

    // Now we want to get our tabular data. We select nodes from node storage
    // limited by 20 per page and sort by nid DESC because we want to show newest
    // node first. Additionally, we check that the user has permission to
    // view the node.
    $query = $this->nodeStorage->getQuery()
      ->sort('nid', 'DESC')
      ->addTag('node_access')
      ->pager(20);

    // The node_access tag does not trigger a check on whether a user has the
    // ability to view unpublished content. The 'bypass node access' permission
    // is really more than we need. But, there is no separate permission for
    // viewing unpublished content. There is a permission to 'view own
    // unpublished content', but we don't have a good way of using that in this
    // query. So, unfortunately this query will incorrectly eliminate even those
    // unpublished nodes that the user may, in fact, be allowed to view.
    if (!$this->currentUser->hasPermission('bypass node access')) {
      $query->condition('status', 1);
    }
    $entity_ids = $query->execute();
    $nodes = $this->nodeStorage->loadMultiple($entity_ids);

    // We are going to output the results in a table so we set up the rows.
    $rows = [];
    foreach ($nodes as $node) {
      // There are certain things (besides unpublished nodes) that the
      // node_access tag won't prevent from being seen. The only way to get at
      // those is by explicitly checking for (view) access on a node-by-node
      // basis. In order to prevent the pager from looking strange, we will
      // "mask" these nodes that should not be accessible. If we don't do this
      // masking, it's possible that we'd have lots of pages that don't show any
      // content.

      $rows[] = [
        'nid' => $node->access('view') ? $node->id() : t('XXXXXX'),
        'title' => $node->access('view') ? $node->getTitle() : t('Redacted'),
      ];
      $current_row = count($rows)-1;
      $translations = array_keys($node->getTranslationLanguages());
      $languages = $this->languageManager->getLanguages();
      foreach (array_keys($languages) as $language) {
        if (!in_array($language, $translations)) {
          $icon = $this->buildTranslationIcon('incomplete');
        }
        elseif($node->langcode->value == $language) {
          $icon = $this->buildTranslationIcon('original');
        }
        else {
          $icon = $this->buildTranslationIcon('complete');
        }
        $rows[$current_row][$language] = $icon;
      }
      $rows[$current_row]['edit'] = $this->buildEditUrl($node);
    }

    // Build a render array which will be themed as a table with a pager.
    $build['tview'] = [
      '#rows' => $rows,
      '#header' => [t('NID'), t('Title')],
      #'#header' => [t('Title')],
      '#type' => 'table',
      '#empty' => t('No content available.'),
    ];
    $build['tview']['#attached']['library'][] = 'tview/tview';

    $build['tview']['#header'] = array_merge($build['tview']['#header'], array_keys($languages));
    $build['tview']['#header'] = array_merge($build['tview']['#header'], [t('Edit')]);

    $build['pager'] = [
      '#type' => 'pager',
      '#weight' => 10,
    ];

    // Ensure that Drupal clears the cache when nodes have been published,
    // unpublished, added or deleted; and when user permissions change.
    $build['#cache']['tags'][] = 'node_list';
    $build['#cache']['contexts'][] = 'user.permissions';

    return $build;
  }

  public function buildTranslationIcon($type) {
    switch ($type) {
      case 'incomplete':
        $type = 'grey';
        $label = t('Not translated');
        break;
      case 'complete':
        $type = 'green';
        $label = t('Complete');
        break;
      default:
        $type = 'white';
        $label = t('Source language');
    }
    // @// TODO: convert to theme function.
    $element['#markup'] = '<div class="tmgmt-ui-icon tmgmt-ui-icon-10 tmgmt-ui-icon-' . $type . '" title="' . $label . '"><span></span></div>';
    return \Drupal::service('renderer')->render($element);
  }

  public function buildEditUrl($node) {
    $base_url = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    $url = Url::fromUri($base_url . 'node/' . $node->id() . '/edit');
    $element['#markup'] = \Drupal::l(t('Edit'), $url);

    return \Drupal::service('renderer')->render($element);
  }
}
