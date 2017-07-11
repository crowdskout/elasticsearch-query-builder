<?php
namespace Crowdskout\ElasticsearchQueryBuilder\Agg;

use Generator;

class Aggregation implements AggregationInterface
{
    /** @var array */
    protected $query;

    /** @var Generator */
    protected $aggGenerator;

    /** @var Aggregation[] */
    protected $nestedAggs = [];

    /** @var AggregationMulti */
    protected $nestedAggMulti;

    public function __construct(array $query, callable $aggGenerator, Aggregation $nestedAgg = null)
    {
        $this->query = $query;
        $this->aggGenerator = $aggGenerator;
        $this->mergeAggs($nestedAgg);
    }

    /**
     * @param AggregationInterface $nestedAgg
     * @return Aggregation
     * @throws \Exception
     */
    public function add(AggregationInterface $nestedAgg = null)
    {
        $this->mergeAggs($nestedAgg);
        return $this;
    }

    /**
     * Can only have one multi that is the last aggregation
     *
     * @param AggregationMulti $nestedAggMulti
     * @return Aggregation
     */
    public function setMulti(AggregationMulti $nestedAggMulti = null)
    {
        $this->nestedAggMulti = $nestedAggMulti;
        return $this;
    }

    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Generate the query to be submitted to Elasticsearch
     *
     * @param Aggregation[] $nestedAggs
     *
     * @return array
     */
    public function generateQuery($nestedAggs = [])
    {
        if (empty($nestedAggs)) {
            $nestedAggs = $this->nestedAggs;
            if ($this->nestedAggMulti !== null) {
                $nestedAggs[] = $this->nestedAggMulti;
            }
        }

        $agg = array_shift($nestedAggs);

        if ($agg === null) {
            return $this->getQuery();
        } elseif ($agg instanceof AggregationMulti) {
            $query = $agg->generateQuery();
        } else {
            $query = $agg->generateQuery($nestedAggs);
        }

        $aggQuery = $this->getQuery();

        if (empty($aggQuery)) {
            $aggQuery = $query;
        } else {
            $aggQuery[key($aggQuery)]['aggs'] = $query;
        }

        return $aggQuery;
    }

    /**
     * Return an array representing the parsed results
     *
     * @param array $results
     * @param AggregationInterface[] $nestedAggs
     * @return array
     */
    public function generateResults($results, $nestedAggs = [])
    {
        if (empty($nestedAggs) && (!empty($this->nestedAggs) || $this->nestedAggMulti !== null)) {
            $nestedAggs = $this->nestedAggs;
            if ($this->nestedAggMulti !== null) {
                $nestedAggs[] = $this->nestedAggMulti;
            }
        }

        $agg = array_shift($nestedAggs);

        $out = ['Total' => 0];

        foreach (call_user_func($this->aggGenerator, $results) as $key => $aggResult) {
            /** @var AggResult $aggResult */
            $resultCarry = $aggResult->getResultsCarry();
            $parsedResult = $aggResult->getParsedResult();

            if ($agg instanceof AggregationMulti) {
                $parsedResult = $agg->generateResults($resultCarry);
            } elseif ($agg !== null) {
                $parsedResult = $agg->generateResults($resultCarry, $nestedAggs);
            }

            if (is_int($key)) {
                return $parsedResult;
            }

            $out['options'][$key] = $parsedResult;
            if (is_numeric($out['options'][$key])) {
                $out['Total'] += $out['options'][$key];
            } elseif (isset($out['options'][$key]['Total'])) {
                $out['Total'] += $out['options'][$key]['Total'];
            }
        }

        return !empty($out['options']) ? $out : [];
    }

    /**
     * Merges in the supplied aggregations
     *
     * @param AggregationInterface $agg
     * @throws \Exception
     */
    protected function mergeAggs(AggregationInterface $agg = null)
    {
        if ($agg === null) {
            return;
        }

        if ($this->nestedAggMulti !== null) {
            throw new \Exception("There's a multi aggregation set, additional aggregations cannot be added or merged unless the multi aggregation is removed");
        }

        if ($agg instanceof AggregationMulti) {
            $this->nestedAggMulti = $agg;
            return;
        }

        $this->nestedAggMulti = $agg->nestedAggMulti; // Carry over the multi agg if it exists
        // Flatten the nested aggregations
        $this->nestedAggs = !empty($agg->query) // don't include $agg if its an empty agg
            ? array_merge($this->nestedAggs, [$agg], $agg->nestedAggs)
            : array_merge($this->nestedAggs, $agg->nestedAggs);
    }

    public static function removePrefix($text, $prefix)
    {
        if (strpos($text, $prefix) === 0) {
            $text = substr($text, strlen($prefix)) . '';
        }
        return $text;
    }
}
