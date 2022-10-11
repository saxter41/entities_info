<?php

namespace Drupal\entities_info;

use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\field\Entity\FieldConfig;

/**
 * Manages entities info to export.
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
  public function getEntitiesInfoTempstore(): PrivateTempStore {
    return $this->tempstorePrivate->get('entities_info_export');
  }

  /**
   * {@inheritdoc}
   */
  public function getValues(PrivateTempStore $entitiesInfoTempstore): mixed {
    return $entitiesInfoTempstore->get('values');
  }

  /**
   * {@inheritdoc}
   */
  public function getEntitiesFields(array $entitiesInfoValues): array {
    return array_map(function ($item) {

      [$bundle, $entity_id] = explode('-ei-', $item);
      $entity_id_of = $this->entityTypeManager->getDefinition($entity_id)->getBundleOf();
      $entity_id = $entity_id_of ?: $entity_id;

      $fields = $this->entityFieldManager->getFieldDefinitions($entity_id, $bundle);
      $fields = array_filter($fields, fn($field) => $field instanceof FieldConfig);
      $fields['count'] = $this->getCountBundle($entity_id, $bundle);

      if (!$fields) {
        return FALSE;
      }
      return $this->getFieldInfo($fields);
    }, $entitiesInfoValues);
  }

  /**
   * Get field name, label, type, required and description.
   *
   * @param array $fields
   *   Fields.
   *
   * @return array
   *   Array with field information.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getFieldInfo(array $fields): array {
    return array_map(function ($field) {
      if (!($field instanceof FieldConfig)) {
        return $field;
      }

      $fieldType = $this->getFieldType($field);
      $count = $this->getCountField($field);

      return [
        'field_name' => $field->getName(),
        'label' => $field->getLabel(),
        'field_type' => $fieldType,
        'required' => $field->isRequired() == 1 ? t("Yes") : t("No"),
        'description' => $field->getDescription(),
        'count_used' => $count,
      ];
    }, $fields);
  }

  /**
   * {@inheritdoc}
   */
  public function createTables(array $entities): array {
    return array_map(function ($index, array $entity) {
      // @todo Create service with tables creation and remove -ei- from entities info manager and pass two parameters
      [$bundle, $entity_id] = explode('-ei-', $index);
      $label = $this->entityTypeManager->getStorage($entity_id)->load($bundle)->label();
      $count = t('Count items:') . $entity['count'];

      if (count($entity) === 1 && array_key_exists('count', $entity)) {
        return [
          '#name' => $label,
          '#count' => $count,
          '#markup' => '<p>' . t('There is not fields created.') . '</p>',
        ];
      }

      unset($entity['count']);
      $rows = $this->getTableRows($entity);

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
  public function getCountBundle(string $entity, string $bundle): array|int {
    $entity_keys = $this->entityTypeManager->getStorage($entity)->getEntityType()->get('entity_keys');
    return $this->entityTypeManager->getStorage($entity)->getQuery()
      ->condition($entity_keys['bundle'], $bundle)
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
      'count_used' => t('Count field use'),
    ];
  }

  /**
   * Get the field type.
   *
   * If the type is entity reference, add target type and target bundle.
   *
   * @param \Drupal\field\Entity\FieldConfig $field
   *   Field config.
   *
   * @return string
   *   Field type.
   */
  protected function getFieldType(FieldConfig $field): string {
    $fieldType = $field->getType();
    if ($fieldType != 'entity_reference') {
      return $fieldType;
    }

    $settings = $field->getSettings();
    $target_bundle = $settings['handler_settings']['target_bundles'];

    if ($target_bundle == NULL) {
      return $fieldType;
    }

    $target_bundle = array_values($target_bundle);
    return $fieldType . ':' . $settings['target_type'] . ':' . $target_bundle[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getCountField(FieldConfig $field): string {
    if ($field->getType() == 'field_menu') {
      return '';
    }
    $entity = $field->getTargetEntityTypeId();
    $bundle = $field->getTargetBundle();
    $name = $field->getName();
    $entity_keys = $this->entityTypeManager->getStorage($entity)->getEntityType()->get('entity_keys');

    return $this->entityTypeManager->getStorage($entity)->getQuery()
      ->condition($entity_keys['bundle'], $bundle)
      ->condition($name, NULL, 'IS NOT NULL')
      ->count()
      ->execute();
  }

  /**
   * Return table rows with field info.
   *
   * @param array $entity
   *   Entity with field configs.
   *
   * @return array
   *   Array with field info.
   */
  protected function getTableRows(array $entity): array {
    return array_map(function ($field) {
      return [
        $field['field_name'],
        $field['label'],
        $field['field_type'],
        $field['required'],
        $field['description'],
        $field['count_used'],
      ];
    }, $entity);
  }

}
