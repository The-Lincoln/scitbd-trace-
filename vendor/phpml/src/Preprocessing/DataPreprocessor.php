<?php
namespace PHPML\Preprocessing;

class DataPreprocessor {
    private $data;
    
    public function __construct(array $data) {
        $this->data = $data;
    }
    
    public function normalize() {
        $normalized = [];
        foreach ($this->data as $row) {
            $normalizedRow = [];
            foreach ($row as $value) {
                $normalizedRow[] = ($value - min($row)) / (max($row) - min($row));
            }
            $normalized[] = $normalizedRow;
        }
        return $normalized;
    }
    
    public function standardize() {
        $standardized = [];
        foreach ($this->data as $row) {
            $standardizedRow = [];
            foreach ($row as $value) {
                $mean = array_sum($row) / count($row);
                $stdDev = sqrt(array_sum(array_map(function($x) use ($mean) {
                    return pow($x - $mean, 2);
                }, $row)) / count($row));
                $standardizedRow[] = ($value - $mean) / $stdDev;
            }
            $standardized[] = $standardizedRow;
        }
        return $standardized;
    }
    
    public function handleMissingValues($strategy = 'mean') {
        $processed = [];
        foreach ($this->data as $row) {
            $processedRow = [];
            foreach ($row as $value) {
                if ($value === null || $value === '') {
                    switch ($strategy) {
                        case 'mean':
                            $processedRow[] = $this->calculateMean($row);
                            break;
                        case 'median':
                            $processedRow[] = $this->calculateMedian($row);
                            break;
                        case 'mode':
                            $processedRow[] = $this->calculateMode($row);
                            break;
                        default:
                            $processedRow[] = 0;
                    }
                } else {
                    $processedRow[] = $value;
                }
            }
            $processed[] = $processedRow;
        }
        return $processed;
    }
    
    private function calculateMean(array $data) {
        $cleanData = array_filter($data, function($value) {
            return $value !== null && $value !== '';
        });
        return array_sum($cleanData) / count($cleanData);
    }
    
    private function calculateMedian(array $data) {
        $cleanData = array_filter($data, function($value) {
            return $value !== null && $value !== '';
        });
        sort($cleanData);
        $count = count($cleanData);
        $middle = floor(($count - 1) / 2);
        if ($count % 2) {
            return $cleanData[$middle];
        }
        return ($cleanData[$middle] + $cleanData[$middle + 1]) / 2;
    }
    
    private function calculateMode(array $data) {
        $cleanData = array_filter($data, function($value) {
            return $value !== null && $value !== '';
        });
        $values = array_count_values($cleanData);
        arsort($values);
        return key($values);
    }
    
    public function encodeCategorical($columnIndex) {
        $categories = [];
        foreach ($this->data as $row) {
            $categories[] = $row[$columnIndex];
        }
        $uniqueCategories = array_unique($categories);
        $encodedData = [];
        
        foreach ($this->data as $row) {
            $encodedRow = $row;
            foreach ($uniqueCategories as $index => $category) {
                $encodedRow[$columnIndex . '_' . $category] = ($row[$columnIndex] === $category) ? 1 : 0;
            }
            unset($encodedRow[$columnIndex]);
            $encodedData[] = $encodedRow;
        }
        
        return $encodedData;
    }
}
