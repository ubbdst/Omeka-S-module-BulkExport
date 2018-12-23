<?php
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Entry;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use Zend\Form\Form;

abstract class AbstractResourceProcessor extends AbstractProcessor implements Configurable, Parametrizable
{
    use ConfigurableTrait, ParametrizableTrait;

    /**
     * @var string
     */
    protected $resourceType;

    /**
     * @var string
     */
    protected $resourceLabel;

    /**
     * @var string
     */
    protected $configFormClass;

    /**
     * @var string
     */
    protected $paramsFormClass;

    /**
     * @var string|int|array
     */
    protected $identifierNames;

    /**
     * @var ArrayObject
     */
    protected $base;

    /**
     * @var array
     */
    protected $mapping;

    /**
     * @var int
     */
    protected $indexResource = 0;

    /**
     * @var int
     */
    protected $processing = 0;

    /**
     * @var int
     */
    protected $totalIndexResources = 0;

    /**
     * @var int
     */
    protected $totalSkipped = 0;

    /**
     * @var int
     */
    protected $totalProcessed = 0;

    /**
     * @var int
     */
    protected $totalErrors = 0;

    public function getResourceType()
    {
        return $this->resourceType;
    }

    public function getLabel()
    {
        return $this->resourceLabel;
    }

    public function getConfigFormClass()
    {
        return $this->configFormClass;
    }

    public function getParamsFormClass()
    {
        return $this->paramsFormClass;
    }

    public function handleConfigForm(Form $form)
    {
        $values = $form->getData();
        $config = new ArrayObject;
        $this->handleFormGeneric($config, $values);
        $this->handleFormSpecific($config, $values);
        $this->setConfig($config);
    }

    public function handleParamsForm(Form $form)
    {
        $values = $form->getData();
        $params = new ArrayObject;
        $this->handleFormGeneric($params, $values);
        $this->handleFormSpecific($params, $values);
        $params['mapping'] = $values['mapping'];
        $this->setParams($params);
    }

    protected function handleFormGeneric(ArrayObject $args, array $values)
    {
        $defaults = [
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:owner' => null,
            'o:is_public' => null,
            'identifier_name' => null,
            'allow_duplicate_identifiers' => false,
            'entries_by_batch' => null,
        ];
        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $args->exchangeArray($result);
    }

    protected function handleFormSpecific(ArrayObject $args, array $values)
    {
    }

    public function process()
    {
        $this->prepareIdentifierNames();

        $this->prepareMapping();

        $this->allowDuplicateIdentifiers = (bool) $this->getParam('allow_duplicate_identifiers');

        $batch = (int) $this->getParam('entries_by_batch') ?: self::ENTRIES_BY_BATCH;

        $this->base = $this->baseEntity();

        $dataToProcess = [];
        foreach ($this->reader as $index => $entry) {
            ++$this->totalIndexResources;
            // The first entry is #1, but the iterator (array) numbered it 0.
            $this->indexResource = $index + 1;
            $this->logger->notice(
                'Processing resource index #{index}', // @translate
                ['index' => $this->indexResource]
            );

            $resource = $this->processEntry($entry);
            if (!$resource) {
                continue;
            }

            if ($this->checkEntity($resource)) {
                ++$this->processing;
                ++$this->totalProcessed;
                $dataToProcess[] = $resource->getArrayCopy();
            } else {
                ++$this->totalErrors;
            }

            // Only add every X for batch import.
            if ($this->processing >= $batch) {
                $this->processEntities($dataToProcess);
                $dataToProcess = [];
                $this->processing = 0;
            }
        }
        // Take care of remainder from the modulo check.
        $this->processEntities($dataToProcess);

        $this->logger->notice(
            'End of process: {total_resources} resources to process, {total_skipped} skipped, {total_processed} processed, {total_errors} errors inside module.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
                'total_errors' => $this->totalErrors,
            ]
        );
    }

    /**
     * Process one entry to create one resource (and eventually attached ones).
     *
     * @param Entry $entry
     * @return ArrayObject|null
     */
    protected function processEntry(Entry $entry)
    {
        if ($entry->isEmpty()) {
            $this->logger->warn(
                'Resource index #{index} is empty and is skipped.', // @translate
                ['index' => $this->indexResource]
            );
            ++$this->totalSkipped;
            return null;
        }

        $resource = clone $this->base;

        // TODO Manage the multivalue separator at field level.
        $multivalueSeparator = $this->reader->getParam('separator', '');

        foreach ($this->mapping as $sourceField => $targets) {
            // Check if the entry has a value for this source field.
            if (!isset($entry[$sourceField])) {
                continue;
            }

            $value = $entry[$sourceField];
            $values = $multivalueSeparator !== ''
                ? explode($multivalueSeparator, $value)
                : [$value];
            $values = array_map([$this, 'trimUnicode'], $values);
            $values = array_filter($values, 'strlen');
            if (!$values) {
                continue;
            }

            $this->fillResource($resource, $targets, $values);
        }

        return $resource;
    }

    protected function baseEntity()
    {
        // TODO Use a specific class that extends ArrayObject to manage process metadata (check and errors).
        $resource = new ArrayObject;
        $resource['o:id'] = null;
        $resource['checked_id'] = false;
        $resource['has_error'] = false;
        $this->baseGeneric($resource);
        $this->baseSpecific($resource);
        return $resource;
    }

    protected function baseGeneric(ArrayObject $resource)
    {
        $resourceTemplateId = $this->getParam('o:resource_template');
        if ($resourceTemplateId) {
            $resource['o:resource_template'] = ['o:id' => $resourceTemplateId];
        }
        $resourceClassId = $this->getParam('o:resource_class');
        if ($resourceClassId) {
            $resource['o:resource_class'] = ['o:id' => $resourceClassId];
        }
        $ownerId = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerId === 'current') {
            $identity = $this->getServiceLocator()->get('ControllerPluginManager')
                ->get('identity');
            $ownerId = $identity()->getId();
        }
        $resource['o:owner'] = ['o:id' => $ownerId];
        $resource['o:is_public'] = $this->getParam('o:is_public') !== 'false';
    }

    protected function baseSpecific(ArrayObject $resource)
    {
    }

    protected function fillResource(ArrayObject $resource, array $targets, array $values)
    {
        foreach ($targets as $target) {
            switch ($target['target']) {
                case $this->fillProperty($resource, $target, $values):
                    break;
                case $this->fillGeneric($resource, $target, $values):
                    break;
                case $this->fillSpecific($resource, $target, $values):
                    break;
                default:
                    $resource[$target['target']] = array_pop($values);
                    break;
            }
        }
    }

    protected function fillProperty(ArrayObject $resource, $target, array $values)
    {
        if (!isset($target['value']['property_id'])) {
            return false;
        }

        foreach ($values as $value) {
            $resourceValue = $target['value'];
            switch ($resourceValue['type']) {
                // Currently, most of the datatypes are literal.
                case 'literal':
                // case strpos($resourceValue['type'], 'customvocab:') === 0:
                default:
                    $resourceValue['@value'] = $value;
                    break;
                case 'uri':
                case strpos($resourceValue['type'], 'valuesuggest:') === 0:
                    $resourceValue['@id'] = $value;
                    // $resourceValue['o:label'] = null;
                    break;
                case 'resource':
                case 'resource:item':
                case 'resource:itemset':
                case 'resource:media':
                    $resourceValue['value_resource_id'] = $value;
                    $resourceValue['@language'] = null;
                    break;
            }
            $resource[$target['target']][] = $resourceValue;
        }
        return true;
    }

    protected function fillGeneric(ArrayObject $resource, $target, array $values)
    {
        switch ($target['target']) {
            case 'o:id':
                $value = (int) array_pop($values);
                if (!$value) {
                    return true;
                }
                $resourceType = isset($resource['resource_type']) ? $resource['resource_type'] : null;
                $id = $this->findResourceFromIdentifier($value, 'o:id', $resourceType);
                if ($id) {
                    $resource['o:id'] = $id;
                    $resource['checked_id'] = !empty($resourceType) && $resourceType !== 'resources';
                } else {
                    $resource['has_error'] = true;
                    $this->logger->err(
                        'Internal id #{id} cannot be found: the entry is skipped.', // @translate
                        ['id' => $id]
                    );
                }
                return true;
            case 'o:resource_template':
                $value = array_pop($values);
                $id = $this->getResourceTemplateId($value);
                if ($id) {
                    $resource['o:resource_template'] = ['o:id' => $id];
                }
                return true;
            case 'o:resource_class':
                $value = array_pop($values);
                $id = $this->getResourceClassId($value);
                if ($id) {
                    $resource['o:resource_class'] = ['o:id' => $id];
                }
                return true;
            case 'o:owner':
            case 'o:email':
                $value = array_pop($values);
                $id = $this->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = ['o:id' => $id];
                }
                return true;
            case 'o:is_public':
                $value = array_pop($values);
                $resource['o:is_public'] = in_array(strtolower($value), ['false', 'no', 'off', 'private'])
                    ? false
                    : (bool) $value;
                return true;
        }
        return false;
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values)
    {
        return false;
    }

    /**
     * Check if a resource is well-formed.
     *
     * @param ArrayObject $resource
     * @return bool
     */
    protected function checkEntity(ArrayObject $resource)
    {
        if (!$this->checkId($resource)) {
            $this->fillId($resource);
        }
        if ($resource['o:id']) {
            if ($this->allowDuplicateIdentifiers) {
                $this->logger->warn(
                    'The identifier is not unique.' // @translate
                );
            } else {
                $this->logger->err(
                    'The identifier is not unique.' // @translate
                );
                $resource['has_error'] = true;
            }
        }
        return !$resource['has_error'];
    }

    /**
     * Process entities.
     *
     * @param array $data
     */
    protected function processEntities(array $data)
    {
        $this->createEntities($data);
    }

    /**
     * Process creation of entities.
     *
     * @param array $data
     */
    protected function createEntities(array $data)
    {
        $resourceType = $this->getResourceType();
        $this->createResources($resourceType, $data);
    }

    /**
     * Process creation of resources.
     *
     * @param array $data
     */
    protected function createResources($resourceType, array $data)
    {
        if (!count($data)) {
            return;
        }

        try {
            if (count($data) === 1) {
                $resource = $this->api()
                    ->create($resourceType, reset($data))->getContent();
                $resources = [$resource];
            } else {
                $resources = $this->api()
                    ->batchCreate($resourceType, $data, [], ['continueOnError' => true])->getContent();
            }
        } catch (\Exception $e) {
            $this->logger->err('Core error during creation: {exception}', ['exception' => $e]); // @translate
            ++$this->totalErrors;
            return;
        }

        $labels = [
            'items' => 'item', // @translate
            'item_sets' => 'item set', // @translate
            'media' => 'media', // @translate
        ];
        $label = $labels[$resourceType];
        foreach ($resources as $resource) {
            $this->logger->notice(
                'Created {resource_type} #{resource_id}', // @translate
                ['resource_type' => $label, 'resource_id' => $resource->id()]
            );
        }
    }

    protected function prepareIdentifierNames()
    {
        $this->identifierNames = [];
        $identifierNames = $this->getParam('identifier_name', ['o:id', 'dcterms:identifier']);
        if (empty($identifierNames)) {
            $this->logger->warn(
                'No identifier name was selected.' // @translate
            );
            return;
        }

        if (!is_array($identifierNames)) {
            $identifierNames = [$identifierNames];
        }

        // For quicker search, prepare the ids of the properties.
        foreach ($identifierNames as $identifierName) {
            $id = $this->getPropertyId($identifierName);
            if ($id) {
                $this->identifierNames[$this->getPropertyTerm($id)] = $id;
            } else {
                $this->identifierNames[$identifierName] = $identifierName;
            }
        }
        $this->identifierNames = array_filter($this->identifierNames);
        if (empty($this->identifierNames)) {
            $this->logger->err(
                'Invalid identifier names: check your params.' // @translate
            );
        }
    }

    /**
     * Prepare full mapping to simplify process.
     *
     * Add automapped metadata for properties (language and datatype).
     */
    protected function prepareMapping()
    {
        $mapping = $this->getParam('mapping', []);

        $automapFields = $this->getServiceLocator()->get('ViewHelperManager')->get('automapFields');
        $sourceFields = $automapFields(array_keys($mapping), ['output_full_matches' => true]);
        $index = -1;
        foreach ($mapping as $sourceField => $targets) {
            ++$index;
            if (empty($targets)) {
                continue;
            }
            $metadata = $sourceFields[$index];
            $fullTargets = [];
            foreach ($targets as $target) {
                $result = [];
                $result['field'] = $metadata['field'];

                // Manage the property of a target when it is a resource type,
                // like "o:item_set {dcterms:title}".
                // It is used to set a metadata for derived resource (media for
                // item) or to find another resource (item set for item, as an
                // identifier name).
                if ($pos = strpos($target, '{')) {
                    $targetData = trim(substr($target, $pos + 1), '{} ');
                    $target = trim(substr($target, $pos));
                    $result['target'] = $target;
                    $result['target_data'] = $targetData;
                    $propertyId = $this->getPropertyId($targetData);
                    if ($propertyId) {
                        $result['target_data_value'] = [
                            'property_id' => $propertyId,
                            'type' => 'literal',
                            'is_public' => true,
                        ];
                    }
                } else {
                    $result['target'] = $target;
                }

                $propertyId = $this->getPropertyId($target);
                if ($propertyId) {
                    $result['value']['property_id'] = $propertyId;
                    $result['value']['type'] = $this->getDataType($metadata['type']) ?: 'literal';
                    $result['value']['@language'] = $metadata['@language'];
                    $result['value']['is_public'] = true;
                } else {
                    $result['@language'] = $metadata['@language'];
                    $result['type'] = $metadata['type'];
                }

                $fullTargets[] = $result;
            }
            $mapping[$sourceField] = $fullTargets;
        }

        // Filter the mapping to avoid to loop entries without target.
        $this->mapping = array_filter($mapping);
    }
}
