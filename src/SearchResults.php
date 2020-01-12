<?php

namespace IDCT\Search;

class SearchResults
{
    protected $data;
    protected $maxResults;
    protected $executionTime;

    public function __construct(array $data, int $maxResults, int $executionTime)
    {
        $this->data = $data;
        $this->maxResults = $maxResults;
        $this->executionTime = $executionTime;    
    }

    public function getExecutionTime()
    {
        return $this->executionTime;        
    }

    public function getMaxResults()
    {
        return $this->maxResults;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getIds()
    {
        return array_keys($this->data);
    }
}