<?php namespace ProcessWire;

/**
 * Page List Custom Children
 * 
 * Makes children/subpages in PageList customizable so they can appear under multiple parents.
 * 
 * ProcessWire 3.x
 * Copyright 2024 by Ryan Cramer
 * 
 * @property string $definitions Unparsed definitions
 * 
 */

class PageListCustomChildren extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return [
			'title' => 'Page List Custom Children',
			'version' => 2,
			'summary' => 'Makes children/subpages in PageList customizable so they can appear under multiple parents.',
			'author' => 'Ryan Cramer',
			'icon' => 'sitemap',
			'autoload' => 'template=admin',
			'requires' => 'ProcessWire>=3.0.200',
		];
	}

	/**
	 * Parsed definitions cache
	 * 
	 * @var array 
	 * 
	 */
	protected $defs = [];

	/**
	 * Construct
	 * 
	 */
	public function __construct() {
		$this->set('definitions', ''); 
		parent::__construct();
	}

	/**
	 * API ready
	 * 
	 */
	public function ready() {
		$process = $this->wire()->page->process;
		
		if($process == 'ProcessPageList' && count($this->getDefinitions())) {
			$this->addHookBefore('ProcessPageList::find', $this, 'hookPageListFind');
			$this->addHookBefore('ProcessPageListRender::getNumChildren', $this, 'hookPageListNumChildren');
			$this->addHookAfter('ProcessPageListActions::getActions', $this, 'hookPageListActions');
			$this->addHookAfter('ProcessPageEdit::loadPage', $this, 'hookPageEditLoadPage');
				
		} else if($process == 'ProcessPageAdd' && count($this->getDefinitions())) {
			$this->addHookAfter('ProcessPageAdd::buildForm', $this, 'hookPageAddBuildForm');
			$this->addHookAfter('Pages::saveReady', $this, 'hookPagesSaveReady');
			
		} else if($process == 'ProcessPageEdit' && count($this->getDefinitions())) {
			$this->addHookAfter('ProcessPageEdit::loadPage', $this, 'hookPageEditLoadPage');
			$this->addHookBefore('ProcessPageEdit::buildFormChildren', function($e) {
				$page = $e->object->getPage(); /** @var Page $page */
				if($page->numChildren) return;
				$page->setQuietly('_plccNoChildren', true);
				$page->set('numChildren', 2);
			});
			$this->addHookAfter('ProcessPageEdit::buildFormChildren', function($e) {
				$page = $e->object->getPage(); /** @var Page $page */
				if($page->get('_plccNoChildren')) $page->set('numChildren', 0)->offsetUnset('_plccNoChildren');
			});
		}
	}

	/**
	 * Hook after ProcessPageEdit::loadPage
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPageEditLoadPage(HookEvent $event) {
		
		$page = $event->return; /** @var Page $page */
		$match = $this->getMatchDefinitionForPage($page);
		
		if(!$match) return;
		
		if(empty($match['newParentId'])) return;
		
		if(!ctype_digit("$match[newParentId]")) {
			$match['newParentId'] = $this->wire()->pages->get($match['newParentId'])->id; 
		}
	
		// update button href to use new parent
		$this->addHookBefore('InputfieldButton(name=AddPageBtn)::render', 
			function(HookEvent $e) use($page, $match) {
				$f = $e->object; /** @var InputfieldButton $f */
				$f->href = str_replace(
					"parent_id=$page->id", 
					"parent_id=$match[newParentId]&plccid=$match[id].$page->id", 
					$f->href
				);
			});
	}

	/**
	 * Hook After ProcessPageAdd::buildForm
	 * 
	 * Update action attribute of form to retain the plccid query string variable
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPageAddBuildForm(HookEvent $event) {
		$form = $event->return; /** @var InputfieldForm $form */
		list($defId, $pageId) = $this->plccid();
		if(!$defId) return;

		$definitions = $this->getDefinitions();
		if(!isset($definitions[$defId])) return;
		
		$action = $form->attr('action');
		$action .= (strpos($action, '?') === false ? '?' : '&') . "plccid=$defId.$pageId";
		
		$form->attr('action', $action);
	}

	/**
	 * Hook after Pages::saveReady
	 * 
	 * Populate values defined in child matching selector so that newly
	 * added pages can have those properties pre-set so they continue to match
	 * as children.
	 * 
	 * @param HookEvent $event
	 *
	 */
	public function hookPagesSaveReady(HookEvent $event) {
		
		$page = $event->arguments(0); /** @var Page $page */
		
		list($defId, $pageId) = $this->plccid();
		
		if(!$defId) return;
		
		$definitions = $this->getDefinitions();
		if(!isset($definitions[$defId])) return;
		
		$match = $definitions[$defId];
	
		foreach($match['useSelectors'] as $s) {
			/** @var Selector $s */
			foreach($s->fields() as $fieldName) {
				if($fieldName === 'template' || $fieldName === 'templates_id') continue;
				if($fieldName === 'parent' || $fieldName === 'parent_id') continue;
				$value = $s->value;
				if(is_string($value)) $value = str_replace('page.id', $pageId, $value);
				$page->set($fieldName, $value);
			}
		}
	}

	/**
	 * Hook after ProcessPageList::getActions()
	 * 
	 * Update the 'new' action to account for pages real parent.
	 * Remove the 'move' action.
	 * 
	 * @param HookEvent $event
	 *
	 */
	public function hookPageListActions(HookEvent $event) {
		
		$page = $event->arguments(0); /** @var Page $page */
		$actions = $event->return; /** @var array $actions */
		$match = $this->getMatchDefinitionForPage($page);
		
		if(!$match) return;
		
		if(empty($match['newParentId'])) {
			unset($actions['new'], $actions['move']);
			$event->return = $actions;
		} else {
			$parentId = $match['newParentId']; 
			if(!ctype_digit($parentId)) {
				$parent = $this->wire()->pages->get($parentId);
				$parentId = $parent->id;
			}
			if($parentId && isset($actions['new'])) {
				$url = $actions['new']['url'];
				$url = str_replace("=$page->id", "=$parentId", $url);
				$url .= "&plccid=$match[id].$page->id";
				$actions['new']['url'] = $url;
				unset($actions['move']);
				$event->return = $actions;
			}
		}
	}

	/**
	 * Hook before ProcessPageList::numChildren
	 * 
	 * Ensure the children count accounts for those from this module. 
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPageListNumChildren(HookEvent $event) {
		$page = $event->arguments(0); /** @var Page $page */
		$selectorOption = $event->arguments(1);
		$match = $this->getMatchDefinitionForPage($page); 
		
		if(!$match) return;
		
		$selector = $match['useSelectorString']; 
		$include = '';
		
		if($selectorOption === true) {
			// no include=mode 
		} else if($selectorOption === false) {
			$include = 'all';
		} else if($selectorOption === 1) {
			$user = $event->wire()->user;
			$include = $user->hasPermission('page-edit') ? 'unpublished' : 'hidden';
		} else if(is_string($selectorOption) && $selectorOption) {
			$selector = trim("$selector, $selectorOption", ", ");
		}
		
		if($include) $selector = "$selector, include=$include";
		
		$numChildren = $this->wire()->pages->count($selector);
		$event->return = $numChildren;
		$event->replace = true;
	}

	/**
	 * Hook before ProcessPageList::find()
	 * 
	 * Update the find results to match custom pages when appropriate.
	 * 
	 * @param HookEvent $event
	 * 
	 */
	public function hookPageListFind(HookEvent $event) {
		
		$selector = $event->arguments(0); /** @var string $selector */
		$page = $event->arguments(1); /** @var Page $page */
		$match = $this->getMatchDefinitionForPage($page);
		
		if(!$match) return;
	
		$sort = $match['sort'];
		if(empty($sort) && !empty($match['newParentId'])) {
			$parent = $this->wire()->pages->get($match['newParentId']); 
			if($parent->id) $sort = $parent->sortfield();
		}
		$selector = trim("$selector, $match[useSelectorString]", ", ");
		if(empty($selector)) return;
		if($sort) $selector .= ", sort=$sort";
		
		$children = $this->wire()->pages->find($selector);
		$event->return = $children;
		$event->replace = true;
	}

	/**
	 * Find a match definition that applies to given $page
	 * 
	 * @param Page $page
	 * @return array|false
	 * 
	 */
	protected function getMatchDefinitionForPage(Page $page) {
	
		$match = $page->get('_plccMatch'); // cache
		if($match !== null) return $match;
		
		$match = false;
		
		foreach($this->getDefinitions() as $def) {
			$matchTemplate = null;

			if(count($def['ifTemplates'])) {
				foreach($def['ifTemplates'] as $t) {
					if(ctype_digit($t)) {
						if($t == $page->template->id) $matchTemplate = $page->template;
					} else {
						if($t == $page->template->name) $matchTemplate = $page->template;
					}
				}
				// if there were if-templates and none matched then abort this $def
				if($matchTemplate === null) continue;
			}
		
			if($def['ifSelectors']->count()) {
				$match = $page->matches($def['ifSelectors']) ? $def : false;
			} else {
				$match = $matchTemplate ? $def : false;
			}

			if($match) break;
		}
	
		if($match) {
			// $match['useSelectorString'] = str_replace('=page.id', "=$page->id", $match['useSelectorString']);
			$match['useSelectorString'] = $this->updateSelectorString($page, $match['useSelectorString']); 
		}
		
		$page->setQuietly('_plccMatch', $match); // cache
		
		return $match;
	}

	/**
	 * Get all match definitions configured with the module
	 * 
	 * @return array
	 * 
	 */
	protected function getDefinitions() {
		if(!empty($this->defs)) return $this->defs;
		
		$defs = [];
		$defTpl = [
			'id' => 0, // runtime numeric ID (it's just the index order+1)
			'ifSelectors' => null, // if pages matches these selectors...
			'ifTemplates' => [],  // templates extracted from ifSelectors
			'useParents' => [], // use children from these parents
			'useSelectors' => [], // use children matching these selectors
			'useSelectorString' => '', // useSelectors ready for use in API call
			'newParentId' => 0, // parent ID of page that new children should add to
			'sort' => '', // field/property to sort children by
		];
		
		foreach(explode("\n", $this->definitions) as $n => $line) {
			
			if(strpos($line, '>>') === false) continue;
			
			list($ifSelector, $useSelector) = explode('>>', $line, 2);
			
			$ifSelector = trim($ifSelector); 
			$useSelector = trim($useSelector); 
			
			$ifSelectors = new Selectors($ifSelector);
			$useSelectors = new Selectors($useSelector);
			
			$useSelectorArray = [];
			
			$def = $defTpl;
			$defId = $n+1;
			$def['id'] = $defId;
			$def['ifSelectors'] = $ifSelectors;
			
			foreach($ifSelectors as $s) {
				if($s->field === 'template') {
					$def['ifTemplates'] = $s->values();
					$def['ifSelectors']->remove($s);
				}
			}
		
			foreach($useSelectors as $s) {
				if($s->field === 'parent') {
					$values = $s->values;
					$def['useParents'] = $values;
					$useSelectorArray[] = 'parent=' . $s->value();
					$def['newParentId'] = reset($values);
				} else if($s->field === 'sort') {
					$def['sort'] = $s->value();
				} else {
					$def['useSelectors'][] = $s;
					$useSelectorArray[] = (string) $s;
				}
			}
			
			$def['useSelectorString'] = implode(', ', $useSelectorArray);
			
			$defs[$defId] = $def;	
		}
		
		$this->defs = $defs;
		
		return $defs;
	}

	/**
	 * Update dynamic "page.property" items in selector string for given page
	 * 
	 * @param Page $page
	 * @param string $s
	 * @return string
	 * 
	 */
	protected function updateSelectorString(Page $page, $s) {
		
		if(strpos($s, '=page.') === false) return $s;
		if(!preg_match_all('/=page\.([._a-zA-Z0-9]+)\b/', $s, $matches)) return $s;
		
		foreach($matches[0] as $key => $fullMatch) {
			$property = $matches[1][$key];
			$value = $page->get($property);
			if($value === null) {
				$value = '';
			} if($value instanceof Page || $value instanceof PageArray) {
				$value = (string) $value;
			} else if(ctype_digit("$value")) {
				// can use value as-is
			} else {
				$value = $this->wire()->sanitizer->selectorValue("$value");
			}
			$s = str_replace($fullMatch, "=$value", $s);
		}
		
		return $s;
	}
		
	
	/**
	 * Get plccid from input and convert it to [ definition-id, page-id ]
	 * 
	 * "plccid" = "[P]age [L]ist [C]ustom [C]hildren [ID]"
	 *
	 * @return int[]
	 *
	 */
	public function plccid() {
		$id = (string) $this->wire()->input->get('plccid');
		if(strpos($id, '.') === false) return [0,0];
		list($matchId, $pageId) = explode('.', $id, 2);
		$matchId = (int) $matchId;
		$pageId = (int) $pageId;
		if($matchId < 1 || $pageId < 1) return [0,0];
		return [ $matchId, $pageId ];
	}

	/**
	 * Module config
	 * 
	 * @param InputfieldWrapper $inputfields
	 * 
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$f = $inputfields->InputfieldTextarea;
		$f->attr('name', 'definitions');
		$f->label = $this->_('Children selector definitions'); 
		$f->description = 
			$this->_('Enter two selectors per line like `page matching selector >> find children selector`.') . ' ' . 
			$this->_('You may optionally use page.*field* (for example `page.id`), in the 2nd selector to refer to the page matched in the 1st selector.') . ' ' . 
			$this->_('Please include a `parent=/path/to/parent` as part of the 2nd selector so this module can detect sorting and support add-page actions.') . ' ' . 
			$this->_('Please see:') . ' ' . 
			'[' . $this->_('More details and usage instructions') . '](https://processwire.com/blog/posts/page-list-custom-children-module/)'; 
		$f->notes = 
			$this->_('The following example displays matching blog-post pages (matching the category) as children of blog-category pages, even though the blog-post pages are actually children of /blog/posts/') . "\n" . 
			$this->_('`template=blog-category >> parent=/blog/posts, categories=page.id`'); 
		
		$f->val($this->definitions);
		$f->themeOffset = 1;
		$inputfields->add($f);
	}
	
}