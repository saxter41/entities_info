<?php

namespace Drupal\entities_info;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\field\Entity\FieldConfig;

/**
 * Class EntitiesInfoManager.
 */
class EntitiesInfoManager implements EntitiesInfoManagerInterface {

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\Entity\EntityFieldManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Drupal\Core\TempStore\PrivateTempStoreFactory definition.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempstorePrivate;

  /**
   * Constructs a new Entity info manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Entity field manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempstore_private
   *   Private tempstore factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, PrivateTempStoreFactory $tempstore_private) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->tempstorePrivate = $tempstore_private;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntitiesInfoTempstore(): \Drupal\Core\TempStore\PrivateTempStore {
    return $this->tempstorePrivate->get('entities_info_export');
  }

  /**
   * {@inheritdoc}
   */
  public function getValues($entitiesInfoTempstore) {
    return $entitiesInfoTempstore->get('values');
  }

  /**
   * {@inheritdoc}
   */
  public function getEntitiesFields(array $entitiesInfoValues): array {
    return array_map(function ($item) {

      [$bundle, $entity_id] = explode('-', $item);
      $entity_id_of = $this->entityTypeManager->getDefinition($entity_id)->getBundleOf();
      $entity_id = $entity_id_of ?: $entity_id;

      $count = $this->getCountBundle($entity_id, $bundle);

      $fields = $this->entityFieldManager->getFieldDefinitions($entity_id, $bundle);
      $fields = array_filter($fields, fn($field) => $field instanceof FieldConfig);
      $fields['count'] = $count;

      if (!$fields) {
        return FALSE;
      }
      return $this->getFieldValues($fields);
    }, $entitiesInfoValues);
  }

  /**
   * Get field name, label, type, required and description.
   *
   * @param $fields
   *   Fields.
   *
   * @return array
   *    Array with field information.
   */
  protected function getFieldValues($fields): array {
    return array_map(function ($field) {
      if (!($field instanceof FieldConfig)) {
        return $field;
      }
      return [
        'field_name' => $field->getName(),
        'label' => $field->getLabel(),
        'field_type' => $field->getType(),
        'required' => $field->isRequired() == 1 ? t("Yes") : t("No"),
        'description' => $field->getDescription(),
      ];
    }, $fields);
  }

  /**
   * {@inheritdoc}
   */
  public function createTables(array $entities): array {
    return array_map(function ($index, $entity) {

      [$bundle, $entity_id] = explode('-', $index);
      $label = $this->entityTypeManager->getStorage($entity_id)->load($bundle)->label();

      if (!$entity) {
        return [
          '#name' => $label,
          '#markup' => '<p>' . t('There is not fields created.') . '</p>',
        ];
      }

      $count = t('Count') . ' ' . t('items') . ': ' . $entity['count'];
      unset($entity['count']);

      $rows = array_map(function ($field) {
        return [
          $field['field_name'],
          $field['label'],
          $field['field_type'],
          $field['required'],
          $field['description'],
        ];
      }, (array) $entity);

      return [
        '#type' => 'table',
        '#header' => $this->getTableHeaders(),
        '#rows' => $rows,
        '#name' => $label,
        '#count' => $count,
      ];
    }, array_keys($entities), $entities);
  }

  /**
   * {@inheritdoc}
   */
  public function getCountBundle($entity, $bundle) {
    return $this->entityTypeManager->getStorage($entity)->getQuery()
      ->condition('type', $bundle)
      ->count()
      ->execute();
  }

  /**
   * Table headers.
   *
   * @return array
   *   Field information labels.
   */
  protected function getTableHeaders(): array {
    return [
      'field_name' => t('Field name'),
      'label' => t('Label'),
      'field_type' => t('Field type'),
      'required' => t('Required'),
      'description' => t('Description'),
    ];
  }

}
