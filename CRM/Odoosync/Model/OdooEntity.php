<?php

class CRM_Odoosync_Model_OdooEntity {
  
  protected $id;
  
  protected $entity;
  
  protected $entity_id;
  
  protected $odoo_resource;
  
  protected $odoo_id;
  
  protected $odoo_field;
  
  protected $action;
  
  protected $status;
  
  protected $change_date;
  
  protected $data;
  
  public function __construct(CRM_Core_DAO $dao) {
    $this->id = $dao->id;
    $this->entity = $dao->entity;
    $this->entity_id = $dao->entity_id;
    $this->odoo_id = $dao->odoo_id > 0 ? $dao->odoo_id : null;
    $this->odoo_resource = $dao->odoo_resource;
    $this->odoo_field = (!empty($dao->odoo_field)) ? $dao->odoo_field : '';
    $this->status = $dao->status;    
    $this->action = $dao->action;
    $this->change_date = new DateTime($dao->change_date);
    $this->data = $dao->data;
  }
  
  public static function getLock() {
    return new CRM_Odoosync_Model_Lock('sync');
  }
  
  public function getId() {
    return $this->id;
  }
  
  public function getEntity() {
    return $this->entity;
  }
  
  public function getEntityId() {
    return $this->entity_id;
  }
  
  public function getOdooId() {
    return $this->odoo_id;
  }
  
  public function getOdooField() {
    return $this->odoo_field;
  }
  
  public function getChangeDate() {
    return $this->change_date;
  }

  public function setOdooId($odooId=null) {
    $this->odoo_id = $odooId;
  }
  
  public function setOdooField($field) {
    $this->odoo_field = $field;
  }
  
  public function getData() {
    if (!empty($this->data)) {
      return unserialize($this->data);
    }
    return false;
  }
  
  public function process($debug = false) {
    $objectList = CRM_Odoosync_Objectlist::singleton();
    
    $synchronisator = $objectList->getSynchronisatorForEntity($this->entity);
    if (!$synchronisator) {
      $this->logSyncError('No synchronisator found');
      return;
    }
    
    //set action to update if object still exists in database (e.g. it is only a soft delete)
    if ($this->action == 'DELETE' && $synchronisator->existsInCivi($this)) {
      $this->action = 'UPDATE';
    } elseif (!$synchronisator->existsInCivi($this)) {
      $this->action = 'DELETE'; //delete from Odoo because entity does not exist in civi anymore
    }
    
    //check if we shoudl sync this item
    if ($this->action != 'DELETE' && !$synchronisator->isThisItemSyncable($this)) {
      $this->status = "NOT SYNCABLE";
      $this->save();
      return;
    }
    
    if ($this->action != 'DELETE' && (!$this->odoo_id || $this->odoo_id <= 0)) {
      $odoo_id = $synchronisator->findOdooId($this);
      if ($odoo_id > 0) {
        $this->odoo_id = $odoo_id;
      }
    } elseif ($this->action != 'DELETE' && !$synchronisator->existsInOdoo($this->odoo_id)) {
      $this->logSyncError('Entity doesn\'t exist in Odoo anymore');
      return;
    }
    
    //set action to update if we do an insert of an existing odoo entity
    if ($this->action == 'INSERT' && $this->odoo_id > 0) {
      $this->action = 'UPDATE';
    }
    //set action to insert if action is update with an unknown odoo id
    if ($this->action == 'UPDATE' && (!$this->odoo_id || $this->odoo_id <= 0)) {
        $this->action = 'INSERT';
    }
    
    
    
    try {
      switch($this->action) {
        case 'INSERT':
          $this->odoo_id = $synchronisator->performInsert($this);
          $this->odoo_resource = $synchronisator->getOdooResourceType();
          $this->status = "SYNCED";
          $this->data = serialize($synchronisator->getSyncData($this, $this->odoo_id));
          $this->save();
          break;
        case 'UPDATE':
          if ($this->getData() != $synchronisator->getSyncData($this, $this->odoo_id)) {          
            $this->odoo_id = $synchronisator->performUpdate($this->odoo_id, $this);
            $this->odoo_resource = $synchronisator->getOdooResourceType();
            $this->data = serialize($synchronisator->getSyncData($this, $this->odoo_id));
          }
          $this->status = "SYNCED";
          $this->save();
          break;
        case 'DELETE':
          if ($synchronisator->existsInOdoo($this->odoo_id, $this)) {
            $this->odoo_id = $synchronisator->performDelete($this->odoo_id, $this);
          }
          $this->remove();
          break;
      }
    } catch (Exception $e) {
      $this->logSyncError($e->getMessage());
      if ($debug) {
        throw $e;
      }
    }
  }
  
  private function remove() {
    CRM_Core_DAO::executeQuery("DELETE FROM `civicrm_odoo_entity`  WHERE `id` = %1", array(1 => array($this->id, 'Positive')));
  }
  
  private function save() {
    $sql = "UPDATE `civicrm_odoo_entity` SET `action` = NULL, odoo_resource = %1, odoo_id = %2, `status` = %3, `odoo_field` = %4, `sync_date` = NOW(), `last_error` = NULL, `last_error_date` = NULL, `data` = %5 WHERE `id` = %6";
    CRM_Core_DAO::executeQuery($sql, array(
      1 => array(!empty($this->odoo_resource) ? $this->odoo_resource : '', 'String'),
      2 => array(!empty($this->odoo_id) ? $this->odoo_id : -1, 'Integer'),
      3 => array($this->status, 'String'),
      4 => array($this->odoo_field, 'String'),
      5 => array(!empty($this->data) ? $this->data : '', 'String'),
      6 => array($this->id, 'Positive'),
    ));
  }
  
  public static function sync($limit = 1000, $debug=false) {
    //do not sync when lock is set
    $lock = self::getLock();
    $lock->autoUnlock();
    if ($lock->isLocked()) {
      throw new Exception('Sync in lock');
    }    
    //set lock
    $lock->lock();
    
    $sql = "SELECT * FROM `civicrm_odoo_entity`  WHERE `action` IS NOT NULL AND `action` != '' AND `change_date` IS NOT NULL AND (`sync_date` IS NULL OR `change_date` >= `sync_date`) ORDER BY `weight` ASC, `action` ASC, `change_date` ASC LIMIT 0, %1";
    $dao = CRM_Core_DAO::executeQuery($sql, array(1 => array($limit, 'Integer')));
    //sync this object
    while ($dao->fetch()) {
      $odooEntity = new CRM_Odoosync_Model_OdooEntity($dao);
      $odooEntity->process($debug);
    }
    
    //release lock
    $lock->unlock();
  }
  
  private function logSyncError($error) {
    $sql = "UPDATE `civicrm_odoo_entity` SET `last_error`  = %1, `last_error_date` = NOW(), `sync_date` = NOW() WHERE `id`  = %2";
    CRM_Core_DAO::executeQuery($sql, array(
      1 => array($error, 'String'),
      2 => array($this->id, 'Positive')
    ));
  }
  
  /**
   * Return the stored odoo_id for an entity
   * 
   * @param String $entity
   * @param int $entity_id
   * @return int|false
   */
  public static function findOdooIdByEntityAndEntityId($entity, $entity_id) {
    if (empty($entity_id) || !is_numeric($entity_id)) {
      return false;
    }

    $sql = "SELECT `odoo_id`  FROM `civicrm_odoo_entity` WHERE `entity` = %1  AND `entity_id`  = %2";
    $dao = CRM_Core_DAO::executeQuery($sql, array(
      1 => array($entity, 'String'),
      2 => array($entity_id, 'Integer'),
    ));
    
    if ($dao->fetch()) {
      return $dao->odoo_id;
    } 
    return false;
  }
  
  public function findOdooIdByEntity($entity, $entity_id) {
    return self::findOdooIdByEntityAndEntityId($entity, $entity_id);
  }
  
  public static function findByOdooId($resource, $odoo_id) {
    $sql = "SELECT *  FROM `civicrm_odoo_entity` WHERE `odoo_resource` = %1  AND `odoo_id`  = %2";
    $dao = CRM_Core_DAO::executeQuery($sql, array(
      1 => array($resource, 'String'),
      2 => array($odoo_id, 'Integer'),
    ));
    
    $values = array();
    while($dao->fetch()) {
      $values[$dao->id]['entity_id'] = $dao->entity_id;
      $values[$dao->id]['entity'] = $dao->entity;
    }
    
    return $values;
  }
  
  public function findByOdooIdAndField($resource, $odoo_id, $odoo_field) {
    $sql = "SELECT *  FROM `civicrm_odoo_entity` WHERE `odoo_resource` = %1  AND `odoo_id`  = %2 AND `odoo_field`  = %3";
    $dao = CRM_Core_DAO::executeQuery($sql, array(
      1 => array($resource, 'String'),
      2 => array($odoo_id, 'Integer'),
      3 => array($odoo_field, 'String'),
    ));
    
    $values = array();
    while($dao->fetch()) {
      $v = array();
      $v['id'] = $dao->id;
      $v['entity_id'] = $dao->entity_id;
      $v['entity'] = $dao->entity;
      $v['odoo_resource'] = $dao->odoo_resource;
      $v['odoo_id'] = $dao->odoo_id;
      $v['odoo_field'] = $dao->odoo_field;
      $v['change_date'] = $dao->change_date;
      $v['sync_date'] = $dao->sync_date;
      $v['action'] = $dao->action;
      $v['weight'] = $dao->weight;
      $v['last_error'] = $dao->last_error;
      $v['status'] = $dao->status;
      $v['data'] = $dao->data;
      $values[$dao->id] = $v;
    }
    
    return $values;
  }
  
}

