<?php
namespace Akash\SearchByCharacter\Plugin\Elasticsearch\SearchAdapter\Query\Builder;

use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeProvider;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\FieldProvider\FieldType\ResolverInterface as TypeResolver;
use Magento\Elasticsearch\Model\Adapter\FieldMapperInterface;
use Magento\Elasticsearch\Model\Config;
use Magento\Elasticsearch\SearchAdapter\Query\ValueTransformerPool;
use Magento\Framework\Search\Request\Query\BoolExpression;
use Magento\Framework\Search\Request\QueryInterface as RequestQueryInterface;
class MatchQuery
{
    /**
     * Elasticsearch condition for case when query must not appear in the matching documents.
     */
    public const QUERY_CONDITION_MUST_NOT = 'must_not';

    /**
     * @var FieldMapperInterface
     */
    private $fieldMapper;

    /**
     * @var AttributeProvider
     */
    private $attributeProvider;

    /**
     * @var TypeResolver
     */
    private $fieldTypeResolver;

    /**
     * @var ValueTransformerPool
     */
    private $valueTransformerPool;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var \Akash\SearchByCharacter\Helper\Data
     */
    private $data;
    /**
     * @var \Magento\Eav\Model\Entity\Attribute
     */ 
    protected $_entityAttribute;

    /**
     * @param FieldMapperInterface $fieldMapper
     * @param AttributeProvider $attributeProvider
     * @param TypeResolver $fieldTypeResolver
     * @param ValueTransformerPool $valueTransformerPool
     * @param \Akash\SearchByCharacter\Helper\Data $data,
     * @param \Magento\Eav\Model\Entity\Attribute $entityAttribute,
     * @param Config $config
     */
    public function __construct(
        FieldMapperInterface $fieldMapper,
        AttributeProvider $attributeProvider,
        TypeResolver $fieldTypeResolver,
        ValueTransformerPool $valueTransformerPool,
        \Akash\SearchByCharacter\Helper\Data $data,
        \Magento\Eav\Model\Entity\Attribute $entityAttribute,
        Config $config
    ) {
        $this->_entityAttribute = $entityAttribute;
        $this->fieldMapper = $fieldMapper;
        $this->attributeProvider = $attributeProvider;
        $this->fieldTypeResolver = $fieldTypeResolver;
        $this->valueTransformerPool = $valueTransformerPool;
        $this->data = $data;
        $this->config = $config;
    }
    /**
     * @inheritdoc
     */
    public function aroundBuild(
        \Magento\Elasticsearch\SearchAdapter\Query\Builder\MatchQuery $subject, 
        callable $proceed,
        array $selectQuery, 
        RequestQueryInterface $requestQuery, 
        $conditionType
    )
    {
        $attributeCodeStringConfigValue = $this->data->getAttributeCodes();
        if ($attributeCodeStringConfigValue == "") { 
            return $proceed(
                $selectQuery, 
                $requestQuery, 
                $conditionType
            );
        }
        $queryValue = $this->prepareQuery($requestQuery->getValue(), $conditionType);
        $queries = $this->buildQueries($requestQuery->getMatches(), $queryValue);
        $requestQueryBoost = $requestQuery->getBoost() ?: 1;
        $minimumShouldMatch = $this->config->getElasticsearchConfigData('minimum_should_match');
        foreach ($queries as $query) {
            $queryBody = $query['body'];
            $matchKey = array_keys($queryBody)[0];
            foreach ($queryBody[$matchKey] as $field => $matchQuery) {
                $matchQuery['boost'] = $requestQueryBoost + $matchQuery['boost'];
                if ($minimumShouldMatch && $matchKey != 'match_phrase_prefix') {
                    $matchQuery['minimum_should_match'] = $minimumShouldMatch;
                }
                $queryBody[$matchKey][$field] = $matchQuery;
            }
            $selectQuery['bool'][$query['condition']][] = $queryBody;
        }
        return $selectQuery;
    }
    /**
     * Prepare query
     *
     * @param string $queryValue
     * @param string $conditionType
     * @return array
     */
    private function prepareQuery(string $queryValue, string $conditionType): array
    {
        $condition = $conditionType === BoolExpression::QUERY_CONDITION_NOT
            ? self::QUERY_CONDITION_MUST_NOT
            : $conditionType;

        return [
            'condition' => $condition,
            'value' => $queryValue,
        ];
    }

    /**
     * Creates valid ElasticSearch search conditions from Match queries
     *
     * The purpose of this method is to create a structure which represents valid search query
     * for a full-text search.
     * It sets search query condition, the search query itself, and sets the search query boost.
     *
     * The search query boost is an optional in the search query and therefore it will be set to 1 by default
     * if none passed with a match query.
     *
     * @param array $matches
     * @param array $queryValue
     * @return array
     */
    private function buildQueries(array $matches, array $queryValue): array
    {
        $conditions = [];
        $attributeCodeStringConfigValue = $this->data->getAttributeCodes();
        $attributeCodeStringConfigValue = explode(",", $attributeCodeStringConfigValue);
        // Checking for quoted phrase \"phrase test\", trim escaped surrounding quotes if found
        $count = 0;
        $value = preg_replace('#^"(.*)"$#m', '$1', $queryValue['value'], -1, $count);
        $condition = ($count) ? 'match_phrase' : 'match';
        $transformedTypes = [];

        foreach ($matches as $match) {
            $resolvedField = $this->fieldMapper->getFieldName(
                $match['field'],
                ['type' => FieldMapperInterface::TYPE_QUERY]
            );
            $attributeAdapter = $this->attributeProvider->getByAttributeCode($resolvedField);
            $fieldType = $this->fieldTypeResolver->getFieldType($attributeAdapter);
            $valueTransformer = $this->valueTransformerPool->get($fieldType ?? 'text');
            $valueTransformerHash = \spl_object_hash($valueTransformer);

            if (!isset($transformedTypes[$valueTransformerHash])) {
                $transformedTypes[$valueTransformerHash] = $valueTransformer->transform($value);
            }
            $transformedValue = $transformedTypes[$valueTransformerHash];
            if (null === $transformedValue) {
                //Value is incompatible with this field type.
                continue;
            }
            $matchCondition = $match['matchCondition'] ?? $condition;
            $fields = [];
            $sttributeText = "";
            if (in_array($match['field'], $attributeCodeStringConfigValue)) {
                $sttributeText = $this->getAttributeInfo(
                    'catalog_product', 
                    $match['field'], 
                    $transformedValue
                );
                if (!is_array($sttributeText) && $sttributeText != "") {
                    $transformedValue = $sttributeText;
                }
            }

            $fields[$resolvedField] = [
                'query' => $transformedValue,
                'boost' => $match['boost'] ?? 1,
            ];

            if (isset($match['analyzer'])) {
                $fields[$resolvedField]['analyzer'] = $match['analyzer'];
            }

            if (in_array($match['field'], $attributeCodeStringConfigValue) && is_array($sttributeText)) {
                foreach ($sttributeText as $value) {
                    $fields[$match['field'].'_value']['query'] = $value;
                    $conditions[] = [
                        'condition' => $queryValue['condition'],
                        'body' => [
                            $matchCondition => $fields,
                        ],
                    ];
                }
            } else {
                $conditions[] = [
                    'condition' => $queryValue['condition'],
                    'body' => [
                        $matchCondition => $fields,
                    ],
                ];
            }
        }
        return $conditions;
    }
    /**
     * Load attribute data by code
     *
     * @param   mixed $entityType  Can be integer, string, or instance of class Mage\Eav\Model\Entity\Type
     * @param   string $attributeCode
     * @param   string $searchQuery
     * @return  string||array
     */
    public function getAttributeInfo($entityType, $attributeCode, $searchQuery)
    {
        $values = "";
        $attributeInfo = $this->_entityAttribute->loadByCode($entityType, $attributeCode);
        $type = ['multiselect', "select"];
        if (in_array($attributeInfo->getFrontendInput(), $type)) {
            $values = [];
            foreach ($attributeInfo->getOptions() as $key => $value) {
                $result = strripos($value->getLabel(), $searchQuery);
                if ($result != "" && $result >= 0) {
                    $values[] = $value->getLabel();
                }
            }
        } 
        return $values;
    }
}