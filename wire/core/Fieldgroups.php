<?php

/**
 * ProcessWire Fieldgroups
 *
 * Maintains collections of Fieldgroup instances. 
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

/**
 * Array of Fieldgroup instances, as used by the Fieldgroups class
 *
 */
class FieldgroupsArray extends WireArray {

	/**
	 * Per WireArray interface, this class only carries Fieldgroup instances
	 *
	 */
	public function isValidItem($item) {
		return $item instanceof Fieldgroup; 
	}

	/**
	 * Per WireArray interface, items are keyed by their ID
	 *
	 */
	public function getItemKey($item) {
		return $item->id; 
	}

	/**
	 * Per WireArray interface, keys must be integers
	 *
	 */
	public function isValidKey($key) {
		return is_int($key); 
	}

	/**
	 * Per WireArray interface, return a blank Fieldgroup
	 *
	 */
	public function makeBlankItem() {
		return new Fieldgroup();
	}

}

/**
 * Maintains collection of all fieldgroups
 *
 */
class Fieldgroups extends WireSaveableItemsLookup {

	/**
	 * Instances of FieldgroupsArray
	 *
	 */
	protected $fieldgroupsArray; 
	
	public function init() {
		$this->fieldgroupsArray = new FieldgroupsArray();
		$this->load($this->fieldgroupsArray);
	}

	/**
	 * Get the DatabaseQuerySelect to perform the load operation of items
	 *
	 * @param Selectors|string|null $selectors Selectors or a selector string to find, or NULL to load all. 
	 * @return DatabaseQuerySelect
	 *
	 */
	protected function getLoadQuery($selectors = null) {
		$query = parent::getLoadQuery($selectors); 
		$lookupTable = $this->wire('database')->escapeTable($this->getLookupTable()); 
		$query->select("$lookupTable.data"); // QA
		return $query; 
	}

	/**
	 * Load all the Fieldgroups from the database
	 *
	 * The loading is delegated to WireSaveableItems.
	 * After loaded, we check for any 'global' fields and add them to the Fieldgroup, if not already there.
	 *
	 * @param WireArray $items
	 * @param Selectors|string|null $selectors Selectors or a selector string to find, or NULL to load all. 
	 * @return WireArray Returns the same type as specified in the getAll() method.
	 *
	 */
	protected function ___load(WireArray $items, $selectors = null) {
		$items = parent::___load($items, $selectors); 	
		return $items; 
	}

	/**
	 * Per WireSaveableItems interface, return all available Fieldgroup instances
	 *
	 */
	public function getAll() {
		return $this->fieldgroupsArray;
	}

	/**
	 * Per WireSaveableItems interface, create a blank instance of a Fieldgroup
	 *
	 */
	public function makeBlankItem() {
		return new Fieldgroup(); 
	}

	/**
	 * Per WireSaveableItems interface, return the name of the table that Fieldgroup instances are stored in
	 *
	 */
	public function getTable() {
		return 'fieldgroups';
	}

	/**
	 * Per WireSaveableItemsLookup interface, return the name of the table that Fields are linked to Fieldgroups 
	 *
	 */
	public function getLookupTable() {
		return 'fieldgroups_fields';
	}

	/**
	 * Get the number of templates using the given fieldgroup. 
	 *
	 * Primarily used to determine if the Fieldgroup is deleteable. 
	 *
	 * @param Fieldgroup $fieldgroup
	 * @return int
	 *
	 */
	public function getNumTemplates(Fieldgroup $fieldgroup) {
		return count($this->getTemplates($fieldgroup)); 
	}

	/**
	 * Given a Fieldgroup, return a TemplatesArray of all templates using the Fieldgroup
	 *
	 * @param Fieldgroup $fieldgroup
	 * @return TemplatesArray
	 *
	 */
	public function getTemplates(Fieldgroup $fieldgroup) {
		$templates = new TemplatesArray();
		$cnt = 0;
		foreach($this->fuel('templates') as $tpl) {
			if($tpl->fieldgroup->id == $fieldgroup->id) $templates->add($tpl); 
		}
		return $templates; 
	}

	/**
	 * Save the Fieldgroup to DB
	 *
	 * If fields were removed from the Fieldgroup, then track them down and remove them from the associated field_* tables
	 *
	 * @param Saveable $item Fieldgroup to save
	 * @return bool True on success, false on failure
	 * @throws WireException
	 *
	 */
	public function ___save(Saveable $item) {

		$database = $this->wire('database');

		if($item->id && $item->removedFields) {

			foreach($this->wire('templates') as $template) {

				if($template->fieldgroup->id !== $item->id) continue; 

				foreach($item->removedFields as $field) {

					// make sure the field is valid to delete from this template
					if(($field->flags & Field::flagGlobal) && !$template->noGlobal) {
						throw new WireException("Field '$field' may not be removed from fieldgroup '{$item->name}' because it is globally required (Field::flagGlobal)");
					}

					if($field->flags & Field::flagPermanent) {
						throw new WireException("Field '$field' may not be removed from fieldgroup '{$item->name}' because it is permanent.");
					}

					$field->type->deleteTemplateField($template, $field); 
					$item->finishRemove($field); 
				}
			}

			$item->resetRemovedFields();
		}

		$contextData = array();
		if($item->id) { 
			// save context data
			$query = $database->prepare("SELECT fields_id, data FROM fieldgroups_fields WHERE fieldgroups_id=:item_id"); 
			$query->bindValue(":item_id", (int) $item->id, PDO::PARAM_INT); 
			$query->execute();
			while($row = $query->fetch(PDO::FETCH_ASSOC)) {
				$contextData[$row['fields_id']] = $row['data'];
			}
			$query->closeCursor();
		}

		$result = parent::___save($item); 

		if(count($contextData)) {
			// restore context data
			foreach($contextData as $fields_id => $data) {
				$fieldgroups_id = (int) $item->id; 
				$fields_id = (int) $fields_id; 
				$query = $database->prepare("UPDATE fieldgroups_fields SET data=:data WHERE fieldgroups_id=:fieldgroups_id AND fields_id=:fields_id"); // QA
				$query->bindValue(":data", $data, PDO::PARAM_STR); 
				$query->bindValue(":fieldgroups_id", $fieldgroups_id, PDO::PARAM_INT);
				$query->bindValue(":fields_id", $fields_id, PDO::PARAM_INT); 
				$query->execute();
			}
		}

		return $result;
	}

	/**
	 * Delete the given fieldgroup from the database
	 *
	 * Also deletes the references in fieldgroups_fields table
	 *
	 * @param Saveable|Fieldgroup $item
	 * @return Fieldgroups $this
	 * @throws WireException
	 *
	 */
	public function ___delete(Saveable $item) {

		$templates = array();
		foreach($this->fuel('templates') as $template) {
			if($template->fieldgroup->id == $item->id) $templates[] = $template->name; 
		}

		if(count($templates)) {
			throw new WireException("Can't delete fieldgroup '{$item->name}' because it is in use by template(s): " . implode(', ', $templates)); 
		}

		return parent::___delete($item); 
	}

	/**
	 * Delete the entries in fieldgroups_fields for the given Field
	 *
	 * @param Field $field
	 * @return bool
	 *
	 */
	public function deleteField(Field $field) {
		$database = $this->wire('database'); 
		$query = $database->prepare("DELETE FROM fieldgroups_fields WHERE fields_id=:fields_id"); // QA
		$query->bindValue(":fields_id", $field->id, PDO::PARAM_INT);
		$result = $query->execute();
		return $result;
	}

	/**
	 * Create and return a cloned copy of this item
	 *
	 * If the new item uses a 'name' field, it will contain a number at the end to make it unique
	 *
	 * @param Saveable $item Item to clone
	 * @param bool|Saveable $item Returns the new clone on success, or false on failure
	 * @return Saveable|Fieldgroup
	 *
	 */
	public function ___clone(Saveable $item) {
		return parent::___clone($item);
		// @TODO clone the field context data
		/*
		$id = $item->id; 
		$item = parent::___clone($item);
		if(!$item) return false;
		return $item; 	
		*/
	}

}

