<?php

namespace IDCT\Search;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\ParameterType;
use IDCT\FileArrayCache;

class Search
{
    protected function generateSentences(string $string) {
        $data = preg_split('/\b/u', $string);
        $words = [];
        foreach ($data as $element)
        {
            $element = mb_strtolower(trim($element));
            if (empty($element)) {
                continue;
            }

            $filtered = preg_replace("/[^a-z0-9]/u", '', $element);

            if (empty($filtered) || strlen($filtered) < 3) {
                continue;
            }

            if (strlen($filtered) > 128) {
                $filtered = substr($filtered, 128);
            }

            $words[] = $filtered;
        }

        $sentenceSize = 3;
        $sentences = [];
        $max = count($words);
        for($i = 0; $i < $max; $i++)
        {    
            $sentence = '';
            for ($j = 0; $j < $sentenceSize; $j++) {
                $ij = $i + $j;
                if (isset($words[$ij])) {
                    $sentence .= $words[$ij];
                    $sentences[] = $sentence;

                }
            }    
        }

        return $sentences;
    }   

    protected Statement $removeStmt;
    protected Statement $addStmt;
    protected Connection $db;
    protected string $prefix;
    
    public function __construct(Connection $database, string $prefix = '', string $cachePath = null)
    {  
        $cachePath = is_string($cachePath) ? $cachePath : sys_get_temp_dir();
        $this->cache = new FileArrayCache($cachePath, 2);
        $this->db = $database;
        $this->prefix = $prefix;
        $this->removeStmt = $database->prepare('DELETE FROM ' . $this->prefix . 'search WHERE id = ?');
        $this->selectStmt = $database->prepare('SELECT sentence, id FROM ' . $this->prefix . 'search WHERE locale = :locale and sentence = :sentence limit :limit offset :offset');
        $this->addStmt = $database->prepare('INSERT INTO ' . $this->prefix . 'search (locale, sentence, id) VALUES (?, ?, ?);');
    }

    public function remove(int $id) 
    {
        $this->removeStmt->execute([$id]);    
    }

    public function search(Locale $locale, string $query, int $page = 0, int $perPage = 10)
    {
        $startTime = time();
        $queryKey = md5($locale . $query);

        $idsWeight = null;
        if (isset($this->cache[$queryKey])) {
            $idsWeight = $this->cache[$queryKey];
        } else {
            $sentences = $this->generateSentences($query);
            $idsWeight = [];

            /* TODO cascading: if the shorter sentence was not found then there is no reason to look for the longer */
            foreach($sentences as $sentence)
            {
                $limit = 1000;
                $offset = 0;
                do {                
                $this->selectStmt->bindValue(':locale', $locale, ParameterType::STRING);
                $this->selectStmt->bindValue(':sentence', $sentence, ParameterType::STRING);
                $this->selectStmt->bindValue(':limit', $limit, ParameterType::INTEGER);
                $this->selectStmt->bindValue(':offset', $offset, ParameterType::INTEGER);
                $this->selectStmt->execute();

                while($row = $this->selectStmt->fetch(FetchMode::ASSOCIATIVE)) {
                    $id = $row['id'];
                    if (!isset($idsWeight[$id])) {
                        $idsWeight[$id] = 0;
                    }

                    ++$idsWeight[$id];
                }
                $offset += $limit;
                } while ($this->selectStmt->rowCount() >= $limit);
                            
            }

            arsort($idsWeight);        
            $this->cache[$queryKey] = $idsWeight;
        }

        $max = count($idsWeight);
        $start = $page*$perPage;
        
        return new SearchResults(
            ($start >= $max) ? [] : array_slice($idsWeight,$page*$perPage, $perPage, true),
            $max,
            time() - $startTime
        );    
    }

    public function add(int $id, Locale $locale, string $text)
    {
        $sentences = $this->generateSentences($text);
        //in case database supports transactions...
        $this->pdo->beginTransaction();
        $this->remove($id);
        foreach ($sentences as $sentence) {
            $this->addStmt->execute([$locale, $sentence, $id]);
        }
        $this->pdo->commit();

        $this->cache->clearCache();
    }
}