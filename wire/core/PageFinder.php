<?php

/**
 * ProcessWire PageFinder
 *
 * Matches selector strings to pages
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2013 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://processwire.com
 *
 */

class PageFinderException extends WireException { }
class PageFinderSyntaxException extends PageFinderException { }

class PageFinder extends Wire {

	/**
	 * Options (and their defaults) that may be provided as the 2nd argument to find()
	 *
	 */
	protected $defaultOptions = array(

		/**
		 * Specify that you only want to find 1 page and don't need info for pagination
		 * 	
		 */
		'findOne' => false,
		
		/**
		 * Specify that it's okay for hidden pages to be included in the results	
		 *
		 */
		'findHidden' => false,

		/**
		 * Specify that no page status should be excluded - results can include unpublished, trash, system, etc.
		 *
		 */
		'findAll' => false,

		/**
		 * This is an optimization used by the Pages::find method, but we observe it here as we may be able
		 * to apply some additional optimizations in certain cases. For instance, if loadPages=false, then 
		 * we can skip retrieval of IDs and omit sort fields.
		 *
		 */
		'loadPages' => true,

		/**
		 * When true, this function returns array of arrays containing page ID, parent ID, template ID and score.
		 * When false, returns only an array of page IDs. returnVerbose=true is required by most usage from Pages 
		 * class. False is only for specific cases. 
		 *
		 */
		'returnVerbose' => true,

		/**
		 * Whether the total quantity of matches should be determined and accessible from getTotal()
		 *
		 * null: determine automatically (disabled when limit=1, enabled in all other cases)
		 * true: always calculate total
		 * false: never calculate total
		 *
		 */
		'getTotal' => null,

		/**
		 * Method to use when counting total records
		 *
		 * If 'count', total will be calculated using a COUNT(*).
		 * If 'calc, total will calculate using SQL_CALC_FOUND_ROWS.
		 * If blank or something else, method will be determined automatically.
		 * 
		 */
		'getTotalType' => 'calc',

		); 

	protected $fieldgroups; 
	protected $getTotal = true; // whether to find the total number of matches
	protected $getTotalType = 'calc'; // May be: 'calc', 'count', or blank to auto-detect. 
	protected $total = 0;
	protected $limit = 0; 
	protected $start = 0;
	protected $parent_id = null;
	protected $templates_id = null;
	protected $checkAccess = true;
	protected $getQueryNumChildren = 0; // number of times the function has been called
	protected $lastOptions = array(); 

	/**
	 * Construct the PageFinder
	 *
	 */
	public function __construct() {
		$this->fieldgroups = $this->fuel('fieldgroups'); 
	}

	/**
	 * Pre-process the selectors to add Page status checks
	 *
	 */
	protected function setupStatusChecks(Selectors $selectors, array &$options) {

		$maxStatus = null; 
		$limit = 0; // for getTotal auto detection
		$start = 0;

		foreach($selectors as $key => $selector) {

			$fieldName = $selector->field; 

			if($fieldName == 'status') {
				$value = $selector->value; 
				if(!ctype_digit("$value")) {
					// allow use of some predefined labels for Page statuses
					if($value == 'hidden') $selector->value = Page::statusHidden; 
						else if($value == 'unpublished') $selector->value = Page::statusUnpublished; 
						else if($value == 'locked') $selector->value = Page::statusLocked; 
						else if($value == 'max') $selector->value = Page::statusMax; 
						else $selector->value = 1; 

					if($selector->operator == '=') {
						// there is no point in an equals operator here, so we make it a bitwise AND, for simplification
						$selectors[$key] = new SelectorBitwiseAnd('status', $selector->value); 
					}
				}
				if(is_null($maxStatus) || $value > $maxStatus) 
					$maxStatus = (int) $selector->value; 

			} else if($fieldName == 'include' && $selector->operator == '=' && in_array($selector->value, array('hidden', 'all'))) {
				if($selector->value == 'hidden') $options['findHidden'] = true; 
					else if($selector->value == 'all') $options['findAll'] = true; 
				$selectors->remove($key);

			} else if($fieldName == 'check_access' || $fieldName == 'checkAccess') { 
				$this->checkAccess = ((int) $selector->value) > 0 ? true : false;
				$selectors->remove($key); 

			} else if($fieldName == 'limit') {
				// for getTotal auto detect
				$limit = (int) $selector->value; 	

			} else if($fieldName == 'start ') {
				// for getTotal auto detect
				$start = (int) $selector->value; 	

			} else if($fieldName == 'sort') {
				// sorting is not needed if we are only retrieving totals
				if($options['loadPages'] === false) $selectors->remove($selector); 

			} else if($fieldName == 'getTotal' || $fieldName == 'get_total') {
				// whether to retrieve the total, and optionally what type: calc or count
				// this applies only if user hasn't themselves created a field called getTotal or get_total
				if(!$this->wire('fields')->get($fieldName)) {
					if(ctype_digit("$selector->value")) {
						$options['getTotal'] = (bool) $selector->value; 
					} else if(in_array($selector->value, array('calc', 'count'))) {
						$options['getTotal'] = true; 
						$options['getTotalType'] = $selector->value; 
					}
					$selectors->remove($selector); 
				}
			}
		}

		if(!is_null($maxStatus) && empty($options['findAll'])) {
			// if a status was already present in the selector, without a findAll, then just make sure the page isn't unpublished
			if($maxStatus < Page::statusUnpublished) 
				$selectors->add(new SelectorLessThan('status', Page::statusUnpublished)); 

		} else if($options['findAll']) { 
			// findAll option means that unpublished, hidden, trash, system may be included
			$selectors->add(new SelectorLessThan('status', Page::statusMax)); 
			$this->checkAccess = false;	

		} else if($options['findOne'] || $options['findHidden']) {
			// findHidden|findOne option, apply optimizations enabling hidden pages to be loaded
			$selectors->add(new SelectorLessThan('status', Page::statusUnpublished)); 

		} else {
			// no status is present, so exclude everything hidden and above
			$selectors->add(new SelectorLessThan('status', Page::statusHidden)); 
		}

		if($options['findOne']) {
			// findOne option is never paginated, always starts at 0
			$selectors->add(new SelectorEqual('start', 0)); 
			$selectors->add(new SelectorEqual('limit', 1)); 
			// getTotal default is false when only finding 1 page
			if(is_null($options['getTotal'])) $options['getTotal'] = false; 

		} else if(!$limit && !$start) {
			// getTotal is not necessary since there is no limit specified (getTotal=same as count)
			if(is_null($options['getTotal'])) $options['getTotal'] = false; 

		} else {
			// get Total default is true when finding multiple pages
			if(is_null($options['getTotal'])) $options['getTotal'] = true; 
		}

		$this->lastOptions = $options; 

	}

	/**
	 * Return all pages matching the given selector.
	 *
	 */
	public function find(Selectors $selectors, $options = array()) {

		$options = array_merge($this->defaultOptions, $options); 

		$this->start = 0; // reset for new find operation
		$this->limit = 0; 
		$this->parent_id = null;
		$this->templates_id = null;
		$this->checkAccess = true; 
		$this->getQueryNumChildren = 0;
		$this->setupStatusChecks($selectors, $options);

		// move getTotal option to a class property, after setupStatusChecks
		$this->getTotal = $options['getTotal'];
		$this->getTotalType = $options['getTotalType'] == 'count' ? 'count' : 'calc'; 
		unset($options['getTotal']); // so we get a notice if we try to access it

		$database = $this->wire('database');
		$cnt = count($selectors); 
		$matches = array(); 
		$query = $this->getQuery($selectors, $options); 
		if($this->wire('config')->debug) $query->set('comment', "Selector: " . (string) $selectors); 

		if($options['loadPages'] || $this->getTotalType == 'calc') {

			$stmt = $query->execute();	
			
			if($stmt->errorCode() > 0) {
				$errorInfo = $stmt->errorInfo();
				throw new PageFinderException($errorInfo[2]);
			}
		
			if($options['loadPages']) { 	
				while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

					// determine score for this row
					$score = 0;
					foreach($row as $k => $v) if(strpos($k, '_score') === 0) {
						$score += $v;
						unset($row[$k]);

					}
					if($options['returnVerbose']) { 
						$row['score'] = $score;
						$matches[] = $row;
					} else {
						$matches[] = $row['id']; 
					}
				}
			}
			$stmt->closeCursor();
		} 
			
		if($this->getTotal) {
			if($this->getTotalType === 'count') {
				$query->set('select', array('COUNT(*)')); 
				$query->set('orderby', array()); 
				$query->set('groupby', array()); 
				$query->set('limit', array()); 
				$stmt = $query->execute();
				$errorInfo = $stmt->errorInfo();
				if($stmt->errorCode() > 0) throw new PageFinderException($errorInfo[2]);
				list($this->total) = $stmt->fetch(PDO::FETCH_NUM); 
				$stmt->closeCursor();
			} else {
				$this->total = (int) $database->query("SELECT FOUND_ROWS()")->fetchColumn();
			}
			
		} else {
			$this->total = count($matches);
		}

		$this->lastOptions = $options; 

		return $matches; 
	}

	/**
	 * Same as find() but returns just a simple array of page IDs without any other info
	 *
	 * @param Selectors $selectors
	 * @param array $options
	 * @return array of page IDs
	 *
	 */
	public function findIDs(Selectors $selectors, $options = array()) {
		$options['returnVerbose'] = false; 
		return $this->find($selectors, $options); 
	}

	/**
	 * Pre-process the given selector to perform any necessary replacements
	 *
	 * This is primarily used to handle sub-selections, i.e. "bar=foo, id=[this=that, foo=bar]"
	 *
	 */
	protected function preProcessSelector(Selector $selector) {
		// i.e. field=[id>0, name=something, this=that]
		if($selector->quote == '[' && !is_array($selector->value) && Selectors::stringHasSelector($selector->value)) {
			// selector contains another embedded selector that we need to convert to page IDs
			$selectors = new Selectors($selector->value); 
			$hasTemplate = false; 
			$hasParent = false; 
			foreach($selectors as $s) {
				if(is_array($s->field)) continue; 
				if($s->field == 'template') $hasTemplate = true; 
				if($s->field == 'parent' || $s->field == 'parent_id') $hasParent = true; 
			}
			// special handling for page references, detect if parent or template is defined, 
			// and add it to the selector if available. This makes it faster. 
			if(!$hasTemplate || !$hasParent) { 
				$fields = is_array($selector->field) ? $selector->field : array($selector->field); 
				$templates = array();
				$parents = array();
				$findSelector = '';
				foreach($fields as $fieldName) { 
					if(strpos($fieldName, '.') !== false) list($unused, $fieldName) = explode('.', $fieldName); 
					$field = $this->wire('fields')->get($fieldName); 	
					if(!$field) continue;
					if(!$hasTemplate && $field->template_id) $templates[] = (int) $field->template_id; 
					if(!$hasParent && $field->parent_id) $parents[] = (int) $field->parent_id; 
					if($field->findPagesSelector && count($fields) == 1) $findSelector = $field->findPagesSelector;	
				}
				if(count($templates)) $selectors->prepend(new SelectorEqual('template', $templates));
				if(count($parents)) $selectors->prepend(new SelectorEqual('parent_id', $parents)); 
				if($findSelector) foreach(new Selectors($findSelector) as $s) $selectors->append($s); 
			}
			
			$pageFinder = new PageFinder();
			$ids = $pageFinder->findIDs($selectors); 
			// populate selector value with array of page IDs
			$selector->value = count($ids) < 2 ? reset($ids) : $ids; 
			$selector->quote = '';
		}
	}


	/**
	 * Given one or more selectors, create the SQL query for finding pages.
	 *
	 * @TODO split this method up into more parts, it's too long
	 *
	 * @param array $selectors Array of selectors.
	 * @param array $options 
	 * @return string SQL statement. 
	 * @throws PageFinderSyntaxException
	 *
	 */
	protected function ___getQuery($selectors, array $options) {

		$where = '';
		$cnt = 1;
		$fieldCnt = array(); // counts number of instances for each field to ensure unique table aliases for ANDs on the same field
		$lastSelector = null; 
		$sortSelectors = array(); // selector containing 'sort=', which gets added last
		$joins = array();
		$startLimit = false; // true when the start/limit part of the query generation is done
		$database = $this->wire('database');

		$query = new DatabaseQuerySelect();
		$query->select($options['returnVerbose'] ? array('pages.id', 'pages.parent_id', 'pages.templates_id') : array('pages.id')); 
		$query->from("pages"); 
		$query->groupby("pages.id"); 

		foreach($selectors as $selectorCnt => $selector) {

			if(is_null($lastSelector)) $lastSelector = $selector; 

			$this->preProcessSelector($selector); 
			$fields = $selector->field; 
			$group = $selector->group; // i.e. @field
			$fields = is_array($fields) ? $fields : array($fields); 
			if(count($fields) > 1) $fields = $this->arrangeFields($fields); 
			$fieldsStr = ':' . implode(':', $fields) . ':'; // for strpos
			$field = reset($fields); // first field
			if(strpos($field, '.')) list($field, $subfield) = explode('.', $field); 
				else $subfield = '';

			// TODO Make native fields and path/url multi-field and multi-value aware
			if($field == 'sort') {
				$sortSelectors[] = $selector; 
				continue; 

			} else if($field == 'limit' || $field == 'start') {
				if(!$startLimit) $this->getQueryStartLimit($query, $selectors); 
				$startLimit = true; 
				continue; 

			} else if($field == 'path' || $field == 'url') {
				$this->getQueryJoinPath($query, $selector); 
				continue; 

			} else if($field == 'has_parent' || $field == 'hasParent') {
				$this->getQueryHasParent($query, $selector); 
				continue; 

			} else if($field == 'num_children' || $field == 'numChildren' || ($field == 'children' && $subfield == 'count')) { 
				$this->getQueryNumChildren($query, $selector); 
				continue; 

			} else if($this->wire('fields')->isNativeName($field) || strpos($fieldsStr, ':parent.') !== false) {
				$this->getQueryNativeField($query, $selector, $fields); 
				continue; 
			} 

			
			// where SQL specific to the foreach() of fields below, if needed. 
			// in this case only used by internally generated shortcuts like the blank value condition
			$whereFields = '';	
			
			foreach($fields as $n => $fieldName) {

				// if a specific DB field from the table has been specified, then get it, otherwise assume 'data'
				if(strpos($fieldName, ".")) list($fieldName, $subfield) = explode(".", $fieldName); 	
					else $subfield = 'data';

				if(!$field = $this->wire('fields')->get($fieldName)) {
					// field does not exist, try to match an API variable
					$value = $this->wire($fieldName); 
					if(!$value) throw new PageFinderSyntaxException("Field does not exist: $fieldName");
					if(count($fields) > 1) throw new PageFinderSyntaxException("You may only match 1 API variable at a time"); 
					if(is_object($value)) {
						if($subfield == 'data') $subfield = 'id';
						$selector->field = $subfield; 
					}
					if(!$selector->matches($value)) {
						$query->where("1>2"); // force non match
					}
					break;
				}

				// keep track of number of times this table name has appeared in the query
				if(!isset($fieldCnt[$field->table])) $fieldCnt[$field->table] = 0; 
					else $fieldCnt[$field->table]++; 

				// use actual table name if first instance, if second instance of table then add a number at the end
				$tableAlias = $field->table . ($fieldCnt[$field->table] ? $fieldCnt[$field->table] : '');
				$tableAlias = $database->escapeTable($tableAlias);

				$valueArray = is_array($selector->value) ? $selector->value : array($selector->value); 
				$join = '';
				$fieldtype = $field->type; 

				foreach($valueArray as $value) {

					// shortcut for blank value condition: this ensures that NULL/non-existence is considered blank
					// without this section the query would still work, but a blank value must actually be present in the field
					if($subfield == 'data') {
						if(empty($value) && in_array($selector->operator, array('=', '!=', '<>'))) {
							// handle blank values -- look in table that has no pages_id relation back to pages, using the LEFT JOIN / IS NULL trick
							// OR check for blank value as defined by the fieldtype
							$blankValue = $database->escapeStr($fieldtype->getBlankValue(new NullPage(), $field)); 
							if($field->table === $tableAlias) $query->leftjoin("$tableAlias ON $tableAlias.pages_id=pages.id"); 
								else $query->leftjoin($database->escapeTable($field->table) . " AS $tableAlias ON $tableAlias.pages_id=pages.id");
							$whereFields .= (strlen($whereFields) ? ' OR ' : ''); 
							if($selector->operator == '=') {
								$whereFields .= "($tableAlias.pages_id IS NULL OR $tableAlias.data='$blankValue')";
							} else {
								$whereFields .= "($tableAlias.pages_id IS NOT NULL AND $tableAlias.data!='$blankValue')";
							}
							unset($blankValue);
							continue; 
						}
					} 
					
					if(isset($subqueries[$tableAlias])) $q = $subqueries[$tableAlias];
						else $q = new DatabaseQuerySelect();

					$q->set('field', $field); // original field if required by the fieldtype
					$q->set('group', $group); // original group of the field, if required by the fieldtype
					$q->set('selector', $selector); // original selector if required by the fieldtype
					$q->set('selectors', $selectors); // original selectors (all) if required by the fieldtype
					$q->set('parentQuery', $query);
					$q = $fieldtype->getMatchQuery($q, $tableAlias, $subfield, $selector->operator, $value); 

					if(count($q->select)) $query->select($q->select); 
					if(count($q->join)) $query->join($q->join);
					if(count($q->leftjoin)) $query->leftjoin($q->leftjoin);
					if(count($q->orderby)) $query->orderby($q->orderby); 
					if(count($q->groupby)) $query->groupby($q->groupby); 

					if(count($q->where)) { 
						// $and = $selector->not ? "AND NOT" : "AND";
						$and = "AND"; /// moved NOT condition to entire generated $sql
						$sql = ''; 
						foreach($q->where as $w) $sql .= $sql ? "$and $w " : "$w ";
						$sql = "($sql) "; 

						if($selector->operator == '!=') {
							$join .= ($join ? "\n\t\tAND $sql " : $sql); 

						} else if($selector->not) { 
							$sql = "((NOT $sql) OR ($tableAlias.pages_id IS NULL))";
							$join .= ($join ? "\n\t\tAND $sql " : $sql); 

						} else { 
							$join .= ($join ? "\n\t\tOR $sql " : $sql); 
						}
					}

					$cnt++; 
				}

				if($join) {
					$joinType = 'join';

					if(count($fields) > 1 
						|| $subfield == 'count' 
						|| ($selector->not && $selector->operator != '!=') 
						|| $selector->operator == '!=') {

						$joinType = "leftjoin";

						if($where) {
							$whereType = $lastSelector->str == $selector->str ? "OR" : ") AND (";
							$where .= "\n\t$whereType ($join) ";
						} else {
							$where .= "($join) ";
						}
						if($selector->not) {
							// removes condition from join, but ensures we still have a $join
							$join = '1=1'; 
						}
					}

					// we compile the joins after going through all the selectors, so that we can 
					// match up conditions to the same tables
					if(isset($joins[$tableAlias])) {
						$joins[$tableAlias]['join'] .= " AND ($join) ";
					} else {
						$joins[$tableAlias] = array(
							'joinType' => $joinType, 
							'table' => $field->table, 
							'tableAlias' => $tableAlias, 	
							'join' => "($join)", 
							);
					}

				}

				$lastSelector = $selector; 	
			} // fields
			
			// if(strlen($whereFields)) $where .= (strlen($where) ? 'AND ' : '') . "($whereFields)";
			if(strlen($whereFields)) {
				if(strlen($where)) $where = "($where) AND ($whereFields)"; 
					else $where .= "($whereFields)";
			}
		
		} // selectors

		if($where) $query->where("($where)"); 
		$this->getQueryAllowedTemplates($query, $options); 

		// complete the joins, matching up any conditions for the same table
		foreach($joins as $j) {
			$joinType = $j['joinType']; 
			$query->$joinType("$j[table] AS $j[tableAlias] ON $j[tableAlias].pages_id=pages.id AND ($j[join])"); 
		}

		if(count($sortSelectors)) foreach(array_reverse($sortSelectors) as $s) $this->getQuerySortSelector($query, $s);

		return $query; 

	}

	/**
	 * Determine which templates the user is allowed to view
	 *
	 */
	protected function getQueryAllowedTemplates(DatabaseQuerySelect $query, $options) {

		static $where = null;
		static $where2 = null;
		static $leftjoin = null;

		// if a template was specified in the search, then we won't attempt to verify access
		// if($this->templates_id) return; 

		// if findOne optimization is set, we don't check template access
		if($options['findOne']) return;

		// if access checking is disabled then skip this
		if(!$this->checkAccess) return; 

		$user = $this->fuel('user'); 

		// no need to perform this checking if the user is superuser
		if($user->isSuperuser()) return; 

		// if we've already figured out this part from a previous query, then use it
		if(!is_null($where)) {
			$query->where($where);
			$query->where($where2);
			$query->leftjoin($leftjoin);
			return;
		}

		// array of templates they ARE allowed to access
		$yesTemplates = array();

		// array of templates they are NOT allowed to access
		$noTemplates = array();

		$guestRoleID = $this->fuel('config')->guestUserRolePageID; 

		if($user->isGuest()) {
			// guest 
			foreach($this->fuel('templates') as $template) {
				if($template->guestSearchable || !$template->useRoles) {
					$yesTemplates[$template->id] = $template;
					continue; 
				}
				foreach($template->roles as $role) {
					if($role->id != $guestRoleID) continue;
					$yesTemplates[$template->id] = $template;
					break;
				}
			}

		} else {
			// other logged-in user
			$userRoleIDs = array();
			foreach($this->fuel('user')->roles as $role) {
				$userRoleIDs[] = $role->id; 
			}

			foreach($this->fuel('templates') as $template) {
				if($template->guestSearchable || !$template->useRoles) {
					$yesTemplates[$template->id] = $template;
					continue; 
				}
				foreach($template->roles as $role) {
					if($role->id != $guestRoleID && !in_array($role->id, $userRoleIDs)) continue; 
					$yesTemplates[$template->id] = $template; 	
					break;
				}
			}
		}

		// determine which templates the user is not allowed to access
		foreach($this->fuel('templates') as $template) {
			if(!isset($yesTemplates[$template->id])) $noTemplates[$template->id] = $template;
		}

		$in = '';
		$yesCnt = count($yesTemplates); 
		$noCnt = count($noTemplates); 

		if($noCnt) {

			// pages_access lists pages that are inheriting access from others. 
			// join in any pages that are using any of the noTemplates to get their access. 
			// we want pages_access.pages_id to be NULL, which indicates that none of the 
			// noTemplates was joined, and the page is accessible to the user. 
			
			$leftjoin = "pages_access ON (pages_access.pages_id=pages.id AND pages_access.templates_id IN(";
			foreach($noTemplates as $template) $leftjoin .= ((int) $template->id) . ",";
			$leftjoin = rtrim($leftjoin, ",") . "))";
			$query->leftjoin($leftjoin);
			$where2 = "pages_access.pages_id IS NULL"; 
			$query->where($where2);
		}

		if($noCnt > 0 && $noCnt < $yesCnt) {
			$templates = $noTemplates; 
			$yes = false; 
		} else {
			$templates = $yesTemplates; 
			$yes = true;
		}
	
		foreach($templates as $template) {
			$in .= ((int) $template->id) . ",";
		}


		$in = rtrim($in, ","); 
		$where = "pages.templates_id ";

		if($in && $yes) {
			$where .= "IN($in)";
		} else if($in) {
		 	$where .= "NOT IN($in)";
		} else {
			$where = "<0"; // no match possible
		}

		$query->where($where); 
	}

	protected function getQuerySortSelector(DatabaseQuerySelect $query, Selector $selector) {

		$field = is_array($selector->field) ? reset($selector->field) : $selector->field; 
		$values = is_array($selector->value) ? $selector->value : array($selector->value); 	
		$fields = $this->fuel('fields'); 
		$database = $this->wire('database');
		$user = $this->wire('user'); 
		$language = $this->wire('languages') && $user->language ? $user->language : null;
		
		foreach($values as $value) {

			$fc = substr($value, 0, 1); 
			$lc = substr($value, -1); 
			$value = trim($value, "-+"); 

			if(strpos($value, ".")) {
				list($value, $subValue) = explode(".", $value); // i.e. some_field.title
			} else {
				$subValue = '';
			}

			if($value == 'random') { 
				$value = 'RAND()';

			} else if($value == 'num_children' || $value == 'numChildren' || ($value == 'children' && $subValue == 'count')) { 
				// sort by quantity of children
				$value = $this->getQueryNumChildren($query, new SelectorGreaterThan('num_children', "-1")); 

			} else if($value == 'parent') {
				// sort by parent native field. does not work with non-native parent fields. 
				$subValue = $database->escapeCol($subValue);
				$tableAlias = "_sort_parent" . ($subValue ? "_$subValue" : ''); 
				$query->join("pages AS $tableAlias ON $tableAlias.id=pages.parent_id"); 
				$value = "$tableAlias." . ($subValue ? $subValue : "name"); 

			} else if($value == 'template') { 
				// sort by template
				$tableAlias = $database->escapeTable("_sort_templates" . ($subValue ? "_$subValue" : '')); 
				$query->join("templates AS $tableAlias ON $tableAlias.id=pages.templates_id"); 
				$value = "$tableAlias." . ($subValue ? $database->escapeCol($subValue) : "name"); 

			} else if($fields->isNativeName($value)) {
				// sort by a native field
				
				if(!strpos($value, ".")) {
					// native field with no subfield
					if($value == 'name' && $language && !$language->isDefault()  && $this->wire('modules')->isInstalled('LanguageSupportPageNames')) {
						// substitute language-specific name field when LanguageSupportPageNames is active and language is not default
						$value = "if(pages.name$language!='', pages.name$language, pages.name)";
					} else {
						$value = "pages." . $database->escapeCol($value);
					}
				}

			} else {
				
				$field = $fields->get($value);
				if(!$field) continue; 
				
				$fieldName = $database->escapeCol($field->name); 
				$subValue = $database->escapeCol($subValue);
				$tableAlias = "_sort_$fieldName". ($subValue ? "_$subValue" : '');
				$table = $database->escapeTable($field->table);

				$query->leftjoin("$table AS $tableAlias ON $tableAlias.pages_id=pages.id");

				if($subValue === 'count') {
					// sort by quantity of items
					$value = "COUNT($tableAlias.data)";

				} else if($field->type instanceof FieldtypePage) {
					// If it's a FieldtypePage, then data isn't worth sorting on because it just contains an ID to the page
					// so we also join the page and sort on it's name instead of the field's "data" field.
					$tableAlias2 = "_sort_page_$fieldName" . ($subValue ? "_$subValue" : '');
					$query->leftjoin("pages AS $tableAlias2 ON $tableAlias.data=$tableAlias2.id"); 
					if(!$subValue) $subValue = 'name';
					$value = "$tableAlias2.$subValue";
					
					if($subValue == 'name' && $language && !$language->isDefault()  && $this->wire('modules')->isInstalled('LanguageSupportPageNames')) {
						// append language ID to 'name' when performing sorts within another language and LanguageSupportPageNames in place
						$value = "if($value$language!='', $value$language, $value)";
					}
					
				} else if(!$subValue && $language && !$language->isDefault() && $field->type instanceof FieldtypeLanguageInterface) {
					// multi-language field, sort by the language version
					$value = "if($tableAlias.data$language != '', $tableAlias.data$language, $tableAlias.data)";
					
				} else {
					
					$value = "$tableAlias." . ($subValue ? $subValue : "data"); ; 
				}
			}

			if($fc == '-' || $lc == '-') $query->orderby("$value DESC", true);
				else $query->orderby("$value", true); 

		}
	}

	protected function getQueryStartLimit(DatabaseQuerySelect $query, $selectors) {

		$start = null; 
		$limit = null;
		$sql = '';

		foreach($selectors as $selector) {
			if($selector->field == 'start') $start = (int) $selector->value; 	
				else if($selector->field == 'limit') $limit = (int) $selector->value; 
		}

		if($limit) {

			$this->limit = $limit; 

			if(is_null($start) && ($input = $this->fuel('input'))) {
				// if not specified in the selector, assume the 'start' property from the default page's pageNum
				$pageNum = $input->pageNum - 1; // make it zero based for calculation
				$start = $pageNum * $limit; 
			}

			if(!is_null($start)) {
				$sql .= "$start,";
				$this->start = $start; 
			}

			$sql .= "$limit";
			
			if($this->getTotal && $this->getTotalType != 'count') $query->select("SQL_CALC_FOUND_ROWS"); 
		}

		if($sql) $query->limit($sql); 
	}


	/**
	 * Special case when requested value is path or URL
	 *
	 */ 
	protected function ___getQueryJoinPath(DatabaseQuerySelect $query, $selector) {
		
		$database = $this->wire('database'); 

		// determine whether we will include use of multi-language page names
		if($this->modules->isInstalled('LanguageSupportPageNames') && count(wire('languages'))) {
			$langNames = array();
			foreach(wire('languages') as $language) {
				if(!$language->isDefault()) $langNames[$language->id] = "name" . (int) $language->id;
			}
		} else {
			$langNames = null;
		}

		if($this->modules->isInstalled('PagePaths') && !$langNames) {
			// @todo add support to PagePaths module for LanguageSupportPageNames
			$pagePaths = $this->modules->get('PagePaths');
			$pagePaths->getMatchQuery($query, $selector); 
			return;
		}

		if($selector->operator !== '=') {
			throw new PageFinderSyntaxException("Operator '$selector->operator' is not supported for path or url unless you install the PagePaths module."); 
		}

		if($selector->value == '/') {
			$parts = array();
			$query->where("pages.id=1");
		} else {
			$selectorValue = $selector->value;
			if($langNames) $selectorValue = wire('modules')->get('LanguageSupportPageNames')->updatePath($selectorValue); 
			$parts = explode('/', rtrim($selectorValue, '/')); 
			$part = $database->escapeStr(array_pop($parts)); 
			$sql = "pages.name='$part'";
			if($langNames) foreach($langNames as $name) $sql .= " OR pages.$name='$part'";
			$query->where($sql); 
			if(!count($parts)) $query->where("pages.parent_id=1");
		}

		$alias = 'pages';
		$lastAlias = 'pages';

		while($n = count($parts)) {
			$part = $database->escapeStr(array_pop($parts)); 
			if(strlen($part)) {
				$alias = "parent$n";
				//$query->join("pages AS $alias ON ($lastAlias.parent_id=$alias.id AND $alias.name='$part')");
				$sql = "pages AS $alias ON ($lastAlias.parent_id=$alias.id AND ($alias.name='$part'";
				if($langNames) foreach($langNames as $id => $name) {
					// $status = "status" . (int) $id;
					// $sql .= " OR ($alias.$name='$part' AND $alias.$status>0) ";
					$sql .= " OR $alias.$name='$part'";
				}
				$sql .= '))';
				$query->join($sql); 

			} else {
				$query->join("pages AS rootparent ON ($alias.parent_id=rootparent.id AND rootparent.id=1)");
			}
			$lastAlias = $alias; 
		}
	}

	/**
	 * Special case when field is native to the pages table
	 *
	 * TODO not all operators will work here, so may want to add some translation or filtering
	 *
	 */
	protected function getQueryNativeField(DatabaseQuerySelect $query, $selector, $fields) {

		$value = $selector->value; 
		$values = is_array($value) ? $value : array($value); 
		$SQL = '';
		$database = $this->wire('database'); 

		foreach($fields as $field) { 

			// the following fields are defined in each iteration here because they may be modified in the loop
			$table = "pages";
			$operator = $selector->operator;
			$subfield = '';
			$IDs = array(); // populated in special cases where we can just match parent IDs
			$sql = '';

			if(strpos($field, '.')) list($field, $subfield) = explode('.', $field);

			if(!$this->wire('fields')->isNativeName($field)) {
				$subfield = $field;
				$field = 'children';
			}
			if($field == 'child') $field = 'children';

			if(in_array($field, array('parent', 'parent_id', 'children'))) {

				if(!$subfield || in_array($subfield, array('id', 'path', 'url'))) {
					// match by location (id or path)
					// convert parent fields like '/about/company/history' to the equivalent ID
					foreach($values as $k => $v) {
						if(ctype_digit("$v")) continue; 
						if(strpos($v, '/') === false) $v = "/$v"; // prevent a plain string with no slashes
						// convert path to id
						$parent = $this->wire('pages')->get($v); 
						if(!$parent instanceof NullPage) $values[$k] = $parent->id; 
							else $values[$k] = null;

					}
					$field = 'parent_id';

					if(count($values) == 1 && $selector->getOperator() === '=') $this->parent_id = reset($values); 

				} else {
					// matching by a parent's native or custom field (subfield)

					if(!$this->wire('fields')->isNativeName($subfield)) {
						$finder = new PageFinder();
						$s = $field == 'children' ? '' : 'children.count>0, ';
						$IDs = $finder->findIDs(new Selectors("include=all, $s$subfield{$operator}" . implode('|', $values)));
						if(!count($IDs)) $IDs[] = -1; // forced non match
					} else {
						// native
						static $n = 0;
						if($field == 'children') {
							$table = "_children_native" . (++$n);
							$query->join("pages AS $table ON $table.parent_id=pages.id");
						} else {
							$table = "_parent_native" . (++$n);
							$query->join("pages AS $table ON pages.parent_id=$table.id");
						}
						$field = $subfield;
					}
				}
			}

			if(count($IDs)) {
				// parentIDs are IDs found via another query, and we don't need to match anything other than the parent ID
				$in = $selector->not ? "NOT IN" : "IN"; 
				$sql .= in_array($field, array('parent', 'parent_id')) ? "$table.parent_id " : "$table.id ";
				$sql .= "$in(" . implode(',', $IDs) . ")";

			} else foreach($values as $value) { 

				if(is_null($value)) {
					// an invalid/unknown walue was specified, so make sure it fails
					$sql .= "1>2";
					continue; 
				}

				if(in_array($field, array('templates_id', 'template'))) {
					// convert templates specified as a name to the numeric template ID
					// allows selectors like 'template=my_template_name'
					$field = 'templates_id';
					if(count($values) == 1 && $selector->getOperator() === '=') $this->templates_id = reset($values);
					if(!ctype_digit("$value")) $value = (($template = $this->fuel('templates')->get($value)) ? $template->id : 0); 
				}

				if(in_array($field, array('created', 'modified'))) {
					// prepare value for created or modified date fields
					if(!ctype_digit($value)) $value = strtotime($value); 
					$value = date('Y-m-d H:i:s', $value); 
				}

				if(in_array($field, array('id', 'parent_id', 'templates_id'))) {
					$value = (int) $value; 
				}

				if($field == 'name' && $operator == '~=') {
					// handle one or more space-separated full words match to 'name' field in any order
					$s = '';
					foreach(explode(' ', $value) as $word) {
						$word = $database->escapeStr(wire('sanitizer')->pageName($word)); 
						$s .= ($s ? ' AND ' : '') . "$table.$field RLIKE '" . '[[:<:]]' . $word . '[[:>:]]' . "'";
					}

				} else if($field == 'name' && in_array($operator, array('%=', '^=', '$=', '%^=', '%$=', '*='))) {
					// handle partial match to 'name' field
					$value = $database->escapeStr(wire('sanitizer')->pageName($value));
					if($operator == '^=' || $operator == '%^=') $value = "$value%";
						else if($operator == '$=' || $operator == '%$=') $value = "%$value";
						else $value = "%$value%";
					$s = "$table.$field LIKE '$value'";
					
				} else if(!$database->isOperator($operator)) {
					throw new PageFinderSyntaxException("Operator '{$operator}' is not supported for '$field'."); 

				} else {
					$value = $database->escapeStr($value); 
					$s = "$table." . $field . $operator . ((ctype_digit("$value") && $field != 'name') ? ((int) $value) : "'$value'");
				}

				if($selector->not) $s = "NOT ($s)";
				if($operator == '!=' || $selector->not) {
					$sql .= $sql ? " AND $s": "$s"; 
				} else {
					$sql .= $sql ? " OR $s": "$s"; 
				}

			}

			if($sql) {
				if($SQL) $SQL .= " OR ($sql)"; 
					else $SQL .= "($sql)";
			}
		}

		if(count($fields) > 1) $SQL = "($SQL)";

		$query->where($SQL); 
	}

	/**
	 * Make the query specific to all pages below a certain parent (children, grandchildren, great grandchildren, etc.)
	 *
	 */
	protected function getQueryHasParent(DatabaseQuerySelect $query, $selector) {

		static $cnt = 0; 

		$parent_id = $selector->value;

		if(!ctype_digit("$parent_id")) {
			// parent_id is a path, convert a path to a parent
			$parent = new NullPage();
			$path = wire('sanitizer')->path($parent_id);
			if($path) $parent = wire('pages')->get('/' . trim($path, '/') . '/');
			$parent_id = $parent->id;
			if(!$parent_id) {
				$query->select("1>2"); // force the query to fail
				return;
			}
		}

		$parent_id = (int) $parent_id;

		$cnt++;

		if($parent_id == 1) {
			// homepage
			if($selector->operator == '!=') {
				// homepage is only page that can match not having a has_parent of 1
				$query->where("pages.id=1"); 
			} else {
				// no different from not having a has_parent, so we ignore it 
			}
			return; 
		}

		// the subquery performs faster than the old method (further below) on sites with tens of thousands of pages
		$in = $selector->operator == '!=' ? 'NOT IN' : 'IN';
		$query->where("pages.parent_id $in (SELECT pages_id FROM pages_parents WHERE parents_id=$parent_id OR pages_id=$parent_id)");

		/*
		// OLD method kept for reference
		$joinType = 'join';
		$table = "pages_has_parent$cnt";

		if($selector->operator == '!=') { 
			$joinType = 'leftjoin';
			$query->where("$table.pages_id IS NULL"); 

		}

		$query->$joinType(
			"pages_parents AS $table ON (" . 
				"($table.pages_id=pages.id OR $table.pages_id=pages.parent_id) " . 
				"AND ($table.parents_id=$parent_id OR $table.pages_id=$parent_id) " . 
			")"
		); 
		*/
	}

	/**
	 * Match a number of children count
	 *
	 */
	protected function getQueryNumChildren(DatabaseQuerySelect $query, $selector) {

		if(!in_array($selector->operator, array('=', '<', '>', '<=', '>=', '!='))) 
			throw new PageFinderSyntaxException("Operator '{$selector->operator}' not allowed for 'num_children' selector."); 

		$value = (int) $selector->value;
		$this->getQueryNumChildren++; 
		$n = (int) $this->getQueryNumChildren;
		$a = "pages_num_children$n";
		$b = "num_children$n";

		if(	(in_array($selector->operator, array('<', '<=', '!=')) && $value) || 
			(in_array($selector->operator, array('>', '>=', '!=')) && $value < 0) || 
			(($selector->operator == '=' || $selector->operator == '>=') && !$value)) {

			// allow for zero values
			$query->select("COUNT($a.id) AS $b"); 
			$query->leftjoin("pages AS $a ON ($a.parent_id=pages.id)");
			$query->groupby("HAVING COUNT($a.id){$selector->operator}$value"); 

			/* FOR REFERENCE
			$query->select("count(pages_num_children$n.id) AS num_children$n"); 
			$query->leftjoin("pages AS pages_num_children$n ON (pages_num_children$n.parent_id=pages.id)");
			$query->groupby("HAVING count(pages_num_children$n.id){$selector->operator}$value"); 
			*/
			return $b;

		} else {

			// non zero values
			$query->select("$a.$b AS $b"); 
			$query->leftjoin(
				"(" . 
				"SELECT p$n.parent_id, COUNT(p$n.id) AS $b " . 
				"FROM pages AS p$n " . 
				"GROUP BY p$n.parent_id " . 
				"HAVING $b{$selector->operator}$value " . 
				") $a ON $a.parent_id=pages.id"); 

			$where = "$a.$b{$selector->operator}$value"; 
			$query->where($where);

			/* FOR REFERENCE
			$query->select("pages_num_children$n.num_children$n AS num_children$n"); 
			$query->leftjoin(
				"(" . 
				"SELECT p$n.parent_id, count(p$n.id) AS num_children$n " . 
				"FROM pages AS p$n " . 
				"GROUP BY p$n.parent_id " . 
				"HAVING num_children$n{$selector->operator}$value" . 
				") pages_num_children$n ON pages_num_children$n.parent_id=pages.id"); 

			$query->where("pages_num_children$n.num_children$n{$selector->operator}$value");
			*/

			return "$a.$b";
		}

	}

	/**
	 * Arrange the order of field names where necessary
	 *
	 */
	protected function arrangeFields(array $fields) {
		$custom = array();
		$native = array();
		foreach($fields as $name) {
			if($this->wire('fields')->isNativeName($name)) $native[] = $name;
				else $custom[] = $name; 
		}
		return array_merge($native, $custom); 
	}

	/**
	 * Returns the total number of results returned from the last find() operation
	 *
	 * If the last find() included limit, then this returns the total without the limit
	 *
	 * @return int
	 *
	 */
	public function getTotal() {
		return $this->total; 
	}

	/**
	 * Returns the limit placed upon the last find() operation, or 0 if no limit was specified
	 *
	 */
	public function getLimit() {
		return $this->limit; 
	}

	/**
	 * Returns the start placed upon the last find() operation
	 *
	 */
	public function getStart() {
		return $this->start; 
	}

	/**
	 * Returns the parent ID, if it was part of the selector
	 *
	 */
	public function getParentID() {
		return $this->parent_id; 
	}

	/**
	 * Returns the templates ID, if it was part of the selector
	 *
	 */
	public function getTemplatesID() {
		return $this->templates_id; 
	}

	/**
	 * Return array of the options provided to PageFinder, as well as those determined at runtime
	 *
	 */
	public function getOptions() {
		return $this->lastOptions; 
	}

}

