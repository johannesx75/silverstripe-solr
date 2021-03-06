<?php
/**
 * A page type specifically used for displaying search results.
 *
 * This is an alternative encapsulation of search logic as it comprises much more than the out of the
 * box example. To use this instead of the default implementation, your search form call in Page should first
 * retrieve the SolrSearchPage to use as its context.
 * 
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrSearchPage extends Page {
    public static $db = array(
		'ResultsPerPage'					=> 'Int',
		'SortBy'							=> "Varchar(64)",
		'SortDir'							=> "Enum('Ascending,Descending')",
		'QueryType'							=> 'Varchar',
		'StartWithListing'					=> 'Boolean',			// whether to start display with a *:* search
		'SearchType'						=> 'MultiValueField',	// types that a user can search within
		'SearchOnFields'					=> 'MultiValueField',
		'BoostFields'						=> 'MultiValueField',
		'BoostMatchFields'					=> 'MultiValueField',

		// faceting fields
		'FacetFields'						=> 'MultiValueField',
		'FacetMapping'						=> 'MultiValueField',
		'FacetQueries'						=> 'MultiValueField',
		'MinFacetCount'						=> 'Int',

		// filter fields (not used for relevance, just for restricting data set)
		'FilterFields'						=> 'MultiValueField',

		// geo spatial options
		'GeoRestrictionField'				=> 'Varchar(64)',
		'GeoCentre'							=> 'SolrGeoPoint',
		'GeoRadius'							=> 'Double',
		'DistanceSort'						=> "Enum(',asc,desc','asc')",
		
		// not a has_one, because we may not have the listing page module
		'ListingTemplateID'					=> 'Int',
	);

	public static $many_many = array(
		'SearchTrees'			=> 'Page',
	);

	/**
	 *
	 * The facets we're interested in for this search page. This will be made a little more
	 * flexible in later releases.
	 * 
	 * for example with the alchemiser module -
	 * 
	 * array (
	 * 'AlcKeywords_ms',
	 * 	'AlcPerson_ms',
	 * 	'AlcCompany_ms',
	 * 	'AlcOrganization_ms',
	 * );
	 *
	 * @var array
	 */
	public static $facets = array();

	/**
	 * A local cache of the current query the user is executing based
	 * on data in the request
	 *
	 * @var SolrResultSet
	 */
	protected $query;

	/**
	 * @var SolrSearchService
	 */
	protected $solr;

	/**
	 * Used for the url param
	 *
	 * @var String
	 */
	public static $filter_param = 'filter';
	
	/**
	 *
	 * @var array
	 */
	public static $additional_search_types = array();
	
	public static $dependencies = array(
		'solrSearchService'			=> '%$SolrSearchService',
	);

	/**
	 * @var SolrSearchService
	 */
	public $solrSearchService;

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$fields->addFieldToTab('Root.Main', new CheckboxField('StartWithListing', _t('SolrSearchPage.START_LISTING', 'Display initial listing - useful for filterable "data type" lists')), 'Content');

		if (class_exists('ListingTemplate')) {
			$templates = DataObject::get('ListingTemplate');
			if ($templates) {
				$templates = $templates->map();
			} else {
				$templates = array();
			}

			$label = _t('SolrSearchPage.CONTENT_TEMPLATE', 'Listing Template - if not set, theme template will be used');
			$fields->addFieldToTab('Root.Main', new DropdownField('ListingTemplateID', $label, $templates, '', null, '(results template)'), 'Content');
		}

		$perPage = array('5' => '5', '10' => '10', '15' => '15', '20' => '20');
		$fields->addFieldToTab('Root.Main',new DropdownField('ResultsPerPage', _t('SolrSearchPage.RESULTS_PER_PAGE', 'Results per page'), $perPage), 'Content');

		$fields->addFieldToTab('Root.Main', new TreeMultiselectField('SearchTrees', 'Restrict results to these subtrees', 'Page'), 'Content');

		if (!$this->SortBy) {
			$this->SortBy = 'Created';
		}

		$objFields = $this->getSelectableFields();
		$fields->addFieldToTab('Root.Main', new DropdownField('SortBy', _t('SolrSearchPage.SORT_BY', 'Sort By'), $objFields), 'Content');
		$fields->addFieldToTab('Root.Main', new DropdownField('SortDir', _t('SolrSearchPage.SORT_DIR', 'Sort Direction'), $this->dbObject('SortDir')->enumValues()), 'Content');

		$types = SiteTree::page_type_classes();
		$source = array_combine($types, $types);
		asort($source);
		
		// add in any explicitly configured 
		$objects = DataObject::get('SolrTypeConfiguration');
		if ($objects) {
			foreach ($objects as $obj) {
				$source[$obj->Title] = $obj->Title;
			}
		}

		ksort($source);

		$source = array_merge($source, self::$additional_search_types);
		
		$types = new MultiValueDropdownField('SearchType', _t('SolrSearchPage.SEARCH_ITEM_TYPE', 'Search items of type'), $source);
		$fields->addFieldToTab('Root.Main', $types, 'Content');

		$fields->addFieldToTab('Root.Main', new MultiValueDropdownField('SearchOnFields', _t('SolrSearchPage.INCLUDE_FIELDS', 'Search On Fields'), $objFields), 'Content');

		$parsers = $this->solrSearchService->getQueryBuilders();
		$options = array();
		foreach ($parsers as $key => $objCls) {
			$obj = new $objCls;
			$options[$key] = $obj->title;
		}

		$fields->addFieldToTab('Root.Main', new DropdownField('QueryType', _t('SolrSearchPage.QUERY_TYPE', 'Query Type'), $options), 'Content');

		$boostVals = array();
		for ($i = 1; $i <= 5; $i++) {
			$boostVals[$i] = $i;
		}

		$fields->addFieldToTab(
			'Root.Main', 
			new KeyValueField('BoostFields', _t('SolrSearchPage.BOOST_FIELDS', 'Boost values'), $objFields, $boostVals),
			'Content'
		);

		$fields->addFieldToTab(
			'Root.Content.Main',
			$f = new KeyValueField('BoostMatchFields', _t('SolrSearchPage.BOOST_MATCH_FIELDS', 'Boost fields with field/value matches'), array(), $boostVals),
			'Content'
		);

		$f->setRightTitle('Enter a Solr field name, followed by the value to boost if found in the result set, eg "title:Home" ');
		
		$fields->addFieldToTab(
			'Root.Main',
			$kv = new KeyValueField('FilterFields', _t('SolrSearchpage.FILTER_FIELDS', 'Fields to filter by')),
			'Content'
		);
		
		$kv->setRightTitle("Lucene clauses that don't affect score");
		
		$fields->addFieldToTab('Root.Main', new HeaderField('FacetHeader', _t('SolrSearchPage.FACET_HEADER', 'Facet Settings')), 'Content');
		
		$fields->addFieldToTab(
			'Root.Main', 
			new MultiValueDropdownField('FacetFields', _t('SolrSearchPage.FACET_FIELDS', 'Fields to create facets for'), $objFields),
			'Content'
		);
		
		$fields->addFieldToTab(
			'Root.Main', 
			new KeyValueField('FacetMapping', _t('SolrSearchPage.FACET_MAPPING', 'Mapping of facet title to nice title'), $objFields),
			'Content'
		);
		
		$fields->addFieldToTab(
			'Root.Main', 
			new KeyValueField('FacetQueries', _t('SolrSearchPage.FACET_QUERIES', 'Fields to create query facets for')),
			'Content'
		);
		
		$fields->addFieldToTab('Root.Main', 
			new NumericField('MinFacetCount', _t('SolrSearchPage.MIN_FACET_COUNT', 'Minimum facet count for inclusion in facet results'), 2), 
			'Content'
		);
		
		
		// geo spatial field stuff
		$fields->addFieldToTab('Root.Main', $geoHeader = new HeaderField('GeoHeader', _t('SolrSearchPage.GEO_HEADER', 'Geospatial Settings')), 'Content');
		
		$fields->addFieldToTab('Root.Main',
			new GeoCoordinateField('GeoCentre', _t('SolrSearchPage.GEO_CENTRE', 'Centre for geo restriction')),
			'Content'
		);
		
		$geoFields = $this->getGeoSelectableFields();
		$geoFields = array_merge(array('' => ''), $geoFields);
		$fields->addFieldToTab('Root.Main', 
			$geoField = new DropdownField('GeoRestrictionField', _t('SolrSearchPage.RESTRICT_BY', 'Geo field to restrict within radius'), $geoFields), 
			'Content'
		);
		$geoField->setRightTitle('Leave the geo field blank and no geospatial restriction will be used');

		$fields->addFieldToTab('Root.Main', 
			new NumericField('GeoRadius', _t('SolrSearchPage.RESTRICT_RADIUS', 'Restrict results within radius (in km)')), 
			'Content'
		);

		$distanceOpts = array(
			'' => 'None', 
			'asc' => 'Nearest to furthest',
			'desc' => 'Furthest to nearest', 
		);

		$fields->addFieldToTab('Root.Main', 
			new DropdownField('DistanceSort', _t('SolrSearchPage.SORT_BY_DISTANCE', 'Sort by distance from point'), $distanceOpts), 
			'Content'
		);

		return $fields;
	}

	/**
	 * Return the fields that can be selected for sorting operations.
	 *
	 * @param String $listType
	 * @return array
	 */
	public function getSelectableFields($listType = null, $excludeGeo = true) {
		if (!$listType) {
			$listType = $this->searchableTypes('Page');
		}

		$availableFields = $this->solrSearchService->getAllSearchableFieldsFor($listType);
		$objFields = array_combine(array_keys($availableFields), array_keys($availableFields));
		$objFields['LastEdited'] = 'LastEdited';
		$objFields['Created'] = 'Created';
		$objFields['ID'] = 'ID';
		$objFields['score'] = 'Score';
		
		if ($excludeGeo) {
			// need to filter out any fields that are of geopoint type, as we can't use those for search
			if (!is_array($listType)) {
				$listType = array($listType);
			}
			foreach ($listType as $classType) {
				$db = Config::inst()->get($classType, 'db');
				foreach ($db as $name => $type) {
					if (is_subclass_of($type, 'SolrGeoPoint') || $type == 'SolrGeoPoint') {
						unset($objFields[$name]);
					}
				}
			}
		}
		
		ksort($objFields);
		return $objFields;
	}
	
	/**
	 * Get a list of geopoint fields that are selectable 
	 */
	public function getGeoSelectableFields() {
		$all = $this->getSelectableFields(null, false);
		$listTypes = $this->searchableTypes('Page');
		$geoFields = array();
		
		foreach ($listTypes as $classType) {
			$db = Config::inst()->get($classType, 'db');
			foreach ($db as $name => $type) {
				if (is_subclass_of($type, 'SolrGeoPoint') || $type == 'SolrGeoPoint') {
					$geoFields[$name] = $name;
				}
			}
		}

		ksort($geoFields);
		return $geoFields;
	}

	/**
	 * Ensures that there is always a 404 page
	 * by checking if there's an instance of
	 * ErrorPage with a 404 error code. If there
	 * is not, one is created when the DB is built.
	 */
	function requireDefaultRecords() {
		parent::requireDefaultRecords();

		if(SiteTree::get_create_default_pages()){
			$page = DataObject::get_one('SolrSearchPage');
			if(!($page && $page->exists())) {
				$page = new SolrSearchPage();
				$page->Title = _t('SolrSearchPage.DEFAULT_PAGE_TITLE', 'Search');
				$page->Content = '';
				$page->ResultsPerPage = 10;
				$page->Status = 'New page';
				$page->write();

				DB::alteration_message('Search page created', 'created');
			}	
		}
		
	}

	/**
	 * Get the solr instance. 
	 * 
	 * Note that we do this as a method just in case we decide in future
	 * that different pages can utilise different solr instances.. 
	 */
	public function getSolr() {
		if (!$this->solr) {
			$this->solr = $this->solrSearchService;
		}
		return $this->solr;
	}
	
	/**
	 * Figures out the list of fields to use in faceting, based on configured / defaults
	 */
	public function fieldsForFacets() {
		$fields = self::$facets;
		if ($this->FacetFields && $ff = $this->FacetFields->getValues()) {
			$fields = array();
			$types = $this->searchableTypes('Page');
			foreach ($ff as $f) {
				$fieldName = $this->getSolr()->getSolrFieldName($f, $types);
				$fields[] = $fieldName;
			}
		}

		return $fields;
	}

	/**
	 * Get the list of field -> query items to be used for faceting by query 
	 */
	public function queryFacets() {
		$fields = array();
		if ($this->FacetQueries && $fq = $this->FacetQueries->getValues()) {
			$fields = array_flip($fq);
		}
		return $fields;
	}

	/**
	 * Get the currently active query for this page, if any
	 * 
	 * @return SolrResultSet
	 */
	public function getQuery() {
		if ($this->query) {
			return $this->query;
		}

		if (!$this->getSolr()->isConnected()) {
			return null;
		}

		$query = null;
		$builder = $this->getSolr()->getQueryBuilder($this->QueryType);
		
		if (isset($_GET['Search'])) {
			$query = $_GET['Search'];

			// lets convert it to a base solr query
			$builder->baseQuery($query);
		}

		$sortBy = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->SortBy;
		$sortDir = isset($_GET['SortDir']) ? $_GET['SortDir'] : $this->SortDir;
		$types = $this->searchableTypes();
		// allow user to specify specific type
		if (isset($_GET['SearchType'])) {
			$fixedType = $_GET['SearchType'];
			if (in_array($fixedType, $types)) {
				$types = array($fixedType);
			}
		}
		
		// (strlen($this->SearchType) ? $this->SearchType : null);

		$fields = $this->getSelectableFields();
		
		// if we've explicitly set a sort by, then we want to make sure we have a type
		// so we can resolve what the field name in solr is. Otherwise we don't care about type
		// overly much 
		if (!count($types) && $sortBy) {
			// default to page
			$types = array('Page');
		}

		if (!isset($fields[$sortBy])) {
			$sortBy = 'score';
		}

		$sortDir = $sortDir == 'Ascending' ? 'asc' : 'desc';

		$activeFacets = $this->getActiveFacets();
		if (count($activeFacets)) {
			foreach ($activeFacets as $facetName => $facetValues) {
				foreach ($facetValues as $value) {
					$builder->andWith($facetName, $value);
				}
			}
		}

		$offset = isset($_GET['start']) ? $_GET['start'] : 0;
		$limit = isset($_GET['limit']) ? $_GET['limit'] : ($this->ResultsPerPage ? $this->ResultsPerPage : 10);

		if (count($types)) {
			$sortBy = $this->solrSearchService->getSortFieldName($sortBy, $types);
			$builder->andWith('ClassNameHierarchy_ms', $types);
		}
		
		if ($this->SearchTrees()->count()) {
			$parents = $this->SearchTrees()->column('ID');
			$builder->andWith('ParentsHierarchy_ms', $parents);
		}

		if (!$sortBy) {
			$sortBy = 'score';
		}
		
		$builder->sortBy($sortBy, $sortDir);

		$selectedFields = $this->SearchOnFields->getValues();

		// the following serves two purposes; filter out the searched on fields to only those that
		// are in the actually  searched on types, and to map them to relevant solr types
		if (count($selectedFields)) {
			$mappedFields = array();
			foreach ($selectedFields as $field) {
				$mappedField = $this->getSolr()->getSolrFieldName($field, $types);
				// some fields that we're searching on don't exist in the types that the user has selected
				// to search within
				if ($mappedField) {
					$mappedFields[] = $mappedField;
				}
			}
			$builder->queryFields($mappedFields);
		}

		if ($boost = $this->BoostFields->getValues()) {
			$boostSetting = array();
			foreach ($boost as $field => $amount) {
				if ($amount > 0) {
					$boostSetting[$this->getSolr()->getSolrFieldName($field, $types)] = $amount;
				}
			}
			$builder->boost($boostSetting);
		}
		
		if ($boost = $this->BoostMatchFields->getValues()) {
			if (count($boost)) {
				$builder->boostFieldValues($boost);
			}
		}

		if(isset($_GET['FieldFilter'])) {
			$filterfields = array_keys($this->FilterFields->getvalues());
			$filters = array_intersect_key($filterfields, array_flip((array)$_GET['FieldFilter']));
			if (count($filters)) {
				foreach ($filters as $filter) {
					$builder->addFilter($filter);
				}
			}
			
		} 
		
		// geo fields
		if ($this->GeoRestrictionField) {
			$mappedField = $this->getSolr()->getSolrFieldName($this->GeoRestrictionField, $types);
			$radius = $this->GeoRadius ? $this->GeoRadius : 5;
			$centre = $this->GeoCentre instanceof SolrGeoPoint ? $this->GeoCentre->latLon() : null;
			
			// allow an extension to decide how to geosearch
			if (!$centre && $this->hasMethod('updateGeoSearch')) {
				$this->extend('updateGeoSearch', $builder, $query, $mappedField, $radius);
			} else if ($centre && $mappedField) {
				$builder->restrictNearPoint($centre, $mappedField, $radius);
				if ($this->DistanceSort) {
					$builder->sortBy('geodist()', strtolower($this->DistanceSort));
				}
			}
		}

		$params = array(
			'facet' => 'true',
			'facet.field' => $this->fieldsForFacets(),
			'facet.limit' => 10,
			'facet.mincount' => $this->MinFacetCount ? $this->MinFacetCount : 1,
			'fl' => '*,score'
		);

		$fq = $this->queryFacets();
		if (count($fq)) {
			$params['facet.query'] = array_keys($fq);
		}
		
		$this->extend('updateQueryBuilder', $builder);

		$this->query = $this->getSolr()->query($builder, $offset, $limit, $params);
		return $this->query;
	}

	/**
	 * Gets a list of facet based filters
	 */
	public function getActiveFacets() {
		return isset($_GET[self::$filter_param]) ? $_GET[self::$filter_param] : array();
	}
	
	/**
	 * get the list of types that we've selected to search on
	 */
	protected function searchableTypes($default = null) {
		$listType = $this->SearchType ? $this->SearchType->getValues() : null;
		if (!$listType) {
			$listType = $default ? array($default) : null;
		}
		return $listType;
	}

	/**
	 * Returns a url parameter string that was just used to execute the current query.
	 *
	 * This is useful for ensuring the parameters used in the search can be passed on again
	 * for subsequent queries.
	 *
	 * @param array $exclusions
	 *			A list of elements that should be excluded from the final query string
	 *
	 * @return String
	 */
	function SearchQuery() {
		$parts = parse_url($_SERVER['REQUEST_URI']);
		if(!$parts) {
			throw new InvalidArgumentException("Can't parse URL: " . $uri);
		}

		// Parse params and add new variable
		$params = array();
		if(isset($parts['query'])) {
			parse_str($parts['query'], $params);
			if (count($params)) {
				return http_build_query($params);
			}
		}
	}
	
	/**
	 * Retrieve all facets in the result set in a way that can be iterated 
	 * over conveniently. 
	 * 
	 * @return \ArrayList 
	 */
	public function AllFacets() {
		$facets = $this->currentFacets();
		$result = array();
		$mapping = $this->facetFieldMapping();
		foreach ($facets as $title => $items) {
			$object = new ViewableData();
			$object->Items = $this->currentFacets($title);
			$title = isset($mapping[$title]) ? $mapping[$title] : $title;
			$object->Title = Varchar::create_field('Varchar', $title);
			$result[] = $object;
		}
		return new ArrayList($result);
	}
	
	/**
	 * Retrieve the mapping of facet field name (eg FieldName_mt) 
	 * mapped to the user entered nice name
	 * 
	 * @return type 
	 */
	protected function facetFieldMapping() {
		$fields = array();
		if ($this->FacetMapping && $ff = $this->FacetMapping->getValues()) {
			$types = $this->searchableTypes('Page');
			foreach ($ff as $f => $mapped) {
				$fieldName = $this->getSolr()->getSolrFieldName($f, $types);
				$fields[$fieldName] = $mapped;
			}
		}
		return $fields;
	}

	/**
	 * Get the list of facet values for the given term
	 *
	 * @param String $term
	 */
	public function currentFacets($term=null) {
		if (!$this->getQuery()) {
			return new ArrayList(array());
		}

		$facets = $this->getQuery()->getFacets();
		$queryFacets = $this->queryFacets();

		if ($term) {
			// return just that term
			$ret = isset($facets[$term]) ? $facets[$term] : null;
			// lets update them all and add a link parameter
			$result = array();
			if ($ret) {
				foreach ($ret as $facetTerm) {
					// if it's a query facet, then we may have a label for it 
					if (isset($queryFacets[$facetTerm->Name])) {
						$facetTerm->Name = $queryFacets[$facetTerm->Name];
					}
					$sq = $this->SearchQuery();
					$sep = strlen($sq) ? '&amp;' : '';
					$facetTerm->SearchLink = $this->Link('results') . '?' . $sq .$sep. self::$filter_param . "[$term][]=$facetTerm->Query";
					$facetTerm->QuotedSearchLink = $this->Link('results') . '?' . $sq .$sep. self::$filter_param . "[$term][]=&quot;$facetTerm->Query&quot;";
					$result[] = new ArrayData($facetTerm);
				}
			}

			return new ArrayList($result);
		}

		return new ArrayList($facets);
	}
}

class SolrSearchPage_Controller extends Page_Controller {

	protected function getSolr() {
		return $this->data()->getSolr();
	}
	
	public function index() {
		if ($this->StartWithListing) {
			$_GET['SortBy'] = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->data()->SortBy;
			$_GET['SortDir'] = isset($_GET['SortDir']) ? $_GET['SortDir'] : $this->data()->SortDir;
			$_GET['Search'] = '*';
			$this->DefaultListing = true;
			
			return $this->results();
		}
		return array();
	}

	public function Form() {
		$fields = new FieldList(
			new TextField('Search', _t('SolrSearchPage.SEARCH','Search'), isset($_GET['Search']) ? $_GET['Search'] : '')
		);

		$objFields = $this->data()->getSelectableFields();
		$objFields = array_merge(array('' => 'Any'), $objFields);
		$sortBy = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->data()->SortBy;
		$sortDir = isset($_GET['SortDir']) ? $_GET['SortDir'] : $this->data()->SortDir;
		$fields->push(new DropdownField('SortBy', _t('SolrSearchPage.SORT_BY', 'Sort By'), $objFields, $sortBy));
		$fields->push(new DropdownField('SortDir', _t('SolrSearchPage.SORT_DIR', 'Sort Direction'), $this->data()->dbObject('SortDir')->enumValues(), $sortDir));

		$actions = new FieldList(new FormAction('results', _t('SolrSearchPage.DO_SEARCH', 'Search')));
		
		$form = new Form($this, 'Form', $fields, $actions);
		$form->addExtraClass('searchPageForm');
		$form->setFormMethod('GET');
		$form->disableSecurityToken();
		return $form;
	}

	public function FacetCrumbs() {
		$activeFacets = $this->data()->getActiveFacets();
		$parts = array();
		$queryString = $this->data()->SearchQuery();
		if (count($activeFacets)) {
			foreach ($activeFacets as $facetName => $facetValues) {
				foreach ($facetValues as $i => $v) {
					$item = new stdClass();
					$item->Name = $v;
					$paramName = urlencode(SolrSearchPage::$filter_param . '[' . $facetName . '][' . $i . ']') .'='. urlencode($item->Name);
					$item->RemoveLink = $this->Link('results') . '?' . str_replace($paramName, '', $queryString);
					$parts[] = new ArrayData($item);
				}
			}
		}

		return new ArrayList($parts);
	}

	/**
	 * Process and render search results
	 */
	function results($data = null, $form = null){
		$query = $this->data()->getQuery();

		$term = isset($_GET['Search']) ? Convert::raw2xml($_GET['Search']) : '';
		
		$results = $query ? $query->getDataObjects(true) : ArrayList::create();
		
		if ($query) {
			$resultData = array(
				'TotalResults'		=> $query->getTotalResults()
			);
		} else {
			$resultData = array();
		}

	  	$data = array(
	     	'Results'		=> $results,
	     	'Query'			=> Varchar::create_field('Varchar', $term),
	      	'Title'			=> $this->data()->Title,
			'ResultData'	=> ArrayData::create($resultData)
	  	);

		$me = $this->class . '_results';
	  	return $this->customise($data)->renderWith(array($me, 'SolrSearchPage_results', 'SolrSearchPage', 'Page'));
	}
	
	/**
	 * Return the results with a template applied to them based on the page's listing template
	 *  
	 */
	public function TemplatedResults() {
		$query = $this->data()->getQuery();
		if ($this->data()->ListingTemplateID && $query) {
			$template = DataObject::get_by_id('ListingTemplate', $this->data()->ListingTemplateID);
			if ($template && $template->exists()) {
				$items = $query ? $query->getDataObjects() : new DataObjectSet();
				$item = $this->data()->customise(array('Items' => $items));
				$view = SSViewer::fromString($template->ItemTemplate);
				return $view->process($item);
			}
		}
	}
}
