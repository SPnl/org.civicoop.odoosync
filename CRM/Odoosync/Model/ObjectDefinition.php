<?php

abstract class CRM_Odoosync_Model_ObjectDefinition implements CRM_Odoosync_Model_ObjectDefinitionInterface {
  
  /**
   * Returns the base class name for the synchronisator
   * 
   * @return string
   */
  abstract protected function getSynchronisatorClass();
  
  public function getSynchronisator() {
    $hookedClass = $this->getSynchronisatorClass();
    $hooks = CRM_Utils_Hook::singleton();
    $hooks->invoke(2, $this, $hookedClass, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, CRM_Utils_Hook::$_nullObject, 'civicrm_odoo_synchronisator');
    if (!is_subclass_of($hookedClass, $this->getSynchronisatorClass())) {
      $hookedClass = $this->getSynchronisatorClass();
    }
    
    return new $hookedClass($this);
  }
  
}
